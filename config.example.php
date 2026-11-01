<?php

declare(strict_types=1);

/**
 * Salin file ini menjadi config.php lalu sesuaikan nilainya.
 * config.php berisi kredensial, jangan pernah di-commit atau di-upload ke repo publik.
 */
return [
    'app' => [
        'name'     => 'TempMail',
        'domain'   => 'domainmu.com',
        'url'      => 'https://tempmail.domainmu.com',
        'timezone' => 'Asia/Jakarta',
        'debug'    => false,
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'tempmail',
        'user'    => 'tempmail',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'imap' => [
        'host'       => 'mail.domainmu.com',
        'port'       => 993,
        'encryption' => 'ssl', // ssl | tls (STARTTLS) | none
        'user'       => 'catchall@domainmu.com',
        'pass'       => '',
        'mailbox'    => 'INBOX',
        'timeout'    => 30,
        // Pesan dihapus dari server hanya setelah berhasil disimpan, supaya
        // kuota mailbox tidak penuh tapi pesan yang gagal dibaca tidak hilang.
        'delete_after_fetch' => true,
        // Hapus juga pesan yang tidak cocok dengan alamat terdaftar.
        // Biarkan false saat deploy pertama supaya pesan yang gagal dikenali
        // bisa diperiksa di webmail. Nyalakan setelah pipeline terbukti stabil.
        'delete_unmatched' => false,
        // Jumlah maksimal pesan yang diproses per satu kali cron berjalan.
        'batch_size' => 25,
    ],

    'inbox' => [
        'local_part_length'      => 10,
        'message_ttl_hours'      => 24,
        'max_messages_per_inbox' => 100,
        'max_message_bytes'      => 8 * 1024 * 1024,
        // 0 = alamat tidak pernah dihapus, hanya pesannya yang kedaluwarsa.
        'address_idle_days'      => 0,
        // Interval polling frontend dalam detik.
        'poll_interval'          => 5,
    ],

    'security' => [
        'new_addresses_per_ip_per_day' => 10,
        // Frontend melakukan polling berkala, jadi batas baca harus jauh
        // lebih longgar daripada batas pembuatan alamat.
        'inbox_reads_per_ip_per_hour'  => 2400,
        'max_attachment_bytes'         => 10 * 1024 * 1024,
        'block_remote_images'          => true,
        // Ganti dengan string acak. Wajib diganti sebelum cron lewat URL dipakai.
        'cron_key'                     => 'ganti-dengan-string-acak-64-karakter',
        // Isi hanya jika aplikasi di belakang reverse proxy milikmu sendiri.
        'trusted_proxies'              => [],
    ],

    'storage' => [
        'attachments' => dirname(__FILE__) . '/storage/attachments',
        'logs'        => dirname(__FILE__) . '/storage/logs',
        // Antrean email dari jalur pipe yang belum berhasil disimpan.
        'queue'       => dirname(__FILE__) . '/storage/queue',
    ],
];
