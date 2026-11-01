<?php

declare(strict_types=1);

namespace TempMail\Mail;

/**
 * Parser MIME minimal namun lengkap untuk keperluan temp mail.
 *
 * Cakupan: multipart (mixed/alternative/related), base64, quoted-printable,
 * charset non-UTF8 (via mbstring/iconv), header RFC 2047, dan lampiran.
 * Tidak ada dependency eksternal — cukup ext-mbstring dan ext-iconv.
 */
final class MimeParser
{
    private const MAX_DEPTH = 12;

    private const MAX_ATTACHMENT_BYTES = 26_214_400; // 25 MB setelah decode

    public function parse(string $raw): ParsedMessage
    {
        $message = new ParsedMessage();
        $message->size = strlen($raw);

        [$headerBlock, $body] = self::splitHeaders($raw);

        $message->headers = self::parseHeaders($headerBlock);

        $subject = $message->header('subject');
        $message->subject = self::decodeHeader($subject);
        if ($message->subject === '') {
            $message->subject = '(tanpa subjek)';
        }

        [$fromName, $fromAddr] = self::parseAddress($message->header('from'));
        $message->fromName = $fromName;
        $message->fromAddr = $fromAddr;

        $message->toAddr = self::extractAddress($message->header('to'));

        $date = $message->header('date');
        $timestamp = $date !== '' ? (int) strtotime($date) : 0;
        $message->timestamp = $timestamp > 0 ? $timestamp : time();

        $this->walk($headerBlock, $body, $message, 0, false);

        if ($message->html !== '') {
            $message->html = Sanitizer::html($message->html);
        }

        if ($message->text === '' && $message->html !== '') {
            $message->text = Sanitizer::toText($message->html);
        }

        return $message;
    }

