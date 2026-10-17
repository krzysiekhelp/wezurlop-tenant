<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Odpowiedź HTTP: kod, nagłówki i treść, wysyłane w jednym miejscu.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Odpowiedź JSON (API connectora). Bez ucieczki ukośników i znaków Unicode — treść czytelna w logach.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Przekierowanie po zapisie lub przy braku dostępu. Dla htmx nagłówek HX-Redirect
     * (docs/szkielet-tenanta.md rozdz. 8), bo `Location` htmx wykonałby w tle i wstawił wynik w treść.
     */
    public static function redirectFor(Request $request, string $location): self
    {
        if ($request->isHtmx()) {
            return (new self('', 200))->withHeader('HX-Redirect', $location);
        }

        return self::redirect($location, 303);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headerValue(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
