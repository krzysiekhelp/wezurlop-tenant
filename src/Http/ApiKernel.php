<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use PDOException;
use Throwable;
use Wezurlop\Controller\Api\ApiErrorResponse;
use Wezurlop\Controller\Api\SyncController;
use Wezurlop\Http\Middleware\ApiKeyAuth;
use Wezurlop\Service\Sync\ApiError;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Obsługa tras /api/… (docs/api-connector.md §5): pełnoprawne trasy z config/routes.php, ale bez sesji
 * i bez CSRF, z własnym middleware klucza. Każda odpowiedź — także błąd i 404 — jest JSON-em
 * bez szczegółów technicznych; szczegóły w logu pod tym samym zdarzenieId.
 */
final class ApiKernel
{
    public const EVENT_TRANSIENT = 'api.transient_error';
    private const RETRY_AFTER = 30;
    /** Kody MySQL/MariaDB oznaczające stan chwilowy: brak połączenia, zbyt wiele połączeń, blokada, zakleszczenie. */
    private const TRANSIENT_DRIVER_CODES = [1040, 1205, 1213, 2002, 2006, 2013];

    public function __construct(
        private readonly Services $services,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request, string $path, Router $router): Response
    {
        $started = hrtime(true);
        $response = $this->dispatch($request, $path, $router);
        // Czas obsługi każdego żądania API w logu: bez tego nie da się zmierzyć paczek po naszej stronie
        // ani pokazać klientowi, czy 30-sekundowy limit jest blisko (CLAUDE.md §15 — logi mają być bogate).
        $this->logger->info('API connectora: żądanie', [
            'operacja' => self::operation($path), 'metoda' => $request->method(),
            'status' => $response->status(), 'ms' => (int) round((hrtime(true) - $started) / 1e6),
        ]);

        return $response;
    }

    private function dispatch(Request $request, string $path, Router $router): Response
    {
        try {
            $match = $router->match($request->method(), $path);
            if ($match->status === RouteMatch::METHOD_NOT_ALLOWED) {
                throw new ApiError(405, 'METODA_NIEDOZWOLONA', 'Niedozwolona metoda', [], ['Allow' => implode(', ', $match->allowedMethods)]);
            }
            $route = $match->route;
            if ($match->status !== RouteMatch::FOUND || $route === null || !$route->isApi() || !$route->isValid()) {
                throw new ApiError(404, 'NIE_ZNALEZIONO', 'Nieznana operacja');
            }
            $controller = new SyncController($this->services->syncService(), $this->services->syncRepository(), $this->logger);
            $auth = new ApiKeyAuth($this->services->apiKeys);

            // Akcja pochodzi z config/routes.php (zwalidowana: method_exists), nigdy z adresu (CLAUDE.md §3).
            return $auth->process($request, $route, static fn (Request $r): Response => $controller->{$route->action}($r, $match->params));
        } catch (ApiError $e) {
            $eventId = bin2hex(random_bytes(4));
            $this->logger->warning('API connectora: odmowa',
                ['zdarzenie' => $eventId, 'sciezka' => $path, 'kod' => $e->kod, 'status' => $e->status] + $e->log);

            return ApiErrorResponse::from($e, $eventId);
        } catch (Throwable $e) {
            $eventId = bin2hex(random_bytes(4));
            if (self::isTransient($e)) {
                // Baza chwilowo niedostępna albo zakleszczenie — paczka wycofana w całości, connector ponowi
                // z tym samym X-Idempotency-Key (odpowiedź nie została zapamiętana).
                $this->logger->warning('API connectora: przerwa techniczna', ['zdarzenie' => $eventId, 'sciezka' => $path, 'error' => $e->getMessage()]);
                $this->recordTransient($eventId, $path);

                return ApiErrorResponse::from(new ApiError(503, 'PRZERWA_TECHNICZNA', 'Baza zajęta, powtórz paczkę', [],
                    ['Retry-After' => (string) self::RETRY_AFTER]), $eventId);
            }
            $this->logger->error('API connectora: błąd', [
                'zdarzenie' => $eventId, 'sciezka' => $path, 'exception' => $e::class, 'error' => $e->getMessage(),
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            return ApiErrorResponse::from(new ApiError(500, 'BLAD_WEWNETRZNY', 'Błąd po stronie serwera — spróbuj ponownie'), $eventId);
        }
    }

    /**
     * Licznik 503 dla /diagnostyka (event_log). Osobne polecenie po wycofaniu transakcji paczki; gdy baza
     * leży całkiem, zostaje sam wpis w logu pliku — tu nic nie wolno już rzucić.
     */
    private function recordTransient(string $eventId, string $path): void
    {
        try {
            $this->services->events->record(self::EVENT_TRANSIENT, null, null, 'api', null, null, null, ['zdarzenie' => $eventId, 'sciezka' => $path]);
        } catch (Throwable $e) {
            $this->logger->warning('API connectora: nie zapisano licznika przerwy technicznej', ['zdarzenie' => $eventId, 'error' => $e->getMessage()]);
        }
    }

    /** Nazwa operacji z adresu — do logu, nigdy do dispatchowania (CLAUDE.md §3). */
    private static function operation(string $path): string
    {
        return preg_match('#^/api/v1/sync/([a-z-]+)#', $path, $m) === 1 ? $m[1] : 'nieznana';
    }

    public static function isTransient(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof PDOException) {
                $driverCode = (int) ($x->errorInfo[1] ?? 0);
                $state = (string) ($x->errorInfo[0] ?? $x->getCode());

                return in_array($driverCode, self::TRANSIENT_DRIVER_CODES, true) || str_starts_with($state, '08')
                    || preg_match('/\[(1040|1205|1213|2002|2006|2013)\]/', $x->getMessage()) === 1;
            }
        }

        return false;
    }
}
