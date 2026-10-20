<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Throwable;
use Twig\Environment;
use Wezurlop\Controller\Install\InstallController;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Middleware\Authorize;
use Wezurlop\Http\Middleware\Csrf;
use Wezurlop\Http\Middleware\LicenseGuard;
use Wezurlop\Http\Middleware\EmploymentContext;
use Wezurlop\Http\Middleware\Middleware;
use Wezurlop\Http\Middleware\Require2fa;
use Wezurlop\Http\Middleware\SessionLifetime;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\CalendarFilterRepository;
use Wezurlop\Repository\DataViewRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Service\Maintenance\TenantReset;
use Wezurlop\Security\ClientInfo;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\CsrfToken;
use Wezurlop\Security\TrustedProxy;
use Wezurlop\Service\CronHeartbeat;
use Wezurlop\Service\Install\PrefixDetector;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Service\TerminWysylki;
use Wezurlop\Service\ZasiegPodgladu;
use Wezurlop\Support\AssetVersions;
use Wezurlop\Support\Config;
use Wezurlop\Support\DataDnia;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Nip;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\RelativeTime;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\WorkTime;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Punkt wejścia aplikacji (docs/szkielet-tenanta.md rozdz. 2).
 *
 * Kolejność kroków bootstrapu jest częścią zabezpieczenia — nie zmieniać:
 *   1. handlery błędów, 2. .env, 3. sprawdzenie instalacji, 4. konfiguracja,
 *   5. nagłówki bezpieczeństwa, 6. sesja, 7. router -> middleware -> kontroler.
 */
final class Kernel
{
    private const MOBILE_ACCESS_OPEN = 'open';
    private const MOBILE_ACCESS_APP_ONLY = 'app_only';

    /** @var (\Closure(): void)|null praca po wysłaniu odpowiedzi (QUEUE_ON_REQUEST) */
    private ?\Closure $afterResponse = null;

    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * Wywoływane przez public/index.php po send(). Domyka kolejkę, gdy cron jest rzadki
     * (docs/profile-hostingu.md, „Kolejka uruchamiana także z żądania”); w każdym innym przypadku nic nie robi.
     */
    public function afterResponse(): void
    {
        if ($this->afterResponse !== null) {
            ($this->afterResponse)();
        }
    }

    public function handle(Request $request): Response
    {
        // ⚠ Proces pracuje w UTC, jawnie (docs/system-wizualny.md §10 punkt 1). Nie polegamy na
        // `date.timezone` z php.ini hostingu — na współdzielonym bywa ustawiona na cokolwiek
        // i zmienia się bez naszej wiedzy, a wtedy czas w bazie przestaje być UTC.
        // Strefę użytkownika dokłada dopiero warstwa widoku, z ustawienia tenanta.
        date_default_timezone_set('UTC');

        // 1. Handlery błędów — pierwsze, żeby błąd w kolejnych krokach trafił do logu, nie na ekran.
        $logger = new Logger($this->appRoot . '/storage/logs');
        $errors = new ErrorHandler(
            $logger,
            $this->appRoot . '/templates',
            $this->serverPrefix($request, 'APP_BASE_PATH'),
            $this->serverPrefix($request, 'APP_FRONT_CONTROLLER'),
        );
        $errors->register();

        try {
            return $this->boot($request, $logger, $errors);
        } catch (Throwable $e) {
            return $errors->responseFor($e, $request->isHtmx());
        }
    }

