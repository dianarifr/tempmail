<?php

declare(strict_types=1);

/**
 * Cron pembersihan saja (tanpa IMAP), berguna bila polling dan purge
 * ingin dijadwalkan terpisah.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot();

use TempMail\Mail\Maintenance;

$result = (new Maintenance())->purge();

printf(
    "[%s] purge messages=%d attachments=%d rate_limits=%d addresses=%d\n",
    date('Y-m-d H:i:s'),
    $result['messages'],
    $result['attachments'],
    $result['rate_limits'],
    $result['addresses']
);
