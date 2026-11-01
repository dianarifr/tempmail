<?php

declare(strict_types=1);

namespace TempMail\Mail;

use PDO;
use TempMail\Config;
use TempMail\Database;
use TempMail\Inbox\Address;
use TempMail\Repo\InboxRepository;
use TempMail\Repo\MessageRepository;

/**
 * Mengambil email baru dari mailbox catch-all dan menyimpannya ke database.
 *
 * Alamat tujuan ditentukan dari header envelope (Envelope-to, X-Original-To),
 * bukan Delivered-To: saat catch-all meneruskan pesan ke satu mailbox,
 * Delivered-To berisi alamat mailbox, bukan alamat acak yang dituju user.
 *
 * Pesan untuk alamat yang tidak terdaftar tidak disimpan. Dengan
 * imap.delete_after_fetch yang hanya menghapus pesan sukses, pesan yang
 * tidak dikenali tetap di server dan bisa diperiksa ulang.
 */
final class Fetcher
{
    /** @var array<string, int|string> */
    private array $stats = [
        'fetched'  => 0,
        'stored'   => 0,
        'skipped'  => 0,
        'failed'   => 0,
    ];

    public function __construct(
        private readonly MessageRepository $messages = new MessageRepository(),
        private readonly InboxRepository $inboxes = new InboxRepository(),
        private readonly ?AttachmentStore $store = null,
    ) {
    }

    private function attachmentStore(): AttachmentStore
    {
        return $this->store ?? new AttachmentStore(Config::string('storage.attachments'));
    }

    /**
     * @return array<string, int|string>
     */
    public function run(): array
    {
        $host = Config::string('imap.host');

        if ($host === '') {
            throw new \RuntimeException('Konfigurasi imap.host belum diisi.');
        }

        $client = new ImapClient(
            $host,
            Config::int('imap.port', 993),
            Config::string('imap.encryption', 'ssl'),
            Config::int('imap.timeout', 30)
        );

        $parser = new MimeParser();
        $deleteAfterFetch = Config::bool('imap.delete_after_fetch', true);
        $deleteUnmatched = Config::bool('imap.delete_unmatched', false);
        $batchSize = max(1, Config::int('imap.batch_size', 25));

        try {
            $client->connect();
            $client->login(Config::string('imap.user'), Config::string('imap.pass'));
            $client->selectMailbox(Config::string('imap.mailbox', 'INBOX'));

            $lastUid = $this->lastUid();
            $uids = array_slice($client->searchUidsGreaterThan($lastUid), 0, $batchSize);

            $purgedAnything = false;

            foreach ($uids as $uid) {
                $this->stats['fetched']++;
                $raw = $client->fetchRaw($uid);

                if ($raw === null || $raw === '') {
                    // Satu pesan gagal diambil tidak boleh memblokir seluruh
                    // antrean, jadi UID tetap dimajukan. Pesannya sendiri
                    // tidak dihapus dari server dan dicatat di log.
                    $this->stats['failed']++;
                    Logger::error('UID ' . $uid . ' gagal diambil dari server, dilewati');
                    $this->saveLastUid($uid);
                    continue;
                }

                $stored = false;
                $unmatched = false;
                $duplicate = false;

                try {
                    $parsed = $parser->parse($raw);
                    $recipient = $this->matchInbox($parsed);

                    if ($recipient === null) {
                        $this->stats['skipped']++;
                        $unmatched = true;
                        Logger::info(sprintf(
                            'UID %d dilewati: tidak ada alamat terdaftar. dari=%s subjek=%s kandidat=[%s]',
                            $uid,
                            $parsed->fromAddr !== '' ? $parsed->fromAddr : '?',
                            $parsed->subject,
                            implode(', ', self::recipientCandidates($parsed))
                        ));
                    } else {
                        $outcome = $this->persist($parsed, $recipient);

                        if ($outcome === 'stored') {
                            $this->stats['stored']++;
                            $stored = true;
                        } else {
                            $this->stats['skipped']++;
                            // Duplikat berarti isinya sudah ada di database,
                            // jadi aman dihapus dari mailbox.
                            $duplicate = $outcome === 'duplicate';

                            if (!$duplicate) {
                                Logger::error(sprintf(
                                    'UID %d gagal disimpan untuk %s (insert ditolak)',
                                    $uid,
                                    $recipient
                                ));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->stats['failed']++;
                    Logger::error('Gagal memproses UID ' . $uid . ': ' . $e->getMessage());
                }

                // Pesan hanya dihapus dari server bila isinya sudah ada di
                // database (tersimpan atau duplikat). Pesan yang tidak bisa
                // dikenali tetap tinggal di mailbox supaya bisa diperiksa bila
                // ada bug pembacaan header, tapi UID tetap dimajukan agar
                // tidak diproses ulang terus dan tidak menghalangi pesan baru.
                //
                // imap.delete_unmatched bisa dinyalakan setelah pipeline
                // terbukti stabil, untuk mencegah mailbox penuh spam.
                $deletable = $stored
                    || $duplicate
                    || ($unmatched && $deleteUnmatched);

                if ($deleteAfterFetch && $deletable) {
                    $client->markDeleted($uid);
                    $purgedAnything = true;
                }

                $this->saveLastUid($uid);
            }

            if ($deleteAfterFetch && $purgedAnything) {
                $client->expunge();
            }

            $client->logout();
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
            $client->disconnect();

            throw $e;
        }

        $this->recordError(null);

        return $this->stats;
    }

    /**
     * Kandidat alamat tujuan, diurutkan dari yang paling akurat.
     *
     * Urutan ini penting. Saat catch-all meneruskan pesan ke satu mailbox,
     * Exim menulis alamat mailbox tujuan di header Delivered-To:
     *
     *   Delivered-To:  tempemail@domain   <- mailbox, bukan alamat acak
     *   Envelope-to:   abc123@domain      <- alamat asli
     *   To:            abc123@domain
     *
     * Kalau Delivered-To didahulukan, semua pesan dianggap milik mailbox
     * catch-all, tidak cocok dengan alamat terdaftar, lalu tidak tersimpan.
     *
     * @return list<string>
     */
    public static function recipientCandidates(ParsedMessage $message): array
    {
        $headers = [
            $message->header('envelope-to'),
            $message->header('x-envelope-to'),
            $message->header('x-original-to'),
            $message->header('delivered-to'),
            $message->header('to'),
            $message->toAddr,
            $message->header('cc'),
        ];

        $candidates = [];

        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }

            if (!preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $header, $matches)) {
                continue;
            }

            foreach ($matches[0] as $email) {
                $normalized = Address::normalize($email);

                if ($normalized !== null && !in_array($normalized, $candidates, true)) {
                    $candidates[] = $normalized;
                }
            }
        }

        // Alamat catch-all adalah mailbox penerima, bukan alamat yang dibuat
        // user. Buang selama masih ada kandidat lain.
        $catchall = Address::normalize(Config::string('imap.user'));

        if ($catchall !== null && count($candidates) > 1) {
            $filtered = array_values(array_filter(
                $candidates,
                static fn (string $candidate): bool => $candidate !== $catchall
            ));

            if ($filtered !== []) {
                $candidates = $filtered;
            }
        }

        return $candidates;
    }

