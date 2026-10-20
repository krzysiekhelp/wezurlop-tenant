<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Wezurlop\License\LicenseGate;
use Wezurlop\Service\Canary;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\CompanyRepository;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\DataViewRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\CalendarFilterRepository;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Repository\HierarchyRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\CsrfToken;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Http\LinkHaslaView;
use Wezurlop\Service\AccessService;
use Wezurlop\Service\AccountService;
use Wezurlop\Service\AuthService;
use Wezurlop\Service\Maintenance\TenantReset;
use Wezurlop\Service\DeviceService;
use Wezurlop\Service\InvitationService;
use Wezurlop\Service\PasswordResetService;
use Wezurlop\Service\Mail\MailSettings;
use Wezurlop\Service\CronStan;
use Wezurlop\Service\TerminWysylki;
use Wezurlop\Service\GrupyService;
use Wezurlop\Service\Hierarchia;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Service\ZasiegPodgladu;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Service\Sync\ApiKeyService;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\Config;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\View;

/**
 * Zależności kontrolerów zbudowane raz na żądanie w Kernelu. Zwykły obiekt z polami —
 * nie kontener DI i nie lokalizator usług (CLAUDE.md §1).
 */
final class ControllerDependencies
{
    public function __construct(
        public readonly View $view,
        public readonly UrlGenerator $urls,
        public readonly ErrorHandler $errors,
        public readonly AuthSession $authSession,
        public readonly ClientFingerprint $fingerprint,
        public readonly AuthService $auth,
        public readonly LinkHaslaView $linkHasla,
        public readonly PasswordResetService $passwordReset,
        public readonly InvitationService $invitations,
        public readonly AccessService $access,
        public readonly AccountService $konta,
        public readonly EmploymentRepository $employments,
        public readonly EventLogRepository $events,
        public readonly JobRepository $jobs,
        public readonly AccountRepository $accounts,
        public readonly CompanyRepository $companies,
        public readonly PersonRepository $people,
        public readonly PermissionRepository $permissions,
        public readonly TwoFactorService $twoFactor,
        public readonly DeviceService $devices,
        public readonly Session $session,
        public readonly Config $config,
        public readonly string $appRoot,
        public readonly LicenseGate $license,
        public readonly Canary $canary,
        public readonly ApiKeyService $apiKeyService,
        public readonly ApiKeyRepository $apiKeys,
        public readonly SyncRepository $sync,
        public readonly SyncDomainRepository $syncDomain,
        public readonly CompanyCalendarRepository $calendars,
        public readonly DataViewRepository $dataView,
        public readonly TenantReset $tenantReset,
        public readonly TenantSettingRepository $settings,
        public readonly MailSettings $mailSettings,
        public readonly TransportFactory $transportFactory,
        public readonly CronStan $cron,
        public readonly TerminWysylki $termin,
        public readonly Hierarchia $hierarchia,
        public readonly HierarchyRepository $hierarchyRepo,
        public readonly GroupRepository $grupyRepo,
        public readonly GrupyService $grupy,
        public readonly PracownikRepository $pracownik,
        public readonly PulpitPracownika $pulpit,
        public readonly KalendarzPracownika $kalendarz,
        public readonly ZasiegPodgladu $zasieg,
        public readonly CalendarFilterRepository $filtrKalendarza,
        public readonly CalendarVisibility $widocznoscKalendarza,
        public readonly \Wezurlop\Repository\CalendarAccessRepository $regulyKalendarza,
        public readonly \Wezurlop\Service\WidocznoscService $widocznoscService,
        public readonly \Wezurlop\Service\KontekstSpolki $kontekstSpolki,
        // Świeży token formularza i jego limit na adres (zlecenie Z56 §2) — jedyny kontroler,
        // który sięga po token wprost; pozostałe dostają go w szablonie przez `csrf_token()`.
        public readonly CsrfToken $csrf,
        public readonly RateLimiter $limiter,
        // Render wiadomości — wiadomość próbna z ekranu ustawień idzie tym samym układem,
        // co poczta z kolejki (zlecenie Z60).
        public readonly \Wezurlop\Service\Mail\MailRenderer $mailRenderer,
    ) {
    }
}
