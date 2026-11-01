<?php

declare(strict_types=1);

namespace TempMail\Inbox;

use TempMail\Config;
use TempMail\Repo\InboxRepository;
use TempMail\Security\RateLimiter;
use TempMail\Http\Request;

/**
 * Use case pembuatan dan pembukaan alamat.
 */
final class InboxService
{
    public function __construct(private readonly InboxRepository $inboxes = new InboxRepository())
    {
    }

    /**
     * Membuat alamat acak baru, dibatasi N alamat per IP per hari.
     *
     * @return array{address: string, remaining: int}
     */
    public function create(string $ip): array
    {
        $limit = Config::int('security.new_addresses_per_ip_per_day', 10);

        if (!RateLimiter::attempt($ip, 'create_address', $limit, RateLimiter::dailyWindow())) {
            throw new LimitReached('Batas pembuatan alamat baru tercapai. Coba lagi besok.');
        }

        $attempts = 0;

        do {
            $localPart = Address::generateLocalPart();
            $address = $localPart . '@' . Address::domain();
            $created = $this->inboxes->create($address, $localPart, (string) (inet_pton($ip) ?: ''));
            $attempts++;
        } while (!$created && $attempts < 5);

        if (!$created) {
            throw new \RuntimeException('Gagal membuat alamat unik, silakan coba lagi.');
        }

        return [
            'address'   => $address,
            'remaining' => RateLimiter::remaining($ip, 'create_address', $limit, RateLimiter::dailyWindow()),
        ];
    }

    /**
     * Membuka alamat yang sudah ada atau mengetik alamat lama.
     * Mengembalikan null bila alamat tidak sah / bukan milik domain ini.
     */
    public function open(string $address, string $ip): ?string
    {
        $normalized = Address::normalize($address);

        if ($normalized === null) {
            return null;
        }

        $readsPerHour = Config::int('security.inbox_reads_per_ip_per_hour', 2400);

        if (!RateLimiter::attempt($ip, 'read_inbox', $readsPerHour, date('Y-m-d H'))) {
            throw new LimitReached('Terlalu banyak permintaan. Tunggu beberapa saat.');
        }

        $localPart = Address::localPartOf($normalized);

        if ($localPart === null) {
            return null;
        }

        // Alamat yang belum pernah dipakai tetap boleh dibuka: email yang
        // datang nanti akan langsung masuk ke alamat itu.
        $this->inboxes->ensure($normalized, $localPart, (string) (inet_pton($ip) ?: ''));
        $this->inboxes->touch($normalized);

        return $normalized;
    }
}
