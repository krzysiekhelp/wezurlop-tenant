<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\DatabaseSessionHandler;
use Wezurlop\Http\Request;
use Wezurlop\Support\NazwaOsoby;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Security\AuthSession;

/**
 * Ustalenie zalogowanego użytkownika (docs/szkielet-tenanta.md rozdz. 4, pkt 3).
 *
 * - konto usunięte, nieaktywne albo z hasłem zmienionym po zalogowaniu → sesja unieważniona,
 * - sesja częściowa (przed 2FA): 5 minut, tylko trasy 'partial' i 'none',
 * - sesja pełna: CurrentUser w atrybucie żądania; uprawnienia dokłada Authorize.
 */
final class Authenticate implements Middleware
{
    public const USER = 'user';
    public const PARTIAL_ACCOUNT = 'partial_account_id';

    public function __construct(
        private readonly AuthSession $auth,
        private readonly AccountRepository $accounts,
        private readonly int $partialTimeout,
        private readonly string $loginUrl,
        private readonly string $secondFactorUrl,
        private readonly DatabaseSessionHandler $sessionHandler,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $accountId = $this->auth->accountId();
        $level = $this->auth->level();
        if ($accountId === null || $level === null) {
            // Ciasteczko wskazywało na sesję unieważnioną (rejestr sesji sprawdzany przy każdym żądaniu).
            $reason = match ($this->sessionHandler->rejectedReason()) {
                DatabaseSessionHandler::REJECTED_REVOKED => $this->blokadaKonta()
                    ? 'konto-zablokowane'
                    : 'sesja-zakonczona',
                DatabaseSessionHandler::REJECTED_EXPIRED => 'wygasla',
                default                                  => null,
            };

            return $route->requiresAuthentication() ? $this->toLogin($request, $reason) : $next($request);
        }

        $account = $this->accounts->findById($accountId);
        $changedAt = AccountRepository::timestamp($account['password_changed_at'] ?? null);
        if ($account === null || $account['status'] !== 'active' || $changedAt >= $this->auth->authenticatedAt()) {
            // ⚠ Blokada w trakcie pracy wychodzi osobnym powodem, bo to jedyny z tych przypadków,
            // w którym użytkownik NIC nie zrobił: nie zmieniał hasła, nie kończył sesji, nie czekał
            // do wygaśnięcia. „Sesja jest nieważna" kazałoby mu zalogować się ponownie i próbować
            // dalej — a hasło ma dobre, więc odbiłby się o „Nieprawidłowy login lub hasło" i szukał
            // błędu u siebie. Powód musi wskazać administratora, nie klawiaturę.
            //
            // ⚠ Warunek jest wąski celowo: TYLKO `blocked` i TYLKO dla sesji, która do tej sekundy
            // działała. Konto `invited`/`niezaproszone` z jakąś sesją to nie blokada, więc zostaje
            // przy dotychczasowym komunikacie.
            $powod = ($account !== null && $account['status'] === AccountRepository::STAN_ZABLOKOWANE)
                ? 'konto-zablokowane'
                : 'sesja-niewazna';
            $this->auth->logout();

            return $route->requiresAuthentication() ? $this->toLogin($request, $powod) : $next($request);
        }

        if ($level === AuthSession::PARTIAL) {
            if (time() - $this->auth->partialStartedAt() > $this->partialTimeout) {
                $this->auth->logout();

                return $route->requiresAuthentication() ? $this->toLogin($request, 'wygasla') : $next($request);
            }
            if (!$route->requiresAuthentication()) {
                return $next($request);
            }
            if (!$route->allowsPartial()) {
                // Sesja po samym haśle nie otwiera niczego poza ekranem kodu i wylogowaniem.
                return Response::redirectFor($request, $this->secondFactorUrl);
            }

            return $next($request->withAttribute(self::PARTIAL_ACCOUNT, $accountId));
        }

        $user = new CurrentUser(
            $accountId,
            NazwaOsoby::imieNazwisko((string) $account['first_name'], (string) $account['last_name']),
            $account['totp_enabled_at'] !== null,
            0,
        );

        return $next($request->withAttribute(self::USER, $user));
    }

    /**
     * Czy odrzuconą sesję zabrała blokada konta (zlecenie `blokady-przyjete-i-etap6` §4).
     *
     * ⚠ Zmierzone, nie założone: **obie** drogi blokady (ręczna i z synchronizacji) unieważniają
     * sesje w tym samym zapytaniu, w którym przestawiają status. Dlatego żądanie zablokowanego
     * użytkownika odbija się o REJESTR SESJI, a nie o status konta niżej — i bez tego sprawdzenia
     * dostawał „Ta sesja została zakończona (np. z innego urządzenia)", czyli komunikat
     * mówiący nieprawdę o przyczynie.
     *
     * ⚠ To nie jest decyzja o dostępie: sesja jest już odrzucona, a jedyne, co stąd wynika,
     * to treść zdania na ekranie logowania. Zwykłe logowanie tej ścieżki nie dotyka —
     * tam zablokowane konto i błędne hasło dają ten sam komunikat (CLAUDE.md §9).
     */
    private function blokadaKonta(): bool
    {
        $accountId = $this->sessionHandler->rejectedAccountId();
        if ($accountId === null) {
            return false;
        }
        $account = $this->accounts->findById($accountId);

        return $account !== null && $account['status'] === AccountRepository::STAN_ZABLOKOWANE;
    }

    private function toLogin(Request $request, ?string $reason = null): Response
    {
        return Response::redirectFor($request, $this->loginUrl . ($reason !== null ? '?powod=' . $reason : ''));
    }
}
