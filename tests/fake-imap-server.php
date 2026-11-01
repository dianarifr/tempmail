<?php

declare(strict_types=1);

/**
 * Server IMAP tiruan untuk menguji ImapClient + Fetcher tanpa mailbox nyata.
 *
 *   php tests/fake-imap-server.php 8143
 *
 * Hanya mengimplementasikan respons yang dibutuhkan aplikasi:
 * greeting, LOGIN, SELECT, UID SEARCH, UID FETCH, UID STORE, EXPUNGE, LOGOUT.
 */

$port = (int) ($argv[1] ?? 8143);
$rawFile = $argv[2] ?? '';

$rawMessage = is_file($rawFile) ? (string) file_get_contents($rawFile) : '';
$rawMessage = str_replace(["\r\n", "\r"], "\n", $rawMessage);
$rawMessage = str_replace("\n", "\r\n", $rawMessage);

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "gagal bind: {$errstr}\n");
    exit(1);
}

$client = @stream_socket_accept($server, 20);

if ($client === false) {
    fwrite(STDERR, "tidak ada koneksi masuk\n");
    exit(1);
}

$state = ['deleted' => [], 'fetch_count' => 0, 'commands' => []];

fwrite($client, "* OK [CAPABILITY IMAP4rev1] fake server ready\r\n");

while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    $state['commands'][] = $line;

    $parts = explode(' ', $line, 3);
    $tag = $parts[0] ?? '*';
    $command = strtoupper($parts[1] ?? '');

    if ($command === 'CAPABILITY') {
        fwrite($client, "* CAPABILITY IMAP4rev1\r\n{$tag} OK done\r\n");
        continue;
    }

    if ($command === 'LOGIN') {
        fwrite($client, "{$tag} OK login completed\r\n");
        continue;
    }

    if ($command === 'SELECT') {
        fwrite($client, "* 1 EXISTS\r\n* 0 RECENT\r\n{$tag} OK [READ-WRITE] selected\r\n");
        continue;
    }

    if ($command === 'UID') {
        $sub = strtoupper($parts[2] ?? '');

        if (str_starts_with($sub, 'SEARCH')) {
            $range = trim(substr($parts[2], 6));
            [$from] = array_map('intval', explode(':', $range));

            // Satu pesan dengan UID 7 selalu tersedia, meniru perilaku
            // server yang mengembalikan UID tertinggi walau tidak ada yang baru.
            $found = $from <= 7 ? '7' : '';
            fwrite($client, "* SEARCH {$found}\r\n{$tag} OK search done\r\n");
            continue;
        }

        if (str_starts_with($sub, 'FETCH')) {
            $state['fetch_count']++;
            $length = strlen($rawMessage);

            fwrite($client, "* 1 FETCH (UID 7 RFC822.SIZE {$length} BODY[] {" . $length . "}\r\n");
            fwrite($client, $rawMessage);
            fwrite($client, ")\r\n{$tag} OK fetch done\r\n");
            continue;
        }

        if (str_starts_with($sub, 'STORE')) {
            $state['deleted'][] = (int) trim(substr($parts[2], strpos($parts[2], '+FLAGS.SILENT') !== false ? 13 : 0));
            fwrite($client, "{$tag} OK store done\r\n");
            continue;
        }
    }

    if ($command === 'EXPUNGE') {
        fwrite($client, "* 1 EXPUNGE\r\n{$tag} OK expunge done\r\n");
        continue;
    }

    if ($command === 'LOGOUT') {
        fwrite($client, "* BYE logging out\r\n{$tag} OK logout done\r\n");
        break;
    }

    fwrite($client, "{$tag} BAD unknown command\r\n");
}

@fclose($client);
@fclose($server);

file_put_contents(
    sys_get_temp_dir() . '/fake-imap-state.json',
    json_encode([
        'fetch_count' => $state['fetch_count'],
        'commands'    => $state['commands'],
    ], JSON_PRETTY_PRINT)
);

echo "fake server selesai, fetch_count={$state['fetch_count']}\n";
