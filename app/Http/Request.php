<?php

declare(strict_types=1);

namespace TempMail\Http;

/**
 * Helper request: hanya membaca dari superglobal, tidak menyentuh state lain.
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Mengambil body JSON atau form-urlencoded sekaligus, sehingga API
     * bisa dipanggil dari fetch() maupun form biasa.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $payload = $_POST;

        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $raw = file_get_contents('php://input', false, null, 0, 65536);

        if (is_string($raw) && $raw !== '' && str_contains($contentType, 'json')) {
            $decoded = json_decode($raw, true, 16);

            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $cache = $payload;
    }

    public static function input(string $key, string $default = ''): string
    {
        $payload = self::payload();
        $value = $payload[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * IP klien. Header proxy hanya dipercaya bila proxy-nya terdaftar
     * di config security.trusted_proxies, supaya X-Forwarded-For tidak
     * bisa dipalsukan untuk melewati rate limit.
     */
    public static function ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $trusted = \TempMail\Config::get('security.trusted_proxies', []);
        if (!is_array($trusted) || $trusted === [] || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded === '') {
            return $remote;
        }

        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) ? $first : $remote;
    }
}
