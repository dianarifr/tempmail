<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Inbox\Address;
use TempMail\Inbox\InboxService;
use TempMail\Inbox\LimitReached;

if (Request::method() !== 'POST') {
    Response::error('Gunakan metode POST.', 405, 'method_not_allowed');
}

$service = new InboxService();

try {
    $result = $service->create(Request::ip());
} catch (LimitReached $e) {
    Response::error($e->getMessage(), 429, 'rate_limited');
} catch (RuntimeException $e) {
    Response::error('Gagal membuat alamat baru.', 500, 'create_failed');
}

Response::ok([
    'address'   => $result['address'],
    'remaining' => $result['remaining'],
    'domain'    => Address::domain(),
    'ttl_hours' => Config::int('inbox.message_ttl_hours', 24),
]);
