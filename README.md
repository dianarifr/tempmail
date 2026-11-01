# TempMail

Temp email untuk domain sendiri: alamat acak sekali klik, tanpa login, jalan di shared hosting cPanel tanpa akses root dan tanpa port 25.

- PHP 8.1+ (diuji di 8.4), MySQL 5.7+ / MariaDB 10.3+
- PDO dengan prepared statement di seluruh query
- Tanpa Composer, tanpa dependency eksternal
- IMAP client dan MIME parser ditulis sendiri (ext-imap sudah dihapus di PHP 8.4)

## Cara kerja

Ada dua jalur masuk. Keduanya menulis ke tabel yang sama, jadi UI dan API
tidak perlu tahu email datang dari mana.

**Jalur IMAP (cadangan)**

```
Internet → MX domain → Exim cPanel → catch-all mailbox
                                          ↓ cron 1 menit
                                   bin/poll.php (IMAP)
```

**Jalur pipe (utama, lebih cepat)**

```
Internet → MX domain → Exim cPanel → bin/ingest.php (stdin)
                                          ↓
                                   MySQL (inboxes, messages)
                                          ↓
                                   Web UI + JSON API
```

Jalur pipe memotong mailbox dan cron: Exim menjalankan skrip langsung saat
email tiba, dan alamat tujuan tersedia di environment variable `RECIPIENT`
sehingga tidak perlu ditebak dari header. Kalau penyimpanan gagal, email
disimpan dulu di `storage/queue/` dan cron berikutnya mencoba lagi.

Keduanya tidak perlu port 25 masuk.

## Instalasi

### 1. Database

Buat database MySQL lewat cPanel, lalu impor skema:

```
mysql -u USER -p NAMADB < sql/schema.sql
```

Atau lewat phpMyAdmin: tab **Import**, pilih `sql/schema.sql`.

### 2. Konfigurasi

```
cp config.example.php config.php
```

Isi bagian berikut:

| Kunci | Isi |
|---|---|
| `app.domain` | domain yang dipakai, misal `domainmu.com` |
| `app.url` | URL aplikasi |
| `db.*` | kredensial database dari cPanel |
| `imap.*` | kredensial mailbox catch-all |
| `security.cron_key` | string acak, wajib diganti |

Generate cron key:

```
php -r "echo bin2hex(random_bytes(32));"
```

### 3. Penerimaan email di cPanel

Ada dua pilihan. **Pipe to a Program** lebih cepat dan tidak memakai kuota
mailbox; **forward ke mailbox** dipakai sebagai cadangan.

#### 3a. Pipe to a Program (disarankan)

1. **Default Address** → pilih domain → **Advanced Options**
2. Pilih **Pipe to a Program**
3. Isi path relatif terhadap home directory:

```
tempmail.domainmu.com/bin/ingest.php
```

4. Pastikan skrip executable: `chmod 700 bin/ingest.php`
5. Shebang di baris pertama `bin/ingest.php` harus cocok dengan PHP host:

```
#!/usr/local/bin/php -q
```

Cek dengan `php -v` di terminal cPanel. Kalau path-nya lain (misalnya
`/opt/cpanel/ea-php84/root/usr/bin/php`), sesuaikan shebang-nya.

Yang perlu diperhatikan:

- Skrip **tidak boleh** menulis apa pun ke stdout. Output sekecil apa pun
  membuat Exim mengirim bounce ke pengirim.
- Skrip selalu keluar dengan kode `0`. Exit non-zero juga memicu bounce.
- Alamat tujuan dibaca dari environment variable `RECIPIENT`, yang diisi Exim
  dengan alamat asli (termasuk untuk catch-all). Tidak ada penebakan header.

#### 3b. Forward ke mailbox (cadangan)

1. **Email Accounts** → buat `catchall@domainmu.com`
2. **Default Address** → **Forward to Email Address** → `catchall@domainmu.com`
3. Isi bagian `imap` di `config.php`

Jalur ini memakai IMAP. Saat catch-all meneruskan pesan ke satu mailbox, Exim
menulis alamat mailbox di `Delivered-To:` dan alamat asli di
`Envelope-to:`/`To:`. Aplikasi membaca `Envelope-to` lebih dulu, lalu membuang
alamat mailbox catch-all dari daftar kandidat. Pesan yang tidak cocok dengan
alamat terdaftar tidak dihapus dari server, dan alasannya dicatat di
`storage/logs/app-YYYY-MM-DD.log`.

### 4. Deploy

Upload seluruh folder. Document root subdomain harus menunjuk ke `public/`, bukan ke folder root aplikasi.

Kalau document root tidak bisa diubah, tambahkan `.htaccess` di root:

```apache
RewriteEngine On
RewriteRule ^(?!public/)(.*)$ public/$1 [L]
```

Folder yang harus writable (755): `storage/attachments`, `storage/logs`, `storage/queue`.

`bin/ingest.php` harus executable (700) agar bisa dijalankan Exim.

### 5. Cron

```
* * * * * /usr/local/bin/php /home/freepale/tempmail.freepalestine.my.id/bin/poll.php >> /home/freepale/tempmail.freepalestine.my.id/storage/logs/poll.log 2>&1
```

Cek path PHP di cPanel → **Cron Jobs** (biasanya `/usr/local/bin/php`).

Cron tetap dipakai meski memakai pipe: tugasnya memproses ulang isi
`storage/queue/` yang gagal disimpan, lalu (kalau jalur IMAP aktif) menarik
email baru. Kalau cron CLI diblokir, pakai HTTP:

```
* * * * * curl -s "https://tempmail.domainmu.com/cron.php?key=CRON_KEY" > /dev/null
```

### 6. Verifikasi

Kirim email ke alamat yang dibuat lewat UI, lalu buka `https://tempmail.domainmu.com`.

