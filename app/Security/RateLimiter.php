<?php

declare(strict_types=1);

namespace TempMail\Security;

use TempMail\Config;
use TempMail\Database;

/**
 * Rate limiter berbasis tabel `rate_limits`.
 *
 * Counter disimpan per (ip, bucket, window). Untuk pembuatan alamat baru
 * window-nya adalah tanggal, sehingga batasnya "per hari".
 */
final class RateLimiter
{
    /**
     * Menaikkan counter dan mengembalikan true bila masih di bawah batas.
     */
    public static function attempt(string $ip, string $bucket, int $limit, string $window): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (ip, bucket, window_key, counter, created_at)
             VALUES (:ip, :bucket, :window, 1, NOW())
             ON DUPLICATE KEY UPDATE counter = counter + 1'
        );
        $stmt->execute([
            ':ip'     => self::packIp($ip),
            ':bucket' => $bucket,
            ':window' => $window,
        ]);

        $stmt = $pdo->prepare(
            'SELECT counter FROM rate_limits
             WHERE ip = :ip AND bucket = :bucket AND window_key = :window'
        );
        $stmt->execute([
            ':ip'     => self::packIp($ip),
            ':bucket' => $bucket,
            ':window' => $window,
        ]);

        return (int) $stmt->fetchColumn() <= $limit;
    }

    public static function remaining(string $ip, string $bucket, int $limit, string $window): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT counter FROM rate_limits
             WHERE ip = :ip AND bucket = :bucket AND window_key = :window'
        );
        $stmt->execute([
            ':ip'     => self::packIp($ip),
            ':bucket' => $bucket,
            ':window' => $window,
        ]);

        $used = (int) ($stmt->fetchColumn() ?: 0);

        return max(0, $limit - $used);
    }

    /** Menghapus counter lama agar tabel tidak tumbuh tanpa batas. */
    public static function prune(int $keepDays = 7): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $keepDays, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public static function dailyWindow(): string
    {
        return date('Y-m-d');
    }

    /**
     * IP disimpan sebagai VARBINARY(16) lewat inet6_aton() setara,
     * sehingga IPv4 dan IPv6 punya panjang konsisten.
     */
    private static function packIp(string $ip): string
    {
        $bin = @inet_pton($ip);

        return $bin === false ? inet_pton('0.0.0.0') : $bin;
    }
}
