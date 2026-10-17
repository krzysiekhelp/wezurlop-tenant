<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use Wezurlop\Http\Session;

/**
 * Token CSRF przechowywany w sesji (CLAUDE.md §7). Weryfikacja wyłącznie przez hash_equals.
 */
final class CsrfToken
{
    private const SESSION_KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(mixed $sent): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($sent) && $sent !== ''
            && is_string($expected) && $expected !== ''
            && hash_equals($expected, $sent);
    }
}
