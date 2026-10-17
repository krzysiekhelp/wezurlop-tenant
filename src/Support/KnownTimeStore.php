<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use ErrorException;

/**
 * Ostatni znany czas serwera (var/license-clock) — wykrywanie cofnięcia zegara przy licencji.
 */
final class KnownTimeStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function read(): ?int
    {
        try {
            $raw = is_file($this->file) ? ErrorGuard::run(fn (): string|false => file_get_contents($this->file)) : false;
        } catch (ErrorException) {
            return null;
        }

        return is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : null;
    }

    public function store(int $time): void
    {
        if ($this->read() === $time) {
            return;
        }
        try {
            ErrorGuard::run(fn (): int|false => file_put_contents($this->file, (string) $time, LOCK_EX));
        } catch (ErrorException) {
            // Brak zapisu osłabia tylko wykrywanie cofnięcia zegara; licencja działa dalej.
        }
    }
}
