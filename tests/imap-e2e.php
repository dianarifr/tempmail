<?php

declare(strict_types=1);

/**
 * Uji Fetcher end-to-end terhadap server IMAP tiruan.
 *
 *   php tests/imap-e2e.php
 *
 * Butuh config.php yang mengarah ke database uji. Skrip akan meng-override
 * host/port IMAP ke server tiruan di port 8143 selama pengujian.
 */

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot();

use TempMail\Config;
use TempMail\Database;
use TempMail\Inbox\Address;
use TempMail\Mail\Fetcher;
use TempMail\Repo\MessageRepository;

$port = 8143;
$fixture = sys_get_temp_dir() . '/fake-mail.eml';

$address = Address::generateLocalPart() . '@' . Address::domain();

$eml = implode("\r\n", [
    'Return-Path: <noreply@example.com>',
    'Delivered-To: ' . $address,
    'From: "Toko Online" <noreply@toko.example>',
    'To: ' . $address,
    'Subject: =?UTF-8?B?UGVzYW5hbiBwZXJ0YW1h?=',
    'Date: Tue, 14 Oct 2025 09:15:00 +0700',
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="X"',
    '',
    '--X',
    'Content-Type: text/plain; charset="utf-8"',
    '',
    'Ini isi pesan uji.',
    '--X',
    'Content-Type: text/html; charset="utf-8"',
    '',
    '<p>Ini <b>isi</b> pesan uji.</p>',
    '--X',
    'Content-Type: text/plain; name="catatan.txt"',
    'Content-Disposition: attachment; filename="catatan.txt"',
    '',
    'lampiran uji',
    '--X--',
    '',
]);

file_put_contents($fixture, $eml);

// Alamat harus terdaftar supaya pesan tidak di-skip.
$localPart = Address::localPartOf($address);
(new \TempMail\Repo\InboxRepository())->create($address, (string) $localPart, inet_pton('127.0.0.1') ?: '');

// Reset sync_state agar polling mulai dari UID 0.
Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();

$serverCommand = sprintf(
    '%s %s %d %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__ . '/fake-imap-server.php'),
    $port,
    escapeshellarg($fixture)
);

$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($serverCommand, $descriptor, $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "gagal menjalankan server tiruan\n");
    exit(1);
}

usleep(400000);

// Override konfigurasi IMAP tanpa mengubah config.php di disk.
$reflection = new ReflectionClass(Config::class);
$values = $reflection->getProperty('values');
$values->setAccessible(true);
$current = $values->getValue();

$current['imap']['host'] = '127.0.0.1';
$current['imap']['port'] = $port;
$current['imap']['encryption'] = 'none';
$current['imap']['user'] = 'catchall@' . Address::domain();
$current['imap']['pass'] = 'secret';
$current['imap']['timeout'] = 10;

$values->setValue(null, $current);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
        return;
    }

    $failed++;
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n[IMAP end-to-end]\n";

try {
    $stats = (new Fetcher())->run();

    check('satu pesan diambil', $stats['fetched'] === 1, json_encode($stats));
    check('pesan tersimpan', $stats['stored'] === 1, json_encode($stats));
    check('tidak ada yang gagal', $stats['failed'] === 0, json_encode($stats));

    $messages = (new MessageRepository())->listForAddress($address);
    check('muncul di database', count($messages) === 1);
    check('subjek terdekode', ($messages[0]['subject'] ?? '') === 'Pesanan pertama', (string) ($messages[0]['subject'] ?? ''));
    check('pengirim benar', ($messages[0]['from_addr'] ?? '') === 'noreply@toko.example');
    check('ada lampiran', (int) ($messages[0]['attachment_count'] ?? 0) === 1);

    $full = (new MessageRepository())->find((int) $messages[0]['id'], $address);
    check('body html tersimpan', str_contains((string) $full['body_html'], '<b>isi</b>'));
    check('body text tersimpan', str_contains((string) $full['body_text'], 'isi pesan uji'));

    // Polling kedua: UID 7 sudah diproses, jadi tidak boleh tersimpan ulang.
    Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL fetcher error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

proc_close($process);

echo "\n" . str_repeat('-', 46) . "\n";
echo "lulus: {$passed}   gagal: {$failed}\n\n";

@unlink($fixture);

exit($failed === 0 ? 0 : 1);
