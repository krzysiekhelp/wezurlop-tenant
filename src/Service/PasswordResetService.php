<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\PasswordResetTokenRepository;
use Wezurlop\Repository\SessionRepository;
use Wezurlop\Security\OneTimeToken;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Db\Connection;

/**
 * Reset hasła (docs/szkielet-tenanta.md rozdz. 6).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly AccountMailService $mail,
        private readonly RateLimiter $limiter,
        private readonly Password $password,
        private readonly EventLogRepository $events,
        private readonly Connection $db,
        private readonly SessionRepository $sessions,
    ) {
    }

    /**
     * Prośba o reset. Odpowiedź dla użytkownika zawsze ta sama; wysyłka tylko przez kolejkę.
     */
    public function request(string $email, string $ipHash): void
    {
        $account = $this->accounts->findByEmail(AuthService::normalizeEmail($email));
        $accountId = $account['id'] ?? null;
        $blocked = $this->limiter->isResetLimited($accountId, $ipHash);
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_RESET, !$blocked && $account !== null);

        if ($blocked || $account === null || $account['status'] !== 'active') {
            return;
        }
        $this->mail->queuePasswordReset($account['id'], $ipHash);
        $this->events->record('auth.password_reset_requested', $account['id'], $ipHash);
    }

    public function isTokenValid(mixed $token): bool
    {
        return $this->accountIdForToken($token) !== null;
    }

    /**
     * Konto z linku — **bez zużywania tokenu** (patrz `Service\LinkHasla`). Ta sama rola
     * co `InvitationService::accountIdForToken()`; obie ścieżki linku zachowują się tak samo.
     */
    public function accountIdForToken(mixed $token): ?int
    {
        return OneTimeToken::isWellFormed($token)
            ? $this->tokens->validAccountId(OneTimeToken::hash($token))
            : null;
    }

    /**
     * **Zmiana własnego hasła z aplikacji** (zlecenie Z69, decyzja Krzysztofa 21.10.2026 —
     * prawdziwa zmiana, nie wysłanie linku resetu).
     *
     * ⚠ Stoi w TEJ klasie, a nie w nowej: wszystko, czego potrzebuje — polityka hasła,
     * hashowanie, licznik prób, rejestr sesji, dziennik i transakcja — jest już tu, a dwa
     * miejsca ustawiające hasło to dwa miejsca, w których można zapomnieć o unieważnieniu sesji.
     *
     * ⚠ **Licznik prób per konto i adres** (`KIND_PASSWORD`, ten sam co przy logowaniu): formularz
     * za zalogowaną sesją jest wygodnym miejscem na zgadywanie hasła, jeśli ktoś dopadł
     * niezablokowanego komputera. Rośnie opóźnienie, nie ma twardej blokady (CLAUDE.md §9).
     *
     * ⚠ **Bieżąca sesja ZOSTAJE, pozostałe się kończą.** Wyjątek przekazujemy jako hash
     * identyfikatora; `null` znaczy „zakończ wszystkie" i tak właśnie działa reset z linku.
     * Samo `password_changed_at` nie wystarcza do utrzymania tej sesji — robi to
     * `AuthSession::odswiezPoZmianieHasla()` po stronie kontrolera.
     *
     * @return string|null komunikat odmowy albo null przy sukcesie
     */
    public function changeOwn(
        int $accountId,
        #[\SensitiveParameter] string $current,
        #[\SensitiveParameter] string $new,
        #[\SensitiveParameter] string $repeat,
        string $ipHash,
        ?string $exceptSessionHash,
    ): ?string {
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_PASSWORD) > 0) {
            return 'Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.';
        }
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return 'Nie udało się zmienić hasła.';
        }
        $ok = $this->password->verify($current, $account['password_hash']);
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_PASSWORD, $ok);
        if (!$ok) {
            // ⚠ Zdarzenie to samo, co przy nieudanym logowaniu hasłem, bo to jest ta sama próba:
            // ktoś podaje hasło i nie zgaduje. Osobny kod rozdzieliłby jeden atak na dwie listy.
            $this->events->record('auth.login_failed', $accountId, $ipHash, null, 'account', $accountId, null, ['via' => 'zmiana_hasla']);

            return 'Obecne hasło jest nieprawidłowe.';
        }
        if ($new !== $repeat) {
            return 'Nowe hasła nie są takie same.';
        }
        $policyError = $this->password->policyError($new);
        if ($policyError !== null) {
            return $policyError;
        }
        // ⚠ To samo hasło odrzucamy, zanim cokolwiek zapiszemy: zapis przestawiłby
        // `password_changed_at` i **wylogował pozostałe urządzenia** bez żadnej zmiany
        // poświadczeń — skutek bez przyczyny, najgorszy rodzaj niespodzianki.
        if ($this->password->verify($new, $account['password_hash'])) {
            return 'Nowe hasło musi być inne niż obecne.';
        }
        $hash = $this->password->hash($new);
        $this->db->transaction(function () use ($accountId, $hash, $exceptSessionHash): void {
            $this->accounts->setPassword($accountId, $hash);
            $this->tokens->revokeAllForAccount($accountId);
            $this->sessions->revokeAllForAccount($accountId, $exceptSessionHash);
        });
        $this->events->record('auth.password_changed', $accountId, $ipHash, null, 'account', $accountId, null, ['via' => 'konto']);

        return null;
    }

    /**
     * Ustawienie nowego hasła. Po zmianie: pozostałe sesje konta nieważne (password_changed_at),
     * niezużyte tokeny unieważnione, wpis w dzienniku.
     *
     * @return string|null komunikat błędu albo null przy sukcesie
     */
    public function complete(mixed $token, #[\SensitiveParameter] string $newPassword, string $ipHash): ?string
    {
        if (!OneTimeToken::isWellFormed($token)) {
            return 'Link jest nieprawidłowy lub wygasł.';
        }
        $policyError = $this->password->policyError($newPassword);
        if ($policyError !== null) {
            return $policyError;
        }
        $hash = $this->password->hash($newPassword);

        $accountId = $this->db->transaction(function () use ($token, $hash): ?int {
            $accountId = $this->tokens->consume(OneTimeToken::hash($token));
            if ($accountId === null) {
                return null;
            }
            $this->accounts->setPassword($accountId, $hash);
            $this->tokens->revokeAllForAccount($accountId);
            // Unieważnienie przez rejestr sesji; password_changed_at zostaje jako druga warstwa.
            $this->sessions->revokeAllForAccount($accountId);

            return $accountId;
        });
        if ($accountId === null) {
            return 'Link jest nieprawidłowy lub wygasł.';
        }
        $this->events->record('auth.password_changed', $accountId, $ipHash, null, 'account', $accountId, null, ['via' => 'reset']);

        return null;
    }
}
