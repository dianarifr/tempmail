<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Inbox\Address;
use TempMail\Mail\Sanitizer;
use TempMail\Repo\MessageRepository;

if (Request::method() !== 'GET') {
    Response::error('Gunakan metode GET.', 405, 'method_not_allowed');
}

$address = Address::normalize(Request::query('address'));
$id = (int) Request::query('id', '0');

if ($address === null || $id <= 0) {
    Response::error('Parameter tidak valid.', 422, 'invalid_request');
}

$message = (new MessageRepository())->find($id, $address);

if ($message === null) {
    Response::error('Pesan tidak ditemukan.', 404, 'not_found');
}

/*
 * File .eml direkonstruksi dari data yang tersimpan (HTML sudah disanitasi),
 * supaya isi unduhan konsisten dengan yang ditampilkan di UI.
 */
$headers = [
    'From: ' . buildHeaderValue($message['from_name'] ?? '', $message['from_addr'] ?? ''),
    'To: ' . buildHeaderValue('', $message['to_addr'] ?? ''),
    'Subject: =?UTF-8?B?' . base64_encode((string) ($message['subject'] ?? '')) . '?=',
    'Date: ' . date('r', (int) strtotime((string) $message['received_at'])),
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
];

$html = (string) ($message['body_html'] ?? '');
if ($html === '') {
    $html = nl2br(htmlspecialchars((string) ($message['body_text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

$body = chunk_split(base64_encode(Sanitizer::html($html)));
$eml = implode("\r\n", $headers) . "\r\n\r\n" . $body;

$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($message['subject'] ?? 'message')) ?? 'message';
$filename = trim(mb_substr($filename !== '' ? $filename : 'message', 0, 80), '_') . '.eml';

header('Content-Type: message/rfc822');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($eml));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

echo $eml;
exit;

function buildHeaderValue(string $name, string $address): string
{
    $address = str_replace(["\r", "\n"], '', trim($address));

    if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
        return 'unknown@localhost';
    }

    $name = str_replace(["\r", "\n"], '', trim($name));

    return $name !== '' ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $address) : $address;
}
