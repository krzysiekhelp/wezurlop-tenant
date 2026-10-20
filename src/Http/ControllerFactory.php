<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use InvalidArgumentException;
use Wezurlop\Controller\Api\SyncController;
use Wezurlop\Controller\App\AdminAccountController;
use Wezurlop\Controller\App\AdminIntegrationController;
use Wezurlop\Controller\App\CalendarController;
use Wezurlop\Controller\App\ContextController;
use Wezurlop\Controller\App\DashboardController;
use Wezurlop\Controller\App\DataController;
use Wezurlop\Controller\App\VisibilityController;
use Wezurlop\Controller\App\DeviceController;
use Wezurlop\Controller\App\DiagnosticsController;
use Wezurlop\Controller\App\EventLogController;
use Wezurlop\Controller\App\GroupController;
use Wezurlop\Controller\App\HierarchyController;
use Wezurlop\Controller\App\LeaveRequestController;
use Wezurlop\Controller\App\LicenseController;
use Wezurlop\Controller\App\ProfileController;
use Wezurlop\Controller\App\SecurityController;
use Wezurlop\Controller\App\SettingsController;
use Wezurlop\Controller\App\StyleguideController;
use Wezurlop\Controller\App\SystemPageController;
use Wezurlop\Controller\App\TeamController;
use Wezurlop\Controller\Auth\ExternalLoginController;
use Wezurlop\Controller\Auth\InvitationController;
use Wezurlop\Controller\Auth\LoginController;
use Wezurlop\Controller\Auth\ResetController;
use Wezurlop\Controller\Auth\TokenController;
use Wezurlop\Controller\Auth\TwoFactorController;

/**
 * Tworzenie kontrolerów z jawnie podanymi zależnościami — bez kontenera DI i bez `new $klasa`
 * z danych żądania (CLAUDE.md §1, §3). Nowy kontroler = nowa pozycja w CONTROLLERS i create().
 */
final class ControllerFactory
{
    private const CONTROLLERS = [
        DashboardController::class,
        CalendarController::class,
        LeaveRequestController::class,
        TeamController::class,
        ProfileController::class,
        SettingsController::class,
        ContextController::class,
        SecurityController::class,
        DeviceController::class,
        AdminAccountController::class,
        GroupController::class,
        HierarchyController::class,
        AdminIntegrationController::class,
        DataController::class,
        VisibilityController::class,
        DiagnosticsController::class,
        EventLogController::class,
        LicenseController::class,
        SystemPageController::class,
        StyleguideController::class,
        LoginController::class,
        ExternalLoginController::class,
        TwoFactorController::class,
        ResetController::class,
        InvitationController::class,
        TokenController::class,
    ];

    public function __construct(private readonly ControllerDependencies $d)
    {
    }

    public function exists(string $class): bool
    {
        return self::knows($class);
    }

    /** Kontrolery z białej listy — także API (tworzony przez ApiKernel, bez zależności sesyjnych). */
    public static function knows(string $class): bool
    {
        return in_array($class, self::CONTROLLERS, true) || $class === SyncController::class;
    }

