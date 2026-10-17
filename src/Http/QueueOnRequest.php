<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Throwable;
use Wezurlop\Service\JobPriority;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Drugi wyzwalacz kolejki, gdy instalator nie potwierdził crona częstszego niż 15 minut
 * (CLAUDE.md §0, docs/profile-hostingu.md): po wysłaniu odpowiedzi — jedno pilne zadanie
 * (link z terminem ważności), budżet ~2 s, lock. Nie zastępuje crona.
 *
 * ⚠ Bez fastcgi_finish_request() (CGI, uWSGI — obie usługi home.pl) przeglądarka czeka na zamknięcie
 * połączenia, dlatego budżet jest mały i dotyczy wyłącznie zadań pilnych.
 */
final class QueueOnRequest
{
    public const BUDGET_SECONDS = 2.0;

    public function __construct(
        private readonly Services $services,
        private readonly Logger $logger,
    ) {
    }

    public function run(): void
    {
        try {
            // Tani test przed czymkolwiek innym — zwykle nie ma nic do zrobienia.
            if (!$this->services->jobs->hasDue(JobPriority::URGENT)) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }

            $lock = fopen($this->services->config->varPath . '/queue-on-request.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            try {
                $stats = $this->services->jobRunner($this->logger)->runUrgent(self::BUDGET_SECONDS, 1);
                $this->logger->info('Kolejka po odpowiedzi', $stats);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable $e) {
            // Odpowiedź już wysłana — błąd tylko do logu; zadanie zostaje w kolejce dla crona.
            $this->logger->error('Kolejka po odpowiedzi: błąd', ['exception' => $e::class, 'error' => $e->getMessage()]);
        }
    }
}