    private function boot(Request $request, Logger $logger, ErrorHandler $errors): Response
    {
        // 2. .env — położenie konfigurowalne (WEZURLOP_ENV_PATH).
        $envFile = EnvFile::path($this->appRoot, $request->serverParams());
        $env = EnvFile::exists($envFile) ? EnvFile::load($envFile) : null;

        // 3. Sprawdzenie instalacji: brak .env albo znacznika → instalator.
        if ($env === null || !is_file($this->installLockPath($env))) {
            return $this->notInstalled($request, $errors, $envFile, $logger);
        }

        // 4. Konfiguracja.
        $config = $this->loadConfig($env, $request);
        $logger->setDirectory($config->logPath());
        $proxy = new TrustedProxy($config->trustedProxies);
        $https = $proxy->isHttps($request->serverParams());

        // 5. Nagłówki bezpieczeństwa — zanim cokolwiek wyprodukuje treść.
        $headers = new SecurityHeaders($config->hstsMaxAge, $config->hstsIncludeSubdomains, $config->noindex);
        $headers->send($https);
        $session = new Session();
        $authSession = new AuthSession($session);
        $csrf = new CsrfToken($session);
        $path = $request->path($config->basePath, $config->frontController);
        $urls = new UrlGenerator(
            $config->routePrefix(),
            $config->basePath,
            $config->assetVersion,
            UrlGenerator::variantForPath($path),
            // ⚠ Wersja zasobu z pliku: na dev `filemtime`, w paczce skrót treści z manifestu,
            // a `ASSET_VERSION` tylko jako ścieżka zapasowa (Support/AssetVersions).
            new AssetVersions($config->assetsPath, $config->assetVersion, $config->isDev()),
        );
        // ⚠ Widok i miesiąc jadą do belki, żeby przełączenie spółki wróciło na TEN SAM ekran
        // kalendarza, a nie na miesiąc bieżący. Surowe wartości z adresu — rozpoznaje je
        // `ContextController::powrot()`, bo to on buduje adres powrotu (§11 reguł).
        $viewContext = new ViewContext($path, $urls, [
            'widok' => $request->queryString('widok', 8),
            'miesiac' => $request->queryString('miesiac', 7),
        ]);
        $twig = $this->createTwig($config, $csrf, $viewContext, $urls);
        $errors->configure($twig, $headers, $https);

        // 6. Sesja — rejestr w bazie (rozdz. 5).
        $services = new Services($config);
        // Licznik zgłoszeń firm w menu: zapytanie dopiero wtedy, gdy szablon o nie poprosi.
        $viewContext->withSignupCounter(static fn (): int => count(array_filter(
            $services->apiKeys->signups(),
            static fn (array $s): bool => $s['status'] === 'pending',
        )));
        // Strefa prezentacji (§10) — też leniwie: strona bez ani jednej daty nie pyta o nią bazy.
        $viewContext->withTimezone(static fn (): string => $services->settings->get('timezone', Strefa::DOMYSLNA));
        // Kolejka po odpowiedzi: gdy tak ustawił instalator ALBO cron nie jest potwierdzony przebiegiem.
        if (CronHeartbeat::kolejkaPoZadaniu(
            ($config->deploy['queue_on_request'] ?? false) === true,
            (new CronHeartbeat($config->varPath))->confirmed((int) ($config->deploy['cron_minutes'] ?? 0)),
        )) {
            $this->afterResponse = static fn () => (new QueueOnRequest($services, $logger))->run();
        }
        // API connectora: bez sesji i bez CSRF — rozpoznane przed startem sesji (docs/api-connector.md §5).
        if (str_starts_with($path, '/api/')) {
            return (new ApiKernel($services, $logger))->handle($request, $path, $this->loadRouter($config, ControllerFactory::knows(...)));
        }
        $fingerprint = new ClientFingerprint($proxy, $config->appKeyBytes());
        $sessionHandler = new DatabaseSessionHandler(
            $services->sessions,
            ClientInfo::fromServer($request->serverParams(), $proxy, $fingerprint),
            $config->session['idle_timeout'],
            $authSession->registryMetadata(...),
        );
        (new SessionStarter($sessionHandler, $config->session['gc_maxlifetime']))->start($https);

        // 7. Router -> łańcuch middleware -> kontroler.
        $view = new View($twig, $urls);
        $twoFactor = $services->twoFactor($session);
        $cronStan = $services->cronStan();
        // Jedno repozytorium widoku pracownika dla pulpitu i obu widoków kalendarza — te ekrany
        // muszą czytać te same dane tym samym zapytaniem, nie dwoma.
        $pracownikRepo = new PracownikRepository($services->db);
        // ⚠ **Jedna instancja widoczności kalendarza na żądanie** — pyta o reguły dostępu przy
        // każdym wyświetleniu kalendarza i przy znaczniku spółki w belce. Dwie instancje
        // to dwa razy te same zapytania o reguły, na każdym ekranie.
        $zasiegPodgladu = new ZasiegPodgladu($services->hierarchyRepository(), $pracownikRepo);
        $regulyKalendarza = new CalendarAccessRepository($services->db);
        $widocznoscKalendarza = new CalendarVisibility(
            $regulyKalendarza,
            $zasiegPodgladu,
            $pracownikRepo,
            $services->permissions,
        );
        // ⚠ **Jedna instancja na żądanie** — ta sama odpowiedź „w jakiej spółce jestem" dla
        // znacznika w belce, dla middleware i dla kontrolerów. Dwie instancje to dwa zapytania
        // o to samo, a przy znaczniku stojącym na każdym ekranie to dwa razy więcej niż trzeba.
        $kontekstSpolki = new KontekstSpolki(
            $services->employments,
            $services->permissions,
            $widocznoscKalendarza,
            $authSession,
            $services->accounts,
        );
        $controllers = new ControllerFactory(new ControllerDependencies(
            $view,
            $urls,
            $errors,
            $authSession,
            $fingerprint,
            $services->auth,
            new LinkHaslaView($services->linkHasla(), $view, $fingerprint, $authSession, $services->accounts, $urls),
            $services->passwordReset,
            $services->invitations,
            $services->access,
            $services->konta,
            $services->employments,
            $services->events,
            $services->jobs,
            $services->accounts,
            $services->companies,
            $services->people,
            $services->permissions,
            $twoFactor,
            $services->devices,
            $session,
            $config,
            $this->appRoot,
            $services->license,
            $services->canary(),
            $services->apiKeyService(),
            $services->apiKeys,
            $services->syncRepository(),
            new SyncDomainRepository($services->db),
            new CompanyCalendarRepository($services->db),
            new DataViewRepository($services->db),
            new TenantReset($services->db, $services->events, $this->appRoot),
            $services->settings,
            $services->mailSettings(),
            $services->transportFactory(),
            $cronStan,
            // ⚠ Ten sam `CronStan`, nie drugi: termin wysyłki i sekcja Cron muszą mówić to samo
            // w tej samej sekundzie. Dwa odczyty dałyby dwa różne zdania na jednym ekranie.
            new TerminWysylki($cronStan),
            $services->hierarchia(),
            $services->hierarchyRepository(),
            $services->grupyRepository(),
            $services->grupy(),
            $pracownikRepo,
            new PulpitPracownika($pracownikRepo, $services->syncRepository()),
            new KalendarzPracownika($pracownikRepo),
            $zasiegPodgladu,
            new CalendarFilterRepository($services->db),
            $widocznoscKalendarza,
            $regulyKalendarza,
            new \Wezurlop\Service\WidocznoscService(
                $regulyKalendarza,
                $services->grupyRepository(),
                $services->events,
            ),
            $kontekstSpolki,
            $csrf,
            $services->limiter,
        ));
        // ⚠ Znacznik spółki w belce: **jedno** rozstrzygnięcie na żądanie, liczone leniwie
        // (ekran logowania i strona błędu nie pytają o nic).
        $viewContext->withZnacznikSpolki(
            static fn (CurrentUser $u): array => $kontekstSpolki->rozstrzygnij($u->accountId(), $u->activeEmployment())
        );
        $router = $this->loadRouter($config, $controllers->exists(...));
        $urls->setMobileRouteCheck($router->hasPath(...));

        // Rodzaj klienta: po zalogowaniu z sesji, wcześniej z ciastka (rozdz. 8). ⚠ Znacznik prezentacji,
        // nie poświadczenie — steruje wyłącznie przekierowaniem; /m/… przechodzi przez ten sam łańcuch
        // middleware co wersja webowa (CLAUDE.md §11).
        $client = $authSession->client() ?? ClientKind::fromRequest($request);
        $redirect = $this->variantRedirect($request, $path, $client, $router, $services, $config);
        if ($redirect !== null) {
            return $redirect;
        }

        $match = $router->match($request->method(), $path);

        if ($match->status === RouteMatch::NOT_FOUND) {
            return $errors->errorPage(404, null, $request->isHtmx());
        }
        if ($match->status === RouteMatch::METHOD_NOT_ALLOWED || $match->route === null) {
            return $errors->errorPage(405, null, $request->isHtmx())
                ->withHeader('Allow', implode(', ', $match->allowedMethods));
        }
        $route = $match->route;
        // ⚠ Zakres ekranu do widoku **z definicji trasy** (zlecenie 10.10.2026 §1) — znacznik
        // spółki w belce bierze się z niego, nie ze ścieżki rozpoznawanej w szablonie.
        $viewContext->setZakres($route->zakres);
        if (!$route->isValid()) {
            // Tylko w produkcji — w trybie deweloperskim błędna trasa zatrzymała start wyżej.
            $logger->error('Odmowa: błędna definicja trasy', ['route' => $route->describe(), 'error' => $route->error]);

            return $errors->errorPage(403, null, $request->isHtmx());
        }

        // Kolejność łańcucha jest częścią zabezpieczenia (rozdz. 4).
        $loginUrl = $urls->to('/logowanie');
        $chain = [
            new SessionLifetime(
                $session,
                $config->session['idle_timeout'],
                $config->session['absolute_timeout'],
                $loginUrl,
            ),
            new Csrf($csrf, $errors, $urls->to('/formularz-wygasl'), $urls,
                new OdnowienieFormularza($view, $services->limiter, $fingerprint)),
            new Authenticate(
                $authSession,
                $services->accounts,
                $config->session['partial_timeout'],
                $loginUrl,
                $urls->to('/logowanie/kod'),
                $sessionHandler,
            ),
            new Require2fa($services->permissions, $twoFactor, $urls->to('/ustawienia/2fa'), $urls->to('/ustawienia/2fa/kody'),
                $config->adminTwoFactorRequired),
            new EmploymentContext($authSession, $services->employments, $services->permissions, $view,
                $urls->to('/kontekst'), $services->accounts),
            new LicenseGuard($services->license, $view),
            new Authorize($services->permissions, $errors, $viewContext),
        ];

        $params = $match->params;
        $handler = static function (Request $request) use ($controllers, $route, $params): Response {
            $controller = $controllers->create($route->controller);

            // Akcja pochodzi z config/routes.php (zwalidowana: method_exists), nigdy z adresu (CLAUDE.md §3).
            return $controller->{$route->action}($request, $params);
        };

        return $this->pipeline($chain, $route, $handler)($request);
    }

