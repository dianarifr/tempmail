<?php

declare(strict_types=1);

/**
 * Konfigurasi khusus test. Tidak pernah menyentuh database atau mailbox
 * produksi: domain, kredensial DB, dan IMAP semuanya nilai uji.
 *
 * Bagian yang butuh database hanya dijalankan bila TEMPMAIL_TEST_DB
 * di-set, supaya `php tests/run.php` aman dijalankan di server produksi.
 */
return [
    'app' => [
        'name'     => 'TempMailTest',
        'domain'   => 'tempmail.test',
        'url'      => 'http://localhost',
        'timezone' => 'Asia/Jakarta',
        'debug'    => true,
    ],

    'db' => [
        'host'    => getenv('TEMPMAIL_TEST_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('TEMPMAIL_TEST_DB_PORT') ?: 3306),
        'name'    => getenv('TEMPMAIL_TEST_DB') ?: 'tempmail_test',
        'user'    => getenv('TEMPMAIL_TEST_DB_USER') ?: 'root',
        'pass'    => getenv('TEMPMAIL_TEST_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'imap' => [
        'host'       => '127.0.0.1',
        'port'       => 993,
        'encryption' => 'none',
        'user'       => 'tempemail@tempmail.test',
        'pass'       => 'test-only',
        'mailbox'    => 'INBOX',
        'timeout'    => 5,
        'delete_after_fetch' => true,
        'delete_unmatched'   => false,
        'batch_size' => 25,
    ],

    'inbox' => [
        'local_part_length'      => 10,
        'message_ttl_hours'      => 24,
        'max_messages_per_inbox' => 100,
        'max_message_bytes'      => 8 * 1024 * 1024,
        'address_idle_days'      => 0,
        'poll_interval'          => 5,
    ],

    'security' => [
        'new_addresses_per_ip_per_day' => 10,
        'inbox_reads_per_ip_per_hour'  => 2400,
        'max_attachment_bytes'         => 10 * 1024 * 1024,
        'block_remote_images'          => true,
        'cron_key'                     => 'test-only-key',
        'trusted_proxies'              => [],
    ],

    'storage' => [
        'attachments' => sys_get_temp_dir() . '/tempmail-test/attachments',
        'logs'        => sys_get_temp_dir() . '/tempmail-test/logs',
        'queue'       => sys_get_temp_dir() . '/tempmail-test/queue',
    ],
];
