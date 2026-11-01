#!/usr/local/bin/php -q
<?php

declare(strict_types=1);

/**
 * Entry point "Pipe to a Program" di cPanel.
 *
 *   Default Address → Advanced Options → Pipe to a Program
 *   isi: tempmail.freepalestine.my.id/bin/ingest.php
 *
 * Exim menjalankan skrip ini untuk setiap email dan mengirim isi email ke
 * stdin, dengan alamat tujuan tersedia di environment variable RECIPIENT.
 *
 * ATURAN PENTING:
 *  - Skrip ini TIDAK BOLEH menulis apa pun ke stdout, termasuk peringatan
 *    PHP. Output sekecil apa pun membuat Exim mengirim bounce ke pengirim.
 *  - Skrip ini SELALU keluar dengan kode 0. Exit non-zero juga memicu
 *    bounce, dan karena pengirim sering alamat palsu, itu jadi backscatter.
 *  - Kegagalan dicatat ke storage/logs, bukan ke output.
 */

use TempMail\Mail\Ingest;
use TempMail\Mail\Logger;

// Jangan pernah biarkan PHP mencetak error/warning.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// Semua output dibuang, termasuk yang tidak sengaja dihasilkan kode lain.
ob_start(static fn (string $buffer): string => '');

// Warning dijadikan exception agar tertangkap dan bisa dicatat, bukan dicetak.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require dirname(__DIR__) . '/app/App.php';

    \TempMail\App::boot();
} catch (\Throwable) {
    // Bootstrap gagal: tidak ada logger yang bisa dipakai. Keluar diam-diam
    // supaya Exim tidak mengirim bounce berisi pesan error internal.
    exit(0);
}

// App::boot() memasang handler yang keluar dengan kode 1 untuk CLI. Untuk
// jalur pipe itu berarti bounce ke pengirim, jadi handler diganti.
set_exception_handler(static function (\Throwable $e): void {
    try {
        Logger::error('ingest: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    } catch (\Throwable) {
        // Tidak ada tempat mencatat lagi.
    }

    exit(0);
});

// Fatal error (misalnya kehabisan memori) juga tidak boleh menghasilkan
// exit code selain 0.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
        try {
            Logger::error('ingest: fatal — ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
        } catch (\Throwable) {
            // Abaikan.
        }
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
});

try {
    $recipient = getenv('RECIPIENT');

    $stdin = fopen('php://stdin', 'rb');
    $raw = $stdin === false ? '' : (string) stream_get_contents($stdin);

    if ($stdin !== false) {
        fclose($stdin);
    }

    // Tanpa RECIPIENT alamat tujuan tidak bisa dipastikan. Jangan menebak
    // dari header: itu sumber bug yang sudah pernah terjadi.
    if (!is_string($recipient) || $recipient === '') {
        Logger::error('ingest: RECIPIENT tidak tersedia, email tidak diproses (' . strlen($raw) . ' byte)');
        exit(0);
    }

    if ($raw === '') {
        Logger::error('ingest: isi email kosong untuk ' . $recipient);
        exit(0);
    }

    $outcome = (new Ingest())->handle($raw, $recipient);

    if ($outcome === 'unknown_address') {
        // Spam ke alamat yang tidak pernah dibuat. Sengaja tidak dicatat:
        // catch-all bisa menerima ratusan pesan seperti ini per hari.
        exit(0);
    }

    if ($outcome !== 'stored' && $outcome !== 'duplicate') {
        Logger::error('ingest: gagal menyimpan untuk ' . $recipient . ' (outcome=' . $outcome . ')');
    }
} catch (\Throwable $e) {
    // Termasuk kegagalan database: pesan sudah ada di antrean, cron akan
    // mencoba lagi. Jangan pernah mengembalikan kode gagal ke Exim.
    try {
        Logger::error('ingest: ' . $e->getMessage());
    } catch (\Throwable) {
        // Log pun tidak tersedia; tetap keluar dengan tenang.
    }
}

exit(0);