    public function create(string $class): object
    {
        $d = $this->d;

        return match ($class) {
            DashboardController::class     => new DashboardController($d->view, $d->pracownik, $d->pulpit, $d->employments, $d->settings),
            CalendarController::class      => new CalendarController($d->view, $d->pracownik, $d->employments,
                $d->kalendarz, $d->zasieg, $d->filtrKalendarza, $d->widocznoscKalendarza, $d->config,
                $d->kontekstSpolki, $d->errors, $d->urls),
            LeaveRequestController::class  => new LeaveRequestController($d->view),
            TeamController::class          => new TeamController($d->view),
            ProfileController::class       => new ProfileController($d->view),
            SettingsController::class      => new SettingsController($d->view, $d->settings, $d->companies, $d->events,
                $d->mailSettings, $d->cron, $d->jobs, $d->transportFactory, $d->urls, $d->config),
            ContextController::class       => new ContextController($d->view, $d->employments, $d->authSession, $d->events, $d->errors, $d->urls, $d->kontekstSpolki, $d->accounts),
            SecurityController::class      => new SecurityController($d->view, $d->twoFactor, $d->permissions, $d->fingerprint, $d->errors, $d->urls),
            DeviceController::class        => new DeviceController($d->view, $d->devices, $d->session, $d->fingerprint, $d->errors, $d->urls),
            AdminAccountController::class  => new AdminAccountController($d->view, $d->accounts, $d->people,
                $d->invitations, $d->access, $d->konta, $d->events, $d->jobs, $d->termin, $d->twoFactor, $d->devices,
                $d->permissions, $d->fingerprint, $d->kontekstSpolki, $d->errors, $d->urls,
                $d->widocznoscKalendarza, $d->config),
            AdminIntegrationController::class => new AdminIntegrationController($d->view, $d->session, $d->apiKeyService, $d->apiKeys,
                $d->sync, $d->syncDomain, $d->events, $d->settings, $d->errors, $d->urls),
            DataController::class          => new DataController($d->view, $d->dataView, $d->kalendarz, $d->permissions,
                $d->apiKeys, $d->syncDomain, $d->errors),
            VisibilityController::class    => new VisibilityController($d->view, $d->regulyKalendarza,
                $d->widocznoscService, $d->widocznoscKalendarza, $d->grupyRepo, $d->zasieg,
                $d->kontekstSpolki, $d->errors, $d->urls),
            DiagnosticsController::class   => new DiagnosticsController($d->view, $d->config, $d->appRoot, $d->canary, $d->events,
                $d->calendars, $d->tenantReset, $d->settings, $d->apiKeys, $d->syncDomain, $d->session, $d->access,
                $d->hierarchyRepo, $d->permissions, $d->mailSettings, $d->cron, $d->transportFactory, $d->errors, $d->urls,
                $d->sync),
            EventLogController::class      => new EventLogController($d->view, $d->events, $d->accounts),
            GroupController::class         => new GroupController($d->view, $d->grupyRepo, $d->grupy,
                $d->permissions, $d->kontekstSpolki, $d->errors, $d->urls),
            HierarchyController::class     => new HierarchyController($d->view, $d->hierarchia, $d->hierarchyRepo,
                $d->permissions, $d->kontekstSpolki, $d->errors, $d->urls),
            // ⚠ `$d->settings` jechało tu już wcześniej, ale konstruktor go NIE PRZYJMOWAŁ —
            // PHP przepuszcza nadmiarowe argumenty do funkcji użytkownika bez słowa, więc
            // kontroler czytał z nieistniejącej właściwości dopiero przy licencji z terminem.
            // Naprawione przy Z48; `$d->access` dokładamy dla jednego źródła liczby dostępów.
            LicenseController::class       => new LicenseController($d->view, $d->license, $d->settings, $d->access),
            SystemPageController::class    => new SystemPageController($d->errors),
            StyleguideController::class    => new StyleguideController($d->view),
            LoginController::class         => new LoginController($d->view, $d->auth, $d->authSession, $d->fingerprint, $d->events, $d->urls),
            ExternalLoginController::class => new ExternalLoginController($d->view),
            TwoFactorController::class     => new TwoFactorController($d->view, $d->twoFactor, $d->authSession, $d->accounts, $d->fingerprint, $d->events, $d->urls),
            ResetController::class         => new ResetController($d->view, $d->passwordReset, $d->linkHasla, $d->fingerprint, $d->urls),
            InvitationController::class    => new InvitationController($d->view, $d->invitations, $d->linkHasla, $d->fingerprint, $d->urls),
            TokenController::class         => new TokenController($d->csrf, $d->limiter, $d->fingerprint),
            default => throw new InvalidArgumentException('Nieznany kontroler: ' . $class),
        };
    }
}
