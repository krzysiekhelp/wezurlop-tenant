<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Wezurlop\Db\Connection;
use Wezurlop\Http\Session;
use Wezurlop\License\LicenseGate;
use Wezurlop\License\LicenseReader;
use Wezurlop\License\LicenseValidator;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\CompanyRepository;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\BackupCodeRepository;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\LeaveRequestRepository;
use Wezurlop\Repository\InvitationRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\LoginAttemptRepository;
use Wezurlop\Repository\PasswordResetTokenRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Repository\SessionRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\SyncTaskRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Support\Strefa;
use Wezurlop\Security\SecretBox;
use Wezurlop\Security\Totp;
use Wezurlop\Service\AccountMailService;
use Wezurlop\Service\AuthService;
use Wezurlop\Service\Calendar\CalendarFactory;
use Wezurlop\Service\Canary;
use Wezurlop\Service\JobRunner;
use Wezurlop\Service\DeviceService;
use Wezurlop\Service\InvitationService;
use Wezurlop\Service\LicenseFetcher;
use Wezurlop\Service\AccessService;
use Wezurlop\Service\AccountService;
use Wezurlop\Service\Mail\FileTransport;
use Wezurlop\Service\CronHeartbeat;
use Wezurlop\Service\CronStan;
use Wezurlop\Service\LinkHasla;
use Wezurlop\Service\Mail\MailRenderer;
use Wezurlop\Service\Mail\MailSettings;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Service\Mail\MailTransport;
use Wezurlop\Service\Mail\SmtpTransport;
use Wezurlop\Service\Leave\LeaveRequestService;
use Wezurlop\Service\PasswordResetService;
use Wezurlop\Service\Sync\ApiKeyService;
use Wezurlop\Service\Sync\RecordApplier;
use Wezurlop\Service\Sync\SchemaValidator;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Service\Sync\SyncWindow;
use Wezurlop\Service\Sync\TaskService;
use Wezurlop\Service\TwoFactorService;

/**
 * Jawne złożenie repozytoriów i serwisów — wspólne dla żądań HTTP i skryptów CLI.
 * Tylko konstrukcja obiektów w jednym miejscu; połączenie z bazą otwierane przy pierwszym zapytaniu.
 */
final class Services
{
    public readonly Connection $db;
    public readonly Password $password;
    public readonly AccountRepository $accounts;
    public readonly CompanyRepository $companies;
    public readonly PersonRepository $people;
    public readonly AccessService $access;
    public readonly AccountService $konta;
    public readonly PermissionRepository $permissions;
    public readonly EmploymentRepository $employments;
    public readonly EventLogRepository $events;
    public readonly JobRepository $jobs;
    public readonly RateLimiter $limiter;
    public readonly AccountMailService $accountMail;
    public readonly AuthService $auth;
    public readonly PasswordResetService $passwordReset;
    public readonly InvitationService $invitations;
    public readonly BackupCodeRepository $backupCodes;
    public readonly TenantSettingRepository $settings;
    public readonly SessionRepository $sessions;
    public readonly DeviceService $devices;
    public readonly LicenseGate $license;
    public readonly SecretBox $secretBox;

    private ?MailSettings $mailSettings = null;

    private ?MailRenderer $mailRenderer = null;

    private ?TransportFactory $transportFactory = null;
    public readonly Totp $totp;
    public readonly ApiKeyRepository $apiKeys;

