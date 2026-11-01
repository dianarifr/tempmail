<?php

declare(strict_types=1);

/**
 * Entry point cron lewat HTTP, untuk hosting yang tidak mengizinkan
 * cron menjalankan PHP CLI. Dilindungi cron key di config.
 *
 * URL: https://tempmail.domainmu.com/cron.php?key=CRON_KEY
 */

require __DIR__ . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Http\Request;
use TempMail\Http\Response;
use TempMail\Mail\Fetcher;
use TempMail\Mail\Logger;
use TempMail\Mail\Maintenance;

$expected = Config::string('security.cron_key');
$provided = Request::query('key');

if ($expected === '' || $expected === 'ganti-dengan-string-acak-64-karakter' || !hash_equals($expected, $provided)) {
    Response::error('Forbidden.', 403, 'forbidden');
}

$result = ['fetch' => null, 'purge' => null];

try {
    $result['fetch'] = (new Fetcher())->run();
} catch (Throwable $e) {
    // Detail error hanya masuk log; respons HTTP tidak boleh membocorkan
    // host atau kredensial IMAP.
    Logger::error('cron fetch gagal: ' . $e->getMessage());
    $result['fetch'] = ['error' => 'fetch_failed'];
}

try {
    $result['purge'] = (new Maintenance())->purge();
} catch (Throwable $e) {
    Logger::error('cron purge gagal: ' . $e->getMessage());
    $result['purge'] = ['error' => 'purge_failed'];
}

Response::json(['ok' => true, 'result' => $result]);
