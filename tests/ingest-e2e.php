<?php

declare(strict_types=1);

/**
 * Uji jalur "Pipe to a Program" (bin/ingest.php + Ingest + IngestQueue).
 *
 *   TEMPMAIL_TEST_DB=tempmail_test php tests/ingest-e2e.php
 *
 * Memakai tests/config.test.php, jadi tidak menyentuh database atau domain
 * produksi. Butuh database uji karena sebagian besar perilaku yang diuji
 * adalah penyimpanan pesan.
 */

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot(dirname(__FILE__) . '/config.test.php');

use TempMail\Config;
use TempMail\Inbox\Address;
use TempMail\Mail\Ingest;
use TempMail\Mail\IngestQueue;
use TempMail\Mail\MessageWriter;
use TempMail\Repo\InboxRepository;
use TempMail\Repo\MessageRepository;

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

/** Membuat alamat acak yang terdaftar di tabel inboxes. */
function freshAddress(): string
{
    $address = Address::generateLocalPart() . '@' . Address::domain();
    (new InboxRepository())->create($address, (string) Address::localPartOf($address), inet_pton('127.0.0.1') ?: '');

    return $address;
}

/** Email mentah sederhana, bentuknya seperti yang dikirim Exim ke stdin. */
function rawEmail(string $to, string $subject = 'Pesan uji'): string
{
    return implode("\r\n", [
        'Return-Path: <noreply@example.com>',
        'From: "Toko" <noreply@toko.example>',
        'To: ' . $to,
        'Subject: ' . $subject,
        'Date: Tue, 14 Oct 2025 09:15:00 +0700',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset="utf-8"',
        '',
        'Isi pesan uji jalur pipe.',
        '',
    ]);
}

/** Mengosongkan antrean supaya hitungan tiap bagian tidak tercampur. */
function clearQueue(IngestQueue $queue): void
{
    $stats = $queue->drain(static fn (): string => MessageWriter::FAILED);

    if ($stats['processed'] > 0) {
        // drain() sengaja tidak menghapus pesan gagal; bersihkan manual.
        foreach (glob(Config::string('storage.queue') . '/*') ?: [] as $path) {
            @unlink($path);
        }
    }
}

echo "\n[Pipe: pesan untuk alamat terdaftar]\n";

try {
    $address = freshAddress();
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);

    $outcome = (new Ingest())->handle(rawEmail($address), $address);

    check('pesan tersimpan', $outcome === MessageWriter::STORED, (string) $outcome);
    check('antrean bersih setelah sukses', $queue->count() === 0, (string) $queue->count());

    $messages = (new MessageRepository())->listForAddress($address);
    check('muncul di daftar', count($messages) === 1);
    check('subjek benar', ($messages[0]['subject'] ?? '') === 'Pesan uji', (string) ($messages[0]['subject'] ?? ''));
    check('pengirim benar', ($messages[0]['from_addr'] ?? '') === 'noreply@toko.example');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Pipe: alamat tidak terdaftar tidak menyentuh disk]\n";

try {
    $queue = IngestQueue::fromConfig();
    $unknown = Address::generateLocalPart() . '@' . Address::domain();

    $outcome = (new Ingest())->handle(rawEmail($unknown), $unknown);

    check('ditolak sebagai alamat asing', $outcome === 'unknown_address', (string) $outcome);
    check('tidak ada file antrean', $queue->count() === 0, (string) $queue->count());
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Pipe: domain lain ditolak]\n";

try {
    $outcome = (new Ingest())->handle(rawEmail('a@example.com'), 'a@example.com');

    check('domain lain ditolak', $outcome === 'unknown_address', (string) $outcome);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Pipe: pesan duplikat]\n";

try {
    $address = freshAddress();
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);

    $raw = rawEmail($address, 'Duplikat');
    $ingest = new Ingest();

    $first = $ingest->handle($raw, $address);
    $second = $ingest->handle($raw, $address);

    check('kiriman pertama tersimpan', $first === MessageWriter::STORED, (string) $first);
    check('kiriman kedua dikenali duplikat', $second === MessageWriter::DUPLICATE, (string) $second);
    check('tetap satu pesan', count((new MessageRepository())->listForAddress($address)) === 1);
    check('antrean bersih', $queue->count() === 0, (string) $queue->count());
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Antrean: dipulihkan cron setelah gagal]\n";

try {
    $address = freshAddress();
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);

    // Simulasikan kegagalan simpan: pesan masuk antrean tapi writer gagal.
    $name = $queue->push(rawEmail($address, 'Pulih'), $address);

    check('entri antrean tercatat', $queue->count() === 1, (string) $queue->count());

    $drained = $queue->drain(static fn (): string => MessageWriter::FAILED);

    check('percobaan gagal tetap di antrean', $queue->count() === 1, (string) $queue->count());
    check('dihitung sebagai gagal', $drained['failed'] === 1, json_encode($drained));

    // Putaran berikutnya berhasil: pesan harus tersimpan dan antrean bersih.
    $drained = $queue->drain(
        static fn (string $raw, string $to): string => (new Ingest())->process($raw, $to)
    );

    check('putaran pemulihan menyimpan pesan', $drained['stored'] === 1, json_encode($drained));
    check('antrean kosong setelah pulih', $queue->count() === 0, (string) $queue->count());

    $messages = (new MessageRepository())->listForAddress($address);
    check('pesan hasil pemulihan ada di database', count($messages) === 1);
    check('subjek pesan pulih benar', ($messages[0]['subject'] ?? '') === 'Pulih', (string) ($messages[0]['subject'] ?? ''));

    unset($name);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Antrean: nama file tidak sah ditolak]\n";

