<?php

declare(strict_types=1);

namespace TempMail\Repo;

use PDO;
use TempMail\Database;

/**
 * Data access untuk tabel `messages` dan `attachments`.
 */
final class MessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** Ringkasan pesan untuk daftar di UI (tanpa body). */
    /** @return list<array<string, mixed>> */
    public function listForAddress(string $address, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, from_name, from_addr, subject, preview, size_bytes, is_read, received_at,
                    (SELECT COUNT(*) FROM attachments a WHERE a.message_id = m.id) AS attachment_count
             FROM messages m
             WHERE address = :address
             ORDER BY received_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':address', $address);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, string $address): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, address, from_name, from_addr, to_addr, subject,
                    body_text, body_html, size_bytes, is_read, received_at
             FROM messages
             WHERE id = :id AND address = :address
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':address' => $address]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function attachmentsFor(int $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, mime_type, size_bytes
             FROM attachments WHERE message_id = :message_id ORDER BY id'
        );
        $stmt->execute([':message_id' => $messageId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findAttachment(int $id, string $address): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.filename, a.mime_type, a.size_bytes, a.stored_name
             FROM attachments a
             INNER JOIN messages m ON m.id = a.message_id
             WHERE a.id = :id AND m.address = :address
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':address' => $address]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findOwned(int $id, string $address): ?array
    {
        return $this->find($id, $address);
    }

    public function messageKeyExists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM messages WHERE message_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $message
     * @param list<array<string, mixed>> $attachments
     */
    public function insert(array $message, array $attachments = []): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO messages
                (address, message_key, from_name, from_addr, to_addr, subject,
                 body_text, body_html, preview, size_bytes, is_read, received_at, created_at)
             VALUES
                (:address, :message_key, :from_name, :from_addr, :to_addr, :subject,
                 :body_text, :body_html, :preview, :size_bytes, 0, :received_at, NOW())'
        );

        $stmt->execute([
            ':address'     => $message['address'],
            ':message_key' => $message['message_key'],
            ':from_name'   => $message['from_name'] ?? null,
            ':from_addr'   => $message['from_addr'] ?? null,
            ':to_addr'     => $message['to_addr'] ?? null,
            ':subject'     => $message['subject'] ?? null,
            ':body_text'   => $message['body_text'] ?? null,
            ':body_html'   => $message['body_html'] ?? null,
            ':preview'     => $message['preview'] ?? null,
            ':size_bytes'  => $message['size_bytes'] ?? 0,
            ':received_at' => $message['received_at'],
        ]);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $messageId = (int) $this->pdo->lastInsertId();

        if ($attachments !== []) {
            $insertAttachment = $this->pdo->prepare(
                'INSERT INTO attachments (message_id, filename, mime_type, size_bytes, stored_name, created_at)
                 VALUES (:message_id, :filename, :mime_type, :size_bytes, :stored_name, NOW())'
            );

            foreach ($attachments as $attachment) {
                $insertAttachment->execute([
                    ':message_id'  => $messageId,
                    ':filename'    => $attachment['filename'],
                    ':mime_type'   => $attachment['mime_type'],
                    ':size_bytes'  => $attachment['size_bytes'],
                    ':stored_name' => $attachment['stored_name'],
                ]);
            }
        }

        return $messageId;
    }

    public function markRead(int $id, string $address): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE messages SET is_read = 1 WHERE id = :id AND address = :address AND is_read = 0'
        );
        $stmt->execute([':id' => $id, ':address' => $address]);
    }

    public function delete(int $id, string $address): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE id = :id AND address = :address');
        $stmt->execute([':id' => $id, ':address' => $address]);

        return $stmt->rowCount() === 1;
    }

    public function deleteAllForAddress(string $address): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE address = :address');
        $stmt->execute([':address' => $address]);

        return $stmt->rowCount();
    }

    public function countForAddress(string $address): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE address = :address');
        $stmt->execute([':address' => $address]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> nama file lampiran milik satu pesan */
    public function attachmentFilesFor(int $messageId, string $address): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.stored_name
             FROM attachments a
             INNER JOIN messages m ON m.id = a.message_id
             WHERE a.message_id = :message_id AND m.address = :address'
        );
        $stmt->execute([':message_id' => $messageId, ':address' => $address]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> nama file lampiran untuk seluruh pesan satu alamat */
    public function attachmentFilesForAddress(string $address): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.stored_name
             FROM attachments a
             INNER JOIN messages m ON m.id = a.message_id
             WHERE m.address = :address'
        );
        $stmt->execute([':address' => $address]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> nama file lampiran yang akan ikut terhapus */
    public function expiredAttachmentFiles(int $ttlHours, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.stored_name
             FROM attachments a
             INNER JOIN messages m ON m.id = a.message_id
             WHERE m.received_at < (NOW() - INTERVAL :hours HOUR)
             LIMIT :limit'
        );
        $stmt->bindValue(':hours', $ttlHours, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteExpired(int $ttlHours): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM messages WHERE received_at < (NOW() - INTERVAL :hours HOUR)'
        );
        $stmt->bindValue(':hours', $ttlHours, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Menyisakan hanya N pesan terbaru pada satu alamat.
     *
     * @return list<string> nama file lampiran milik pesan yang terhapus
     */
    public function trimToLimit(string $address, int $keep): array
    {
        if ($keep <= 0) {
            return [];
        }

        // Placeholder tidak boleh berulang saat ATTR_EMULATE_PREPARES = false,
        // jadi alamat yang sama di-bind dua kali dengan nama berbeda.
        $stmt = $this->pdo->prepare(
            'SELECT a.stored_name
             FROM attachments a
             INNER JOIN messages m ON m.id = a.message_id
             WHERE m.address = :address_files
               AND m.id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM messages
                       WHERE address = :address_keep
                       ORDER BY received_at DESC, id DESC
                       LIMIT :keep
                   ) AS recent
               )'
        );
        $stmt->bindValue(':address_files', $address);
        $stmt->bindValue(':address_keep', $address);
        $stmt->bindValue(':keep', $keep, PDO::PARAM_INT);
        $stmt->execute();

        $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $delete = $this->pdo->prepare(
            'DELETE FROM messages
             WHERE address = :address_outer
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM messages
                       WHERE address = :address_inner
                       ORDER BY received_at DESC, id DESC
                       LIMIT :keep
                   ) AS recent
               )'
        );
        $delete->bindValue(':address_outer', $address);
        $delete->bindValue(':address_inner', $address);
        $delete->bindValue(':keep', $keep, PDO::PARAM_INT);
        $delete->execute();

        return $files;
    }
}
