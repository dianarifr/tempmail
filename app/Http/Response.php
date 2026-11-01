<?php

declare(strict_types=1);

namespace TempMail\Http;

/**
 * Membangun response JSON dan menerapkan header keamanan standar.
 */
final class Response
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        exit;
    }

    public static function error(string $message, int $status = 400, string $code = 'error'): never
    {
        self::json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data = []): never
    {
        self::json(['ok' => true] + $data);
    }
}
