<?php

declare(strict_types=1);

namespace TempMail\Mail;

use RuntimeException;
use TempMail\Config;

/**
 * Antrean email mentah di disk.
 *
 * Jalur pipe menyerahkan email sekali saja: kalau penyimpanan ke database
 * gagal, Exim tidak akan mencoba lagi dan emailnya hilang. Karena itu isi
 * email ditulis dulu ke disk, baru diproses. File yang tersisa berarti ada
 * pesan yang belum tersimpan, dan cron berikutnya akan mencobanya lagi.
 *
 * Setiap entri terdiri dari dua file:
 *   <32hex>.eml   isi email mentah
 *   <32hex>.json  alamat tujuan, supaya percobaan ulang tidak perlu
 *                 menebak dari header
 *
 * Nama file selalu hasil random_bytes, jadi tidak ada bagian dari input
 * email yang menjadi nama path.
 */
final class IngestQueue
{
    /** Batas umur entri; lebih tua dari ini dibuang tanpa diproses. */
    private const MAX_AGE_SECONDS = 604800; // 7 hari

    private const MAX_FILES_PER_RUN = 50;

    public function __construct(private readonly string $directory)
    {
        // Direktori kosong berarti config.php belum punya key storage.queue
        // (misalnya setelah upgrade). Tanpa pemeriksaan ini, mkdir('') gagal
        // dengan "Invalid path" dan antrean tidak pernah aktif.
        if (trim($this->directory) === '') {
            throw new RuntimeException('Konfigurasi storage.queue belum diisi.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Tidak bisa membuat direktori antrean: ' . $this->directory);
        }
    }

    public static function fromConfig(): self
    {
        return new self(self::resolveDirectory());
    }

    /**
     * Menentukan direktori antrean.
     *
     * Nilai storage.queue dipakai bila ada. Kalau tidak, dipakai
     * storage/logs/../queue sehingga lokasinya tetap di dalam folder
     * storage milik aplikasi, bukan di tempat lain.
     */
    private static function resolveDirectory(): string
    {
        $configured = Config::string('storage.queue');

        if (trim($configured) !== '') {
            return $configured;
        }

        // Sejajarkan dengan folder logs: <storage>/logs → <storage>/queue.
        $logs = Config::string('storage.logs');

        if (trim($logs) !== '') {
            return dirname(rtrim($logs, '/')) . '/queue';
        }

        return dirname(__DIR__, 2) . '/storage/queue';
    }

    /**
     * Menulis email mentah beserta alamat tujuannya ke antrean.
     *
     * @return string nama entri (tanpa ekstensi)
     */
    public function push(string $raw, string $recipient): string
    {
        $name = bin2hex(random_bytes(16));

        $meta = json_encode(['recipient' => $recipient], JSON_UNESCAPED_SLASHES);

        if ($meta === false) {
            throw new RuntimeException('Gagal menyusun metadata antrean.');
        }

        // Metadata ditulis lebih dulu: entri tanpa .eml dianggap sampah dan
        // dibuang oleh drain(), jadi urutan ini tidak bisa meninggalkan
        // email yang tampak valid tapi tanpa alamat.
        if (file_put_contents($this->path($name, 'json'), $meta, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis metadata antrean.');
        }

        if (file_put_contents($this->path($name, 'eml'), $raw, LOCK_EX) === false) {
            @unlink($this->path($name, 'json'));

            throw new RuntimeException('Gagal menulis email ke antrean.');
        }

        @chmod($this->path($name, 'eml'), 0640);
        @chmod($this->path($name, 'json'), 0640);

        return $name;
    }

    public function remove(string $name): void
    {
        @unlink($this->path($name, 'eml'));
        @unlink($this->path($name, 'json'));
    }

    /**
     * Memproses isi antrean satu per satu.
     *
     * @param callable(string, string): string $handler menerima email mentah
     *        dan alamat tujuan, mengembalikan 'stored', 'duplicate', atau 'failed'
     * @return array{processed: int, stored: int, duplicate: int, failed: int, expired: int}
     */
    public function drain(callable $handler): array
    {
        $stats = ['processed' => 0, 'stored' => 0, 'duplicate' => 0, 'failed' => 0, 'expired' => 0];

        foreach ($this->entries() as $name) {
            if ($stats['processed'] >= self::MAX_FILES_PER_RUN) {
                break;
            }

            $emlPath = $this->path($name, 'eml');
            $metaPath = $this->path($name, 'json');

            $age = time() - (int) @filemtime($emlPath);

            if ($age > self::MAX_AGE_SECONDS) {
                $this->remove($name);
                $stats['expired']++;
                continue;
            }

            $raw = @file_get_contents($emlPath);
            $meta = @file_get_contents($metaPath);

            if (!is_string($raw) || $raw === '' || !is_string($meta)) {
                $this->remove($name);
                $stats['expired']++;
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($meta, true);
            $recipient = is_array($decoded) ? ($decoded['recipient'] ?? '') : '';
            $address = is_string($recipient) ? \TempMail\Inbox\Address::normalize($recipient) : null;

            if ($address === null) {
                $this->remove($name);
                $stats['expired']++;
                continue;
            }

            $stats['processed']++;

            try {
                $outcome = $handler($raw, $address);
            } catch (\Throwable $e) {
                Logger::error('Antrean ' . $name . ' gagal diproses: ' . $e->getMessage());
                $outcome = MessageWriter::FAILED;
            }

            // Pesan yang gagal tetap di antrean untuk dicoba lagi nanti.
            if ($outcome === MessageWriter::STORED) {
                $stats['stored']++;
                $this->remove($name);
            } elseif ($outcome === MessageWriter::DUPLICATE) {
                $stats['duplicate']++;
                $this->remove($name);
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public function count(): int
    {
        return count($this->entries());
    }

    /** @return list<string> nama entri yang punya pasangan .eml dan .json */
    private function entries(): array
    {
        $listing = @scandir($this->directory);

        if ($listing === false) {
            return [];
        }

        $names = [];

        foreach ($listing as $entry) {
            if (preg_match('/^([a-f0-9]{32})\.eml$/', $entry, $m)) {
                $names[] = $m[1];
            }
        }

        sort($names);

        return $names;
    }

    private function path(string $name, string $extension): string
    {
        // Nama entri selalu hasil random_bytes. Pemeriksaan ini memastikan
        // tidak ada nilai dari luar yang bisa menunjuk path lain.
        if (!preg_match('/^[a-f0-9]{32}$/', $name)) {
            throw new RuntimeException('Nama entri antrean tidak sah.');
        }

        return $this->directory . '/' . $name . '.' . $extension;
    }
}
