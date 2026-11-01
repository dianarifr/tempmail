<?php

declare(strict_types=1);

namespace TempMail\Mail;

use TempMail\Config;

/**
 * Log sederhana ke file, dipakai oleh cron karena outputnya tidak terlihat.
 */
final class Logger
{
    public static function write(string $level, string $message): void
    {
        $directory = Config::string('storage.logs');

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $line = sprintf(
            "[%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message)
        );

        @file_put_contents($directory . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message): void
    {
        self::write('error', $message);
    }

    public static function info(string $message): void
    {
        self::write('info', $message);
    }
}
