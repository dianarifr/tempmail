<?php

declare(strict_types=1);

/**
 * Cron utama: ambil email baru dari mailbox catch-all.
 *
 *   * * * * * /usr/local/bin/php /home/USER/tempemail/bin/poll.php >> /home/USER/tempemail/storage/logs/poll.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot();

use TempMail\Mail\Fetcher;
use TempMail\Mail\Logger;
use TempMail\Mail\Maintenance;

$lockFile = dirname(__DIR__) . '/storage/poll.lock';
$lock = fopen($lockFile, 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[skip] proses poll lain masih berjalan\n");
    exit(0);
}

$exitCode = 0;

try {
    $stats = (new Fetcher())->run();

    printf(
        "[%s] fetched=%d stored=%d skipped=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        $stats['fetched'],
        $stats['stored'],
        $stats['skipped'],
        $stats['failed']
    );

    $purged = (new Maintenance())->purge();

    if (array_sum($purged) > 0) {
        printf(
            "[%s] purge messages=%d attachments=%d rate_limits=%d addresses=%d\n",
            date('Y-m-d H:i:s'),
            $purged['messages'],
            $purged['attachments'],
            $purged['rate_limits'],
            $purged['addresses']
        );
    }
} catch (Throwable $e) {
    Logger::error('poll gagal: ' . $e->getMessage());
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode);
