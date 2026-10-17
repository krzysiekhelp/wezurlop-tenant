<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use Wezurlop\Support\Config;

/**
 * Wykrycie przepisywania adresów i prefiksów (docs/szkielet-tenanta.md rozdz. 12, krok 2).
 *
 * Decyzję podejmuje TEST: instalator pobiera z własnego serwera adres próbny (bez index.php
 * i z nim) oraz plik zasobu. SCRIPT_NAME służy wyłącznie do wypisania kandydatów, nie do decyzji.
 * Połączenie zawsze na SERVER_ADDR — adres docelowy nie pochodzi z danych żądania (SSRF).
 */
final class PrefixDetector
{
    public const PROBE_PATH = '/_wezurlop_probe';

    /**
     * Zasób sprawdzany po HTTP przy wykrywaniu prefiksu — musi ISTNIEĆ w `public/`.
     *
     * ⚠ To jest twarda zależność, nie ozdoba: jeśli ten plik zniknie albo zmieni nazwę,
     * `assetCode` nigdy nie będzie równe 200, wykrywanie prefiksu **zawsze** skończy się
     * `failed`, a instalator u klienta powie, że nie widzi własnych zasobów. Ta pułapka
     * zadziałała przy kasowaniu starego systemu: stała wskazywała `tokens.css`, który właśnie
     * usunęliśmy. Istnienie pliku pilnuje teraz `bin/check-rules.php`.
     */
    public const PROBE_ASSET = '/assets/css/00-tokens.css';
    public const AUTH_RECEIVED = 'authorization: yes';
    public const AUTH_MISSING = 'authorization: no';
    public const AUTH_PROBE_VALUE = 'Bearer wezurlop-probe';

    public static function probeAnswer(string $nonce): string
    {
        return 'wezurlop-probe:' . hash('sha256', 'wezurlop-probe' . $nonce);
    }

    /**
     * @param array<string, mixed> $server
     * Sonda wysyła też nagłówek Authorization — odpowiedź mówi, czy dotarł do PHP (pod CGI/FastCGI
     * bywa wycinany; wtedy klucze API idą przez X-Api-Key, CLAUDE.md §0).
     *
     * @return array{base: string, front: string, tried: list<string>, authorization?: bool, failed?: bool}|null
     */
    public function detect(array $server): ?array
    {
        $target = self::target($server);
        if ($target === null) {
            return null;
        }
        $scriptName = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '/index.php';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $scriptDir = Config::isValidBasePath($scriptDir) ? $scriptDir : '';

        $candidates = [['', ''], [$scriptDir, ''], ['', '/index.php'], [$scriptDir, '/index.php']];
        $seen = [];
        $tried = [];
        foreach ($candidates as [$base, $front]) {
            $label = ($base === '' ? '(korzeń)' : $base) . ($front === '' ? '' : ' + ' . $front);
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $nonce = bin2hex(random_bytes(8));
            [$code, $body] = $this->get($target, $base . $front . self::PROBE_PATH . '?n=' . $nonce);
            $lines = explode("\n", trim($body));
            $routeOk = $code === 200 && $lines[0] === self::probeAnswer($nonce);
            [$assetCode] = $routeOk ? $this->get($target, $base . self::PROBE_ASSET) : [0, ''];
            $tried[] = $label . ': trasa ' . ($routeOk ? 'OK' : 'brak') . ($routeOk ? ', zasoby HTTP ' . $assetCode : '');
            if ($routeOk && $assetCode === 200) {
                return [
                    'base' => $base, 'front' => $front, 'tried' => $tried,
                    'authorization' => ($lines[1] ?? '') === self::AUTH_RECEIVED,
                ];
            }
        }

        return ['base' => '', 'front' => '', 'tried' => $tried, 'failed' => true];
    }

    /**
     * @param array<string, mixed> $server
     * @return array{origin: string, resolve: list<string>}|null
     */
    public static function target(array $server): ?array
    {
        $host = is_string($server['HTTP_HOST'] ?? null) ? strtolower($server['HTTP_HOST']) : '';
        $addr = is_string($server['SERVER_ADDR'] ?? null) ? $server['SERVER_ADDR'] : '';
        if (preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host) !== 1 || filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $https = is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off';
        $name = explode(':', $host)[0];
        $port = (int) ($server['SERVER_PORT'] ?? ($https ? 443 : 80));
        $ip = str_contains($addr, ':') ? '[' . $addr . ']' : $addr;

        return [
            'origin'  => ($https ? 'https://' : 'http://') . $host,
            'resolve' => [$name . ':' . $port . ':' . $ip],
        ];
    }

    /**
     * @param array{origin: string, resolve: list<string>} $target
     * @return array{0: int, 1: string}
     */
    private function get(array $target, string $path): array
    {
        $ch = curl_init($target['origin'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RESOLVE        => $target['resolve'],
            CURLOPT_USERAGENT      => 'wezurlop-installer',
            CURLOPT_HTTPHEADER     => ['Authorization: ' . self::AUTH_PROBE_VALUE],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, is_string($body) ? $body : ''];
    }
}