    /**
     * @param list<Middleware> $chain
     * @param callable(Request): Response $handler
     * @return callable(Request): Response
     */
    private function pipeline(array $chain, Route $route, callable $handler): callable
    {
        $next = $handler;
        foreach (array_reverse($chain) as $middleware) {
            $next = static fn (Request $request): Response => $middleware->process($request, $route, $next);
        }

        return $next;
    }

    /**
     * Powłoka natywna wchodzi na „/” → widok mobilny. Przy MOBILE_ACCESS=app_only przeglądarka
     * na /m/… dostaje przekierowanie na adres webowy — nie 403 i nie 404 (rozdz. 8). Tylko GET/HEAD:
     * formularz wysłany z widoku mobilnego nie może zgubić treści na przekierowaniu.
     */
    private function variantRedirect(Request $request, string $path, ClientKind $client, Router $router, Services $services, Config $config): ?Response
    {
        $method = $request->method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        if ($path === '/' && $client->isApp()) {
            return Response::redirect($config->routePrefix() . '/m', 302);
        }
        if (UrlGenerator::variantForPath($path) !== UrlGenerator::MOBILE || $client->isApp()
            || $services->settings->get('mobile_access', self::MOBILE_ACCESS_APP_ONLY) === self::MOBILE_ACCESS_OPEN) {
            return null;
        }
        $target = substr($path, 2);
        $target = $target === '' || !$router->hasPath($target) ? '/' : $target;
        $query = parse_url($request->serverValue('REQUEST_URI') ?? '', PHP_URL_QUERY);
        $location = $config->routePrefix() . $target . (is_string($query) && $query !== '' ? '?' . $query : '');

        return Response::redirectFor($request, $location)->withHeader('Cache-Control', 'no-store');
    }

