<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Http\Session;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\BackupCodeRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Security\BackupCodes;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Security\SecretBox;
use Wezurlop\Security\Totp;
use Wezurlop\Db\Connection;

/**
 * 2FA (TOTP) dla kont lokalnych (CLAUDE.md §9, docs/szkielet-tenanta.md rozdz. 6).
 *
 * - sekret generowany serwerowo, w bazie zaszyfrowany (SecretBox, APP_KEY),
 * - parowanie trwa w sesji (sekret zaszyfrowany także tam) i wymaga potwierdzenia kodem,
 * - kody zapasowe: 8, hashowane; jawne tylko w sesji do chwili potwierdzenia „zapisałem je” —
 *   jednorazowość pokazania rozstrzyga serwer, nie przeglądarka,
 * - w dzienniku zdarzeń nigdy kod ani sekret.
 */
final class TwoFactorService
{
    private const PENDING = '_2fa_pending';
    private const PENDING_TTL = 600;
    private const CODES = '_2fa_codes';

    /** Odmowa wyłączenia 2FA przy wymogu dla administratorów — jedno zdanie dla ekranu i serwera. */
    public const ODMOWA_WYMOGU = 'Weryfikacja dwuetapowa jest wymagana dla administratorów — nie można jej wyłączyć.';

    public function __construct(
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly BackupCodeRepository $backupCodes,
        private readonly Totp $totp,
        private readonly SecretBox $box,
        private readonly Password $password,
        private readonly RateLimiter $limiter,
        private readonly EventLogRepository $events,
        private readonly Connection $db,
    ) {
    }

    // --- Parowanie ------------------------------------------------------------------------------

    public function startPairing(int $accountId): void
    {
        $this->session->set(self::PENDING, [
            'account' => $accountId,
            'secret'  => $this->box->seal($this->totp->createSecret()),
            'at'      => time(),
        ]);
    }

    /** Sekret trwającego parowania; null, gdy parowania nie ma, wygasło albo dotyczy innego konta. */
    public function pendingSecret(int $accountId): ?string
    {
        $pending = $this->session->get(self::PENDING);
        if (!is_array($pending) || ($pending['account'] ?? null) !== $accountId
            || !is_int($pending['at'] ?? null) || time() - $pending['at'] > self::PENDING_TTL
            || !is_string($pending['secret'] ?? null)) {
            return null;
        }

        return $this->box->open($pending['secret']);
    }

    public function cancelPairing(): void
    {
        $this->session->set(self::PENDING, null);
    }

    public function qrSvg(int $accountId): ?string
    {
        $secret = $this->pendingSecret($accountId);
        $account = $this->accounts->findById($accountId);
        if ($secret === null || $account === null) {
            return null;
        }

        return $this->totp->qrSvg($account['email'], $secret);
    }

