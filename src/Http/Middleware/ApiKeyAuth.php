<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Service\Sync\ApiError;

/**
 * Uwierzytelnienie connectora kluczem instalacji (docs/api-connector.md §2, CLAUDE.md §0).
 *
 * - klucz z `Authorization: Bearer`, a gdy go brak — z `X-Api-Key` (część hostingów wycina Authorization),
 * - w bazie wyłącznie SHA-256; porównanie hash_equals; klucz nigdy w logu ani w komunikacie,
 * - trasa musi deklarować uprawnienie z listy uprawnień klucza (domyślnie odmawiaj),
 * - osobny limit częstotliwości klucza (120/min), niezależny od limitów logowania,
 * - X-Protokol: brak = 1; inna wartość niż obsługiwana → 409.
 * Bez sesji i bez CSRF — to nie przeglądarka.
 */
final class ApiKeyAuth implements Middleware
{
    public const ATTRIBUTE = 'api_key';
    public const PROTOCOL_VERSIONS = [1];
    private const PERMISSIONS = ['api.sync'];
    private const PER_MINUTE = 120;

    public function __construct(private readonly ApiKeyRepository $keys)
    {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $key = self::extractKey($request);
        if ($key === null) {
            throw new ApiError(401, 'KLUCZ_NIEPRAWIDLOWY', 'Brak albo niepoprawny klucz instalacji');
        }
        $hash = hash('sha256', $key);
        $row = $this->keys->findByHash($hash);
        if ($row === null || !hash_equals($row['key_hash'], $hash)) {
            throw new ApiError(401, 'KLUCZ_NIEPRAWIDLOWY', 'Brak albo niepoprawny klucz instalacji');
        }
        // ⚠ Kod odpowiedzi zostaje bez zmian (connector go zna), ale sam kod nie mówi, CO się stało.
        // Powód jedzie jako osobne pole — jak `powod` przy 409 — żeby administrator u klienta dostał
        // prawdziwy komunikat zamiast „klucz wygasł” przy kluczu unieważnionym ręcznie
        // (docs/system-wizualny.md §5.6 w duchu, docs/api-connector.md „Klucz instalacji”).
        if ($row['status'] !== 'active') {
            // ⚠ Klucz zastąpiony nowym dostaje KLUCZ_WYGASL — connector ten kod zna i na nim się
            // zatrzymuje. Klucz unieważniony ręcznie to inna sytuacja i inny komunikat.
            throw $row['replaced_by_id'] !== null
                ? new ApiError(401, 'KLUCZ_WYGASL',
                    'Klucz został zastąpiony nowym, który przejął synchronizację — wpisz nowy klucz w konfiguracji connectora',
                    ['powod' => 'zastapiony_nowym_kluczem'])
                : new ApiError(401, 'KLUCZ_NIEPRAWIDLOWY',
                    'Klucz unieważniony przez administratora — poproś o wydanie nowego',
                    ['powod' => 'uniewazniony_przez_administratora']);
        }
        if (!in_array($route->permission, self::PERMISSIONS, true)) {
            throw new ApiError(403, 'BRAK_UPRAWNIEN', 'Operacja niedostępna dla tego klucza');
        }
        if ($this->keys->hit($row['id'], 'api', 60) > self::PER_MINUTE) {
            throw new ApiError(429, 'ZA_SZYBKO', 'Zwolnij', [], ['Retry-After' => (string) (60 - time() % 60)]);
        }
        $protocol = $request->header('X-Protokol');
        if ($protocol !== null && trim($protocol) !== '' && !in_array(trim($protocol), array_map('strval', self::PROTOCOL_VERSIONS), true)) {
            throw new ApiError(409, 'WERSJA_PROTOKOLU', 'Nieobsługiwana wersja', ['obslugiwane' => self::PROTOCOL_VERSIONS]);
        }
        $this->keys->touch($row['id']);

        return $next($request->withAttribute(self::ATTRIBUTE, ['id' => $row['id'], 'name' => $row['name']]));
    }

    /** 64 znaki heksadecymalne; nagłówek Authorization przywracany przez .htaccess pod CGI (HTTP_AUTHORIZATION). */
    private static function extractKey(Request $request): ?string
    {
        $auth = $request->serverValue('HTTP_AUTHORIZATION') ?? $request->serverValue('REDIRECT_HTTP_AUTHORIZATION');
        $candidate = null;
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) === 1) {
            $candidate = $m[1];
        }
        $candidate ??= $request->header('X-Api-Key');

        return is_string($candidate) && preg_match('/^[0-9a-f]{64}$/', $candidate) === 1 ? $candidate : null;
    }
}