    /** @param callable(string): bool $controllerExists */
    private function loadRouter(Config $config, callable $controllerExists): Router
    {
        $routesFile = $this->appRoot . '/config/routes.php';
        $factory = require $routesFile;
        $definitions = is_callable($factory) ? $factory($config->isDev()) : null;
        $mtime = filemtime($routesFile);

        return Router::fromDefinitions(
            is_array($definitions) ? $definitions : [],
            $controllerExists,
            $config->isDev(),
            $config->varPath . '/cache/routes.php',
            $mtime === false ? 0 : $mtime,
        );
    }

    /** @param array<string, string> $env */
    private function installLockPath(array $env): string
    {
        // Ta sama reguła co VAR_PATH w config/config.php; konfiguracja nie jest jeszcze wczytana.
        $varPath = ($env['VAR_PATH'] ?? '') !== '' ? rtrim($env['VAR_PATH'], '/') : $this->appRoot . '/var';

        return $varPath . '/install.lock';
    }

    /**
     * Przed instalacją (docs/szkielet-tenanta.md rozdz. 12): sonda prefiksów, instalator,
     * a każdy inny adres — przekierowanie do instalatora. Prefiksy liczone ze zmiennych serwera,
     * a gdy ich brak — z SCRIPT_NAME i tego, czy adres żądania zawiera front controller.
     */
    private function notInstalled(Request $request, ErrorHandler $errors, string $envFile, Logger $logger): Response
    {
        [$basePath, $frontController] = $this->preInstallPrefixes($request);
        $path = $request->path($basePath, $frontController);
        $https = (new TrustedProxy([]))->isHttps($request->serverParams());
        $headers = SecurityHeaders::withoutHsts();
        $headers->send($https);

        if ($path === PrefixDetector::PROBE_PATH) {
            $nonce = $request->query('n');
            if (!is_string($nonce) || preg_match('/^[a-f0-9]{16}$/', $nonce) !== 1) {
                return Response::text("Nieprawidłowe żądanie\n", 400);
            }

            $auth = $request->header('Authorization') === PrefixDetector::AUTH_PROBE_VALUE
                ? PrefixDetector::AUTH_RECEIVED : PrefixDetector::AUTH_MISSING;

            return Response::text(PrefixDetector::probeAnswer($nonce) . "\n" . $auth . "\n")
                ->withHeader('Cache-Control', 'no-store');
        }

        $routePrefix = $basePath . $frontController;
        if ($path !== InstallController::PATH) {
            return Response::redirect($routePrefix . InstallController::PATH, 302)
                ->withHeader('Cache-Control', 'no-store');
        }

        // Instalator też ma dostawać świeże arkusze: katalog zasobów znamy z `appRoot`.
        $urls = new UrlGenerator($routePrefix, $basePath, '0', UrlGenerator::WEB,
            new AssetVersions($this->appRoot . '/public/assets', '0', true));
        $twig = TwigFactory::create($this->appRoot . '/templates', null, false, $urls, [
            'csrf_token'   => static fn (): string => '',
            'current_user' => static fn (): ?array => null,
            'can'          => static fn (string $permission): bool => false,
            'active'       => static fn (string $section): bool => false,
            // Instalator nie ma sesji ani spółki — znacznik w belce po prostu nie istnieje.
            'znacznik_spolki' => static fn (): ?array => null,
            'zgloszenia_firm' => static fn (): int => 0,
            'czas_pracy'   => WorkTime::format(...),
            'czas_godziny' => WorkTime::hours(...),
            'czas_minuty'  => WorkTime::minutes(...),
            'norma_dnia'   => WorkTime::dayNorm(...),
            'etat'         => WorkTime::fte(...),
            'etat_krotki'  => WorkTime::fteKrotki(...),
            'etat_opis'    => WorkTime::fteOpis(...),
            'odmiana_osob' => Odmiana::osoby(...),
            'odmiana_osob_d' => Odmiana::osobyDopelniacz(...),
            'odmiana_nieobecnosci' => Odmiana::nieobecnosci(...),
            'odmiana_dni_robocze' => Odmiana::dniRobocze(...),
            'odmiana_wpisow' => Odmiana::wpisy(...),
            'odmiana_zadan' => Odmiana::zadania(...),
            'nip_grupami'  => Nip::human(...),
            'data_dnia'    => DataDnia::pl(...),
            'czas_wzgledny' => static fn (?string $utc): string => RelativeTime::format($utc),
            // Instalator działa, zanim ustawienia istnieją — strefa domyślna, ta sama funkcja.
            'czas'          => static fn (?string $utc): string => Strefa::czas($utc, Strefa::DOMYSLNA),
            'czas_sekundy'  => static fn (?string $utc): string => Strefa::czasZSekundami($utc, Strefa::DOMYSLNA),
            'czas_ze_strefa' => static fn (?string $utc): string => Strefa::czasZeStrefa($utc, Strefa::DOMYSLNA),
            'czas_pelny'     => static fn (?string $utc): string => Strefa::czasSekundyZeStrefa($utc, Strefa::DOMYSLNA),
            'czas_data'     => static fn (?string $utc): string => Strefa::czas($utc, Strefa::DOMYSLNA, 'd.m.Y'),
            'czas_godzina'  => static fn (?string $utc): string => Strefa::godzina($utc, Strefa::DOMYSLNA),
            'czas_godzina_krotka' => static fn (?string $utc): string => Strefa::godzinaKrotka($utc, Strefa::DOMYSLNA),
            'czas_dzien_naglowek' => static fn (?string $utc): string => Strefa::naglowekDnia($utc, Strefa::DOMYSLNA),
            'czas_prognoza' => static fn (?string $utc): string => Strefa::prognoza($utc, Strefa::DOMYSLNA),
            'strefa_tenanta' => static fn (): string => Strefa::DOMYSLNA,
        ]);
        $errors->configure($twig, $headers, $https);

        $env = EnvFile::exists($envFile) ? EnvFile::load($envFile) : [];
        $varPath = ($env['VAR_PATH'] ?? '') !== '' ? rtrim($env['VAR_PATH'], '/') : $this->appRoot . '/var';

        return (new InstallController($this->appRoot, $envFile, $varPath, $twig, $logger, $https))->handle($request);
    }

