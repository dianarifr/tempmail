<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Inbox\Address;
use TempMail\Inbox\InboxService;
use TempMail\Inbox\LimitReached;
use TempMail\Repo\MessageRepository;

if (Request::method() !== 'GET') {
    Response::error('Gunakan metode GET.', 405, 'method_not_allowed');
}

$address = Address::normalize(Request::query('address'));

if ($address === null) {
    Response::error('Alamat tidak valid atau bukan milik domain ini.', 422, 'invalid_address');
}

try {
    $service = new InboxService();
    $address = $service->open($address, Request::ip());
} catch (LimitReached $e) {
    Response::error($e->getMessage(), 429, 'rate_limited');
}

if ($address === null) {
    Response::error('Alamat tidak valid.', 422, 'invalid_address');
}

$messages = (new MessageRepository())->listForAddress(
    $address,
    Config::int('inbox.max_messages_per_inbox', 100)
);

$items = array_map(static function (array $row): array {
    return [
        'id'               => (int) $row['id'],
        'from_name'        => (string) ($row['from_name'] ?? ''),
        'from_addr'        => (string) ($row['from_addr'] ?? ''),
        'subject'          => (string) ($row['subject'] ?? ''),
        'preview'          => (string) ($row['preview'] ?? ''),
        'is_read'          => (bool) $row['is_read'],
        'size_bytes'       => (int) $row['size_bytes'],
        'attachment_count' => (int) $row['attachment_count'],
        'received_at'      => (string) $row['received_at'],
    ];
}, $messages);

Response::ok([
    'address'    => $address,
    'messages'   => $items,
    'count'      => count($items),
    'ttl_hours'  => Config::int('inbox.message_ttl_hours', 24),
    'poll_after' => Config::int('inbox.poll_interval', 5),
    'server_time' => date('Y-m-d H:i:s'),
]);
