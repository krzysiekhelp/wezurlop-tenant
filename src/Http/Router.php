<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use ErrorException;
use FastRoute\BadRouteException;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Wezurlop\Support\ErrorGuard;

/**
 * Routing (docs/szkielet-tenanta.md rozdz. 3): trasy wyłącznie z config/routes.php,
 * jawnie, z wymaganym uprawnieniem albo jawnym poziomem uwierzytelnienia.
 *
 * Poziomy auth: 'none' (każdy), 'partial' (sesja co najmniej po haśle — także pełna),
 * 'full' (domyślny; wymaga 'permission'). 'exempt' => ['2fa', 'context'] — trasa dostępna przed
 * sparowaniem 2FA / przed wyborem firmy (ekran parowania, wybór firmy, wylogowanie).
 *
 * Domyślnie odmawiaj: trasa bez 'permission' i bez 'auth' => 'none'|'partial' jest błędem
 * konfiguracji — w trybie deweloperskim zatrzymuje start (RouteConfigurationException),
 * w produkcji odpowiada 403.
 */
final class Router
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const AUTH_LEVELS = [Route::AUTH_NONE, Route::AUTH_PARTIAL, Route::AUTH_FULL, Route::AUTH_API];
    private const PERMISSION_FORMAT = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @param list<Route> $routes */
    private function __construct(
        private readonly array $routes,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    /**
     * @param list<array<int|string, mixed>> $definitions zawartość config/routes.php
     * @param callable(string): bool $controllerExists czy kontroler jest znany fabryce
     * @param string|null $cacheFile skompilowane wyrażenia tras; null = bez cache
     * @throws RouteConfigurationException w trybie ścisłym, przy pierwszej błędnej trasie
     */
    public static function fromDefinitions(
        array $definitions,
        callable $controllerExists,
        bool $strict,
        ?string $cacheFile = null,
        int $definitionsMtime = 0,
    ): self {
        $routes = [];
        foreach ($definitions as $index => $definition) {
            $route = self::validate($index, $definition, $controllerExists);
            if ($strict && !$route->isValid()) {
                throw new RouteConfigurationException($route->error ?? 'Błędna trasa');
            }
            $routes[] = $route;
        }

        return new self($routes, new GroupCountBasedDispatcher(
            self::dispatchData($routes, $strict, $cacheFile, $definitionsMtime)
        ));
    }

    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher->dispatch($method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch(
                RouteMatch::FOUND,
                $path,
                $this->routes[(int) $result[1]],
                array_map('strval', $result[2]),
            ),
            Dispatcher::METHOD_NOT_ALLOWED => new RouteMatch(
                RouteMatch::METHOD_NOT_ALLOWED,
                $path,
                null,
                [],
                array_values($result[1]),
            ),
            default => new RouteMatch(RouteMatch::NOT_FOUND, $path),
        };
    }

    /** Czy istnieje trasa o tej ścieżce (dowolna metoda) — dla adresów wariantu mobilnego. */
    public function hasPath(string $path): bool
    {
        foreach (['GET', 'POST'] as $method) {
            if ($this->dispatcher->dispatch($method, $path)[0] !== Dispatcher::NOT_FOUND) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $definition
     * @param callable(string): bool $controllerExists
     */
    private static function validate(int|string $index, mixed $definition, callable $controllerExists): Route
    {
        $label = 'Trasa #' . $index;
        if (!is_array($definition)) {
            return self::invalid($label . ': definicja musi być tablicą');
        }

        $method = $definition[0] ?? null;
        $path = $definition[1] ?? null;
        $handler = $definition[2] ?? null;
        $auth = $definition['auth'] ?? Route::AUTH_FULL;
        $permission = $definition['permission'] ?? null;
        $exempt = $definition['exempt'] ?? [];

        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            return self::invalid($label . ': nieobsługiwana metoda HTTP');
        }
        if (!is_string($path) || !str_starts_with($path, '/')) {
            return self::invalid($label . ': ścieżka musi zaczynać się od „/”');
        }
        $label = 'Trasa ' . $method . ' ' . $path;

        $unknownKeys = array_diff(array_keys($definition), [0, 1, 2, 'auth', 'permission', 'exempt', 'zakres']);
        if ($unknownKeys !== []) {
            return self::invalid($label . ': nieznane klucze: ' . implode(', ', array_map('strval', $unknownKeys)), $method, $path);
        }
        if (!is_array($handler) || count($handler) !== 2 || !is_string($handler[0] ?? null) || !is_string($handler[1] ?? null)) {
            return self::invalid($label . ': obsługa musi mieć postać [Kontroler::class, \'akcja\']', $method, $path);
        }
        [$controller, $action] = $handler;
        if (!$controllerExists($controller) || !method_exists($controller, $action)) {
            return self::invalid($label . ': nieznany kontroler lub akcja ' . $controller . '::' . $action, $method, $path);
        }
        if (!is_string($auth) || !in_array($auth, self::AUTH_LEVELS, true)) {
            return self::invalid($label . ': „auth” musi być jednym z: none, partial, full, api', $method, $path);
        }
        if ($permission !== null && (!is_string($permission) || preg_match(self::PERMISSION_FORMAT, $permission) !== 1)) {
            return self::invalid($label . ': błędny kod uprawnienia', $method, $path);
        }

        // ⚠ **Zakres ekranu jest obowiązkowy dla tras z pełną sesją** i zakazany dla pozostałych
        // (zlecenie 10.10.2026 §1): znacznik spółki w belce bierze się z tej deklaracji, więc
        // trasa bez niej znaczyłaby ekran, na którym znacznik zgaduje. Domyślnie odmawiaj —
        // tak samo jak przy uprawnieniu.
        $zakres = $definition['zakres'] ?? null;
        if ($auth === Route::AUTH_FULL) {
            if (!is_string($zakres) || !in_array($zakres, Route::ZAKRESY, true)) {
                return self::invalid($label . ': brak „zakres” — podaj jeden z: '
                    . implode(', ', Route::ZAKRESY), $method, $path);
            }
        } elseif ($zakres !== null) {
            return self::invalid($label . ': „zakres” nie łączy się z \'auth\' => \'' . $auth . '\'',
                $method, $path);
        }

        if (!is_array($exempt) || !array_is_list($exempt)
            || array_diff($exempt, Route::EXEMPTIONS) !== [] || count(array_unique($exempt)) !== count($exempt)) {
            return self::invalid($label . ': „exempt” musi być listą z: ' . implode(', ', Route::EXEMPTIONS), $method, $path);
        }
        if ($exempt !== [] && $auth === Route::AUTH_NONE) {
            return self::invalid($label . ': „exempt” nie dotyczy tras z \'auth\' => \'none\'', $method, $path);
        }

        // Domyślnie odmawiaj: poziom „full” wymaga uprawnienia; „none” i „partial” to jawne deklaracje
        // i nie łączą się z uprawnieniem (anonim ani sesja przed 2FA uprawnień nie mają).
        // API: klucz instalacji i jawne uprawnienie api.* — domyślnie odmawiaj tak samo jak dla sesji.
        if ($auth === Route::AUTH_API) {
            if (!is_string($permission) || !str_starts_with($permission, 'api.') || $exempt !== [] || !str_starts_with($path, '/api/')) {
                return self::invalid($label . ': trasa API wymaga ścieżki /api/…, uprawnienia api.* i nie ma wyjątków', $method, $path);
            }

            return new Route($method, $path, $controller, $action, $auth, $permission, null, []);
        }
        if (str_starts_with($path, '/api/')) {
            return self::invalid($label . ': ścieżki /api/… są zarezerwowane dla tras z \'auth\' => \'api\'', $method, $path);
        }
        if ($auth === Route::AUTH_FULL && $permission === null) {
            return self::invalid($label . ': brak „permission” — trasa wymaga uprawnienia albo jawnego \'auth\' => \'none\'', $method, $path);
        }
        if ($auth !== Route::AUTH_FULL && $permission !== null) {
            return self::invalid($label . ': „permission” nie łączy się z \'auth\' => \'' . $auth . '\'', $method, $path);
        }

        return new Route($method, $path, $controller, $action, $auth, $permission, null,
            array_values($exempt), is_string($zakres) ? $zakres : null);
    }

    private static function invalid(string $error, string $method = 'GET', string $path = '/__invalid__'): Route
    {
        return new Route($method, $path, '', '', Route::AUTH_FULL, null, $error);
    }

    /**
     * @param list<Route> $routes
     * @return array<int, mixed>
     */
    private static function dispatchData(array $routes, bool $strict, ?string $cacheFile, int $definitionsMtime): array
    {
        if ($cacheFile !== null && is_file($cacheFile) && filemtime($cacheFile) > $definitionsMtime) {
            $cached = self::readCache($cacheFile, count($routes));
            if ($cached !== null) {
                return $cached;
            }
        }

        $collector = new RouteCollector(new Std(), new GroupCountBasedGenerator());
        $seen = [];
        foreach ($routes as $index => $route) {
            $key = $route->describe();
            if ($route->path === '/__invalid__' || isset($seen[$key])) {
                // Duplikat: w trybie ścisłym błąd, w produkcji obowiązuje pierwsza definicja.
                if ($strict && isset($seen[$key])) {
                    throw new RouteConfigurationException('Trasa ' . $key . ': zdefiniowana więcej niż raz');
                }
                continue;
            }
            try {
                $collector->addRoute($route->method, $route->path, $index);
            } catch (BadRouteException $e) {
                if ($strict) {
                    throw new RouteConfigurationException('Trasa ' . $key . ': ' . $e->getMessage(), 0, $e);
                }
                continue;
            }
            $seen[$key] = true;
        }
        $data = $collector->getData();

        if ($cacheFile !== null) {
            self::writeCache($cacheFile, count($routes), $data);
        }

        return $data;
    }

    /** @return array<int, mixed>|null */
    private static function readCache(string $cacheFile, int $routeCount): ?array
    {
        try {
            $cached = ErrorGuard::run(static fn (): mixed => require $cacheFile);
        } catch (ErrorException) {
            return null;
        }

        return is_array($cached) && ($cached['count'] ?? null) === $routeCount && is_array($cached['data'] ?? null)
            ? $cached['data']
            : null;
    }

    /** @param array<int, mixed> $data */
    private static function writeCache(string $cacheFile, int $routeCount, array $data): void
    {
        $content = "<?php\n\n// Wygenerowane przez Http\\Router z config/routes.php — nie edytować.\n\nreturn "
            . var_export(['count' => $routeCount, 'data' => $data], true) . ";\n";
        $temp = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            // Zapis do pliku tymczasowego i atomowa podmiana — żadne żądanie nie wczyta połowy pliku.
            ErrorGuard::run(static function () use ($temp, $cacheFile, $content): void {
                file_put_contents($temp, $content, LOCK_EX);
                rename($temp, $cacheFile);
            });
        } catch (ErrorException) {
            // Brak cache nie jest błędem — trasy zostaną skompilowane przy kolejnym żądaniu.
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