Pada jalur pipe, email muncul dalam hitungan detik. Pada jalur IMAP, tunggu
satu menit (interval cron).

Semua skrip uji memakai `tests/config.test.php`, bukan `config.php`, jadi tidak
pernah menyentuh database atau mailbox produksi:

```
php tests/run.php         # parser, sanitizer, validasi alamat, deteksi penerima
php tests/imap-e2e.php    # protokol IMAP terhadap server tiruan
php tests/ingest-e2e.php  # jalur pipe, antrean, dan bin/ingest.php
```

Bagian database hanya berjalan bila `TEMPMAIL_TEST_DB` di-set, dan kredensial
bisa diarahkan lewat env:

```
TEMPMAIL_TEST_DB=tempmail_test \
TEMPMAIL_TEST_DB_HOST=127.0.0.1 TEMPMAIL_TEST_DB_PORT=3306 \
TEMPMAIL_TEST_DB_USER=root TEMPMAIL_TEST_DB_PASS= \
php tests/run.php
```

## Keamanan

Yang sudah diterapkan:

- **Prepared statement** di semua query, `ATTR_EMULATE_PREPARES = false`.
- **Sanitasi HTML** dua lapis: saat pesan disimpan, dan saat dikirim ke klien. Whitelist tag/atribut, `javascript:` dan `url()` diblokir.
- **iframe sandbox** tanpa `allow-scripts` untuk merender body email, jadi skrip di email tidak pernah jalan.
- **Lampiran** disimpan di luar document root dengan nama acak 32 hex; disajikan lewat PHP sebagai `attachment` dengan `nosniff`. Tipe berbahaya (`text/html`, `svg+xml`, dll) diturunkan jadi `application/octet-stream`.
- **Domain dikunci**: alamat selain `@domainmu.com` ditolak, jadi aplikasi tidak bisa dipakai membaca domain lain.
- **Rate limit** pembuatan alamat: 10 alamat baru per IP per hari. Alamat lama yang dibuka ulang tidak dihitung.
- **Inbound-only**: tidak ada kode pengiriman email sama sekali.
- **Jalur pipe tanpa output**: `bin/ingest.php` membuang seluruh output dan selalu keluar dengan kode `0`, sehingga email bermasalah tidak menghasilkan bounce ke pengirim (yang sering alamat palsu).
- **Alamat dari Exim, bukan header**: jalur pipe memakai `RECIPIENT` dari environment, sehingga header `To:`/`Cc:` yang dipalsukan pengirim tidak bisa mengarahkan pesan ke inbox orang lain.
- **Nama file antrean acak**: entri antrean divalidasi dengan pola ketat, jadi tidak ada input dari luar yang bisa menunjuk path lain.
- Header keamanan: `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `noindex`.

Yang perlu kamu tahu:

- **Alamat email adalah kuncinya.** Siapa pun yang tahu alamatnya bisa membaca inbox itu. Ini sifat dasar temp mail tanpa login.
- Jangan pakai untuk akun penting atau pemulihan akun utama.
- Catch-all menerima semua spam ke alamat acak. Pada jalur IMAP, pesan yang tidak cocok dengan alamat terdaftar tidak disimpan tapi tetap di server supaya bisa diperiksa lewat webmail. Pada jalur pipe, pesan seperti itu dibuang sebelum menyentuh disk. Pesan lebih tua dari 24 jam dihapus cron.
- **Kuota mail**: kalau memakai jalur pipe, mailbox tidak dipakai sama sekali. Kalau memakai jalur IMAP, naikkan kuota mailbox catch-all (250 MB) supaya email baru tidak ditolak saat kuota penuh.

## Tuning

Semua di `config.php`:

| Kunci | Default | Arti |
|---|---|---|
| `inbox.message_ttl_hours` | 24 | umur simpan pesan |
| `inbox.max_messages_per_inbox` | 100 | pesan terbaru yang disimpan per alamat |
| `inbox.poll_interval` | 5 | interval polling frontend (detik) |
| `imap.delete_after_fetch` | true | hapus dari server hanya setelah pesan berhasil disimpan |
| `imap.delete_unmatched` | false | hapus juga pesan yang tidak cocok dengan alamat terdaftar |
| `imap.batch_size` | 25 | pesan maksimal per satu kali cron |
| `security.new_addresses_per_ip_per_day` | 10 | batas pembuatan alamat |
| `security.block_remote_images` | true | blokir gambar eksternal (anti tracking pixel) |
| `inbox.max_message_bytes` | 8388608 | email lebih besar dari ini ditolak jalur pipe |

## Struktur

```
app/
  App.php              bootstrap, autoloader, error handler
  Config.php           konfigurasi dot-notation
  Database.php         koneksi PDO + helper transaksi
  Http/                Request, Response
  Inbox/               Address (validasi), InboxService, LimitReached
  Mail/                ImapClient, MimeParser, Sanitizer, Fetcher, Ingest,
                       IngestQueue, MessageWriter, AttachmentStore,
                       Maintenance, Logger
  Repo/                InboxRepository, MessageRepository
  Security/            RateLimiter
bin/
  poll.php             cron: proses antrean lalu tarik email IMAP
  ingest.php           entry point "Pipe to a Program" (dijalankan Exim)
  purge.php            cron pembersihan saja
public/
  index.php            UI
  cron.php             cron lewat HTTP
  api/                 create, inbox, message, delete, attachment, raw
sql/schema.sql
tests/
  run.php              unit test tanpa PHPUnit
  imap-e2e.php         uji Fetcher dengan server IMAP tiruan
  ingest-e2e.php       uji jalur pipe, antrean, dan bin/ingest.php
  fake-imap-server.php server tiruan
  config.test.php      konfigurasi khusus test
```
