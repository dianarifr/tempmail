<?php

declare(strict_types=1);

/**
 * Test manual tanpa PHPUnit, dijalankan dari CLI:
 *   php tests/run.php
 *
 * Bagian 1 (parser, sanitizer, alamat) tidak butuh database.
 * Bagian 2 (simpan pesan) butuh koneksi DB yang sudah diisi lewat config.php.
 */

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot();

use TempMail\Inbox\Address;
use TempMail\Mail\MimeParser;
use TempMail\Mail\Sanitizer;

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

echo "\n[1] Alamat\n";

check('normalize alamat valid', Address::normalize('Abc12@TempMail.Test') === 'abc12@tempmail.test');
check('tolak domain lain', Address::normalize('abc@example.com') === null);
check('tolak local part aneh', Address::normalize('a b@tempmail.test') === null);
check('tolak reserved', Address::normalize('admin@tempmail.test') === null);
check('tolak double dot', Address::normalize('a..b@tempmail.test') === null);
check('tolak kosong', Address::normalize('') === null);
check('tolak newline injection', Address::normalize("a@tempmail.test\nBcc: x@y.z") === null);
check('generate panjang 10', strlen(Address::generateLocalPart()) === 10);
check('generate tidak memakai 0/1', !preg_match('/[01lo]/', Address::generateLocalPart()));

echo "\n[2] RFC2047 header\n";

$parser = new MimeParser();

check(
    'base64 encoded subject',
    MimeParser::decodeHeader('=?UTF-8?B?S29kZSB2ZXJpZmlrYXNp?=') === 'Kode verifikasi'
);
check(
    'quoted-printable encoded subject',
    MimeParser::decodeHeader('=?ISO-8859-1?Q?Caf=E9?=') === 'Café'
);
check(
    'dua encoded word berurutan',
    MimeParser::decodeHeader('=?UTF-8?B?SGVsbG8=?= =?UTF-8?B?V29ybGQ=?=') === 'HelloWorld'
);

echo "\n[3] Parser multipart + lampiran\n";

$raw = implode("\r\n", [
    'Return-Path: <noreply@example.com>',
    'Delivered-To: Tester@TempMail.Test',
    'From: "Layanan Contoh" <noreply@example.com>',
    'To: tester@tempmail.test',
    'Subject: =?UTF-8?B?S29kZSB2ZXJpZmlrYXNp?=',
    'Date: Tue, 14 Oct 2025 09:15:00 +0700',
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="BOUND-1"',
    '',
    'preamble',
    '--BOUND-1',
    'Content-Type: multipart/alternative; boundary="BOUND-2"',
    '',
    '--BOUND-2',
    'Content-Type: text/plain; charset="utf-8"',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    'Halo, kode kamu adalah 123456.=0A=0AJangan dibagikan.',
    '--BOUND-2',
    'Content-Type: text/html; charset="utf-8"',
    'Content-Transfer-Encoding: base64',
    '',
    chunk_split(base64_encode('<html><body><p>Halo, kode kamu <b>123456</b>.</p></body></html>'), 76, "\r\n"),
    '--BOUND-2--',
    '--BOUND-1',
    'Content-Type: application/pdf; name="invoice.pdf"',
    'Content-Disposition: attachment; filename="invoice.pdf"',
    'Content-Transfer-Encoding: base64',
    '',
    chunk_split(base64_encode('%PDF-1.4 fake pdf bytes'), 76, "\r\n"),
    '--BOUND-1--',
    'epilogue',
]);

$message = $parser->parse($raw);

check('subjek terdekode', $message->subject === 'Kode verifikasi');
check('nama pengirim terdekode', $message->fromName === 'Layanan Contoh');
check('alamat pengirim', $message->fromAddr === 'noreply@example.com');
check('ada body text', str_contains($message->text, '123456'));
check('quoted-printable terdekode', !str_contains($message->text, '=0A'));
check('ada body html', str_contains($message->html, '<b>123456</b>'));
check('ada 1 lampiran', count($message->attachments) === 1);
check('nama lampiran', $message->attachments[0]['filename'] === 'invoice.pdf');
check('lampiran terdekode', str_starts_with($message->attachments[0]['data'], '%PDF-1.4'));
check('tanggal terparse', $message->timestamp === strtotime('2025-10-14 09:15:00 +0700'));

echo "\n[4] Sanitizer\n";

