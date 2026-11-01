<?php

declare(strict_types=1);

namespace TempMail\Mail;

/**
 * Pesan email hasil parsing, bentuk siap simpan.
 */
final class ParsedMessage
{
    public string $fromName = '';
    public string $fromAddr = '';
    public string $toAddr = '';
    public string $subject = '';
    public string $text = '';
    public string $html = '';
    public int $size = 0;
    public int $timestamp = 0;

    /** @var list<array{filename: string, mime_type: string, data: string}> */
    public array $attachments = [];

    /** @var array<string, string> header ternormalisasi ke lowercase */
    public array $headers = [];

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
