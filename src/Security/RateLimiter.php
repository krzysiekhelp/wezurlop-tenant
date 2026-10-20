<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\LoginAttemptRepository;
use Wezurlop\Repository\TenantSettingRepository;

/**
 * Limity prób (CLAUDE.md §9, docs/szkielet-tenanta.md rozdz. 6).
 *
 * - Konto i adres: po przekroczeniu progu kolejne próby są OPÓŹNIANE wykładniczo (1, 2, 4… s,
 *   najwyżej 5 min) — konto nigdy nie jest zamykane. Próba w trakcie opóźnienia jest odrzucana
 *   bez sprawdzania i bez zapisu, więc nie wydłuża opóźnienia; reset hasła zeruje licznik konta.
 *   Progi konta i adresu są różne (5 i 30 w oknie 15 min).
 * - Cała instalacja (password spraying): próg z tenant_setting; przekroczenie nikogo nie blokuje —
 *   wpis w dzienniku i alert przez kolejkę, najwyżej raz na godzinę.
 * - Reset hasła: twardy limit żądań (3 na konto, 10 na adres / 15 min) — ochrona skrzynki
 *   przed zalewem; licznik logowania go nie dotyczy.
 * - Świeży token formularza (`/token-formularza`, zlecenie Z56 §2): twardy limit na adres.
 *   Opóźnianie nie ma tu sensu — żądanie nie jest próbą uwierzytelnienia i nie ma „konta",
 *   do którego dałoby się je przypisać.
 */
final class RateLimiter
{
    public const KIND_PASSWORD = 'password';
    public const KIND_TOTP = 'totp';
    public const KIND_BACKUP_CODE = 'backup_code';
    public const KIND_RESET = 'reset';
    /** ⚠ Nie ma progu w DELAY_THRESHOLDS: ten rodzaj nigdy nie przechodzi przez delaySeconds(). */
    public const KIND_TOKEN = 'token';

    public const JOB_SECURITY_ALERT = 'mail.security_alert';

    private const WINDOW_MINUTES = 15;
    private const MAX_DELAY_SECONDS = 300;
    private const ALERT_PERIOD_SECONDS = 3600;

    /** Próg nieudanych prób, od którego zaczyna się opóźnianie: [konto, adres]. */
    private const DELAY_THRESHOLDS = [
        self::KIND_PASSWORD    => [5, 30],
        self::KIND_TOTP        => [5, 30],
        self::KIND_BACKUP_CODE => [5, 30],
    ];

    private const RESET_LIMITS = [3, 10];

    /**
     * Żądania o świeży token z jednego adresu w oknie 15 minut.
     *
     * ⚠ Hojnie, bo adres bywa wspólny: za firmowym NAT-em albo za Cloudflare siedzi cała
     * załoga, a przekroczenie limitu nie blokuje logowania — tylko odświeżanie tokenu,
     * po którym zostaje siatka serwerowa (`OdnowienieFormularza`). Limit jest po to, żeby
     * pętla w pętli nie produkowała sesji i wierszy bez końca.
     */
    private const TOKEN_LIMIT_PER_IP = 120;

    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly TenantSettingRepository $settings,
        private readonly EventLogRepository $events,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * Ile sekund pozostało do chwili, w której próba zostanie rozpatrzona (0 = teraz).
     */
    public function delaySeconds(?int $accountId, string $ipHash, string $kind): int
    {
        [$accountThreshold, $ipThreshold] = self::DELAY_THRESHOLDS[$kind];
        $remaining = $this->remaining($this->attempts->ipFailures($ipHash, $kind, self::WINDOW_MINUTES), $ipThreshold);
        if ($accountId !== null) {
            $remaining = max($remaining, $this->remaining(
                $this->attempts->accountFailures($accountId, $kind, self::WINDOW_MINUTES),
                $accountThreshold,
            ));
        }

        return $remaining;
    }

    /** Twardy limit żądań resetu hasła. */
    public function isResetLimited(?int $accountId, string $ipHash): bool
    {
        [$perAccount, $perIp] = self::RESET_LIMITS;

        return $this->attempts->ipRequests($ipHash, self::KIND_RESET, self::WINDOW_MINUTES) >= $perIp
            || ($accountId !== null
                && $this->attempts->accountRequests($accountId, self::KIND_RESET, self::WINDOW_MINUTES) >= $perAccount);
    }

    /** Twardy limit żądań o świeży token formularza — wyłącznie na adres. */
    public function isTokenLimited(string $ipHash): bool
    {
        return $this->attempts->ipRequests($ipHash, self::KIND_TOKEN, self::WINDOW_MINUTES)
            >= self::TOKEN_LIMIT_PER_IP;
    }

    /**
     * ⚠ Zapisywane jako próba UDANA: to nie jest nieudane uwierzytelnienie, więc nie ma prawa
     * podkręcać opóźnienia logowania ani licznika całej instalacji (password spraying).
     */
    public function recordTokenRequest(string $ipHash): void
    {
        $this->attempts->record(null, $ipHash, self::KIND_TOKEN, true);
    }

    public function record(?int $accountId, string $ipHash, string $kind, bool $succeeded): void
    {
        $this->attempts->record($accountId, $ipHash, $kind, $succeeded);
        if (!$succeeded && $kind === self::KIND_PASSWORD) {
            $this->checkTenantWide();
        }
    }

    /** @param array{count: int, last_seconds_ago: ?int} $failures */
    private function remaining(array $failures, int $threshold): int
    {
        if ($failures['count'] < $threshold || $failures['last_seconds_ago'] === null) {
            return 0;
        }
        $delay = min(self::MAX_DELAY_SECONDS, 2 ** min(20, $failures['count'] - $threshold));

        return max(0, $delay - $failures['last_seconds_ago']);
    }

    private function checkTenantWide(): void
    {
        $threshold = (int) $this->settings->get('login_alert_threshold', '50');
        $window = max(1, (int) $this->settings->get('login_alert_window_minutes', '15'));
        if ($threshold <= 0) {
            return;
        }
        $failures = $this->attempts->tenantFailures(self::KIND_PASSWORD, $window);
        if ($failures < $threshold || !$this->settings->claimAlertSlot('login_alert_last_at', self::ALERT_PERIOD_SECONDS)) {
            return;
        }

        $this->events->record('security.login_spraying_suspected', null, null, 'system', null, null, null, [
            'failures'       => $failures,
            'window_minutes' => $window,
            'threshold'      => $threshold,
        ]);
        $this->jobs->enqueue(self::JOB_SECURITY_ALERT, [
            'failures'       => $failures,
            'window_minutes' => $window,
        ]);
    }
}