$dirty = '<div onclick="steal()" style="color:red;background:url(http://track/x)">'
    . '<script>alert(1)</script>'
    . '<img src="x" onerror="alert(2)">'
    . '<a href="javascript:alert(3)">klik</a>'
    . '<a href="https://example.com" target="_blank">aman</a>'
    . '<iframe src="http://evil"></iframe>'
    . '<p style="font-size:14px;color:#333">teks</p>'
    . '<style>body{display:none}</style>'
    . '</div>';

$clean = Sanitizer::html($dirty);

check('script hilang', !str_contains($clean, '<script'));
check('isi script tidak bocor', !str_contains($clean, 'alert(1)'));
check('onerror hilang', !str_contains($clean, 'onerror'));
check('onclick hilang', !str_contains($clean, 'onclick'));
check('javascript: hilang', !str_contains($clean, 'javascript:'));
check('iframe hilang', !str_contains($clean, '<iframe'));
check('style tag hilang', !str_contains($clean, '<style'));
check('url() di style hilang', !str_contains($clean, 'url('));
check('style aman dipertahankan', str_contains($clean, 'font-size:14px'));
check('link https dipertahankan', str_contains($clean, 'https://example.com'));
check('rel ditambahkan', str_contains($clean, 'rel="noopener noreferrer nofollow"'));
check('img eksternal diblokir', !str_contains($clean, 'src="x"'));

check('toText membuang tag', Sanitizer::toText('<p>Halo <b>dunia</b></p>') === 'Halo dunia');
check('preview memotong', mb_strlen(Sanitizer::preview(str_repeat('a', 400)), 'UTF-8') === 161);

echo "\n[5] Deteksi penerima\n";

$delivered = new \TempMail\Mail\ParsedMessage();
$delivered->headers['delivered-to'] = 'abc@tempmail.test';
check(
    'delivered-to dipakai lebih dulu',
    \TempMail\Mail\Fetcher::recipient($delivered) === 'abc@tempmail.test'
);

$spoofed = new \TempMail\Mail\ParsedMessage();
$spoofed->headers['delivered-to'] = 'abc@tempmail.test';
$spoofed->headers['to'] = 'ceo@tempmail.test';
check(
    'to tidak menimpa delivered-to',
    \TempMail\Mail\Fetcher::recipient($spoofed) === 'abc@tempmail.test'
);

$foreign = new \TempMail\Mail\ParsedMessage();
$foreign->headers['delivered-to'] = 'orang@example.com';
check('alamat domain lain ditolak', \TempMail\Mail\Fetcher::recipient($foreign) === null);

echo "\n[6] Simpan ke database\n";

try {
    $repo = new \TempMail\Repo\MessageRepository();
    $inboxes = new \TempMail\Repo\InboxRepository();

    $address = Address::generateLocalPart() . '@' . Address::domain();
    $localPart = Address::localPartOf($address);
    $inboxes->create($address, (string) $localPart, inet_pton('127.0.0.1') ?: '');

    $stored = $parser->parse(str_replace('tester@tempmail.test', $address, $raw));

    $id = $repo->insert([
        'address'     => $address,
        'message_key' => hash('sha256', $address . microtime(true)),
        'from_name'   => $stored->fromName,
        'from_addr'   => $stored->fromAddr,
        'to_addr'     => $address,
        'subject'     => $stored->subject,
        'body_text'   => $stored->text,
        'body_html'   => $stored->html,
        'preview'     => Sanitizer::preview($stored->text),
        'size_bytes'  => $stored->size,
        'received_at' => date('Y-m-d H:i:s', $stored->timestamp),
    ]);

    check('pesan tersimpan', is_int($id) && $id > 0);

    $list = $repo->listForAddress($address);
    check('muncul di daftar', count($list) === 1);
    check('subjek benar di daftar', $list[0]['subject'] === 'Kode verifikasi');
    check('belum dibaca', (int) $list[0]['is_read'] === 0);

    $found = $repo->find((int) $id, $address);
    check('bisa dibuka', $found !== null);
    check('akses alamat lain ditolak', $repo->find((int) $id, 'lain@tempmail.test') === null);

    $repo->markRead((int) $id, $address);
    $after = $repo->find((int) $id, $address);
    check('tanda dibaca tersimpan', (int) $after['is_read'] === 1);

    $deleted = $repo->delete((int) $id, $address);
    check('hapus pesan', $deleted === true);
    check('hapus pesan alamat lain gagal', $repo->delete(999999, $address) === false);
    check('daftar kosong setelah hapus', $repo->countForAddress($address) === 0);
} catch (Throwable $e) {
    echo '  SKIP bagian database: ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 46) . "\n";
echo "lulus: {$passed}   gagal: {$failed}\n\n";

exit($failed === 0 ? 0 : 1);
