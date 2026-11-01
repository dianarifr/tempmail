<?php

declare(strict_types=1);

namespace TempMail\Mail;

use TempMail\Config;
use TempMail\Inbox\Address;
use TempMail\Repo\InboxRepository;

/**
 * Jalur masuk lewat "Pipe to a Program" cPanel.
 *
 * Exim menjalankan bin/ingest.php dan mengirim isi email ke stdin. Alamat
 * tujuan tersedia di environment variable RECIPIENT, jadi tidak perlu
 * menebak dari header seperti pada jalur IMAP.
 *
 * Pesan untuk alamat yang tidak terdaftar dibuang tanpa menyentuh disk:
 * alamat selalu dibuat lewat UI sebelum dibagikan, jadi alamat asing berarti
 * spam ke catch-all.
 */
final class Ingest
{
    private ?InboxRepository $inboxRepo = null;

    public function __construct(
        private readonly ?InboxRepository $inboxes = null,
        private readonly ?MessageWriter $writer = null,
        private readonly ?IngestQueue $queue = null,
    ) {
    }

    /**
     * Koneksi database baru dibuka saat benar-benar dipakai.
     *
     * Penting untuk jalur pipe: kalau database mati, konstruktor tidak boleh
     * gagal sebelum email sempat diantrekan ke disk.
     */
    private function inboxes(): InboxRepository
    {
        if ($this->inboxes !== null) {
            return $this->inboxes;
        }

        return $this->inboxRepo ??= new InboxRepository();
    }

    private function writer(): MessageWriter
    {
        return $this->writer ?? new MessageWriter();
    }

    private function queue(): IngestQueue
    {
        return $this->queue ?? IngestQueue::fromConfig();
    }

    /**
     * Menyimpan satu email mentah untuk alamat tertentu.
     *
     * @return MessageWriter::STORED|MessageWriter::DUPLICATE|MessageWriter::FAILED|'unknown_address'
     */
    public function handle(string $raw, string $recipient): string
    {
        // Buang spam sebelum menyentuh disk atau database.
        if (!Address::belongsToUs($recipient)) {
            return 'unknown_address';
        }

        $address = Address::normalize($recipient);

        if ($address === null) {
            return 'unknown_address';
        }

        if (strlen($raw) > Config::int('inbox.max_message_bytes', 8 * 1024 * 1024)) {
            return MessageWriter::FAILED;
        }

        // Cek alamat terdaftar. Kalau database sendiri tidak bisa dihubungi,
        // jangan buang email: antrekan seperti biasa supaya cron bisa
        // menyimpannya setelah database pulih. Tanpa ini, gangguan database
        // sesaat menyebabkan email hilang permanen.
        try {
            $known = $this->inboxes()->exists($address);
        } catch (\Throwable $e) {
            Logger::error('ingest: database tidak bisa diperiksa (' . $e->getMessage()
                . '), email untuk ' . $address . ' diantrekan');

            try {
                $this->queue()->push($raw, $address);
            } catch (\Throwable) {
                // Disk juga bermasalah; tidak ada lagi yang bisa diselamatkan.
            }

            return MessageWriter::FAILED;
        }

        if (!$known) {
            return 'unknown_address';
        }

        // Tulis dulu: kalau pemrosesan gagal, file ini yang menyelamatkan
        // email dari kehilangan permanen.
        $queued = $this->queue()->push($raw, $address);
        $outcome = $this->process($raw, $address);

        if ($outcome === MessageWriter::STORED || $outcome === MessageWriter::DUPLICATE) {
            $this->queue()->remove($queued);
        }

        return $outcome;
    }

    /**
     * Memproses email mentah untuk alamat yang sudah dipastikan terdaftar.
     * Dipakai jalur pipe dan saat cron memutar ulang isi antrean.
     *
     * @return MessageWriter::STORED|MessageWriter::DUPLICATE|MessageWriter::FAILED
     */
    public function process(string $raw, string $address): string
    {
        $parsed = (new MimeParser())->parse($raw);

        return $this->writer()->write($parsed, $address);
    }
}
