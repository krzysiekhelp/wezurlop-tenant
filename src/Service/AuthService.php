<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;

/**
 * Logowanie hasłem (docs/szkielet-tenanta.md rozdz. 6).
 *
 * Wynik niczego nie zdradza: nieznany e-mail, złe hasło, konto zablokowane lub nieaktywne i przekroczony
 * limit prób dają ten sam rezultat, a przy nieznanym e-mailu hasło i tak jest weryfikowane.
 */
final class AuthService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly Password $password,
        private readonly RateLimiter $limiter,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * @param string $client rodzaj klienta (web/app) — wyłącznie do dziennika, nie do decyzji
     * @return array{account_id: int, two_factor: bool}|null null = „Nieprawidłowy login lub hasło”
     */
    public function attempt(string $email, #[\SensitiveParameter] string $password, string $ipHash, string $client = 'web'): ?array
    {
        $account = $this->accounts->findByEmail(self::normalizeEmail($email));
        $accountId = $account['id'] ?? null;

        $delay = $this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_PASSWORD);
        if ($delay > 0) {
            // Próba w trakcie opóźnienia: bez sprawdzania hasła i bez zapisu jako nieudana — nie wydłuża
            // opóźnienia, a konto nie zostaje zamknięte (CLAUDE.md §9). Weryfikacja „na próżno” dla czasu.
            $this->password->verifyDummy($password);
            $this->events->record('auth.login_delayed', $accountId, $ipHash, null, null, null, null, ['seconds' => $delay]);

            return null;
        }

        $valid = $this->password->verify($password, $account['password_hash'] ?? null);
        if (!$valid || $account === null || $account['status'] !== 'active') {
            $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_PASSWORD, false);
            $this->events->record('auth.login_failed', $accountId, $ipHash);

            return null;
        }

        if ($this->password->needsRehash((string) $account['password_hash'])) {
            $this->accounts->updatePasswordHash($account['id'], $this->password->hash($password));
        }
        $this->limiter->record($account['id'], $ipHash, RateLimiter::KIND_PASSWORD, true);
        $twoFactor = $account['totp_enabled_at'] !== null;
        if (!$twoFactor) {
            $this->accounts->touchLastLogin($account['id']);
            $this->events->record('auth.login_success', $account['id'], $ipHash, null, null, null, null, ['client' => $client]);
        } else {
            $this->events->record('auth.password_ok_2fa_pending', $account['id'], $ipHash, null, null, null, null, ['client' => $client]);
        }

        return ['account_id' => $account['id'], 'two_factor' => $twoFactor];
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
