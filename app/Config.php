<?php

declare(strict_types=1);

namespace TempMail;

use RuntimeException;

/**
 * Pembungkus konfigurasi berbasis array dengan akses dot-notation.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'File konfigurasi tidak ditemukan: ' . $path
                . '. Salin config.example.php menjadi config.php lalu isi kredensialnya.'
            );
        }

        /** @var mixed $config */
        $config = require $path;

        if (!is_array($config)) {
            throw new RuntimeException('config.php harus mengembalikan array.');
        }

        self::$values = $config;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new RuntimeException('Konfigurasi belum dimuat.');
        }

        $value = self::$values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }
}
