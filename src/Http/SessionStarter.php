<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use RuntimeException;

/**
 * Start sesji z handlerem bazodanowym (CLAUDE.md §8, docs/szkielet-tenanta.md rozdz. 5).
 *
 * Plików sesji nie ma — znika problem współdzielonego session.save_path na hostingu.
 * GC PHP wyłączony: sprzątanie robi zadanie z kolejki.
 */
final class SessionStarter
{
    public function __construct(
        private readonly DatabaseSessionHandler $handler,
        private readonly int $gcMaxLifetime,
    ) {
    }

    public function start(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.lazy_write', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $https ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) $this->gcMaxLifetime);
        ini_set('session.gc_probability', '0');

        // ⚠ `Cache-Control: no-store, no-cache, must-revalidate` na KAŻDEJ odpowiedzi HTML
        // wysyła mechanizm sesji PHP (`session.cache_limiter = nocache`), nie nasz kod.
        // Zmierzone 22.09.2026: `ini_get('session.cache_limiter')` = `nocache`, a odpowiedź
        // `/logowanie` niosła dokładnie ten nagłówek.
        //
        // ⚠ **`no-store` zostaje świadomie, i ma swoją cenę: wyłącza bfcache** — cache
        // wstecz/dalej w przeglądarce. „Wstecz" zawsze odpytuje serwer, więc ekrany wracają
        // wolniej niż w innych aplikacjach i ktoś kiedyś zgłosi to jako „muli". Kupujemy tym
        // to, że **żaden ekran z danymi kadrowymi nie zostaje w cache'u dysku po wylogowaniu** —
        // na komputerze współdzielonym „wstecz" po wylogowaniu pokazałoby listę pracowników.
        // Przy zgłoszeniu „wolno działa wstecz" to jest zdanie do przeczytania PRZED
        // rozluźnieniem nagłówka (rozstrzygnięcie 22.09.2026).
        //
        // ⚠ **Ustawiamy to JAWNIE, a nie liczymy na domyślną wartość PHP** (rozstrzygnięcie
        // 22.09.2026, §13 reguł: nagłówki wysyła dokładnie jedna warstwa — nasza). Hosting
        // z `session.cache_limiter = public` w `php.ini` podałby ekrany z danymi kadrowymi jako
        // zasób publiczny — do cache'u przeglądarki i pośredników — a w naszym kodzie nie byłoby
        // niczego, co by to zauważyło. To ten sam wzorzec co `.env` odsłonięty przez jedną usługę
        // hostingową i chroniony przez drugą: **ochrona stojąca na cudzym domyśle nie jest
        // ochroną.** Wynik sprawdza `/diagnostyka` — POBRANIEM PO HTTP, nie przez `ini_get`.
        ini_set('session.cache_limiter', 'nocache');

        session_set_save_handler($this->handler, true);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Nie udało się uruchomić sesji');
        }
    }
}
