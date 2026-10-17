<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Żądanie HTTP. Jedyne miejsce, które przepisuje superglobale do obiektu —
 * reszta aplikacji dostaje dane przez tę klasę.
 */
final class Request
{
    /** @var array<string, mixed> dane ustalone przez middleware (np. zalogowany użytkownik) */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        private readonly array $server,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE);
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function method(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        return is_string($method) ? strtoupper($method) : 'GET';
    }

    public function serverValue(string $key): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function serverParams(): array
    {
        return $this->server;
    }

    public function header(string $name): ?string
    {
        return $this->serverValue('HTTP_' . strtoupper(str_replace('-', '_', $name)));
    }

    /**
     * Żądanie wysłane przez htmx. Wyłącznie do wyboru formy odpowiedzi (fragment, HX-Redirect) —
     * nigdy do decyzji o uprawnieniach; nagłówek może wysłać każdy (CLAUDE.md §5).
     */
    public function isHtmx(): bool
    {
        return $this->header('HX-Request') === 'true';
    }

    public function post(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    /** Pole formularza jako tekst; pusty, gdy brak, nie jest tekstem albo przekracza limit (CLAUDE.md §11). */
    public function postString(string $key, int $maxLength): string
    {
        $value = $this->body[$key] ?? null;
        if (!is_string($value) || mb_strlen($value) > $maxLength || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        return $value;
    }

    public function query(string $key): mixed
    {
        return $this->query[$key] ?? null;
    }

    /**
     * Parametr zapytania jako tekst — ten sam warunek co przy polu formularza: zły typ, za długi
     * albo z uszkodzonym kodowaniem daje pusty string, nie wyjątek i nie wartość „jakąś” (CLAUDE.md §11).
     */
    public function queryString(string $key, int $maxLength): string
    {
        $value = $this->query[$key] ?? null;
        if (!is_string($value) || mb_strlen($value) > $maxLength || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        return $value;
    }

    public function cookie(string $key): mixed
    {
        return $this->cookies[$key] ?? null;
    }

    /**
     * Ścieżka trasy (docs/szkielet-tenanta.md rozdz. 3).
     *
     * Źródło podstawowe to REQUEST_URI; PATH_INFO tylko wtedy, gdy serwer nie ustawia
     * REQUEST_URI. Pod FastCGI PATH_INFO bywa ustawiane także przy działającym przepisywaniu
     * adresów, więc nie może mieć pierwszeństwa. Front controller (APP_FRONT_CONTROLLER, zawsze też
     * /index.php) odcinany jawnie — wariant bez przepisywania: /public/index.php/logowanie.
     */
    public function path(string $basePath, string $frontController = ''): string
    {
        $uri = $this->serverValue('REQUEST_URI') ?? '';

        if ($uri !== '') {
            $path = parse_url($uri, PHP_URL_PATH);
            $path = is_string($path) ? $path : '/';
            $path = $this->stripBasePath($path, $basePath);

            foreach (array_unique(array_filter([$frontController, '/index.php'])) as $script) {
                if ($path === $script || str_starts_with($path, $script . '/')) {
                    $path = substr($path, strlen($script));
                    break;
                }
            }
        } else {
            $path = $this->serverValue('PATH_INFO') ?? $this->serverValue('ORIG_PATH_INFO') ?? '/';
        }

        if ($path === '') {
            return '/';
        }

        // /m/ i /kalendarz/ trafiają w te same trasy co /m i /kalendarz.
        return strlen($path) > 1 ? rtrim($path, '/') ?: '/' : $path;
    }

    private function stripBasePath(string $path, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        if ($basePath === '') {
            return $path;
        }
        if ($path === $basePath) {
            return '/';
        }
        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }
}
