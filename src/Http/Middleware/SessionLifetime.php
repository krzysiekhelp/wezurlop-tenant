<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Http\Session;

/**
 * Limit bezczynności i absolutny czas życia sesji (CLAUDE.md §8).
 *
 * Wygasła sesja zalogowana → wyczyszczenie, nowy identyfikator i ekran logowania z komunikatem.
 * Wygasła sesja anonimowa → wyczyszczenie po cichu (nie ma kogo wylogować).
 */
final class SessionLifetime implements Middleware
{
    private const CREATED = '_created_at';
    private const LAST_ACTIVITY = '_last_activity';
    /** Klucz ustawiany przy logowaniu (M5); jego obecność oznacza sesję uwierzytelnioną. */
    private const ACCOUNT = 'account_id';
    private const ACTIVITY_RESOLUTION = 60;

    public function __construct(
        private readonly Session $session,
        private readonly int $idleTimeout,
        private readonly int $absoluteTimeout,
        private readonly string $loginUrl,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $now = time();
        $created = $this->session->get(self::CREATED);
        $lastActivity = $this->session->get(self::LAST_ACTIVITY);

        $expired = is_int($created) && is_int($lastActivity)
            && ($now - $lastActivity > $this->idleTimeout || $now - $created > $this->absoluteTimeout);

        if ($expired) {
            $wasAuthenticated = $this->session->get(self::ACCOUNT) !== null;
            $this->session->reset();
            $this->start($now);
            if ($wasAuthenticated) {
                return Response::redirectFor($request, $this->loginUrl . '?powod=wygasla');
            }
        } elseif (!is_int($created)) {
            $this->start($now);
        }

        // Najwyżej raz na minutę — inaczej dane sesji zmieniałyby się przy każdym żądaniu
        // i session.lazy_write nie miałby czego oszczędzać (zapis do bazy na każde żądanie).
        $last = $this->session->get(self::LAST_ACTIVITY);
        if (!is_int($last) || $now - $last >= self::ACTIVITY_RESOLUTION) {
            $this->session->set(self::LAST_ACTIVITY, $now);
        }

        return $next($request);
    }

    private function start(int $now): void
    {
        $this->session->set(self::CREATED, $now);
        $this->session->set(self::LAST_ACTIVITY, $now);
    }
}
