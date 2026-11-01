<?php

declare(strict_types=1);

namespace TempMail\Mail;

use TempMail\Config;
use TempMail\Inbox\Address;
use TempMail\Repo\InboxRepository;
use TempMail\Repo\MessageRepository;

/**
 * Menyimpan satu pesan yang sudah diparse ke database.
 *
 * Dipakai dua jalur masuk yang berbeda:
 *  - Fetcher      : email diambil dari mailbox lewat IMAP (cron)
 *  - Ingest       : email dipipe Exim langsung ke skrip PHP
 *
 * Keduanya harus menghasilkan baris database yang identik, jadi logika
 * penyimpanan sengaja dipusatkan di sini, bukan diduplikasi.
 */
final class MessageWriter
{
    /** Hasil penyimpanan: pesan masuk, sudah ada, atau gagal. */
    public const STORED = 'stored';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';

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
     * @return self::STORED|self::DUPLICATE|self::FAILED
     */
    public function write(ParsedMessage $message, string $address): string
    {
        $localPart = Address::localPartOf($address);

        if ($localPart === null) {
            return self::FAILED;
        }

        // Pemanggil sudah memastikan alamat terdaftar; ensure() hanya berjaga
        // bila barisnya terhapus di antara pengecekan dan penyimpanan.
        $this->inboxes->ensure($address, $localPart, inet_pton('0.0.0.0') ?: '');

        $bodyText = $message->text;
        $bodyHtml = $message->html;

        if (strlen($bodyText) > 1_048_576) {
            $bodyText = mb_substr($bodyText, 0, 400_000, 'UTF-8');
        }

        $preview = Sanitizer::preview($bodyText !== '' ? $bodyText : Sanitizer::toText($bodyHtml));

        $messageKey = hash(
            'sha256',
            $address . '|' . $message->fromAddr . '|' . $message->subject
                . '|' . $message->timestamp . '|' . $message->size
        );

        if ($this->messages->messageKeyExists($messageKey)) {
            return self::DUPLICATE;
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

            return self::FAILED;
        }

        $trimmed = $this->messages->trimToLimit($address, Config::int('inbox.max_messages_per_inbox', 100));

        foreach ($trimmed as $storedName) {
            $store->delete($storedName);
        }

        return self::STORED;
    }
}
