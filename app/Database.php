<?php

declare(strict_types=1);

namespace TempMail;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Koneksi PDO tunggal (lazy).
 *
 * Semua query di aplikasi ini memakai prepared statement dengan placeholder
 * bernama; ATTR_EMULATE_PREPARES dimatikan agar escaping benar-benar
 * dilakukan oleh server MySQL, bukan oleh driver.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        return self::$connection ??= self::connect();
    }

    private static function connect(): PDO
    {
        $host = Config::string('db.host', 'localhost');
        $port = Config::int('db.port', 3306);
        $name = Config::string('db.name');
        $user = Config::string('db.user');
        $pass = Config::string('db.pass');
        $charset = Config::string('db.charset', 'utf8mb4');

        if ($name === '' || $user === '') {
            throw new RuntimeException('Konfigurasi database (db.name / db.user) belum diisi.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Gagal terhubung ke database: ' . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }

    /**
     * Menjalankan callback di dalam transaksi.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