    /**
     * Włączenie 2FA po potwierdzeniu kodem. Zwraca komunikat błędu albo null.
     * Kody zapasowe trafiają do sesji do jednorazowego pokazania.
     */
    public function confirmPairing(int $accountId, string $code, string $ipHash): ?string
    {
        $secret = $this->pendingSecret($accountId);
        if ($secret === null) {
            return 'Parowanie wygasło. Rozpocznij je od nowa.';
        }
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_TOTP) > 0) {
            return 'Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.';
        }
        $window = $this->totp->matchingWindow($secret, trim($code), null);
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_TOTP, $window !== null);
        if ($window === null) {
            return 'Kod jest nieprawidłowy. Sprawdź, czy zegar telefonu jest ustawiony automatycznie.';
        }

        $codes = BackupCodes::generate();
        $hashes = array_map(fn (string $c): string => $this->password->hash(BackupCodes::normalize($c)), $codes);
        $this->db->transaction(function () use ($accountId, $secret, $window, $hashes): void {
            $this->accounts->enableTotp($accountId, $this->box->seal($secret), $window);
            $this->backupCodes->replaceAll($accountId, $hashes);
        });
        $this->cancelPairing();
        $this->session->set(self::CODES, [
            'account' => $accountId,
            'codes'   => $this->box->seal(implode("\n", $codes)),
        ]);
        $this->events->record('2fa.enabled', $accountId, $ipHash, null, 'account', $accountId);

        return null;
    }

    // --- Kody zapasowe do jednorazowego pokazania -----------------------------------------------

    /** @return list<string>|null kody czekające na potwierdzenie „zapisałem je” */
    public function codesToShow(int $accountId): ?array
    {
        $pending = $this->session->get(self::CODES);
        if (!is_array($pending) || ($pending['account'] ?? null) !== $accountId || !is_string($pending['codes'] ?? null)) {
            return null;
        }
        $plain = $this->box->open($pending['codes']);

        return $plain === null ? null : explode("\n", $plain);
    }

    public function hasCodesToShow(int $accountId): bool
    {
        return $this->codesToShow($accountId) !== null;
    }

    public function acknowledgeCodes(int $accountId): void
    {
        $this->session->set(self::CODES, null);
        $this->events->record('2fa.backup_codes_acknowledged', $accountId, null, null, 'account', $accountId);
    }

    public function isEnabled(int $accountId): bool
    {
        $account = $this->accounts->findById($accountId);

        return $account !== null && $account['totp_enabled_at'] !== null;
    }

    public function unusedBackupCodes(int $accountId): int
    {
        return $this->backupCodes->countUnused($accountId);
    }

    // --- Weryfikacja przy logowaniu -------------------------------------------------------------

    /** Kod z aplikacji. Limit prób obowiązkowy — sześć cyfr to milion kombinacji. */
    public function verifyTotp(int $accountId, string $code, string $ipHash): bool
    {
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_TOTP) > 0) {
            $this->events->record('2fa.delayed', $accountId, $ipHash);
            return false;
        }
        $ok = $this->checkTotp($accountId, trim($code));
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_TOTP, $ok);
        if (!$ok) {
            $this->events->record('2fa.failed', $accountId, $ipHash);
        }

        return $ok;
    }

    public function verifyBackupCode(int $accountId, string $code, string $ipHash): bool
    {
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_BACKUP_CODE) > 0) {
            $this->events->record('2fa.delayed', $accountId, $ipHash, null, null, null, null, ['kind' => 'backup_code']);
            return false;
        }
        $normalized = BackupCodes::normalize($code);
        $matched = null;
        if (BackupCodes::isWellFormed($normalized)) {
            // Wszystkie kody sprawdzane zawsze — czas odpowiedzi nie zdradza pozycji trafienia.
            foreach ($this->backupCodes->unused($accountId) as $row) {
                if ($this->password->verify($normalized, $row['code_hash']) && $matched === null) {
                    $matched = $row['id'];
                }
            }
        }
        $ok = $matched !== null && $this->backupCodes->markUsed($matched, $accountId);
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_BACKUP_CODE, $ok);
        $this->events->record($ok ? '2fa.backup_code_used' : '2fa.failed', $accountId, $ipHash, null, null, null, null,
            $ok ? ['remaining' => $this->backupCodes->countUnused($accountId)] : ['kind' => 'backup_code']);

        return $ok;
    }

    // --- Wyłączenie i reset -----------------------------------------------------------------------

    /**
     * Wyłączenie: hasło **i** aktualny kod (CLAUDE.md §9). Zwraca komunikat błędu albo null.
     *
     * ⚠ **Hasło jest wymagane także wtedy, gdy zlecenie rysuje okno bez niego** (Z69 pkt 7
     * pokazywał sam kod): §9 mówi „wyłączenie wymaga hasła i aktualnego kodu", a to reguła
     * z dokumentu wiążącego, nie szkic ekranu. Wyłączenie drugiego składnika samym kodem
     * z odblokowanego telefonu byłoby zdjęciem zabezpieczenia przez to, co właśnie zabezpiecza.
     *
     * ⚠ `$backup = true` znaczy „to jest kod ZAPASOWY, nie z aplikacji" (Z69: telefon zgubiony,
     * ale kody zapisane). Kod zapasowy sprawdza `verifyBackupCode()`, które **zużywa** trafiony
     * kod — i tak ma być: wyłączenie 2FA i tak unieważnia wszystkie kody, a przy nieudanej
     * próbie nic się nie zużywa. Hasło sprawdzamy PRZED kodem w obu drogach, więc samo
     * zgadywanie kodów bez hasła nie zużyje ani jednego.
     */
    public function disable(
        int $accountId,
        #[\SensitiveParameter] string $password,
        string $code,
        string $ipHash,
        bool $backup = false,
        bool $wymagane = false,
    ): ?string {
        // ⚠ **Odmowa wymogu stoi TUTAJ, nie tylko w kontrolerze** (Z69 pkt 7): kontroler zna
        // konfigurację i rolę, ale „nie wolno zdjąć drugiego składnika" jest regułą
        // bezpieczeństwa — musi dać się sprawdzić testem bez przeglądarki i musi paść także
        // wtedy, gdy kiedyś pojawi się druga droga do tej metody.
        if ($wymagane) {
            return self::ODMOWA_WYMOGU;
        }
        $account = $this->accounts->findById($accountId);
        if ($account === null || $account['totp_enabled_at'] === null) {
            return '2FA nie jest włączone.';
        }
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_TOTP) > 0) {
            return 'Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.';
        }
        $passwordOk = $this->password->verify($password, $account['password_hash']);
        $codeOk = $passwordOk && ($backup
            ? $this->verifyBackupCode($accountId, $code, $ipHash)
            : $this->checkTotp($accountId, trim($code)));
        // ⚠ Licznik `KIND_TOTP` zapisujemy tylko dla kodu z aplikacji: drogę zapasową liczy już
        // `verifyBackupCode()` swoim własnym licznikiem (`KIND_BACKUP_CODE`), a podwójny zapis
        // tej samej próby zamykałby człowiekowi obie drogi za jedną pomyłkę.
        if (!$backup) {
            $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_TOTP, $codeOk);
        }
        if (!$codeOk) {
            $this->events->record('2fa.disable_failed', $accountId, $ipHash);
            return 'Nieprawidłowe hasło lub kod.';
        }

        $this->removeSecondFactor($accountId);
        $this->events->record('2fa.disabled', $accountId, $ipHash, null, 'account', $accountId);

        return null;
    }

    /**
     * **Nowe kody zapasowe** — stare przestają działać natychmiast (zlecenie Z69 pkt 8).
     *
     * ⚠ Wymaga hasła, bo to jest wymiana poświadczeń awaryjnych: komu uda się dopaść
     * niezablokowanego komputera, ten bez hasła nie wyrobi sobie ośmiu kodów na przyszłość.
     * Kodu z aplikacji tu NIE żądamy — człowiek sięga po tę funkcję właśnie wtedy, gdy kody
     * mu się kończą albo wyciekły, a telefon ma przy sobie i tak zaraz poda kod przy logowaniu.
     *
     * ⚠ Kody pokazujemy **raz**, tą samą drogą co po włączeniu 2FA (pojemnik w sesji,
     * zaszyfrowany), więc po odświeżeniu strony nie ma ich już nigdzie w jawnej postaci.
     *
     * @return string|null komunikat odmowy albo null przy sukcesie
     */
    public function regenerateCodes(int $accountId, #[\SensitiveParameter] string $password, string $ipHash): ?string
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null || $account['totp_enabled_at'] === null) {
            return '2FA nie jest włączone.';
        }
        if ($this->limiter->delaySeconds($accountId, $ipHash, RateLimiter::KIND_PASSWORD) > 0) {
            return 'Zbyt wiele prób. Odczekaj chwilę i spróbuj ponownie.';
        }
        $ok = $this->password->verify($password, $account['password_hash']);
        $this->limiter->record($accountId, $ipHash, RateLimiter::KIND_PASSWORD, $ok);
        if (!$ok) {
            return 'Hasło jest nieprawidłowe.';
        }
        $codes = BackupCodes::generate();
        $hashes = array_map(fn (string $c): string => $this->password->hash(BackupCodes::normalize($c)), $codes);
        $this->backupCodes->replaceAll($accountId, $hashes);
        $this->session->set(self::CODES, [
            'account' => $accountId,
            'codes'   => $this->box->seal(implode("\n", $codes)),
        ]);
        $this->events->record('2fa.backup_codes_regenerated', $accountId, $ipHash, null, 'account', $accountId,
            null, ['ile' => count($codes)]);

        return null;
    }

    /**
     * Reset przez administratora (zgubiony telefon) — jawne obejście zabezpieczenia,
     * zawsze z wpisem: kto i komu (CLAUDE.md §9).
     */
    public function resetByAdmin(int $targetAccountId, int $adminAccountId, string $adminLabel, string $ipHash): bool
    {
        $target = $this->accounts->findById($targetAccountId);
        if ($target === null) {
            return false;
        }
        $this->removeSecondFactor($targetAccountId);
        $this->events->record('2fa.reset_by_admin', $adminAccountId, $ipHash, $adminLabel, 'account', $targetAccountId, null, [
            'was_enabled' => $target['totp_enabled_at'] !== null,
        ]);

        return true;
    }

    private function removeSecondFactor(int $accountId): void
    {
        $this->db->transaction(function () use ($accountId): void {
            $this->accounts->disableTotp($accountId);
            $this->backupCodes->deleteAll($accountId);
        });
    }

    private function checkTotp(int $accountId, string $code): bool
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null || $account['totp_enabled_at'] === null || $account['totp_secret_enc'] === null) {
            return false;
        }
        $secret = $this->box->open($account['totp_secret_enc']);
        if ($secret === null) {
            return false;
        }
        $window = $this->totp->matchingWindow($secret, $code, $account['totp_last_window']);

        return $window !== null && $this->accounts->claimTotpWindow($accountId, $window);
    }
}
