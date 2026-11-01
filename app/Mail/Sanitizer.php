<?php

declare(strict_types=1);

namespace TempMail\Mail;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use TempMail\Config;

/**
 * Sanitizer HTML untuk body email.
 *
 * Dua lapis pertahanan dipakai di aplikasi ini: HTML dibersihkan di sini
 * sebelum disimpan, lalu dirender di dalam <iframe sandbox> tanpa
 * allow-scripts di sisi frontend. Keduanya wajib, bukan pilihan.
 *
 * Implementasi sendiri (tanpa HTMLPurifier) supaya tidak ada dependency
 * yang perlu di-upload ke shared hosting.
 */
final class Sanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'blockquote', 'br',
        'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'div',
        'dl', 'dt', 'em', 'figcaption', 'figure', 'font', 'footer', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'main', 'ol',
        'p', 'pre', 'q', 's', 'section', 'small', 'span', 'strike', 'strong',
        'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
        'u', 'ul', 'title',
    ];

    /** Tag yang dibuang beserta seluruh isinya. */
    private const DROP_WITH_CONTENT = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'base',
        'meta', 'link', 'template', 'noscript', 'svg', 'math', 'audio', 'video',
        'source', 'track', 'canvas', 'portal', 'marquee', 'blink', 'xml',
    ];

    private const ALLOWED_ATTRS = [
        '*'      => ['class', 'id', 'style', 'title', 'dir', 'lang', 'align', 'valign'],
        'a'      => ['href', 'name', 'target', 'rel'],
        'img'    => ['src', 'alt', 'width', 'height', 'border', 'hspace', 'vspace'],
        'table'  => ['width', 'height', 'border', 'cellpadding', 'cellspacing', 'bgcolor', 'summary'],
        'td'     => ['width', 'height', 'colspan', 'rowspan', 'bgcolor', 'nowrap'],
        'th'     => ['width', 'height', 'colspan', 'rowspan', 'bgcolor', 'nowrap', 'scope'],
        'tr'     => ['bgcolor'],
        'col'    => ['width', 'span'],
        'colgroup' => ['width', 'span'],
        'font'   => ['color', 'face', 'size'],
        'hr'     => ['width', 'size', 'noshade'],
        'ol'     => ['start', 'type'],
        'ul'     => ['type'],
    ];

    private const SAFE_LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const MAX_HTML_BYTES = 2 * 1024 * 1024;

    public static function html(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            $html = substr($html, 0, self::MAX_HTML_BYTES);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');

        // Pembungkus div memberi kita satu root yang stabil untuk diambil hasilnya.
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="tm-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $root = $doc->getElementById('tm-root');

        if ($root === null) {
            return '';
        }

        self::cleanNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }

    /** Mengubah HTML menjadi teks polos (untuk preview dan body_text fallback). */
    public static function toText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    public static function preview(string $text, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
    }

    private static function cleanNode(DOMNode $node): void
    {
        $child = $node->firstChild;

        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Tag tak dikenal dibuka isinya, bukan dibuang, agar teks tetap terbaca.
                    self::cleanNode($child);
                    self::unwrap($child);
                    $child = $next;
                    continue;
                }

                self::cleanAttributes($child);
                self::cleanNode($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }

            $child = $next;
        }
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function cleanAttributes(DOMElement $element): void
    {
        $tag = strtolower($element->nodeName);
        $allowed = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);

        /** @var list<DOMAttr> $attributes */
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            switch ($name) {
                case 'style':
                    if (!self::isSafeStyle($value)) {
                        $element->removeAttribute('style');
                    }
                    break;

                case 'href':
                    if (!self::isSafeLink($value)) {
                        $element->removeAttribute('href');
                    }
                    break;

                case 'src':
                    if (!self::isSafeImage($value)) {
                        $element->removeAttribute('src');
                        $element->setAttribute('data-blocked', '1');
                    }
                    break;
            }
        }

        if ($tag === 'a') {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
            if ($element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
            }
        }
    }

    /**
     * Style dibatasi ke deklarasi warna/teks/layout sederhana. url() dilarang
     * karena bisa dipakai sebagai pelacak atau memuat resource eksternal.
     */
    private static function isSafeStyle(string $value): bool
    {
        if ($value === '' || strlen($value) > 2000) {
            return false;
        }

        $lower = strtolower($value);

        foreach (['expression', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '@import', 'url(', '&#', '\\'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return false;
            }
        }

        return (bool) preg_match('/^[a-zA-Z0-9\s:;,.%#()"\'-]+$/', $value);
    }

    private static function isSafeLink(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        $parts = parse_url($value);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        // Relatif tanpa scheme ditolak: email tidak punya konteks base URL.
        if ($scheme === '') {
            return false;
        }

        return in_array($scheme, self::SAFE_LINK_SCHEMES, true);
    }

    /**
     * Gambar eksternal diblokir secara default (tracking pixel), kecuali
     * security.block_remote_images dimatikan.
     */
    private static function isSafeImage(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (str_starts_with(strtolower($value), 'data:')) {
            return (bool) preg_match('#^data:image/(png|jpeg|gif|webp);base64,[a-z0-9+/=\s]+$#i', $value);
        }

        if (!self::isSafeLink($value)) {
            return false;
        }

        if (!Config::bool('security.block_remote_images', true)) {
            return true;
        }

        return !preg_match('#^https?://#i', $value);
    }
}