    public function __construct(public readonly Config $config)
    {
        // Profil `data` z bezpiecznikiem DDL. Próba zapisywana osobnym połączeniem — wpis musi
        // przetrwać wycofanie transakcji, w której padł błąd.
        $this->db = new Connection($config->db, Connection::PROFILE_DATA, static function (string $keyword) use ($config): void {
            (new EventLogRepository(new Connection($config->db)))->record(
                'security.ddl_blocked', null, null, 'system', null, null, null, ['keyword' => $keyword],
            );
        });
        $this->password = new Password($config->varPath . '/cache/password-dummy.hash');
        $this->accounts = new AccountRepository($this->db);
        $this->companies = new CompanyRepository($this->db);
        $this->people = new PersonRepository($this->db);
        $this->permissions = new PermissionRepository($this->db);
        $this->employments = new EmploymentRepository($this->db);
        $this->events = new EventLogRepository($this->db);
        $this->jobs = new JobRepository($this->db);
        $clock = new KnownTimeStore($config->licenseClockPath());
        $this->license = new LicenseGate(
            new LicenseReader((string) ($config->license['file'] ?? ''), (string) ($config->license['public_key_file'] ?? '')),
            new LicenseValidator(
                $clock->read(...),
                $clock->store(...),
                fn (): int => (int) $this->settings->get('license_grace_days', '7'),
            ),
            $config->installationHost(),
            $config->isDev(),
        );
        $this->settings = new TenantSettingRepository($this->db);
        $this->access = new AccessService($this->db, $this->permissions, $this->accounts, $this->employments, $this->events, $this->license);
        // ⚠ `$this->access` POWYŻEJ tej linii nie jest przypadkiem: blokada konta pyta o limit
        // licencji tą samą drogą, co nadanie dostępu (decyzja z 24.10.2026).
        $this->konta = new AccountService($this->db, $this->accounts, $this->events, $this->widocznosc(), $this->access);
        $this->sessions = new SessionRepository($this->db);
        $this->devices = new DeviceService($this->sessions, $this->events);
        $this->limiter = new RateLimiter(new LoginAttemptRepository($this->db), $this->settings, $this->events, $this->jobs);
        $resetTokens = new PasswordResetTokenRepository($this->db);
        $invitationTokens = new InvitationRepository($this->db);
        $this->accountMail = new AccountMailService($this->settings, $this->jobs, $this->accounts,
            $resetTokens, $invitationTokens, $this->companies, $this->employments, $this->mailRenderer(), $config);
        $this->auth = new AuthService($this->accounts, $this->password, $this->limiter, $this->events);
        $this->passwordReset = new PasswordResetService(
            $this->accounts, $resetTokens, $this->accountMail, $this->limiter, $this->password, $this->events, $this->db,
            $this->sessions,
        );
        $this->backupCodes = new BackupCodeRepository($this->db);
        $this->secretBox = new SecretBox($config->appKeyBytes());
        $this->totp = new Totp();
        $this->apiKeys = new ApiKeyRepository($this->db);
        $this->invitations = new InvitationService(
            $this->db, $this->people, $this->accounts, $this->permissions, $invitationTokens,
            $this->jobs, $this->accountMail, $this->password, $this->events,
        );
    }

    /** 2FA korzysta z sesji bieżącego żądania — budowane osobno, tylko w kontekście HTTP. */
    public function twoFactor(Session $session): TwoFactorService
    {
        return new TwoFactorService(
            $session, $this->accounts, $this->backupCodes, $this->totp, $this->secretBox,
            $this->password, $this->limiter, $this->events, $this->db,
        );
    }

    public function licenseFetcher(): LicenseFetcher
    {
        return new LicenseFetcher(
            (string) ($this->config->license['panel_url'] ?? ''),
            (string) ($this->config->license['installation_key'] ?? ''),
            (string) ($this->config->license['file'] ?? ''),
        );
    }

    public function canary(): Canary
    {
        return new Canary(
            $this->settings,
            $this->events,
            $this->jobs,
            $this->config->storagePath,
            $this->config->appUrl,
            $this->config->basePath,
            $this->config->deploy['self_addr'] ?? null,
        );
    }

    public function jobRunner(Logger $logger): JobRunner
    {
        // ⚠ Fabryka, nie gotowy transport: brak konfiguracji SMTP w produkcji nie ma prawa
        // wywrócić całej kolejki (sprzątanie sesji, sygnał kontrolny, sprawdzenia kluczy).
        return new JobRunner($this->jobs, $this->accountMail, $this->sessions,
            $this->transportFactory(), $logger,
            $this->canary(), $this->syncRepository(), $this->apiKeys, $this->settings, $this->events);
    }

    /** Składanie i rozstrzyganie wniosków — wspólna ścieżka dla interfejsu (tura B) i narzędzi deweloperskich. */
    public function leaveRequests(): LeaveRequestService
    {
        return new LeaveRequestService(
            $this->db,
            new LeaveRequestRepository($this->db),
            new CalendarFactory($this->settings, new CompanyCalendarRepository($this->db)),
            new SyncTaskRepository($this->db),
            $this->events,
            new CompanyCalendarRepository($this->db),
        );
    }

    public function syncRepository(): SyncRepository
    {
        return new SyncRepository($this->db);
    }

    public function syncDomain(): SyncDomainRepository
    {
        return new SyncDomainRepository($this->db);
    }

    public function apiKeyService(): ApiKeyService
    {
        return new ApiKeyService($this->db, $this->apiKeys, $this->events, new SyncDomainRepository($this->db), $this->settings);
    }

    /** API connectora — składane tylko dla żądań /api/… i skryptów CLI. */
    public function syncService(): SyncService
    {
        $sync = $this->syncRepository();
        $domain = new SyncDomainRepository($this->db);
        $tasks = new SyncTaskRepository($this->db);
        $applier = new RecordApplier($sync, $domain, $tasks, new CompanyCalendarRepository($this->db), $this->events, $this->jobs, new SyncWindow());

        return new SyncService(
            $this->db, $sync, $domain, $tasks, $this->apiKeys, $this->settings,
            new SchemaValidator(require dirname(__DIR__, 2) . '/config/sync-schema.php'), // plik kodu (config/), nie konfiguracja wdrożenia
            $applier,
            new TaskService($tasks, $sync, $this->events, $this->jobs),
            $this->events,
            $this->apiKeyService(),
            $this->jobs,
            // Droga 2 przeliczania reguł widoczności (Z80 B3): wygaszenie etatu i blokada konta.
            $this->widocznosc(),
        );
    }

