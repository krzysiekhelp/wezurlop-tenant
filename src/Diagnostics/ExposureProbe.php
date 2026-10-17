<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

/**
 * Czy plik spod katalogu aplikacji da się pobrać przez HTTP (CLAUDE.md §2: wynik, nie obecność reguły).
 * Wspólne dla kroku 7 instalatora, strony /diagnostyka i dobowego sprawdzenia pliku-kanarka.
 *
 * Trzy wyniki, nie dwa: chronione, odsłonięte, nierozstrzygnięte (brak odpowiedzi to NIE „chronione”).
 */
final class ExposureProbe
{
    public const PROTECTED = 'protected';
    public const EXPOSED = 'exposed';
    public const UNRESOLVED = 'unresolved';

    public function __construct(private readonly HttpProbeTarget $target)
    {
    }

    /**
     * @param string $rel ścieżka względem korzenia aplikacji
     * @param list<string> $needles fragmenty treści pliku; obecność w odpowiedzi = wyciek
     * @param bool $executable plik PHP: odpowiedź 2xx znaczy, że został wykonany
     * @return array{result: string, codes: list<int>, leaks: list<string>, errors: list<string>}
     */
    public function check(string $rel, array $needles, bool $executable = false): array
    {
        $codes = [];
        $leaks = [];
        $errors = [];
        foreach ($this->target->appRootPrefixes() as $prefix) {
            $path = $prefix . '/' . ltrim($rel, '/');
            $response = $this->get($path);
            if ($response === null) {
                $errors[] = $path;
                continue;
            }
            [$code, $body] = $response;
            $codes[] = $code;
            $leaked = false;
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($body, $needle)) {
                    $leaked = true;
                    break;
                }
            }
            // Plik PHP odpowiadający kodem 2xx został wykonany — router aplikacji odpowiada na takie adresy 404.
            if ($leaked || ($executable && $code >= 200 && $code < 300)) {
                $leaks[] = $path . ' (HTTP ' . $code . ')';
            }
        }

        $result = match (true) {
            $leaks !== []  => self::EXPOSED,
            $codes === []  => self::UNRESOLVED,
            default        => self::PROTECTED,
        };

        return ['result' => $result, 'codes' => array_values(array_unique($codes)), 'leaks' => $leaks, 'errors' => $errors];
    }

    /** @return array{0: int, 1: string}|null kod i treść; null przy błędzie połączenia */
    /**
     * Nagłówek `Cache-Control` odpowiedzi HTML — **zmierzony, nie zadeklarowany**.
     *
     * ⚠ `ini_get('session.cache_limiter')` mówi, co **ma się stać**; nagłówek odpowiedzi mówi,
     * co **się stało**. Ta sama zasada co przy `.htaccess`: deklaracja nie jest dowodem
     * (rozstrzygnięcie 22.09.2026). Pobieramy ekran logowania, bo jest publiczny — sonda nie
     * potrzebuje sesji ani uprawnień, a nagłówek na nim jest ten sam co na ekranach z danymi.
     *
     * @return array{naglowek: ?string, kod: ?int}
     */
    public function cacheControlHtml(): array
    {
        $ch = curl_init($this->target->origin . ($this->target->appRootPrefixes()[0] ?? '') . '/logowanie');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RESOLVE        => $this->target->curlResolve(),
            CURLOPT_USERAGENT      => 'wezurlop-diag',
        ]);
        $odp = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($odp) || $kod === 0) {
            return ['naglowek' => null, 'kod' => null];
        }
        // Nagłówek może przyjść wielokrotnie (PHP dokłada swój) — bierzemy wszystkie wystąpienia.
        preg_match_all('/^cache-control:\s*(.+)$/im', $odp, $m);
        $wartosci = array_map(static fn (string $w): string => trim($w), $m[1]);

        return ['naglowek' => $wartosci === [] ? null : implode(' | ', $wartosci), 'kod' => $kod];
    }

    private function get(string $path): ?array
    {
        $ch = curl_init($this->target->origin . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PATH_AS_IS     => true,
            CURLOPT_RESOLVE        => $this->target->curlResolve(),
            CURLOPT_USERAGENT      => 'wezurlop-diag',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return is_string($body) ? [$code, $body] : null;
    }
}
