<?php

declare(strict_types=1);

namespace Wezurlop\Security;

/**
 * Dane klienta do rejestru sesji: HMAC adresu (limity, dziennik), adres zanonimizowany do sieci
 * (/24 dla IPv4, /48 dla IPv6 — wyłącznie do wyświetlenia na liście urządzeń) i User-Agent
 * (wyłącznie do wyświetlenia — nigdy do decyzji, CLAUDE.md §11).
 */
final class ClientInfo
{
    /**
     * @param array<string, mixed> $server
     * @return array{ip_hash: ?string, ip_prefix: ?string, user_agent: ?string}
     */
    public static function fromServer(array $server, TrustedProxy $proxy, ClientFingerprint $fingerprint): array
    {
        $ip = $proxy->clientIp($server);
        $ua = $server['HTTP_USER_AGENT'] ?? null;

        return [
            'ip_hash'    => $fingerprint->ipHash($server),
            'ip_prefix'  => self::anonymize($ip),
            'user_agent' => is_string($ua) && $ua !== '' ? mb_substr($ua, 0, 255) : null,
        ];
    }

    public static function anonymize(string $ip): ?string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 4) {
            return inet_ntop(substr($packed, 0, 3) . "\0") . '/24';
        }

        return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** Czytelny opis przeglądarki i systemu — przybliżony, tylko do wyświetlenia. */
    /**
     * Czy to urządzenie **przenośne** — wyłącznie do wyboru IKONY na liście urządzeń (Z69).
     *
     * ⚠ `User-Agent` jest tekstem od klienta, więc nie wolno na nim oprzeć ŻADNEJ decyzji
     * o dostępie (CLAUDE.md §11). Wybór ikony to prezentacja — ta sama klasa czyta już z niego
     * nazwę przeglądarki i systemu na tę samą listę. Gdy nie wiadomo, pokazujemy komputer:
     * zgadywanie „telefon" przy braku danych myliłoby bardziej niż ogólna ikona.
     */
    public static function mobilny(?string $ua): bool
    {
        if ($ua === null || $ua === '') {
            return false;
        }

        return str_contains($ua, 'Android')
            || str_contains($ua, 'iPhone')
            || str_contains($ua, 'iPad')
            || str_contains($ua, 'Mobile');
    }

    public static function describeUserAgent(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'Nieznane urządzenie';
        }
        $app = preg_match('#wezurlop-app/([\d.]+)#i', $ua, $m) === 1 ? 'Aplikacja wezurlop ' . $m[1] : null;
        $browser = match (true) {
            str_contains($ua, 'Edg/')     => 'Edge',
            str_contains($ua, 'OPR/')     => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/')  => 'Chrome',
            str_contains($ua, 'Safari/')  => 'Safari',
            str_contains($ua, 'curl/')    => 'curl',
            default                       => 'Przeglądarka',
        };
        $os = match (true) {
            str_contains($ua, 'Android')                                  => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')      => 'iOS',
            str_contains($ua, 'Windows')                                  => 'Windows',
            str_contains($ua, 'Mac OS X')                                 => 'macOS',
            str_contains($ua, 'Linux')                                    => 'Linux',
            default                                                       => null,
        };

        return ($app ?? $browser) . ($os !== null ? ' · ' . $os : '');
    }
}