    /**
     * ⚠ Ustawienia poczty czytamy z BAZY, nie z `.env` (etap 7.2). `MAIL_*` w `.env` przestało
     * być czytane: serwer SMTP klienta zmienia się po instalacji, a `.env` pisze instalator raz.
     */
    /**
     * Treść wiadomości: jeden zestaw zmiennych, dwa wyjścia (HTML + tekst).
     *
     * ⚠ Osobny, minimalny Twig — wiadomości renderuje także kolejka w CLI, gdzie nie ma
     * ani `UrlGenerator`, ani funkcji globalnych widoku (patrz `MailRenderer`).
     */
    public function cronStan(): CronStan
    {
        // ⚠ Katalog aplikacji wyprowadzony z `templatesPath` — `Services` nie dostaje go osobno,
        // a polecenie crona musi nieść PRAWDZIWĄ ścieżkę tej instalacji, nie placeholder.
        return new CronStan($this->config, new CronHeartbeat($this->config->varPath),
            $this->settings, $this->jobs, dirname($this->config->templatesPath));
    }

    public function hierarchyRepository(): \Wezurlop\Repository\HierarchyRepository
    {
        return new \Wezurlop\Repository\HierarchyRepository($this->db);
    }

    public function hierarchia(): \Wezurlop\Service\Hierarchia
    {
        // ⚠ Zapis hierarchii przelicza reguły widoczności (Z80 B3, droga 1): zmiana krawędzi
        // odbiera albo przywraca podwładnych, a od tego zależy skutek reguł „przełożony X"
        // i „podwładni X".
        return new \Wezurlop\Service\Hierarchia($this->db, $this->hierarchyRepository(), $this->events,
            $this->widocznosc());
    }

    public function grupyRepository(): \Wezurlop\Repository\GroupRepository
    {
        return new \Wezurlop\Repository\GroupRepository($this->db);
    }

    /**
     * ⚠ **Jedna wytwórnia na wszystkie drogi** (zlecenie Z80 cz. B). Do 23.10.2026 serwis
     * widoczności powstawał wyłącznie w środku `grupy()`, bo tylko tamta droga go potrzebowała.
     * Od Z80 przelicza reguły także zapis hierarchii, wygaszenie etatu z synchronizacji
     * i blokada konta — a cztery kopie tego samego `new` rozjechałyby się przy pierwszej
     * nowej zależności serwisu.
     */
    public function widocznosc(): \Wezurlop\Service\WidocznoscService
    {
        return new \Wezurlop\Service\WidocznoscService(
            new \Wezurlop\Repository\CalendarAccessRepository($this->db),
            $this->grupyRepository(),
            $this->events,
        );
    }

    public function grupy(): \Wezurlop\Service\GrupyService
    {
        return new \Wezurlop\Service\GrupyService(
            $this->grupyRepository(),
            $this->events,
            // ⚠ Usunięcie grupy sprząta reguły widoczności, które ją wskazują (zlecenie Z53 §1) —
            // z wpisem w dzienniku, czego kaskada bazy by nie zrobiła.
            $this->widocznosc(),
        );
    }

    public function linkHasla(): LinkHasla
    {
        return new LinkHasla($this->accounts, $this->events);
    }

    public function mailRenderer(): MailRenderer
    {
        return $this->mailRenderer ??= new MailRenderer(
            $this->config->templatesPath,
            $this->config->isDev() ? null : $this->config->varPath . '/cache/twig',
            // ⚠ Strefa INSTALACJI do znacznika czasu w stopce (Z60) — ta sama, z której liczą
            // godziny ekrany; wartość domyślna tylko wtedy, gdy ustawienia jej nie mają.
            (string) $this->settings->get('timezone', Strefa::DOMYSLNA),
        );
    }

    public function mailSettings(): MailSettings
    {
        // ⚠ Nazwa hosta z `APP_URL` — ta sama, z której budujemy linki w wiadomościach.
        // Bez niej `Message-ID` niósł nazwę maszyny (zmierzone: `<…@wezurlop>`).
        return $this->mailSettings ??= new MailSettings(
            $this->settings,
            $this->secretBox,
            (string) (parse_url($this->config->appUrl, PHP_URL_HOST) ?: ''),
        );
    }

    public function transportFactory(): TransportFactory
    {
        return $this->transportFactory ??= new TransportFactory(
            $this->mailSettings(),
            $this->config->mailDumpPath(),
            $this->config->isDev(),
        );
    }

    /**
     * ⚠ Rzuca `MailException`, gdy w produkcji poczta nie jest skonfigurowana — świadomie.
     * Cichy zapis do pliku dawał kolejce **sukces** przy niedostarczonej wiadomości.
     *
     * @throws \Wezurlop\Service\Mail\MailException
     */
    public function mailTransport(): MailTransport
    {
        return $this->transportFactory()->transport();
    }
}
