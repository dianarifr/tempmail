<?php

declare(strict_types=1);

namespace TempMail\Repo;

use PDO;
use TempMail\Database;

/**
 * Data access untuk tabel `inboxes`. Semua query memakai prepared statement.
 */
final class InboxRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function exists(string $address): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inboxes WHERE address = :address LIMIT 1');
        $stmt->execute([':address' => $address]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(string $address): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT address, local_part, created_at, last_opened_at
             FROM inboxes WHERE address = :address LIMIT 1'
        );
        $stmt->execute([':address' => $address]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Membuat alamat baru. Mengembalikan false bila alamat sudah dipakai
     * (tabrakan random), sehingga pemanggil bisa mencoba lagi.
     */
    public function create(string $address, string $localPart, string $ipBinary): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO inboxes (address, local_part, created_ip, created_at)
             VALUES (:address, :local_part, :ip, NOW())'
        );
        $stmt->execute([
            ':address'    => $address,
            ':local_part' => $localPart,
            ':ip'         => $ipBinary,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Mendaftarkan alamat yang diketik user bila belum ada. */
    public function ensure(string $address, string $localPart, string $ipBinary): void
    {
        $this->create($address, $localPart, $ipBinary);
    }

    public function touch(string $address): void
    {
        $stmt = $this->pdo->prepare('UPDATE inboxes SET last_opened_at = NOW() WHERE address = :address');
        $stmt->execute([':address' => $address]);
    }

    /**
     * Menghapus alamat yang lama tidak dibuka, beserta seluruh pesannya.
     * Nonaktif bila inbox.address_idle_days = 0.
     */
    public function pruneIdle(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM inboxes
             WHERE last_opened_at IS NOT NULL
               AND last_opened_at < (NOW() - INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
