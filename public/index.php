<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use TempMail\Config;
use TempMail\Inbox\Address;

$config = [
    'domain'        => Address::domain(),
    'pollInterval'  => Config::int('inbox.poll_interval', 5),
    'ttlHours'      => Config::int('inbox.message_ttl_hours', 24),
    'maxAddresses'  => Config::int('security.new_addresses_per_ip_per_day', 10),
];

$bootstrapJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?= htmlspecialchars(Config::string('app.name', 'TempMail'), ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>
    // Tema dipilih sebelum paint supaya tidak ada kedip.
    (function () {
        try {
            var saved = localStorage.getItem('tempmail.theme');
            if (saved === 'light' || saved === 'dark') {
                document.documentElement.dataset.theme = saved;
            } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.dataset.theme = 'dark';
            }
        } catch (e) {}
    })();
</script>
</head>
<body>
<div class="shell">

    <header class="site-head">
        <div class="wordmark">
            <?= htmlspecialchars(Config::string('app.name', 'TempMail'), ENT_QUOTES, 'UTF-8') ?>
            <span><?= htmlspecialchars(Address::domain(), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="head-side">
            <span class="ttl-note">pesan disimpan <?= (int) $config['ttlHours'] ?> jam</span>
            <button type="button" class="btn btn-quiet" id="theme-toggle" aria-pressed="false">Tema gelap</button>
        </div>
    </header>

    <main class="layout">

        <div>
            <section class="panel" aria-labelledby="addr-heading">
                <div class="panel-head">
                    <h2 class="panel-title" id="addr-heading">Alamat aktif</h2>
                    <span class="count-badge" id="remaining-note"></span>
                </div>
                <div class="panel-body">
                    <div class="address-value is-empty" id="address-value" aria-live="polite">Belum ada alamat</div>

                    <div class="address-actions">
                        <button type="button" class="btn btn-primary" id="btn-create">Buat alamat</button>
                        <button type="button" class="btn" id="btn-copy" disabled>Salin</button>
                    </div>

                    <div class="meta-row">
                        <span id="address-status">-</span>
                        <span id="ttl-countdown"></span>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="open-heading">
                <div class="panel-head">
                    <h2 class="panel-title" id="open-heading">Buka alamat lama</h2>
                </div>
                <div class="panel-body">
                    <form id="open-form" novalidate>
                        <label class="field-label" for="open-input">Alamat email</label>
                        <div class="input-group">
                            <input
                                type="email"
                                id="open-input"
                                name="address"
                                placeholder="namamu@<?= htmlspecialchars(Address::domain(), ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="off"
                                spellcheck="false"
                                inputmode="email"
                                required>
                            <button type="submit" class="btn">Buka</button>
                        </div>
                        <p class="field-hint" id="open-hint">Alamat lama tetap bisa dibuka kapan saja. Isinya mengikuti masa simpan pesan.</p>
                    </form>
                </div>
            </section>

            <section class="panel" aria-labelledby="notes-heading">
                <div class="panel-head">
                    <h2 class="panel-title" id="notes-heading">Cara pakai</h2>
                </div>
                <div class="panel-body">
                    <div class="notes">
                        <ul>
                            <li>Alamat ini <strong>tanpa kata sandi</strong>. Siapa pun yang tahu alamatnya bisa membacanya.</li>
                            <li>Jangan pakai untuk akun penting, perbankan, atau pemulihan akun utama.</li>
                            <li>Email masuk tertunda sampai proses pengambilan berikutnya berjalan, biasanya di bawah satu menit.</li>
                            <li>Aplikasi ini hanya menerima. Tidak ada fitur mengirim atau membalas.</li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>

        <section class="panel inbox-panel" aria-labelledby="inbox-heading">
            <div class="list-head">
                <div class="list-head-left">
                    <h2 class="panel-title" id="inbox-heading">Kotak masuk</h2>
                    <span class="count-badge" id="message-count"></span>
                </div>
                <div class="list-tools">
                    <span class="refresh-state" id="refresh-state" aria-live="polite">idle</span>
                    <button type="button" class="btn btn-quiet" id="btn-refresh">Muat ulang</button>
                    <button type="button" class="btn btn-quiet" id="btn-clear" disabled>Hapus semua</button>
                </div>
            </div>

            <div class="loading-bar" id="loading-bar" hidden></div>

            <div class="state" id="state-empty">
                <h3>Belum ada alamat</h3>
                <p>Buat alamat acak untuk mulai menerima email, atau buka alamat yang pernah kamu pakai.</p>
                <button type="button" class="btn btn-primary" id="btn-create-2">Buat alamat acak</button>
            </div>

            <div class="state" id="state-loading" hidden>
                <h3>Memuat pesan</h3>
                <p>Mengambil daftar pesan untuk alamat ini.</p>
            </div>

            <div class="state" id="state-inbox-empty" hidden>
                <h3>Kotak masuk kosong</h3>
                <p>Belum ada email untuk alamat ini. Biarkan halaman terbuka, daftar akan diperbarui sendiri.</p>
            </div>

            <div class="state state-error" id="state-error" hidden>
                <h3 id="error-title">Gagal memuat</h3>
                <p id="error-detail">Periksa koneksi lalu coba lagi.</p>
                <button type="button" class="btn" id="btn-retry">Coba lagi</button>
            </div>

            <ul class="message-list" id="message-list" hidden></ul>
        </section>

        <section class="panel reader" id="reader" aria-labelledby="reader-subject" hidden>
            <div class="reader-head">
                <button type="button" class="btn btn-quiet reader-back" id="btn-back">Kembali ke daftar</button>
                <h2 class="reader-subject" id="reader-subject">-</h2>
                <div class="reader-from" id="reader-meta"></div>
                <div class="reader-tools">
                    <a class="btn btn-icon" id="btn-raw" href="#" rel="noopener noreferrer">Unduh .eml</a>
                    <button type="button" class="btn btn-icon" id="btn-delete-one">Hapus pesan</button>
                </div>
            </div>
            <iframe
                class="mail-frame"
                id="mail-frame"
                title="Isi pesan"
                sandbox=""
                referrerpolicy="no-referrer"
                hidden></iframe>
            <div class="reader-plain" id="reader-plain" hidden></div>
            <div class="attachments" id="attachments" hidden>
                <h3 class="attachments-title">Lampiran</h3>
                <ul class="attachment-list" id="attachment-list"></ul>
            </div>
        </section>

    </main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script id="bootstrap" type="application/json"><?= $bootstrapJson ?></script>
<script src="assets/app.js" defer></script>
</body>
</html>
