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
