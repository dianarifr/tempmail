<?php

declare(strict_types=1);

namespace TempMail\Mail;

use TempMail\Config;
use TempMail\Repo\InboxRepository;
use TempMail\Repo\MessageRepository;
use TempMail\Security\RateLimiter;

/**
 * Pembersihan berkala: pesan kedaluwarsa, file lampiran, counter rate limit.
 */
final class Maintenance
{
    public function __construct(
        private readonly MessageRepository $messages = new MessageRepository(),
        private readonly InboxRepository $inboxes = new InboxRepository(),
    ) {
    }

    /**
     * @return array{messages: int, attachments: int, rate_limits: int, addresses: int}
     */
    public function purge(): array
    {
        $ttl = Config::int('inbox.message_ttl_hours', 24);
        $store = new AttachmentStore(Config::string('storage.attachments'));

        $files = $this->messages->expiredAttachmentFiles($ttl);
        $deletedFiles = 0;

        foreach ($files as $storedName) {
            $store->delete($storedName);
            $deletedFiles++;
        }

        // Baris pesan dihapus setelah file-nya, karena relasi ON DELETE CASCADE
        // akan menghapus baris attachments lebih dulu.
        $deletedMessages = $this->messages->deleteExpired($ttl);

        return [
            'messages'    => $deletedMessages,
            'attachments' => $deletedFiles,
            'rate_limits' => RateLimiter::prune(7),
            'addresses'   => $this->inboxes->pruneIdle(Config::int('inbox.address_idle_days', 0)),
        ];
    }
}
