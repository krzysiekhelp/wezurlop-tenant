<?php

declare(strict_types=1);

namespace Wezurlop\Security;

/**
 * Adres klienta i wykrywanie HTTPS za proxy (docs/szkielet-tenanta.md rozdz. 2, CLAUDE.md §9).
 *
 * JEDYNE miejsce w aplikacji, które czyta nagłówki X-Forwarded-*. Nagłówki proxy są
 * honorowane wyłącznie wtedy, gdy REMOTE_ADDR należy do listy zaufanych proxy
 * z konfiguracji (domyślnie pustej) — inaczej każdy klient podałby dowolny adres.
 */
final class TrustedProxy
{
    /** @param list<string> $trusted adresy IP lub zakresy CIDR (IPv4 i IPv6) */
    public function __construct(private readonly array $trusted)
    {
    }

    /** @param array<string, mixed> $server */
    public function clientIp(array $server): string
    {
        $remote = $this->validIp($server['REMOTE_ADDR'] ?? null) ?? '0.0.0.0';
        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($forwarded) || $forwarded === '') {
            return $remote;
        }

        // Łańcuch czytany od prawej: ostatni wpis dopisało najbliższe proxy.
        // Pierwszy adres spoza listy zaufanych to klient; wpisy z lewej strony mógł podać sam klient.
        $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($hops as $hop) {
            $ip = $this->validIp($hop);
            if ($ip === null) {
                return $remote;
            }
            if (!$this->isTrusted($ip)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $remote = $this->validIp($server['REMOTE_ADDR'] ?? null);
        if ($remote === null || !$this->isTrusted($remote)) {
            return false;
        }

        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!is_string($proto) || $proto === '') {
            return false;
        }
        $first = strtolower(trim(explode(',', $proto)[0]));

        return $first === 'https';
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->trusted as $range) {
            if ($this->inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $ip, string $range): bool
    {
        [$network, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $ipBin = inet_pton($ip);
        $netBin = $this->validIp($network) !== null ? inet_pton($network) : false;
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits === null) {
            return $ipBin === $netBin;
        }
        if (!ctype_digit($bits) || (int) $bits > $maxBits) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private function validIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $ip = filter_var(trim($value), FILTER_VALIDATE_IP);

        return is_string($ip) ? $ip : null;
    }
}
