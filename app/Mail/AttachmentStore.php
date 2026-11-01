<?php

declare(strict_types=1);

namespace TempMail\Mail;

/**
 * Atomically menulis file lampiran ke storage dan mengelola namanya.
 */
final class AttachmentStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Tidak bisa membuat direktori lampiran: ' . $this->directory);
        }
    }

    /**
     * @param array{filename: string, mime_type: string, data: string} $attachment
     * @return array{filename: string, mime_type: string, size_bytes: int, stored_name: string}|null
     */
    public function store(array $attachment, int $maxBytes): ?array
    {
        $data = $attachment['data'];
        $size = strlen($data);

        if ($size === 0 || $size > $maxBytes) {
            return null;
        }

        $storedName = bin2hex(random_bytes(16));
        $path = $this->path($storedName);

        // Nama file selalu hasil random_bytes, sehingga path traversal
        // dan nama file berbahaya tidak mungkin terbentuk dari input email.
        if (file_put_contents($path, $data, LOCK_EX) === false) {
            return null;
        }

        @chmod($path, 0640);

        return [
            'filename'    => self::safeFilename($attachment['filename'], $attachment['mime_type']),
            'mime_type'   => $attachment['mime_type'],
            'size_bytes'  => $size,
            'stored_name' => $storedName,
        ];
    }

    public function path(string $storedName): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $storedName)) {
            throw new \RuntimeException('Nama file lampiran tidak valid.');
        }

        return $this->directory . '/' . $storedName;
    }

    public function read(string $storedName): ?string
    {
        $path = $this->path($storedName);

        if (!is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);

        return $data === false ? null : $data;
    }

    public function delete(string $storedName): void
    {
        try {
            $path = $this->path($storedName);
        } catch (\RuntimeException) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Membersihkan nama file: buang path, karakter kontrol, dan batasi panjang.
     * Nama asli hanya dipakai untuk ditampilkan, penyimpanan memakai nama acak.
     */
    public static function safeFilename(string $filename, string $mime = ''): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $filename) ?? '';
        $filename = trim($filename, " .\t");

        if ($filename === '') {
            $filename = 'lampiran';
        }

        if (mb_strlen($filename, 'UTF-8') > 120) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $base = mb_substr($filename, 0, 110, 'UTF-8');
            $filename = $base . ($extension !== '' ? '.' . mb_substr($extension, 0, 9, 'UTF-8') : '');
        }

        return $filename;
    }

    /** Lampiran berpotensi berbahaya selalu dikirim sebagai octet-stream. */
    public static function downloadMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime)) {
            return 'application/octet-stream';
        }

        $blocked = [
            'text/html', 'application/xhtml+xml', 'image/svg+xml',
            'application/javascript', 'text/javascript', 'application/xml',
            'text/xml', 'application/x-msdownload', 'application/x-executable',
        ];

        return in_array($mime, $blocked, true) ? 'application/octet-stream' : $mime;
    }
}