    /**
     * Memecah blok header dan body pada baris kosong pertama.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitHeaders(string $raw): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);

        if (str_starts_with($normalized, "\n")) {
            return ['', substr($normalized, 1)];
        }

        $pos = strpos($normalized, "\n\n");

        if ($pos === false) {
            return [$normalized, ''];
        }

        return [substr($normalized, 0, $pos), substr($normalized, $pos + 2)];
    }

    /** @return array<string, string> */
    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            // Header bersambung (folding) diawali spasi atau tab.
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $name)) {
                continue;
            }

            if (isset($headers[$name])) {
                // Header berulang digabung agar Received/To tidak hilang.
                $headers[$name] .= ', ' . $value;
                continue;
            }

            $headers[$name] = $value;
            $current = $name;
        }

        return $headers;
    }

    /**
     * Menelusuri struktur MIME secara rekursif.
     */
    private function walk(string $headerBlock, string $body, ParsedMessage $message, int $depth, bool $isAttachmentContext): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $headers = self::parseHeaders($headerBlock);
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? 'text/plain')[0]));
        $params = self::parseParams($headers['content-type'] ?? '');
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $disposition = strtolower(trim(explode(';', $headers['content-disposition'] ?? '')[0]));
        $dispositionParams = self::parseParams($headers['content-disposition'] ?? '');

        $filename = $dispositionParams['filename']
            ?? $params['name']
            ?? '';

        $filename = self::decodeHeader($filename);

        if ($contentType === '' ) {
            $contentType = 'text/plain';
        }

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';

            if ($boundary === '') {
                return;
            }

            foreach (self::splitMultipart($body, $boundary) as $part) {
                [$partHeader, $partBody] = self::splitHeaders($part);
                $this->walk($partHeader, $partBody, $message, $depth + 1, $isAttachmentContext);
            }

            return;
        }

        $isAttachment = $disposition === 'attachment'
            || ($filename !== '' && !str_starts_with($contentType, 'text/'))
            || ($filename !== '' && $isAttachmentContext);

        if ($isAttachment) {
            $data = self::decodeBody($body, $encoding);

            if ($data === '' || strlen($data) > self::MAX_ATTACHMENT_BYTES) {
                return;
            }

            $message->attachments[] = [
                'filename'  => $filename !== '' ? $filename : ('lampiran-' . (count($message->attachments) + 1)),
                'mime_type' => self::normalizeMime($contentType),
                'data'      => $data,
            ];

            return;
        }

        if (str_starts_with($contentType, 'text/plain') && $message->text === '') {
            $decoded = self::decodeBody($body, $encoding);
            $message->text = self::convertCharset($decoded, $params['charset'] ?? '');
            return;
        }

        if (str_starts_with($contentType, 'text/html') && $message->html === '') {
            $decoded = self::decodeBody($body, $encoding);
            $message->html = self::convertCharset($decoded, $params['charset'] ?? '');
            return;
        }

        if (str_starts_with($contentType, 'message/')) {
            $decoded = self::decodeBody($body, $encoding);
            [$innerHeader, $innerBody] = self::splitHeaders($decoded);
            $this->walk($innerHeader, $innerBody, $message, $depth + 1, false);
        }
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = explode($delimiter, $body);

        // Bagian pertama adalah preamble, bagian terakhir adalah epilogue.
        array_shift($parts);

        $result = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }

            $result[] = ltrim($part, "\r\n");
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function parseParams(string $headerValue): array
    {
        $params = [];
        $parts = explode(';', $headerValue);
        array_shift($parts);

        foreach ($parts as $part) {
            $eq = strpos($part, '=');

            if ($eq === false) {
                continue;
            }

            $key = strtolower(trim(substr($part, 0, $eq)));
            $value = trim(substr($part, $eq + 1));

            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }

            if ($key !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => (string) quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function convertCharset(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));

        if ($text === '' || $charset === '' || $charset === 'utf-8' || $charset === 'utf8') {
            return self::ensureUtf8($text);
        }

        // Alias umum yang tidak dikenali mbstring.
        $charset = match ($charset) {
            'latin1', 'latin-1', 'iso8859-1' => 'ISO-8859-1',
            'windows-1252', 'cp1252'         => 'Windows-1252',
            default                          => $charset,
        };

        if (!in_array($charset, mb_list_encodings(), true) && !in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            return $converted === false ? self::ensureUtf8($text) : $converted;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return is_string($converted) ? $converted : self::ensureUtf8($text);
    }

    private static function ensureUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        return is_string($converted) ? $converted : '';
    }

    /**
     * Dekode header RFC 2047 (=?UTF-8?B?...?= dan =?ISO-8859-1?Q?...?=).
     */
    public static function decodeHeader(string $value): string
    {
        if ($value === '' || !str_contains($value, '=?')) {
            return self::ensureUtf8($value);
        }

        // Spasi pemisah antara dua encoded-word yang berurutan harus
        // dihilangkan, dan itu dilakukan sebelum dekode (RFC 2047 §6.2).
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $payload = $m[3];

                $raw = $encoding === 'B'
                    ? (string) base64_decode($payload, false)
                    : quoted_printable_decode(str_replace('_', ' ', $payload));

                return self::convertCharset($raw, $charset);
            },
            $value
        );

        $result = $decoded ?? $value;

        return self::ensureUtf8($result);
    }

    /**
     * @return array{0: string, 1: string} [nama, alamat]
     */
    private static function parseAddress(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['', ''];
        }

        // Format: "Nama" <email@domain>
        if (preg_match('/^(.*?)<([^<>]+)>\s*$/s', $value, $m)) {
            $name = trim($m[1], " \t\"'");
            $name = self::decodeHeader($name);
            $addr = strtolower(trim($m[2]));

            return [$name, $addr];
        }

        $email = self::extractAddress($value);

        return ['', $email !== '' ? $email : strtolower(substr($value, 0, 190))];
    }

    private static function extractAddress(string $value): string
    {
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return strtolower($m[0][0]);
        }

        return '';
    }

    private static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime)) {
            return 'application/octet-stream';
        }

        return $mime;
    }
}