    /** @return array{0: string, 1: string} */
    private function preInstallPrefixes(Request $request): array
    {
        $base = $this->serverPrefix($request, 'APP_BASE_PATH');
        $front = $this->serverPrefix($request, 'APP_FRONT_CONTROLLER');
        if ($base !== '' || $front !== '') {
            return [$base, $front];
        }
        $scriptName = $request->serverValue('SCRIPT_NAME') ?? '';
        if (!str_ends_with($scriptName, '/index.php')) {
            return ['', ''];
        }
        $scriptDir = substr($scriptName, 0, -strlen('/index.php'));
        $scriptDir = Config::isValidBasePath($scriptDir) ? $scriptDir : '';
        $uriPath = parse_url($request->serverValue('REQUEST_URI') ?? '', PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? $uriPath : '';

        if ($uriPath === $scriptName || str_starts_with($uriPath, $scriptName . '/')) {
            return [$scriptDir, '/index.php'];
        }
        if ($scriptDir !== '' && ($uriPath === $scriptDir || str_starts_with($uriPath, $scriptDir . '/'))) {
            return [$scriptDir, ''];
        }

        return ['', ''];
    }

    /** @param array<string, string> $env */
    private function loadConfig(array $env, Request $request): Config
    {
        $factory = require $this->appRoot . '/config/config.php';

        return Config::fromArray($factory($env, $request->serverParams(), $this->appRoot));
    }

    private function createTwig(Config $config, CsrfToken $csrf, ViewContext $viewContext, UrlGenerator $urls): Environment
    {
        return TwigFactory::create(
            $config->templatesPath,
            $config->varPath . '/cache/twig',
            $config->isDev(),
            $urls,
            [
                'csrf_token'   => $csrf->token(...),
                // ⚠ Po ilu sekundach od wczytania strony warto poprosić o świeży token
                // (zlecenie Z56 §2) — **z konfiguracji sesji**, nie z liczby w szablonie
                // ani w JS. Inaczej skrócenie czasu bezczynności u klienta rozjechałoby się
                // z progiem w przeglądarce i formularz znów wygasałby bez ostrzeżenia.
                'prog_tokenu'  => static fn (): int => OdnowienieFormularza::progSekund($config->session['idle_timeout']),
                'current_user' => $viewContext->currentUser(...),
                'can'          => $viewContext->can(...),
                'active'       => $viewContext->active(...),
                // ⚠ **Znacznik spółki w górnej belce** (zlecenie 10.10.2026 §1): jedna kontrolka
                // na każdym ekranie, z zakresem z definicji trasy. Liczony leniwie i raz.
                'znacznik_spolki' => $viewContext->znacznikSpolki(...),
                // Licznik czekających zgłoszeń w menu — liczony leniwie, tylko gdy pozycja jest widoczna.
                'zgloszenia_firm' => $viewContext->pendingSignups(...),
                // Minuty z kadr na „dni i godziny” — czysta funkcja, bez bazy i bez plików (CLAUDE.md §5).
                'czas_pracy'   => WorkTime::format(...),
                'czas_godziny' => WorkTime::hours(...),
                'czas_minuty'  => WorkTime::minutes(...),
                'norma_dnia'   => WorkTime::dayNorm(...),
                'etat'         => WorkTime::fte(...),
                // ⚠ Dwie formy etatu, bo decyduje pozycja: w kolumnie liczbowej sama liczba
                // („0,50"), a pełny opis („1/2 etatu") w `title` (Z45 §4).
                'etat_krotki'  => WorkTime::fteKrotki(...),
                'etat_opis'    => WorkTime::fteOpis(...),
                // Odmiana liczebnika — czysta funkcja, jedno miejsce (`Odmiana`), zero logiki w szablonie.
                // ⚠ Dwie funkcje dla jednego słowa, bo o formie decyduje POZYCJA W ZDANIU:
                // `odmiana_osob` dla mianownika („zasięg: 53 osoby"), `odmiana_osob_d`
                // po przyimku rządzącym dopełniaczem („widać 30 z 53 osób", „u 2 osób").
                'odmiana_osob' => Odmiana::osoby(...),
                'odmiana_osob_d' => Odmiana::osobyDopelniacz(...),
                'odmiana_nieobecnosci' => Odmiana::nieobecnosci(...),
                // ⚠ „i 2 inne", „i 7 innych" — forma idzie za rodzajem żeńskim rzeczownika
                // domyślnego (osoba/firma), a sam rzeczownik stoi w zdaniu wyżej (Z58, pole firm).
                'odmiana_innych' => Odmiana::inne(...),
                // ⚠ „23 dni robocze", nie „23 dni roboczych" — trzecia forma polskiego
                // liczebnika. Lista wpisów w Miesiącu podaje dni bez nagłówka kolumny,
                // więc słowo musi być odmienione w zdaniu.
                'odmiana_dni_robocze' => Odmiana::dniRobocze(...),
                // „1 wpis”, „3 wpisy”, „5 wpisów” — wpisy kadrowe sklejone w jeden ciąg.
                'odmiana_wpisow' => Odmiana::wpisy(...),
                // „1 zadanie”, „3 zadania”, „5 zadań” — kafel kolejki na `/ustawienia/poczta` (Z43).
                'odmiana_zadan' => Odmiana::zadania(...),
                'nip_grupami'  => Nip::human(...),
                // ⚠ Data KALENDARZOWA z kadr (`2009-03-09` → `09.03.2009`), nie znacznik czasu:
                // nie przechodzi przez strefę, bo data zatrudnienia godziny nie ma, a przeliczanie
                // stref dałoby przesunięcie o dzień (`Support\DataDnia`, docs §10 punkt 3).
                'data_dnia'    => DataDnia::pl(...),
                'czas_wzgledny' => static fn (?string $utc): string => RelativeTime::format($utc),
                // ⚠ JEDNO miejsce, w którym UTC z bazy staje się czasem użytkownika (docs §10 punkt 3).
                // Szablony i kontrolery nie formatują dat same.
                'czas'          => static fn (?string $utc): string => Strefa::czas($utc, $viewContext->timezone()),
                'czas_sekundy'  => static fn (?string $utc): string => Strefa::czasZSekundami($utc, $viewContext->timezone()),
                'czas_ze_strefa' => static fn (?string $utc): string => Strefa::czasZeStrefa($utc, $viewContext->timezone()),
                // ⚠ Data, godzina Z SEKUNDAMI i nazwa strefy — forma do zestawiania z logiem
                // i dziennikiem. „Ile temu" jest prawdą tylko w chwili renderowania.
                'czas_pelny'     => static fn (?string $utc): string => Strefa::czasSekundyZeStrefa($utc, $viewContext->timezone()),
                'czas_data'     => static fn (?string $utc): string => Strefa::czas($utc, $viewContext->timezone(), 'd.m.Y'),
                'czas_godzina'  => static fn (?string $utc): string => Strefa::godzina($utc, $viewContext->timezone()),
                'czas_godzina_krotka' => static fn (?string $utc): string => Strefa::godzinaKrotka($utc, $viewContext->timezone()),
                // ⚠ Kolumna „Logowanie" na liście kont administracyjnych (Z59): „dziś, 09:41"
                // przy dzisiejszym dniu, sama data przy starszym. Strefa z ustawień tenanta,
                // jak w każdym innym `czas_*` — inaczej „dziś" liczyłoby się w UTC.
                'czas_logowanie' => static fn (?string $utc): string => Strefa::logowanie($utc, $viewContext->timezone()),
                'czas_dzien_naglowek' => static fn (?string $utc): string => Strefa::naglowekDnia($utc, $viewContext->timezone()),
                // ⚠ Prognoza BEZ sekund — patrz `Strefa::prognoza()`. Nie ta sama funkcja
                // co pomiar, żeby różnicy nie dało się przegapić przy pisaniu szablonu.
                'czas_prognoza' => static fn (?string $utc): string => Strefa::prognoza($utc, $viewContext->timezone()),
                'strefa_tenanta' => $viewContext->timezone(...),
            ],
        );
    }

    /** Prefiks adresów przed wczytaniem konfiguracji — tylko ze zmiennej serwera, walidowany. */
    private function serverPrefix(Request $request, string $key): string
    {
        $value = $request->serverValue($key) ?? $request->serverValue('REDIRECT_' . $key) ?? '';
        $valid = $key === 'APP_FRONT_CONTROLLER'
            ? Config::isValidFrontController($value)
            : Config::isValidBasePath($value);

        return $valid ? $value : '';
    }
}
