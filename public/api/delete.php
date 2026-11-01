<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Inbox\Address;
use TempMail\Mail\AttachmentStore;
use TempMail\Repo\MessageRepository;

if (Request::method() !== 'POST' && Request::method() !== 'DELETE') {
    Response::error('Gunakan metode POST atau DELETE.', 405, 'method_not_allowed');
}

$address = Address::normalize(Request::input('address'));
$id = (int) Request::input('id', '0');

if ($address === null) {
    Response::error('Alamat tidak valid.', 422, 'invalid_address');
}

$repo = new MessageRepository();
$store = new AttachmentStore(Config::string('storage.attachments'));

if ($id > 0) {
    // Kumpulkan nama file sebelum baris dihapus; ON DELETE CASCADE akan
    // menghapus baris attachments sehingga namanya tidak bisa dibaca lagi.
    $files = $repo->attachmentFilesFor($id, $address);

    if (!$repo->delete($id, $address)) {
        Response::error('Pesan tidak ditemukan.', 404, 'not_found');
    }

    foreach ($files as $storedName) {
        $store->delete($storedName);
    }

    Response::ok(['deleted' => 1]);
}

$files = $repo->attachmentFilesForAddress($address);
$deleted = $repo->deleteAllForAddress($address);

foreach ($files as $storedName) {
    $store->delete($storedName);
}

Response::ok(['deleted' => $deleted]);