    /**
     * Alamat tujuan pertama yang valid, tanpa melihat isi database.
     * Untuk pencocokan ke database gunakan recipientCandidates().
     */
    public static function recipient(ParsedMessage $message): ?string
    {
        return self::recipientCandidates($message)[0] ?? null;
    }

    /**
     * Kandidat pertama yang benar-benar terdaftar di tabel inboxes.
     * Ini yang mencegah pesan multi-penerima masuk ke alamat yang salah.
     */
    public function matchInbox(ParsedMessage $message): ?string
    {
        foreach (self::recipientCandidates($message) as $candidate) {
            if ($this->inboxes->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Menyimpan satu pesan.
     *
     * @return 'stored'|'duplicate'|'failed' hasil penyimpanan
     */
    private function persist(ParsedMessage $message, string $address): string
    {
        $localPart = Address::localPartOf($address);

        if ($localPart === null) {
            return 'failed';
        }

        // Alamat sudah dipastikan ada oleh matchInbox(); ensure() hanya
        // berjaga bila barisnya terhapus di antara dua query.
        $this->inboxes->ensure($address, $localPart, inet_pton('0.0.0.0') ?: '');

        $bodyText = $message->text;
        $bodyHtml = $message->html;

        if (strlen($bodyText) > 1_048_576) {
            $bodyText = mb_substr($bodyText, 0, 400_000, 'UTF-8');
        }

        $preview = Sanitizer::preview($bodyText !== '' ? $bodyText : Sanitizer::toText($bodyHtml));

        $messageKey = hash('sha256', $address . '|' . $message->fromAddr . '|' . $message->subject . '|' . $message->timestamp . '|' . $message->size);

        if ($this->messages->messageKeyExists($messageKey)) {
            return 'duplicate';
        }

        $storedAttachments = [];
        $store = $this->attachmentStore();
        $maxBytes = Config::int('security.max_attachment_bytes', 10 * 1024 * 1024);

        foreach ($message->attachments as $attachment) {
            $stored = $store->store($attachment, $maxBytes);

            if ($stored !== null) {
                $storedAttachments[] = $stored;
            }
        }

        $id = $this->messages->insert([
            'address'     => $address,
            'message_key' => $messageKey,
            'from_name'   => $message->fromName !== '' ? mb_substr($message->fromName, 0, 190, 'UTF-8') : null,
            'from_addr'   => $message->fromAddr !== '' ? mb_substr($message->fromAddr, 0, 190, 'UTF-8') : null,
            'to_addr'     => $address,
            'subject'     => mb_substr($message->subject, 0, 500, 'UTF-8'),
            'body_text'   => $bodyText !== '' ? $bodyText : null,
            'body_html'   => $bodyHtml !== '' ? $bodyHtml : null,
            'preview'     => $preview !== '' ? $preview : null,
            'size_bytes'  => min($message->size, 4_294_967_295),
            'received_at' => date('Y-m-d H:i:s', $message->timestamp),
        ], $storedAttachments);

        if ($id === null) {
            // Baris gagal disimpan: hapus file yang sudah terlanjur ditulis.
            foreach ($storedAttachments as $attachment) {
                $store->delete($attachment['stored_name']);
            }

            return 'failed';
        }

        $trimmed = $this->messages->trimToLimit($address, Config::int('inbox.max_messages_per_inbox', 100));

        foreach ($trimmed as $storedName) {
            $store->delete($storedName);
        }

        return 'stored';
    }
    private function lastUid(): int
    {
        $stmt = Database::connection()->prepare('SELECT last_uid FROM sync_state WHERE id = 1');
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function saveLastUid(int $uid): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_state (id, last_uid, last_polled_at, last_error)
             VALUES (1, :uid, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                last_uid = GREATEST(last_uid, VALUES(last_uid)),
                last_polled_at = NOW(),
                last_error = NULL'
        );
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function recordError(?string $error): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_state (id, last_uid, last_polled_at, last_error)
             VALUES (1, 0, NOW(), :error_insert)
             ON DUPLICATE KEY UPDATE last_polled_at = NOW(), last_error = :error_update'
        );

        $value = $error === null ? null : mb_substr($error, 0, 500, 'UTF-8');

        $stmt->execute([':error_insert' => $value, ':error_update' => $value]);
    }
}
