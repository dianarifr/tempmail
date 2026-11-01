<?php

declare(strict_types=1);

namespace TempMail\Inbox;

use TempMail\Config;

/**
 * Aturan penamaan dan validasi alamat email.
 */
final class Address
{
    /** Karakter yang mudah tertukar (0/o, 1/l/i) dihilangkan. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Local part yang tidak boleh dibuat karena rawan disalahgunakan. */
    private const RESERVED = [
        'abuse', 'admin', 'administrator', 'postmaster', 'hostmaster', 'webmaster',
        'root', 'noreply', 'no-reply', 'mailer-daemon', 'catchall', 'catch-all',
        'billing', 'security', 'support', 'dmarc', 'spf', 'dkim',
    ];

    public static function domain(): string
    {
        return strtolower(Config::string('app.domain'));
    }

    public static function generateLocalPart(): string
    {
        $length = max(6, min(32, Config::int('inbox.local_part_length', 10)));
        $max = strlen(self::ALPHABET) - 1;
        $local = '';

        for ($i = 0; $i < $length; $i++) {
            $local .= self::ALPHABET[random_int(0, $max)];
        }

        return $local;
    }

    public static function isReserved(string $localPart): bool
    {
        return in_array(strtolower($localPart), self::RESERVED, true);
    }

    /** Apakah alamat sudah memakai domain milik aplikasi ini. */
    public static function belongsToUs(string $address): bool
    {
        $address = strtolower(trim($address));
        $at = strrpos($address, '@');

        if ($at === false) {
            return false;
        }

        return substr($address, $at + 1) === self::domain();
    }

    /**
     * Memvalidasi alamat lengkap dan mengembalikan bentuk ternormalisasi,
     * atau null bila tidak sah.
     */
    public static function normalize(string $address): ?string
    {
        $address = strtolower(trim($address));

        if ($address === '' || strlen($address) > 190) {
            return null;
        }

        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }

        $localPart = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($domain !== self::domain()) {
            return null;
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/', $localPart)) {
            return null;
        }

        if (str_contains($localPart, '..') || self::isReserved($localPart)) {
            return null;
        }

        return $localPart . '@' . $domain;
    }

    /** Mengekstrak local part dari alamat milik kita, atau null. */
    public static function localPartOf(string $address): ?string
    {
        $normalized = self::normalize($address);
        if ($normalized === null) {
            return null;
        }

        return substr($normalized, 0, (int) strpos($normalized, '@'));
    }
}