try {
    $queue = IngestQueue::fromConfig();
    $threw = false;

    try {
        $queue->remove('../../config.php');
    } catch (Throwable) {
        $threw = true;
    }

    check('path traversal ditolak', $threw);
    check('config.php masih ada', is_file(dirname(__DIR__) . '/config.php'));
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Pipe: email melebihi batas ukuran]\n";

try {
    $address = freshAddress();
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);

    $limit = Config::int('inbox.max_message_bytes', 8 * 1024 * 1024);
    $huge = rawEmail($address) . str_repeat('A', $limit + 1024);

    $outcome = (new Ingest())->handle($huge, $address);

    check('email kebesaran ditolak', $outcome === MessageWriter::FAILED, (string) $outcome);
    check('tidak meninggalkan file antrean', $queue->count() === 0, (string) $queue->count());
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Entry point bin/ingest.php]\n";

/**
 * Menjalankan bin/ingest.php seperti Exim: email lewat stdin, alamat di
 * environment variable RECIPIENT.
 *
 * @return array{stdout: string, exit: int}
 */
function runIngest(string $eml, ?string $recipient, array $env = []): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/ingest.php');

    $environment = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'TEMPMAIL_CONFIG' => __DIR__ . '/config.test.php',
        'TEMPMAIL_TEST_DB' => (string) getenv('TEMPMAIL_TEST_DB'),
        'TEMPMAIL_TEST_DB_HOST' => (string) getenv('TEMPMAIL_TEST_DB_HOST'),
        'TEMPMAIL_TEST_DB_PORT' => (string) getenv('TEMPMAIL_TEST_DB_PORT'),
        'TEMPMAIL_TEST_DB_USER' => (string) getenv('TEMPMAIL_TEST_DB_USER'),
        'TEMPMAIL_TEST_DB_PASS' => (string) getenv('TEMPMAIL_TEST_DB_PASS'),
    ];

    if ($recipient !== null) {
        $environment['RECIPIENT'] = $recipient;
    }

    $environment = array_merge($environment, $env);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);

    if (!is_resource($process)) {
        return ['stdout' => '', 'exit' => -1];
    }

    fwrite($pipes[0], $eml);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'exit' => proc_close($process)];
}

try {
    $address = freshAddress();
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);

    $result = runIngest(rawEmail($address, 'Lewat entry point'), $address);

    check('exit code 0', $result['exit'] === 0, 'exit=' . $result['exit']);
    check('tidak ada output ke stdout', $result['stdout'] === '', var_export($result['stdout'], true));

    $messages = (new MessageRepository())->listForAddress($address);
    check('pesan tersimpan lewat entry point', count($messages) === 1);
    check('subjek benar', ($messages[0]['subject'] ?? '') === 'Lewat entry point', (string) ($messages[0]['subject'] ?? ''));
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

// Alamat asing: harus tetap senyap dan tidak menyimpan apa pun.
try {
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);
    $unknown = Address::generateLocalPart() . '@' . Address::domain();

    $result = runIngest(rawEmail($unknown), $unknown);

    check('alamat asing: exit code 0', $result['exit'] === 0, 'exit=' . $result['exit']);
    check('alamat asing: stdout kosong', $result['stdout'] === '');
    check('alamat asing: tidak ada file antrean', $queue->count() === 0, (string) $queue->count());
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

// Database mati: email harus selamat di antrean, bukan hilang.
try {
    $queue = IngestQueue::fromConfig();
    clearQueue($queue);
    $address = freshAddress();

    $result = runIngest(
        rawEmail($address, 'Saat database mati'),
        $address,
        ['TEMPMAIL_TEST_DB_PORT' => '3999']
    );

    check('database mati: exit code 0', $result['exit'] === 0, 'exit=' . $result['exit']);
    check('database mati: stdout kosong', $result['stdout'] === '');
    check('database mati: email masuk antrean', $queue->count() === 1, (string) $queue->count());

    // Database hidup lagi: cron memulihkan pesan dari antrean.
    $drained = $queue->drain(
        static fn (string $raw, string $to): string => (new Ingest())->process($raw, $to)
    );

    check('pemulihan menyimpan pesan', $drained['stored'] === 1, json_encode($drained));
    check('antrean kosong setelah pulih', $queue->count() === 0, (string) $queue->count());

    $messages = (new MessageRepository())->listForAddress($address);
    check('pesan hasil pemulihan ada', count($messages) === 1);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n[Antrean: config tanpa storage.queue]\n";

try {
    // Server yang baru di-upgrade belum punya key storage.queue. Dulu ini
    // menghasilkan mkdir('') dan antrean mati tanpa email tersimpan.
    $reflection = new ReflectionClass(Config::class);
    $values = $reflection->getProperty('values');
    $values->setAccessible(true);
    $current = $values->getValue();

    $savedQueue = $current['storage']['queue'] ?? null;
    unset($current['storage']['queue']);
    $values->setValue(null, $current);

    $queue = IngestQueue::fromConfig();
    $directory = (new ReflectionClass($queue))->getProperty('directory');
    $directory->setAccessible(true);
    $resolved = $directory->getValue($queue);

    check('direktori antrean tetap ditentukan', $resolved !== '', (string) $resolved);
    check(
        'direktori antrean sejajar dengan folder logs',
        dirname($resolved) === dirname(Config::string('storage.logs')),
        (string) $resolved
    );
    check('direktori antrean benar-benar dibuat', is_dir($resolved), (string) $resolved);

    // Kembalikan konfigurasi seperti semula.
    $current['storage']['queue'] = $savedQueue;
    $values->setValue(null, $current);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL error: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 46) . "\n";
echo "lulus: {$passed}   gagal: {$failed}\n\n";

exit($failed === 0 ? 0 : 1);
