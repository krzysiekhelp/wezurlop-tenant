<?php

declare(strict_types=1);

namespace Wezurlop\Security;

/**
 * Tokeny jednorazowe z linków: random_bytes(32), do bazy wyłącznie hash SHA-256.
 */
final class OneTimeToken
{
    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /** Wstępna walidacja formatu, zanim token trafi do zapytania. */
    public static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
