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
 * Alamat tujuan ditentukan dari header Delivered-To (paling akurat untuk
 * catch-all), lalu X-Original-To, lalu To. Pesan untuk alamat yang tidak
 * terdaftar dibuang — tanpa ini, spam ke alamat acak akan memenuhi database.
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
        $batchSize = max(1, Config::int('imap.batch_size', 25));

        try {
            $client->connect();
            $client->login(Config::string('imap.user'), Config::string('imap.pass'));
            $client->selectMailbox(Config::string('imap.mailbox', 'INBOX'));

            $lastUid = $this->lastUid();
            $uids = array_slice($client->searchUidsGreaterThan($lastUid), 0, $batchSize);

            foreach ($uids as $uid) {
                $this->stats['fetched']++;
                $raw = $client->fetchRaw($uid);

                if ($raw === null || $raw === '') {
                    $this->stats['failed']++;
                    continue;
                }

                try {
                    $parsed = $parser->parse($raw);
                    $recipient = self::recipient($parsed);

                    if ($recipient === null || !$this->inboxes->exists($recipient)) {
                        $this->stats['skipped']++;
                    } elseif ($this->persist($parsed, $recipient)) {
                        $this->stats['stored']++;
                    } else {
                        $this->stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $this->stats['failed']++;
                    Logger::error('Gagal memproses UID ' . $uid . ': ' . $e->getMessage());
                }

                if ($deleteAfterFetch) {
                    $client->markDeleted($uid);
                }

                $this->saveLastUid($uid);
            }

            if ($deleteAfterFetch && $uids !== []) {
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

    /** Menentukan alamat tujuan pesan. */
    public static function recipient(ParsedMessage $message): ?string
    {
        $candidates = [
            $message->header('delivered-to'),
            $message->header('x-original-to'),
            $message->header('envelope-to'),
            $message->toAddr !== '' ? $message->toAddr : $message->header('to'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $candidate, $matches)) {
                foreach ($matches[0] as $email) {
                    $normalized = Address::normalize($email);

                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }
        }

        return null;
    }

    private function persist(ParsedMessage $message, string $address): bool
    {
        $localPart = Address::localPartOf($address);

        if ($localPart === null) {
            return false;
        }

        // Alamat yang dikirimi email tapi belum terdaftar (misalnya user
        // mengetik alamat lama setelah emailnya datang) tetap dicatat.
        $this->inboxes->ensure($address, $localPart, inet_pton('0.0.0.0') ?: '');

        $bodyText = $message->text;
        $bodyHtml = $message->html;

        if (strlen($bodyText) > 1_048_576) {
            $bodyText = mb_substr($bodyText, 0, 400_000, 'UTF-8');
        }

        $preview = Sanitizer::preview($bodyText !== '' ? $bodyText : Sanitizer::toText($bodyHtml));

        $messageKey = hash('sha256', $address . '|' . $message->fromAddr . '|' . $message->subject . '|' . $message->timestamp . '|' . $message->size);

        if ($this->messages->messageKeyExists($messageKey)) {
            return false;
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

            return false;
        }

        $trimmed = $this->messages->trimToLimit($address, Config::int('inbox.max_messages_per_inbox', 100));

        foreach ($trimmed as $storedName) {
            $store->delete($storedName);
        }

        return true;
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
