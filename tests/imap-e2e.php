<?php

declare(strict_types=1);

/**
 * Uji Fetcher end-to-end terhadap server IMAP tiruan.
 *
 *   php tests/imap-e2e.php
 *
 * Memakai tests/config.test.php, bukan config.php, supaya tidak pernah
 * menyentuh database atau mailbox produksi. Skrip meng-override host/port
 * IMAP ke server tiruan di port 8143 selama pengujian.
 *
 * Butuh database uji:
 *   TEMPMAIL_TEST_DB=tempmail_test php tests/imap-e2e.php
 */

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot(dirname(__FILE__) . '/config.test.php');

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
    // Urutan header ini meniru Exim di shared hosting: Delivered-To berisi
    // alamat mailbox catch-all, bukan alamat acak yang dituju pengirim.
    'Delivered-To: catchall@' . Address::domain(),
    'Envelope-to: ' . $address,
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

/**
 * Menjalankan satu putaran uji: tulis fixture, jalankan server tiruan,
 * jalankan Fetcher, lalu kembalikan statistik dan perintah IMAP yang diterima
 * server. Server tiruan selalu menyajikan satu pesan dengan UID 7.
 *
 * @return array{stats: array<string, int>, commands: list<string>}
 */
function runRound(string $eml, string $fixture, int $port): array
{
    pointImapAt($port);
    file_put_contents($fixture, $eml);

    $serverCommand = sprintf(
        '%s %s %d %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/fake-imap-server.php'),
        $port,
        escapeshellarg($fixture)
    );

    $process = proc_open($serverCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        fwrite(STDERR, "gagal menjalankan server tiruan\n");
        exit(1);
    }

    usleep(400000);

    $stats = null;
    $error = null;

    try {
        $stats = (new Fetcher())->run();
    } catch (Throwable $e) {
        $error = $e;
    }

    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($error !== null) {
        throw $error;
    }

    $stateFile = sys_get_temp_dir() . '/fake-imap-state.json';
    $state = is_file($stateFile)
        ? json_decode((string) file_get_contents($stateFile), true)
        : [];

    return [
        'stats'    => $stats,
        'commands' => $state['commands'] ?? [],
    ];
}

/** Apakah ada perintah IMAP yang menghapus pesan (UID STORE +FLAGS \Deleted)? */
function wasDeleted(array $commands): bool
{
    foreach ($commands as $command) {
        if (strtoupper($command) !== '' && preg_match('/^[A-Za-z0-9]+\s+UID\s+STORE/i', $command)) {
            return true;
        }
    }

    return false;
}

/** Mengarahkan konfigurasi IMAP ke server tiruan. */
function pointImapAt(int $port): void
{
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
}

// Alamat harus terdaftar supaya pesan tidak di-skip.
$localPart = Address::localPartOf($address);
(new \TempMail\Repo\InboxRepository())->create($address, (string) $localPart, inet_pton('127.0.0.1') ?: '');

// Reset sync_state agar polling mulai dari UID 0.
Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();

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

echo "\n[IMAP end-to-end: pesan terdaftar]\n";

try {
    $round = runRound($eml, $fixture, $port);
    $stats = $round['stats'];

    check('satu pesan diambil', $stats['fetched'] === 1, json_encode($stats));
    check('pesan tersimpan', $stats['stored'] === 1, json_encode($stats));
    check('tidak ada yang gagal', $stats['failed'] === 0, json_encode($stats));
    check('pesan terhapus dari server setelah tersimpan', wasDeleted($round['commands']));

    $messages = (new MessageRepository())->listForAddress($address);
    check('muncul di database', count($messages) === 1);
    check('subjek terdekode', ($messages[0]['subject'] ?? '') === 'Pesanan pertama', (string) ($messages[0]['subject'] ?? ''));
    check('pengirim benar', ($messages[0]['from_addr'] ?? '') === 'noreply@toko.example');
    check('ada lampiran', (int) ($messages[0]['attachment_count'] ?? 0) === 1);

    $full = (new MessageRepository())->find((int) $messages[0]['id'], $address);
    check('body html tersimpan', str_contains((string) $full['body_html'], '<b>isi</b>'));
    check('body text tersimpan', str_contains((string) $full['body_text'], 'isi pesan uji'));

    // Lingkaran kedua dengan pesan yang sama: message_key sudah ada, jadi
    // tidak boleh tersimpan dua kali, tapi pesan duplikat aman dihapus.
    Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();

    $again = runRound($eml, $fixture, $port + 3);

    check('pesan duplikat tidak tersimpan ulang', $again['stats']['stored'] === 0, json_encode($again['stats']));
    check('pesan duplikat dilewati', $again['stats']['skipped'] === 1, json_encode($again['stats']));
    check('pesan duplikat dihapus dari server', wasDeleted($again['commands']));
    check('tetap satu pesan di database', count((new MessageRepository())->listForAddress($address)) === 1);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL fetcher error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

// Opsi B: pesan untuk alamat yang tidak terdaftar tidak boleh dihapus dari
// server. Pesannya tetap ada supaya bisa diperiksa bila ada bug header.
echo "\n[IMAP end-to-end: alamat tidak terdaftar]\n";

try {
    $unknown = Address::generateLocalPart() . '@' . Address::domain();

    $emlUnknown = implode("\r\n", [
        'Return-Path: <noreply@example.com>',
        'Delivered-To: catchall@' . Address::domain(),
        'Envelope-to: ' . $unknown,
        'From: "Asing" <noreply@asing.example>',
        'To: ' . $unknown,
        'Subject: Untuk alamat acak',
        'Date: Tue, 14 Oct 2025 09:20:00 +0700',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset="utf-8"',
        '',
        'Isi pesan untuk alamat yang tidak terdaftar.',
        '',
    ]);

    Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();

    $round = runRound($emlUnknown, $fixture, $port + 1);

    check('pesan tanpa alamat terdaftar dilewati', $round['stats']['skipped'] === 1, json_encode($round['stats']));
    check('tidak ada yang tersimpan', $round['stats']['stored'] === 0, json_encode($round['stats']));
    check('pesan TIDAK dihapus dari server (opsi B)', !wasDeleted($round['commands']));

    // Setelah pipeline dipercaya, delete_unmatched boleh dinyalakan agar
    // spam tidak menumpuk di mailbox.
    $values = (new ReflectionClass(Config::class))->getProperty('values');
    $values->setAccessible(true);
    $current = $values->getValue();
    $current['imap']['delete_unmatched'] = true;
    $values->setValue(null, $current);

    Database::connection()->prepare('UPDATE sync_state SET last_uid = 0 WHERE id = 1')->execute();

    $round = runRound($emlUnknown, $fixture, $port + 2);

    check('delete_unmatched=true menghapus pesan tak dikenal', wasDeleted($round['commands']));
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL fetcher error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 46) . "\n";
echo "lulus: {$passed}   gagal: {$failed}\n\n";

@unlink($fixture);

exit($failed === 0 ? 0 : 1);
