<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Inbox\Address;
use TempMail\Mail\AttachmentStore;
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
$attachment = $repo->findAttachment($id, $address);

if ($attachment === null) {
    Response::error('Lampiran tidak ditemukan.', 404, 'not_found');
}

$storedName = (string) $attachment['stored_name'];
$filename = AttachmentStore::safeFilename((string) $attachment['filename']);

$binary = (new AttachmentStore(Config::string('storage.attachments')))->read($storedName);

if ($binary === null) {
    Response::error('File lampiran sudah tidak tersedia.', 410, 'gone');
}

$asciiFallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'attachment';

header('Content-Type: ' . AttachmentStore::downloadMime((string) $attachment['mime_type']));
header('Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($binary));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, no-store, max-age=0');

echo $binary;
exit;
