<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console\Simulator;

use RuntimeException;

/**
 * Klient HTTP symulatora — zachowuje się jak connector: klucz w OBU nagłówkach, X-Protokol: 1,
 * X-Idempotency-Key przy POST. Weryfikacja certyfikatu zawsze włączona (CLAUDE.md §13); adres
 * docelowy z opcji polecenia, a `resolve` tylko przypina nazwę do lokalnego adresu (dev bez pętli NAT).
 */
final class HttpClient
{
    /** @var list<array{method: string, operation: string, status: int, json: mixed}> ruch API — do sprawdzenia z OpenAPI */
    public array $log = [];

    /**
     * @param string $baseUrl np. https://tenant.wezurlop.pl albo http://tenant-cgi.wezurlop.pl/public/index.php
     * @param string|null $resolve adres IP, pod który przypiąć nazwę hosta
     */
    public function __construct(private readonly string $baseUrl, private readonly ?string $resolve)
    {
    }

    public function label(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float}
     */
    public function request(string $method, string $path, ?string $body, array $headers): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init');
        }
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }

                return strlen($line);
            },
        ]);
        if ($this->resolve !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $this->resolve]);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $start = hrtime(true);
        $raw = curl_exec($ch);
        $ms = (hrtime(true) - $start) / 1e6;
        if (!is_string($raw)) {
            throw new RuntimeException('HTTP ' . $method . ' ' . $path . ': ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $json = json_decode($raw, true);
        if (preg_match('#^/api/v1/sync/([a-z-]+)#', $path, $m) === 1) {
            $this->log[] = ['method' => $method, 'operation' => $m[1], 'status' => $status, 'json' => $json];
        }

        return ['status' => $status, 'body' => $raw, 'json' => $json, 'headers' => $responseHeaders, 'ms' => $ms];
    }
}
