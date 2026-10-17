<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

use InvalidArgumentException;

/**
 * Dokąd diagnostyka wysyła żądania sprawdzające ekspozycję plików.
 *
 * Adres pochodzi wyłącznie z konfiguracji (APP_URL, APP_BASE_PATH) — nigdy z nagłówka Host
 * ani z danych formularza (CLAUDE.md §13, SSRF). Z APP_URL brany jest tylko origin
 * (schemat, host, port); prefiks aplikacji to APP_BASE_PATH.
 *
 * $resolveIp pozwala połączyć się z własnym serwerem z pominięciem DNS (np. SERVER_ADDR),
 * nie zmieniając nazwy hosta w żądaniu — weryfikacja certyfikatu działa dalej.
 */
final class HttpProbeTarget
{
    public readonly string $origin;
    public readonly string $basePath;
    private readonly string $host;
    private readonly int $port;

    public function __construct(
        string $appUrl,
        string $basePath,
        public readonly ?string $resolveIp = null,
        public readonly ?string $documentRoot = null,
    ) {
        $parts = parse_url($appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('APP_URL musi być pełnym adresem http(s)://');
        }
        if ($resolveIp !== null && filter_var($resolveIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Nieprawidłowy adres IP do połączenia');
        }

        $scheme = strtolower($parts['scheme']);
        $this->origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = rtrim($basePath, '/');
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    }

    /** @return list<string> wpis dla CURLOPT_RESOLVE (pusty, gdy bez wymuszania adresu) */
    public function curlResolve(): array
    {
        if ($this->resolveIp === null) {
            return [];
        }
        $ip = str_contains($this->resolveIp, ':') ? '[' . $this->resolveIp . ']' : $this->resolveIp;

        return [$this->host . ':' . $this->port . ':' . $ip];
    }

    /**
     * Prefiksy adresów, pod którymi może być widoczny korzeń aplikacji.
     *
     * @return list<string>
     */
    public function appRootPrefixes(): array
    {
        $prefixes = [];
        if (str_ends_with($this->basePath, '/public')) {
            // układ awaryjny: katalog domeny = korzeń aplikacji, public/ widoczne pod /public
            $prefixes[] = substr($this->basePath, 0, -strlen('/public'));
        }
        // wyjście z public/ ścieżką względną
        $prefixes[] = $this->basePath . '/..';
        $prefixes[] = $this->basePath . '/%2e%2e';
        $prefixes[] = '';

        return array_values(array_unique($prefixes));
    }
}
