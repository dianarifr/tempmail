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

$repo = new MessageRepository();
$message = $repo->find($id, $address);

if ($message === null) {
    Response::error('Pesan tidak ditemukan atau sudah kedaluwarsa.', 404, 'not_found');
}

$repo->markRead($id, $address);

$attachments = array_map(static function (array $row): array {
    return [
        'id'        => (int) $row['id'],
        'filename'  => (string) $row['filename'],
        'mime_type' => (string) $row['mime_type'],
        'size'      => (int) $row['size_bytes'],
    ];
}, $repo->attachmentsFor($id));

Response::ok([
    'message' => [
        'id'          => (int) $message['id'],
        'from_name'   => (string) ($message['from_name'] ?? ''),
        'from_addr'   => (string) ($message['from_addr'] ?? ''),
        'to_addr'     => (string) ($message['to_addr'] ?? ''),
        'subject'     => (string) ($message['subject'] ?? ''),
        'body_text'   => (string) ($message['body_text'] ?? ''),
        // Sanitasi lapis kedua: data lama di database mungkin tersimpan
        // sebelum aturan sanitizer diperketat.
        'body_html'   => Sanitizer::html((string) ($message['body_html'] ?? '')),
        'size_bytes'  => (int) $message['size_bytes'],
        'received_at' => (string) $message['received_at'],
    ],
    'attachments' => $attachments,
]);
