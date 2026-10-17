<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use RuntimeException;

/**
 * Dostęp do sesji uruchomionej przez SessionStarter. Jedyne miejsce dotykające $_SESSION.
 */
final class Session
{
    public function get(string $key): mixed
    {
        $this->assertActive();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertActive();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->assertActive();

        return array_key_exists($key, $_SESSION);
    }

    /** Jawny identyfikator bieżącej sesji — tylko do porównań i haszowania, nigdy do zapisu. */
    public function id(): string
    {
        $this->assertActive();

        return session_id() ?: '';
    }

    /**
     * Odczyt bez wymogu aktywnej sesji — dla handlera w trakcie zapisu (session_write_close).
     */
    public function peek(string $key): mixed
    {
        return isset($_SESSION) && is_array($_SESSION) ? ($_SESSION[$key] ?? null) : null;
    }

    /** Nowy identyfikator sesji z usunięciem starego pliku (ochrona przed fixation, CLAUDE.md §8). */
    public function regenerate(): void
    {
        $this->assertActive();
        session_regenerate_id(true);
    }

    /** Wyczyszczenie danych i nowy identyfikator — sesja trwa dalej jako anonimowa. */
    public function reset(): void
    {
        $this->assertActive();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Sesja nie została uruchomiona');
        }
    }
}
