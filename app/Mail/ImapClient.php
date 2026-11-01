<?php

declare(strict_types=1);

namespace TempMail\Mail;

use RuntimeException;

/**
 * Klien IMAP4rev1 minimal berbasis socket.
 *
 * Ditulis sendiri karena ekstensi ext-imap dihapus dari PHP 8.4 dan paket
 * composer tidak bisa dipasang dari shared hosting tanpa akses terminal.
 * Yang diimplementasi hanya perintah yang dibutuhkan aplikasi ini:
 * CAPABILITY, LOGIN, SELECT, UID SEARCH, UID FETCH, STORE, EXPUNGE, LOGOUT.
 */
final class ImapClient
{
    /** @var resource|null */
    private $socket = null;

    private int $tagCounter = 0;

    private string $host;
    private int $port;
    private string $encryption;
    private int $timeout;

    public function __construct(string $host, int $port, string $encryption, int $timeout = 30)
    {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = strtolower($encryption);
        $this->timeout = max(5, $timeout);
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('Gagal konek IMAP %s:%d — %s', $this->host, $this->port, $errstr !== '' ? $errstr : 'unknown error'));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $greeting = $this->readLine();

        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Server IMAP tidak memberi greeting yang valid.');
        }

        if ($this->encryption === 'tls') {
            $this->send('STARTTLS');
            $this->assertOk($this->readUntilTag(), 'STARTTLS');

            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Negosiasi TLS dengan server IMAP gagal.');
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->send('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        $this->assertOk($this->readUntilTag(), 'LOGIN');
    }

    public function selectMailbox(string $mailbox): void
    {
        $this->send('SELECT ' . self::quote($mailbox));
        $this->assertOk($this->readUntilTag(), 'SELECT');
    }

    /** @return list<int> UID yang lebih besar dari $sinceUid */
    public function searchUidsGreaterThan(int $sinceUid): array
    {
        $this->send('UID SEARCH UID ' . ($sinceUid + 1) . ':*');
        $response = $this->readUntilTag();
        $this->assertOk($response, 'UID SEARCH');

        if (!preg_match('/^\* SEARCH(.*)$/mi', $response, $m)) {
            return [];
        }

        $uids = [];

        foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
            if ($token !== '' && ctype_digit($token)) {
                $uid = (int) $token;
                // Server mengembalikan UID terakhir walau tidak ada yang baru;
                // penyaringan sebenarnya dilakukan di pemanggil.
                if ($uid > $sinceUid) {
                    $uids[] = $uid;
                }
            }
        }

        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * Mengambil RFC822 mentah untuk satu UID.
     * Mengembalikan null bila pesan sudah tidak ada.
     */
    public function fetchRaw(int $uid): ?string
    {
        $this->send('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        $raw = $this->readFetchResponse();

        if ($raw === null) {
            return null;
        }

        return $raw;
    }

    public function markDeleted(int $uid): void
    {
        $this->send('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        $this->assertOk($this->readUntilTag(), 'UID STORE');
    }

    public function expunge(): void
    {
        $this->send('EXPUNGE');
        $this->assertOk($this->readUntilTag(), 'EXPUNGE');
    }

    public function logout(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->send('LOGOUT');
            $this->readUntilTag();
        } finally {
            $this->disconnect();
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    private function send(string $command): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket IMAP belum terhubung.');
        }

        $tag = $this->nextTag();
        $payload = $tag . ' ' . $command . "\r\n";

        if (fwrite($this->socket, $payload) === false) {
            throw new RuntimeException('Gagal mengirim perintah ke server IMAP.');
        }

        $this->lastTag = $tag;
    }

    private string $lastTag = '';

    private function nextTag(): string
    {
        $this->tagCounter++;

        return 'T' . $this->tagCounter;
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket IMAP belum terhubung.');
        }

        $line = fgets($this->socket, 65536);

        if ($line === false) {
            throw new RuntimeException('Koneksi IMAP terputus saat membaca respons.');
        }

        return rtrim($line, "\r\n");
    }

    /** Membaca respons sampai baris tag terakhir. */
    private function readUntilTag(): string
    {
        $buffer = '';

        while (true) {
            $line = $this->readLine();
            $buffer .= $line . "\n";

            if ($this->lastTag !== '' && str_starts_with($line, $this->lastTag . ' ')) {
                return $buffer;
            }
        }
    }

    /**
     * Membaca satu respons FETCH, termasuk literal {n} yang dikirim sebagai
     * blok biner mentah setelah baris.
     */
    private function readFetchResponse(): ?string
    {
        $raw = null;

        while (true) {
            $line = $this->readLine();

            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $length = (int) $m[1];

                if ($length > 0) {
                    $data = $this->readBytes($length);
                    $raw = $data;

                    // Sisa baris penutup (")\r\n") dibaca setelah literal.
                    $this->readLine();
                }
            }

            if ($this->lastTag !== '' && str_starts_with($line, $this->lastTag . ' ')) {
                break;
            }
        }

        return $raw;
    }

    private function readBytes(int $length): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket IMAP belum terhubung.');
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 65536));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Timeout saat membaca literal IMAP.');
                }
                break;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    private function assertOk(string $response, string $command): void
    {
        if (preg_match('/^' . preg_quote($this->lastTag, '/') . ' OK/mi', $response)) {
            return;
        }

        $detail = '';

        if (preg_match('/^' . preg_quote($this->lastTag, '/') . ' (?:NO|BAD) (.+)$/mi', $response, $m)) {
            $detail = trim($m[1]);
        }

        throw new RuntimeException('Perintah IMAP ' . $command . ' gagal: ' . ($detail !== '' ? $detail : 'respons tidak dikenal'));
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
