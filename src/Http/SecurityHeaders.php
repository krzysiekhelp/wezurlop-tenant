<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Nagłówki bezpieczeństwa (CLAUDE.md §13). Wysyła je wyłącznie aplikacja — serwer WWW
 * nie może ich dokładać drugi raz.
 *
 * HSTS z konfiguracji, tylko po HTTPS, max-age=0 oznacza „nie wysyłaj”; preload nigdy.
 */
final class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    public function __construct(
        private readonly int $hstsMaxAge,
        private readonly bool $hstsIncludeSubdomains,
        private readonly bool $noindex = true,
    ) {
    }

    /** Wariant bez HSTS — dla odpowiedzi wysyłanych, zanim konfiguracja jest znana. */
    public static function withoutHsts(): self
    {
        return new self(0, false);
    }

    /** @return array<string, string> */
    public function headers(bool $https): array
    {
        $headers = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => self::CSP,
        ];
        if ($this->noindex) {
            // Instancja tenanta nie jest witryną — nic tu nie ma trafiać do wyszukiwarek.
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        if ($https && $this->hstsMaxAge > 0) {
            $headers['Strict-Transport-Security'] = 'max-age=' . $this->hstsMaxAge
                . ($this->hstsIncludeSubdomains ? '; includeSubDomains' : '');
        }

        return $headers;
    }

    public function send(bool $https): void
    {
        if (headers_sent()) {
            return;
        }
        foreach ($this->headers($https) as $name => $value) {
            header($name . ': ' . $value);
        }
        header_remove('X-Powered-By');
    }
}
