<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console;

use DateTimeImmutable;
use InvalidArgumentException;
use DateTimeZone;
use Phinx\Console\PhinxApplication;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;
use Wezurlop\Dev\Console\Simulator\Checker;
use Wezurlop\Dev\Console\Simulator\Connector;
use Wezurlop\Dev\Console\Simulator\Examples;
use Wezurlop\Dev\Console\Simulator\HttpClient;
use Wezurlop\Dev\Console\Simulator\OpenApi;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Dev\Repository\SyncFixtureRepository;
use Wezurlop\Service\Calendar\WorkingCalendar;
use Wezurlop\Service\Sync\TaskService;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * bin/sync-symulator.php — symulator connectora R2 dla API tenanta. WYŁĄCZNIE środowisko deweloperskie:
 * przed każdym celem odtwarza bazę z seeda (rollback → migrate → seed), więc na czymkolwiek innym niż dev
 * byłby narzędziem do skasowania danych — odmawia pracy, gdy APP_ENV ≠ dev.
 *
 *   bin/sync-symulator.php                                  (oba cele, wszystkie scenariusze)
 *   bin/sync-symulator.php --cel=tenant --scenariusze=przyklady,powtorki
 *   bin/sync-symulator.php --rekordy=10000                  (wielkość pełnego ładowania w pomiarze)
 *   bin/sync-symulator.php --url-tenant-cgi=http://… --adres-tenant-cgi=127.0.0.2
 *   bin/sync-symulator.php --cel=paczka                     (zbudowana paczka: dev/package-vhost.sh)
 *   bin/sync-symulator.php --force                          (⚠ mimo danych w bazie — patrz niżej)
 *   bin/sync-symulator.php --cel=paczka --baza=<nazwa-bazy>_test
 *                                                           (osobna baza testowa: regresja nie dotyka bazy,
 *                                                            na której trwa przebieg integracyjny; instancję
 *                                                            testową stawia PACZKA_DB=… dev/package-vhost.sh)
 *
 * ⚠ BLOKADY, świadomie proste (rozstrzygnięcia 20.09.2026):
 * 1. Symulator odmawia startu, gdy w bazie jest klucz API wydany poza symulatorem ALBO firma o NIP-ie
 *    spoza zestawu seeda — jedno i drugie znaczy, że ktoś na tej bazie pracuje, a przed każdym celem
 *    robimy `rollback → migrate → seed`. ⚠ Sam klucz nie wystarcza: dane prawdziwego przebiegu przeżyją
 *    unieważnienie klucza. Świadome obejście: `--force`.
 * 2. Na celu `paczka` odmawia startu, gdy paczka jest starsza niż ostatni commit repozytorium kodu.
 *    Test, który po cichu sprawdza poprzednią wersję, jest gorszy niż brak testu (CLAUDE.md §15).
 *
 * Scenariusze: przyklady (docs/przyklady-api.md co do pola: 1.x, 2.x, 3.x, 4.x), przebieg (życie
 * przebiegId: koniec, wygaśnięcie, nowy start, nieznany), klucze (wymiana klucza przy tej samej firmie
 * nie wywołuje pełnego ładowania; jedna firma = jeden aktywny klucz), niezmiennik (zapis i usuniecie tego samego
 * wniosku nigdy w jednym cyklu), przeglad (przerwany
 * przegląd nie kasuje niczego), powtorki (paczka i wynik powtórzone), pracownik (usunięcie pracownika
 * z zatrudnieniem i nieobecnościami), okno (usunięcie nieobecności z roku poza oknem), pomiar (czasy).
 */
final class SyncSimulatorCommand
{
    /** Baza z --baza=; null = baza z .env. Paczka musi stać na tej samej, inaczej żądania i dane się rozjeżdżają. */
    private ?string $database = null;

    private const SCENARIOS = ['przyklady', 'przebieg', 'niezmiennik', 'klucze', 'przeglad', 'powtorki', 'pracownik', 'okno', 'stan', 'pola', 'tozsamosc', 'pomiar'];
    private const NIP_A = '1234567009';
    private const NIP_B_SIGNUP = '9876543210';
    private const NIP_LOAD = '5252525006';
    /** Czwarty poprawny NIP — zgłoszenie, które administrator odrzuca. */
    private const NIP_REJECTED = '7010000005';
    /** NIP o błędnej sumie kontrolnej (stary przykład z dokumentacji). */
    private const NIP_INVALID = '1234567890';
    /** Firma, której u nas nie ma — stan `firma_nieznana` w odpowiedzi 403. */
    private const NIP_NOWA = '1111111111';
    /** ⚠ Tylko dla scenariusza „stan”: firma, której nikt nie zna i nie zgłasza w innych scenariuszach. */
    private const NIP_STAN = '3030303037';
    /** Firmy, które tworzy seed albo sam symulator; każda inna w bazie blokuje start (blokada 1). */
    private const SEED_NIPS = [self::NIP_A, self::NIP_B_SIGNUP, self::NIP_LOAD, self::NIP_NOWA];
    /** Zbiory poza `nieobecnosc` — do potwierdzenia kursorów pustą paczką. */
    private const ZBIORY_BEZ_NIEOBECNOSCI = ['firma', 'pracownik', 'zatrudnienie', 'limit', 'slownik', 'kalendarz'];
    private const YEAR = 2026; // rok przykładów z dokumentu

    private Services $s;
    private SyncFixtureRepository $fx;
    private SyncDomainRepository $domain;
    private Examples $ex;
    private Checker $check;
    private OpenApi $api;
    private int $companyA = 0;
    /** @var array<string, string> requestId wydane w 1.3 (zapis/usuniecie) i 2.x */
    private array $rid = [];
    /** @var array<string, int> wnioski z przykładów 2.x */
    private array $req = [];
    /** @var array<string, array<string, float|int|string>> */
    private array $timings = [];

    /**
     * @param list<string> $argv
     * @param resource $out
     */
    public function __construct(private readonly array $argv, private $out, private readonly string $appRoot)
    {
    }

    public function __invoke(Services $services, Logger $logger): int
    {
        if (!$services->config->isDev()) {
            fwrite($this->out, "Symulator odtwarza bazę z seeda — działa wyłącznie przy APP_ENV=dev.\n");

            return 2;
        }
        $opt = ['cel' => 'tenant,tenant-cgi', 'scenariusze' => implode(',', self::SCENARIOS), 'rekordy' => '10000',
            'url-tenant' => $services->config->appUrl, 'adres-tenant' => (string) ($services->config->deploy['self_addr'] ?? ''),
            'url-tenant-cgi' => 'http://tenant-cgi.wezurlop.pl/public/index.php', 'adres-tenant-cgi' => '127.0.0.2',
            'url-paczka' => 'http://paczka.localhost:8090', 'adres-paczka' => '127.0.0.1'];
        foreach (array_slice($this->argv, 1) as $arg) {
            if ($arg === '--force') {
                continue;
            }
            if (preg_match('/^--(cel|scenariusze|rekordy|url-tenant|adres-tenant|url-tenant-cgi|adres-tenant-cgi|url-paczka|adres-paczka|baza)=(.*)$/', $arg, $m) !== 1) {
                fwrite($this->out, "Nieznana opcja: {$arg} (opis w dev/Console/SyncSimulatorCommand.php)\n");

                return 2;
            }
            $opt[$m[1]] = $m[2];
        }
        $force = in_array('--force', array_slice($this->argv, 1), true);
        $scenarios = array_values(array_intersect(self::SCENARIOS, explode(',', $opt['scenariusze'])));
        $targets = array_values(array_intersect(['tenant', 'tenant-cgi', 'paczka'], explode(',', $opt['cel'])));
        $records = max(500, (int) $opt['rekordy']);

        // ⚠ Osobna baza testowa: i my, i phinx pracujemy na niej, a instancja HTTP musi być na nią wskazana
        // (dev/package-vhost.sh z PACZKA_DB). Bez tego symulator kasowałby bazę, na której ktoś pracuje.
        if (($opt['baza'] ?? '') !== '') {
            if (preg_match('/^[a-z0-9_]{1,64}$/', $opt['baza']) !== 1) {
                fwrite($this->out, "Nazwa bazy: małe litery, cyfry i podkreślenia.\n");

                return 2;
            }
            // ⚠ Cele „tenant” i „tenant-cgi” to instancje czytające bazę z .env — z --baza= wysyłałyby żądania
            // do bazy roboczej, a seed leciałby do testowej. Tylko „paczka” dostaje nazwę bazy (PACZKA_DB w vhoście).
            $inne = array_values(array_diff($targets, ['paczka']));
            if ($inne !== []) {
                fwrite($this->out, '--baza= działa wyłącznie z celem „paczka” (jego vhost dostaje PACZKA_DB).'
                    . ' Cele ' . implode(', ', $inne) . " czytają bazę z .env — seed poszedłby do innej bazy niż żądania.\n"
                    . "Uruchom: bin/sync-symulator.php --cel=paczka --baza={$opt['baza']}\n");

                return 2;
            }
            putenv('WEZURLOP_DB_NAME=' . $opt['baza']);
            $services = $this->servicesFor($opt['baza']);
            $this->database = $opt['baza'];
            fwrite($this->out, "baza testowa: {$opt['baza']}\n");
        }
        $this->s = $services;
        $this->fx = new SyncFixtureRepository($services->db);
        // ⚠ Blokada 1: klucz API wydany NIE przez symulator to znak, że na tej bazie ktoś pracuje
        // (np. trwa przebieg integracyjny) — a symulator kasuje bazę przed każdym celem.
        // Własne klucze symulatora (nazwa „symulator: …”) nie blokują, bo zostają po każdym przebiegu
        // i inaczej --force stałoby się nawykiem, czyli blokadą bez działania.
        try {
            $keys = count(array_filter($services->apiKeys->all(), static fn (array $k): bool => !str_starts_with($k['name'], 'symulator:')));
            $foreign = array_values(array_diff(array_map(
                static fn (array $c): string => $c['nip'],
                $this->fx->companies(),
            ), self::SEED_NIPS));
        } catch (Throwable $e) {
            // Pusta baza bez tabel (świeża baza testowa) — nie ma czego chronić.
            $keys = 0;
            $foreign = [];
        }
        if (($keys > 0 || $foreign !== []) && !$force) {
            fwrite($this->out, "To nie wygląda na bazę do kasowania:\n"
                . ($keys > 0 ? "  · klucze API wydane poza symulatorem: {$keys}\n" : '')
                . ($foreign !== [] ? '  · firmy o NIP-ach spoza seeda: ' . implode(', ', $foreign) . "\n" : '')
                . "Symulator przed każdym celem robi rollback → migrate → seed, więc zniszczyłby ten stan\n"
                . "(np. dane prawdziwego przebiegu integracyjnego — one przeżywają unieważnienie klucza).\n"
                . "Jeśli naprawdę tego chcesz: bin/sync-symulator.php --force\n");

            return 2;
        }
        if ($keys > 0 || $foreign !== []) {
            fwrite($this->out, sprintf("⚠ --force: kasuję bazę (klucze poza symulatorem: %d, firmy spoza seeda: %d).\n",
                $keys, count($foreign)));
        }
        $this->domain = new SyncDomainRepository($services->db);
        $this->ex = new Examples($this->appRoot . '/docs/przyklady-api.md');
        $this->check = new Checker($this->out);
        $this->api = new OpenApi($this->appRoot . '/docs/openapi-sync-v1.json');
        $this->contract();

        foreach ($targets as $target) {
            // ⚠ Blokada 2: dowodem jest przebieg na tym, co wyszło z builda (CLAUDE.md §15).
            $stale = $target === 'paczka' ? $this->stalePackage() : null;
            if ($stale !== null) {
                fwrite($this->out, $stale . "\n");

                return 2;
            }
            $http = new HttpClient($opt['url-' . $target], $opt['adres-' . $target] !== '' ? $opt['adres-' . $target] : null);
            fwrite($this->out, "\n══ Cel: {$target} ({$http->label()})\n");
            $this->reseed();
            $this->settingsDefaults();
            $this->companyA = (int) ($this->domain->companyByNip(self::NIP_A)['id'] ?? 0);
            $this->rid = [];
            $this->req = [];
            foreach ($scenarios as $scenario) {
                $t = hrtime(true);
                match ($scenario) {
                    'przyklady' => $this->examples($http),
                    'przebieg'  => $this->runLifetime($http),
                    'niezmiennik' => $this->taskInvariant($http),
                    'klucze'    => $this->keyRotation($http),
                    'przeglad'  => $this->interruptedReview($http),
                    'powtorki'  => $this->repeats($http),
                    'pracownik' => $this->workerRemoved($http),
                    'okno'      => $this->windowShift($http),
                    'stan'      => $this->connectionState($http),
                    'pola'      => $this->clientFields($http),
                    'tozsamosc' => $this->identityFingerprint($http),
                    'pomiar'    => $this->measure($http, $target, $records),
                };
                fwrite($this->out, sprintf("  (scenariusz %s: %.1f s)\n", $scenario, (hrtime(true) - $t) / 1e9));
            }
            $this->check->scope('zgodność ruchu z docs/openapi-sync-v1.json');
            $errors = [];
            foreach ($http->log as $call) {
                $errors = array_merge($errors, $this->api->response($call['method'], $call['operation'], $call['status'], $call['json']));
            }
            $this->check->that($errors === [], count($http->log) . ' odpowiedzi API zgodnych z OpenAPI (status opisany, treść wg schematu)',
                implode('; ', array_slice(array_unique($errors), 0, 5)));
        }

        $this->summary();

        return $this->check->failures() === [] ? 0 : 1;
    }

    // --- 1.x, 2.x, 3.x, 4.x — przykłady z dokumentu ------------------------------------------------------

    private function examples(HttpClient $http): void
    {
        $c = $this->connector($http, 'przyklady', [self::NIP_A]);

        // 1.1 — kursory jak po wcześniejszych cyklach, przegląd kontrolny przed chwilą
        $this->check->scope('1.x przebieg udany');
        $req = $this->ex->get('1.1', 0);
        $this->fx->setStream($this->companyA, 881, $req['kursory']);
        $r = $c->post('start', $req);
        $this->check->example('1.1', $r, 200, $this->ex->get('1.1', 1), ['przebiegId' => Checker::UUID]);
        $c->runId = (string) ($r['json']['przebiegId'] ?? '');
        $c->cursors = $req['kursory'];

        // 1.2
        $req = Examples::expandSums($this->ex->get('1.2', 0));
        $req['przebiegId'] = $c->runId;
        $r = $c->post('dane', $req);
        $this->check->example('1.2', $r, 200, $this->ex->get('1.2', 1));
        $c->cursors['nieobecnosc'] = (string) ($r['json']['kursor'] ?? '');
        $this->check->that($this->fx->externalAbsence($this->companyA, 'kadrowe:17')['removed'] ?? false, 'kadrowe:17 usunięta z nieobecności zewnętrznych');
        $this->check->that(($this->fx->externalAbsence($this->companyA, 'zasilek:512')['removed'] ?? true) === false, 'zasilek:512 aktywny');
        $this->check->that($this->fx->recordState($this->companyA, 'nieobecnosc', 'urlop:12315') === 'active', 'urlop:12315 w rejestrze, powiązany z wnioskiem');

        // 1.3
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'limit' => 20]);
        $this->check->example('1.3', $r, 200, $this->ex->get('1.3', 0), ['zadania.*.requestId' => Checker::REQUEST_ID]);
        $issued = array_column(is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [], 'requestId');
        $this->rid['zapis'] = (string) ($issued[0] ?? '');
        $this->rid['usuniecie'] = (string) ($issued[1] ?? '');

        // 1.4 — requestId z dokumentu → wydane przez nas, w tej samej kolejności
        $req = $this->ex->get('1.4', 0);
        $req['przebiegId'] = $c->runId;
        foreach ($req['wyniki'] as $i => $w) {
            $req['wyniki'][$i]['requestId'] = $i === 0 ? $this->rid['zapis'] : $this->rid['usuniecie'];
        }
        $r = $c->post('wyniki', $req);
        $this->check->example('1.4', $r, 200, $this->ex->get('1.4', 1));
        $zapis = $this->fx->task($this->rid['zapis']);
        $usun = $this->fx->task($this->rid['usuniecie']);
        $request = $zapis === null ? null : $this->fx->request((int) $zapis['leave_request_id']);
        $this->check->that(($request['status'] ?? '') === 'confirmed' && (int) $request['days_confirmed'] === 4 && (int) $request['minutes_confirmed'] === 1920,
            'wniosek rozbity na dwa wpisy: confirmed, dni i minuty z obu części (4 dni, 1920 min)', json_encode($request) ?: '');
        $this->check->that(count($zapis === null ? [] : $this->fx->r2Records((int) $zapis['leave_request_id'])) === 2, 'dwa wpisy R2 przy wniosku');
        $cancelled = $usun === null ? null : $this->fx->request((int) $usun['leave_request_id']);
        $this->check->that(($cancelled['status'] ?? '') === 'cancelled_confirmed', 'anulowanie potwierdzone w kadrach');
        $this->check->that((bool) ($this->fx->r2Records((int) ($usun['leave_request_id'] ?? 0))[0]['removed'] ?? false), 'urlop:12280 oznaczony jako usunięty');

        // 1.5
        $req = $this->ex->get('1.5', 0);
        $req['przebiegId'] = $c->runId;
        $r = $c->post('koniec', $req);
        $expected = $this->ex->get('1.5', 1);
        $expected['nastepnyInterwal'] = self::interval(false);
        $this->check->example('1.5 (interwał wg pory: ' . $expected['nastepnyInterwal'] . ' s)', $r, 200, $expected);
        $run = $this->fx->run($c->runId);
        $this->check->that(($run['status'] ?? '') === 'closed' && isset($run['stats']['dane']['nieobecnosc']), 'przebieg zamknięty i opisany w sync_run');

        $this->unusualResults($c);
        $c = $this->protocolErrors($http, $c);
        $this->fullLoadAndReview($c);
    }

    /** 2.1–2.5: wyniki nietypowe na wnioskach przygotowanych w fixture. */
    private function unusualResults(Connector $c): void
    {
        $this->check->scope('2.x wyniki nietypowe');
        $cal = new WorkingCalendar();
        $y = self::YEAR;
        $plan = [
            '2.1' => ['311', 0, "$y-12-28", "$y-12-30"],
            '2.2' => ['101', 5, "$y-11-16", "$y-11-16"],
            '2.3' => ['207', 0, "$y-11-20", "$y-11-20"],
            '2.4' => ['101', 0, "$y-12-07", "$y-12-07"],
            '2.5' => ['311', 0, "$y-11-23", "$y-11-23"],
        ];
        $byKey = [];
        foreach ($plan as $section => [$ref, $code, $from, $to]) {
            $days = array_map(static fn ($d): string => is_string($d) ? $d : $d->format('Y-m-d'),
                $cal->workingDays(new DateTimeImmutable($from), new DateTimeImmutable($to)));
            $this->req[$section] = $this->fx->createRequest($this->fx->employmentId($this->companyA, $ref), $this->fx->leaveTypeId('urlop', $code),
                $from, $to, 'approved', $days);
            $byKey[$ref . '|' . $from] = $section;
        }
        $r = $c->start();
        $modes = array_unique(array_column(is_array($r['json']['zbiory'] ?? null) ? $r['json']['zbiory'] : [], 'tryb'));
        $this->check->that($r['status'] === 200 && $modes === ['zmiany'], 'start z kursorami connectora: wszystkie zbiory w trybie zmian', json_encode($modes) ?: '');
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            $section = $byKey[$z['zatrudnienieId'] . '|' . $z['dataOd']] ?? null;
            if ($section !== null) {
                $this->rid[$section] = $z['requestId'];
            }
        }
        $this->check->that(count(array_intersect_key($this->rid, $plan)) === 5, 'wydano pięć zadań zapisu (po jednym na wniosek)');
        $c->koniec();

        // 2.4: pracownik wycofał wniosek po wydaniu zadania → `wycofane` w następnym starcie
        $this->fx->setRequestStatus($this->req['2.4'], 'cancelled');
        $r = $c->start();
        $this->check->that(($r['json']['wycofane'] ?? null) === [$this->rid['2.4'] ?? '-'], 'start: wycofane = [requestId wniosku 2.4]', json_encode($r['json']['wycofane'] ?? null) ?: '');

        $wyniki = [];
        foreach (array_keys($plan) as $section) {
            $w = $this->ex->get($section, 0);
            $w['requestId'] = $this->rid[$section] ?? '';
            $wyniki[] = $w;
        }
        $r = $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => $wyniki]);
        $this->check->that($r['status'] === 200 && ($r['json'] ?? null) === ['przyjeto' => 5], 'wyniki 2.1–2.5 przyjęte (przyjeto: 5)', $r['body']);

        $st = fn (string $s): array => $this->fx->request($this->req[$s]) ?? [];
        $this->check->that($st('2.1')['status'] === 'confirmed' && $st('2.1')['r2_warning_code'] === 'OSTRZ_LIMIT_PRZEKROCZONY' && (int) $st('2.1')['days_confirmed'] === 3,
            '2.1 zapisanoZOstrzezeniem → confirmed z flagą ostrzeżenia');
        $this->check->that($this->fx->count('jobs', 0, TaskService::JOB_HR_ALERT) >= 1, '2.1 alert do kadr w kolejce');
        $this->check->that($st('2.2')['status'] === 'conflict' && $st('2.2')['r2_error_code'] === 'R2_NA_ZADANIE_PRZEKROCZONY', '2.2 blad → conflict');
        $t23 = $this->fx->task($this->rid['2.3'] ?? '');
        $this->check->that($st('2.3')['status'] === 'sync_failed' && ($t23['status'] ?? '') === 'pending' && (int) ($t23['attempts'] ?? 0) === 1,
            '2.3 bladPrzejsciowy → zadanie wraca do puli z licznikiem prób 1');
        $this->check->that(($this->fx->task($this->rid['2.4'] ?? '')['status'] ?? '') === 'withdrawn' && $st('2.4')['status'] === 'cancelled', '2.4 wycofano → zadanie wycofane, wniosek bez zmian');
        $kinds = array_column($this->fx->conflicts($this->companyA), 'kind');
        $this->check->that($st('2.5')['status'] === 'conflict' && in_array('zmiana_czesciowa', $kinds, true)
            && $this->fx->count('jobs', 0, TaskService::JOB_HR_ALERT_URGENT) === 1, '2.5 ZMIANA_CZESCIOWA → conflict i alert o najwyższym priorytecie');

        $r = $c->koniec();
        $this->check->that(($r['json']['nastepnyInterwal'] ?? null) === 30, 'koniec: interwał 30 s, bo czeka zadanie 2.3', $r['body']);
        $c->start();
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $this->check->that(array_column($r['json']['zadania'] ?? [], 'requestId') === [$this->rid['2.3'] ?? '-'], '2.3 wydane ponownie w kolejnym cyklu, z tym samym requestId');
        $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => [
            ['requestId' => $this->rid['2.3'] ?? '', 'status' => 'zapisano', 'kod' => null, 'komunikat' => null,
                'zapisy' => [['id' => 'urlop:12350', 'dataOd' => "$y-11-20", 'dataDo' => "$y-11-20", 'dni' => 1, 'minuty' => 240]]],
        ]]);
        $this->check->that($st('2.3')['status'] === 'confirmed', '2.3 po ponowieniu zapisane → confirmed');

        // ⚠ Wpis podpisany jako nasz, którego nie wydaliśmy: informacja dla kadr, nie konflikt
        $obcy = self::absence('urlop:97001', '101', 'urlop', 0, "$y-05-11", "$y-05-12", 2, 960, null, 'connector', 'wu-NIEZNANYREQUESTID000000001');
        $this->rid['obcy'] = 'urlop:97001';
        $konflikty = $this->fx->count('conflicts', $this->companyA);
        $alerty = $this->fx->count('jobs', 0, TaskService::JOB_HR_ALERT);
        $c->send('nieobecnosc', [$obcy]);
        $this->check->that($this->fx->count('conflicts', $this->companyA) === $konflikty, 'obcy wpis connectora nie tworzy konfliktu');
        $this->check->that($this->fx->count('jobs', 0, TaskService::JOB_HR_ALERT) === $alerty + 1, 'obcy wpis connectora → jedno ostrzeżenie dla kadr');
        $this->check->that(($this->fx->externalAbsence($this->companyA, 'urlop:97001')['removed'] ?? true) === false,
            'obcy wpis trafia do nieobecności zewnętrznych jak każdy inny');
        $c->send('nieobecnosc', [$obcy]);
        $this->check->that($this->fx->count('jobs', 0, TaskService::JOB_HR_ALERT) === $alerty + 1, 'powtórzony ten sam wpis nie dokłada drugiego ostrzeżenia');
        $events = $this->fx->run((string) $c->runId)['stats']['zdarzenia'] ?? [];
        $this->check->that(($events['obceWpisyConnectora'] ?? 0) === 1, 'zdarzenie policzone w sync_run', json_encode($events) ?: '');

        // ⚠ `blad` z niepustymi `zapisy` (przywrócone wpisy z nowymi id): NIE są realizacją wniosku
        // (rozstrzygnięcie 20.09.2026) — nie wiążemy ich z wnioskiem, idą do treści konfliktu.
        $days = array_map(static fn ($d): string => is_string($d) ? $d : $d->format('Y-m-d'),
            $cal->workingDays(new DateTimeImmutable("$y-11-09"), new DateTimeImmutable("$y-11-10")));
        $blocked = $this->fx->createRequest($this->fx->employmentId($this->companyA, '101'), $this->fx->leaveTypeId('urlop', 0),
            "$y-11-09", "$y-11-10", 'approved', $days);
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $rid = '';
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            if ($z['dataOd'] === "$y-11-09") {
                $rid = (string) $z['requestId'];
            }
        }
        $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => [[
            'requestId' => $rid, 'status' => 'blad', 'kod' => 'R2_KOLIZJA', 'komunikat' => 'Termin zajęty w kadrach',
            'zapisy' => [
                ['id' => 'urlop:99001', 'dataOd' => "$y-11-09", 'dataDo' => "$y-11-09", 'dni' => 1, 'minuty' => 480],
                ['id' => 'urlop:99002', 'dataOd' => "$y-11-10", 'dataDo' => "$y-11-10", 'dni' => 1, 'minuty' => 480],
            ],
        ]]]);
        $state = $this->fx->request($blocked) ?? [];
        $detail = $this->fx->conflictDetail($blocked) ?? [];
        $this->check->that(($state['status'] ?? '') === 'conflict' && $state['days_confirmed'] === null && $state['minutes_confirmed'] === null,
            'blad z zapisami: wniosek w conflict, dni i minuty NIE policzone', json_encode($state) ?: '');
        $this->check->that($this->fx->r2Records($blocked) === [], 'blad z zapisami: wpisy nie zostały powiązane z wnioskiem');
        $this->check->that(array_column($detail['blokujaceZapisy'] ?? [], 'id') === ['urlop:99001', 'urlop:99002'],
            'blad z zapisami: oba wpisy w treści konfliktu jako blokujące termin', json_encode($detail) ?: '');
        $c->koniec();
    }

    /** 3.1–3.7. Zwraca connector do dalszej pracy — w 3.4 klucz scenariusza jest wymieniany. */
    private function protocolErrors(HttpClient $http, Connector $c): Connector
    {
        $this->check->scope('3.x błędy protokołu');
        $err = ['blad.zdarzenieId' => Checker::EVENT_ID];
        $c->start();
        $before = $this->fx->count('records', $this->companyA);

        // 3.1 — dwa rekordy niezgodne ze schematem; paczka odrzucona w całości
        $req = Examples::expandSums($this->ex->get('1.2', 0));
        $req['przebiegId'] = $c->runId;
        unset($req['rekordy'][0]['dataDo']);
        $req['rekordy'][1]['dni'] = '5';
        $this->check->example('3.1', $c->post('dane', $req), 422, $this->ex->get('3.1', 0), $err);
        $this->check->that($this->fx->count('records', $this->companyA) === $before, '3.1 nic nie zapisano z odrzuconej paczki');

        // 3.2 — trzy stany firmy w odpowiedzi 403 (rozstrzygnięcie 20.09.2026)
        // a) firma nieznana: nie ma jej u nas i nikt jej nie zgłosił — to jest przykład z dokumentu
        $nowa = new Connector($http, $c->key, self::NIP_NOWA);
        $this->check->example('3.2 (firma_nieznana)', $nowa->start(), 403, $this->ex->get('3.2', 0), $err);
        // b) firma zgłoszona, czeka na decyzję
        $this->check->that(($this->s->apiKeys->signupByNip(self::NIP_NOWA)['status'] ?? null) === null, 'przed zgłoszeniem nie ma wiersza zgłoszenia');
        $c->post('zgloszenie-firmy', ['nip' => self::NIP_NOWA, 'nazwa' => 'Firma nowa sp. z o.o.']);
        $r = $nowa->start();
        $this->check->that($r['status'] === 403 && ($r['json']['blad']['stan'] ?? '') === 'firma_zgloszona',
            '3.2 po zgłoszeniu → stan firma_zgloszona', $r['body']);
        // c) firma istnieje u nas, ale poza zakresem tego klucza
        $b = new Connector($http, $c->key, self::NIP_B_SIGNUP);
        $r = $b->start();
        $this->check->that($r['status'] === 403 && ($r['json']['blad']['stan'] ?? '') === 'firma_poza_zakresem',
            '3.2 firma istnieje, ale poza zakresem klucza → stan firma_poza_zakresem', $r['body']);

        // 3.3 — zgłoszenie firmy; powtórka bez duplikatu; ⚠ każda ścieżka poza `zatwierdzona` zostawia wiersz
        $req = $this->ex->get('3.3', 0);
        $this->check->example('3.3', $c->post('zgloszenie-firmy', $req), 202, $this->ex->get('3.3', 1));
        $this->check->example('3.3 powtórzone', $c->post('zgloszenie-firmy', $req), 202, $this->ex->get('3.3', 1));
        $this->check->that($this->fx->count('signups', 0, self::NIP_B_SIGNUP) === 1, '3.3 jedno zgłoszenie mimo powtórki');
        $this->check->that(($this->s->apiKeys->signupByNip(self::NIP_B_SIGNUP)['status'] ?? '') === 'pending',
            '3.3 firma istniejąca poza zakresem też zostawia wiersz zgłoszenia');
        $signup = array_values(array_filter($this->s->apiKeys->signups(), static fn (array $x): bool => $x['nip'] === self::NIP_B_SIGNUP))[0] ?? null;
        $this->s->apiKeyService()->approveSignup((int) ($signup['id'] ?? 0), null);
        $this->check->example('3.3 po zatwierdzeniu → zatwierdzona', $c->post('zgloszenie-firmy', $req), 200, ['status' => 'zatwierdzona']);
        $r = $b->start();
        $modes = array_unique(array_column(is_array($r['json']['zbiory'] ?? null) ? $r['json']['zbiory'] : [], 'tryb'));
        $this->check->that($r['status'] === 200 && $modes === ['pelny'], 'po zatwierdzeniu przez administratora: przebieg, wszystkie zbiory pełne');
        // zatwierdzenie firmy nieznanej zakłada ją w aplikacji
        $nowaSignup = $this->s->apiKeys->signupByNip(self::NIP_NOWA);
        $this->s->apiKeyService()->approveSignup((int) ($nowaSignup['id'] ?? 0), null);
        $r = $nowa->start();
        $this->check->that($r['status'] === 200 && array_column($this->fx->companies(), 'nip') !== [],
            'zatwierdzenie zgłoszenia zakłada firmę i otwiera przebieg', $r['body']);
        $nowa->koniec();
        // odrzucenie: connector przestaje zgłaszać; cofnięcie przez administratora przywraca „oczekuje”
        $rej = ['nip' => self::NIP_REJECTED, 'nazwa' => 'Firma odrzucana sp. z o.o.'];
        $this->check->example('3.3 nowy NIP → oczekuje', $c->post('zgloszenie-firmy', $rej), 202, ['status' => 'oczekuje']);
        $rejId = (int) ($this->s->apiKeys->signupByNip(self::NIP_REJECTED)['id'] ?? 0);
        $this->s->apiKeyService()->rejectSignup($rejId, null);
        $this->check->example('3.3 po odrzuceniu → odrzucona', $c->post('zgloszenie-firmy', $rej), 200, ['status' => 'odrzucona']);
        $this->check->that(($this->s->apiKeys->signupByNip(self::NIP_REJECTED)['status'] ?? '') === 'rejected', '3.3 powtórka nie zmienia odrzucenia');
        $this->s->apiKeyService()->reopenSignup($rejId, null);
        $this->check->example('3.3 po cofnięciu odrzucenia → oczekuje', $c->post('zgloszenie-firmy', $rej), 202, ['status' => 'oczekuje']);

        // 3.9 — NIP o błędnej sumie kontrolnej: 422 przed sprawdzeniem zakresu klucza, na każdej operacji z nip
        $bad = new Connector($http, $c->key, self::NIP_INVALID);
        $this->check->example('3.9 (start)', $bad->start(), 422, $this->ex->get('3.9', 0), $err);
        $this->check->example('3.9 (zgloszenie-firmy)', $c->post('zgloszenie-firmy', ['nip' => self::NIP_INVALID, 'nazwa' => 'Literówka']), 422, $this->ex->get('3.9', 0), $err);
        $this->check->that($this->fx->count('signups', 0, self::NIP_INVALID) === 0, '3.9 literówka nie trafiła do zgłoszeń');
        $this->check->example('3.9 (zadania)', $bad->get('zadania', ['nip' => self::NIP_INVALID, 'przebiegId' => 'x']), 422, $this->ex->get('3.9', 0), $err);
        $r = $c->get('zadania', ['nip' => 'PL 123-456-70-09', 'przebiegId' => 'nieznany']);
        $this->check->that($r['status'] === 409, 'NIP „PL 123-456-70-09” znormalizowany i w zakresie (409 PRZEBIEG_NIEZNANY)', $r['body']);

        // 3.4 — klucz zastąpiony nowym: stary działa BEZ TERMINU, aż nowy przejmie; potem 401 KLUCZ_WYGASL.
        // ⚠ Wymieniamy klucz TEGO scenariusza, bo firma ma jeden klucz w użyciu (rozstrzygnięcie 20.09.2026) —
        // drugi klucz „obok”, na tę samą firmę, w ogóle nie powstanie.
        $oldKey = $c->key;
        $oldId = $c->keyId;
        $new = $this->s->apiKeyService()->replace($oldId, null);
        $probe = static fn (string $key): array => (new Connector($http, $key, self::NIP_A))->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => 'nieznany']);
        $this->check->that($probe($oldKey)['status'] === 409 && $probe($new['key'])['status'] === 409,
            '3.4 przed przejęciem działają oba klucze (409 PRZEBIEG_NIEZNANY = uwierzytelnione)');
        // Przejęcie: nowy klucz domyka pierwszy przebieg — i dopiero to unieważnia stary.
        $przejmujacy = new Connector($http, $new['key'], self::NIP_A);
        $przejmujacy->keyId = (int) $new['id'];
        $przejmujacy->start();
        $przejmujacy->koniec();
        $this->check->example('3.4', $probe($oldKey), 401, $this->ex->get('3.4', 0), $err);
        $this->check->that($probe($new['key'])['status'] === 409, '3.4 nowy klucz działa dalej');
        $this->check->that(($probe($oldKey)['json']['blad']['powod'] ?? '') === 'zastapiony_nowym_kluczem',
            '3.4 powód: zastapiony_nowym_kluczem');
        // ⚠ Connector wkleja nowy klucz i otwiera NOWY przebieg: przejęcie odbyło się przez pełny cykl
        // nowego klucza, więc przebieg otwarty na starym kluczu został porzucony (to poprawne zachowanie).
        $c = $c->withKey($new['key'], $new['id']);
        $c->start();

        // 3.5 — limit częstotliwości na osobnym kluczu: bierzemy firmę B (zatwierdzoną w 3.3) i odpinamy ją
        // od klucza scenariusza, żeby nie złamać zasady „jedna firma = jeden aktywny klucz”.
        $this->s->apiKeyService()->setCompanies($c->keyId, [$this->companyA], null);
        $burst = $this->connector($http, 'limit', [self::NIP_B_SIGNUP]);
        $r = null;
        for ($i = 0; $i < 300; $i++) {
            $r = $burst->get('zadania', ['nip' => self::NIP_B_SIGNUP, 'przebiegId' => 'nieznany']);
            if ($r['status'] === 429) {
                break;
            }
        }
        $this->check->example('3.5 (po ' . ($i + 1) . ' żądaniach)', $r, 429, $this->ex->get('3.5', 0), $err);
        $retry = $r['headers']['retry-after'] ?? '';
        $this->check->that(preg_match('/^\d+$/', $retry) === 1 && (int) $retry >= 1 && (int) $retry <= 60, '3.5 nagłówek Retry-After: ' . $retry);

        // 3.6 — ponad 500 rekordów, treść ponad 2 MB, bomba gzip
        $one = Examples::expandSums($this->ex->get('1.2', 0))['rekordy'][1];
        $many = [];
        for ($i = 0; $i < 501; $i++) {
            $many[] = ['id' => 'zasilek:9' . $i] + $one;
        }
        $env = ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => true];
        $this->check->example('3.6 (501 rekordów)', $c->post('dane', $env + ['rekordy' => $many]), 413, $this->ex->get('3.6', 0), $err);
        $big = json_encode($env + ['rekordy' => [], 'x' => str_repeat('a', 2_100_000)], JSON_THROW_ON_ERROR);
        $this->check->example('3.6 (treść > 2 MB)', $c->post('dane', [], null, [], $big), 413, $this->ex->get('3.6', 0), $err);
        $bomb = gzencode(json_encode($env + ['rekordy' => [], 'x' => str_repeat('0', 9_000_000)], JSON_THROW_ON_ERROR), 9);
        $this->check->example('3.6 (gzip: ' . strlen((string) $bomb) . ' B → 9 MB)', $c->post('dane', [], null, ['Content-Encoding' => 'gzip'], (string) $bomb), 413,
            $this->ex->get('3.6', 0), $err);
        $r = $c->post('dane', [], null, ['Content-Encoding' => 'gzip'], 'to-nie-jest-gzip');
        $this->check->that($r['status'] === 400 && ($r['json']['blad']['kod'] ?? '') === 'ZADANIE_NIEPOPRAWNE', 'uszkodzony gzip → 400 (nie 500)', $r['body']);
        $gz = gzencode(json_encode(['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []], JSON_THROW_ON_ERROR));
        $r = $c->post('dane', [], null, ['Content-Encoding' => 'gzip'], (string) $gz);
        $this->check->that($r['status'] === 200, 'paczka skompresowana gzip przyjęta', $r['body']);
        $c->cursors['slownik'] = (string) ($r['json']['kursor'] ?? $c->cursors['slownik']);

        // 503 — baza chwilowo zablokowana: paczka czeka najwyżej 10 s i wraca z Retry-After (bez zapisu i bez zapamiętania odpowiedzi)
        $pdo = $this->s->db->pdo();
        $pdo->beginTransaction();
        $this->fx->lockStream($this->companyA);
        $key = Connector::uuid();
        $slow = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []], $key);
        $pdo->rollBack();
        $this->check->example(sprintf('3.8 (blokada w bazie, odpowiedź po %.1f s)', $slow['ms'] / 1000), $slow, 503, $this->ex->get('3.8', 0), $err);
        $this->check->that(($slow['headers']['retry-after'] ?? '') === '30', '3.8 Retry-After: 30');
        $this->check->that((int) ($this->fx->run((string) $c->runId)['stats']['przerwyTechniczne'] ?? 0) === 1, '3.8 licznik 503 w sync_run');
        $retry = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []], $key);
        $this->check->that($retry['status'] === 200 && !isset($retry['headers']['x-idempotent-replay']), 'ponowienie z tym samym kluczem przetworzone od nowa → 200');
        $c->cursors['slownik'] = (string) ($retry['json']['kursor'] ?? $c->cursors['slownik']);

        // 3.7 — niezgodna wersja protokołu; brak nagłówka = wersja 1
        $this->check->example('3.7', $c->post('start', ['nip' => self::NIP_A], null, ['X-Protokol' => '2']), 409, $this->ex->get('3.7', 0), $err);
        $r = $http->request('GET', '/api/v1/sync/zadania?nip=' . self::NIP_A . '&przebiegId=' . $c->runId, null, ['X-Api-Key' => $c->key]);
        $this->check->that($r['status'] === 200, 'brak X-Protokol i Authorization (sam X-Api-Key) → obsłużone jak wersja 1');
        $c->koniec();

        return $c;
    }

    /** 4.1–4.2: pełne ładowanie i przegląd kontrolny. */
    private function fullLoadAndReview(Connector $c): void
    {
        $this->check->scope('4.x pełne ładowanie i przegląd kontrolny');
        $c->cursors = [];
        $r = $c->start();
        $this->check->example('4.1', $r, 200, $this->ex->get('4.1', 0), ['przebiegId' => Checker::UUID], ['zbiory']);
        $modes = array_unique(array_column(is_array($r['json']['zbiory'] ?? null) ? $r['json']['zbiory'] : [], 'tryb'));
        $this->check->that($modes === ['pelny'] && count($r['json']['zbiory']) === 7, '4.1 wszystkie 7 zbiorów pełne, kursory null');

        $conflicts = $this->fx->count('conflicts', $this->companyA);
        $absences = $this->absencesA();
        $c->send('nieobecnosc', array_values($absences));
        // przegląd w dwóch paczkach; druga to dokładnie przykład 4.2
        $req = Examples::expandSums($this->ex->get('4.2', 0));
        $inDoc = array_column($req['pozycje'], 'id');
        $first = [];
        foreach ($absences as $id => $rec) {
            if (!in_array($id, $inDoc, true)) {
                $first[] = ['id' => $id, 'suma' => $rec['suma']];
            }
        }
        $r = $c->post('przeglad', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => false, 'pozycje' => $first]);
        $this->check->that($r['status'] === 200 && ($r['json']['przyjeto'] ?? 0) === count($first), 'przegląd, paczka 1 (ostatnia: false)');
        $req['przebiegId'] = $c->runId;
        $r = $c->post('przeglad', $req);
        $expected = $this->ex->get('4.2', 1);
        // Dokument skraca paczkę do dwóch pozycji z 412 i sam mówi: `przyjeto` = liczba pozycji w TEJ paczce.
        $expected['przyjeto'] = count($req['pozycje']);
        $this->check->example('4.2 (przyjeto = ' . $expected['przyjeto'] . ' pozycji paczki)', $r, 200, $expected, ['kursor' => Checker::CURSOR]);
        $run = $this->fx->run((string) $c->runId);
        $review = $run['stats']['przeglad']['nieobecnosc'] ?? [];
        $this->check->that(($review['brakujace'] ?? -1) === 0 && ($review['rozjazd'] ?? -1) === 0, '4.2 przegląd zgodny: brakujących 0, rozjazdów 0', json_encode($review) ?: '');
        $this->check->that($this->fx->count('conflicts', $this->companyA) === $conflicts, '4.2 bez nowych konfliktów');
        $r = $c->koniec();
        $this->check->that(($r['json']['pelnyPrzegladWymagany'] ?? null) === false, 'koniec: pełny przegląd niewymagany');
    }

    // --- życie przebiegId (docs/api-connector.md 3.1) ---------------------------------------------------

    private function runLifetime(HttpClient $http): void
    {
        $this->check->scope('życie przebiegId');
        $c = $this->connector($http, 'przebieg', [self::NIP_A]);
        $ttl = (int) $this->s->settings->get('sync_run_ttl_minutes', '0');
        $this->check->that($ttl === 30, 'czas życia z tenant_setting.sync_run_ttl_minutes: ' . $ttl . ' min (nie z kodu)');

        // 1) koniec — przebieg zamknięty
        $c->start();
        $closed = (string) $c->runId;
        $c->koniec();
        $r = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $closed, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 409 && ($r['json']['blad']['kod'] ?? '') === 'PRZEBIEG_NIEZNANY'
            && ($r['json']['blad']['powod'] ?? '') === 'zamkniety', 'po koniec → 409 z powod: zamkniety', $r['body']);

        // 2) bezczynność dłuższa niż TTL — przebieg wygasa, kursor zostaje
        $c->start();
        $expiring = (string) $c->runId;
        $batch = $c->send('slownik', [self::sum(['id' => 'urlop:0', 'zrodlo' => 'urlop', 'kod' => 0, 'nazwa' => 'urlop wypoczynkowy',
            'tylkoOdczyt' => false, 'doWniosku' => true, 'nieobecnosc' => true])]);
        $cursor = (string) ($batch[0]['json']['kursor'] ?? '');
        $this->fx->ageRun($expiring, $ttl + 1);
        $r = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $expiring, 'zbior' => 'slownik', 'paczka' => 2, 'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 409 && ($r['json']['blad']['powod'] ?? '') === 'wygasl',
            'po ' . ($ttl + 1) . ' min bezczynności → 409 z powod: wygasl', $r['body']);
        $this->check->that(($this->fx->run($expiring)['status'] ?? '') === 'expired', 'wygasły przebieg ma status expired w sync_run');
        $r = $c->start();
        $this->check->that(($r['json']['zbiory']['slownik']['tryb'] ?? '') === 'zmiany' && ($r['json']['zbiory']['slownik']['kursor'] ?? '') === $cursor,
            'kursor przeżył wygaśnięcie — nowy przebieg podejmuje pracę od ' . $cursor, json_encode($r['json']['zbiory']['slownik'] ?? null) ?: '');

        // 3) nowy start tej samej firmy unieważnia poprzedni (jeden otwarty na firmę)
        $previous = (string) $c->runId;
        $c->start();
        $r = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $previous, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 409 && ($r['json']['blad']['powod'] ?? '') === 'zastapiony',
            'poprzedni przebieg po nowym start → 409 z powod: zastapiony', $r['body']);
        $this->check->that(($this->fx->run($previous)['status'] ?? '') === 'abandoned', 'unieważniony przebieg ma status abandoned');
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $this->check->that($r['status'] === 200, 'nowy przebieg działa dalej');

        // 4) identyfikator, którego nie znamy
        $r = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => Connector::uuid(), 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 409 && ($r['json']['blad']['powod'] ?? '') === 'nieznany',
            'nieznany przebiegId → 409 z powod: nieznany', $r['body']);
        $other = new Connector($http, $c->key, self::NIP_A);
        $other->runId = $c->runId;
        $r = $other->post('dane', ['nip' => self::NIP_B_SIGNUP, 'przebiegId' => $c->runId, 'zbior' => 'slownik', 'paczka' => 1,
            'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 403, 'przebieg innej firmy: najpierw 403 zakresu klucza, bez ujawniania przebiegu', $r['body']);
        $c->koniec();
    }

    // --- niezmiennik: zapis i usuniecie tego samego wniosku nigdy w jednym cyklu -------------------------

    private function taskInvariant(HttpClient $http): void
    {
        $this->check->scope('niezmiennik: zapis i usuniecie nigdy razem');
        $c = $this->ensureLoaded($http, 'niezmiennik');
        $leave = $this->s->leaveRequests();
        $y = self::YEAR;

        // A) zapis wydany i NIEpotwierdzony, wniosek anulowany → wycofane, bez zadania usunięcia
        $a = $leave->submit('101', 'urlop:0', "$y-07-06", "$y-07-07");
        $leave->approve($a['id']);
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $ridA = '';
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            if ($z['dataOd'] === "$y-07-06") {
                $ridA = (string) $z['requestId'];
            }
        }
        $this->check->that($ridA !== '' && count($this->fx->tasksFor($a['id'])) === 1, 'wydane jedno zadanie zapisu, wynik niepotwierdzony');
        $leave->requestCancel($a['id']);
        $state = $leave->approveCancel($a['id']);
        $this->check->that($state === 'cancel_requested' && ($this->fx->request($a['id'])['status'] ?? '') === 'cancel_requested',
            'zapis wydany i niepotwierdzony liczy się jak obecny w kadrach — wniosek NIE zamyka się lokalnie', $state);
        $c->koniec();
        $r = $c->start();
        $this->check->that(in_array($ridA, is_array($r['json']['wycofane'] ?? null) ? $r['json']['wycofane'] : [], true),
            'anulowany wniosek z niepotwierdzonym zapisem → requestId na liście wycofane', json_encode($r['json']['wycofane'] ?? null) ?: '');
        $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $tasks = $this->fx->tasksFor($a['id']);
        $this->check->that(count($tasks) === 1 && $tasks[0]['operation'] === 'zapis', 'nie powstało zadanie usunięcia (byłyby dwa w jednym cyklu)',
            json_encode($tasks) ?: '');
        $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => [
            ['requestId' => $ridA, 'status' => 'wycofano', 'kod' => null, 'komunikat' => null, 'zapisy' => []],
        ]]);
        $tasks = $this->fx->tasksFor($a['id']);
        $this->check->that(count($tasks) === 1 && $tasks[0]['status'] === 'withdrawn', 'wynik wycofano zamknął sprawę', json_encode($tasks) ?: '');
        $this->check->that(($this->fx->request($a['id'])['status'] ?? '') === 'cancelled',
            'po wyniku wycofano wniosek domknięty lokalnie jako cancelled (nic nie trafiło do kadr)');
        $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $this->check->that(count($this->fx->tasksFor($a['id'])) === 1, 'także w kolejnym pobraniu zadań usunięcie nie powstaje');

        // C) anulowanie PRZED wydaniem jakiegokolwiek zadania → zamknięcie lokalne, bez zadania
        $noTask = $leave->submit('101', 'urlop:0', "$y-07-20", "$y-07-21");
        $leave->approve($noTask['id']);
        $leave->requestCancel($noTask['id']);
        $state = $leave->approveCancel($noTask['id']);
        $this->check->that($state === 'cancelled' && $this->fx->tasksFor($noTask['id']) === [],
            'anulowanie przed wydaniem zadania → cancelled lokalnie, zero zadań', $state);
        $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $this->check->that($this->fx->tasksFor($noTask['id']) === [], 'pobranie zadań nie tworzy usunięcia dla wniosku zamkniętego lokalnie');

        // B) zapis POTWIERDZONY, potem anulowanie → dopiero teraz usuniecie, ze wskazaniem wpisów
        $b = $leave->submit('101', 'urlop:0', "$y-07-13", "$y-07-14");
        $leave->approve($b['id']);
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $ridB = '';
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            if ($z['dataOd'] === "$y-07-13") {
                $ridB = (string) $z['requestId'];
            }
        }
        $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => [[
            'requestId' => $ridB, 'status' => 'zapisano', 'kod' => null, 'komunikat' => null,
            'zapisy' => [['id' => 'urlop:98001', 'dataOd' => "$y-07-13", 'dataDo' => "$y-07-14", 'dni' => 2, 'minuty' => 960]],
        ]]]);
        $leave->requestCancel($b['id']);
        $leave->approveCancel($b['id']);
        $r = $c->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $c->runId]);
        $issued = [];
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            if ($z['dataOd'] === "$y-07-13") {
                $issued = $z;
            }
        }
        $tasks = $this->fx->tasksFor($b['id']);
        $this->check->that(($issued['operacja'] ?? '') === 'usuniecie' && array_column($issued['zapisy'] ?? [], 'id') === ['urlop:98001'],
            'po potwierdzonym zapisie anulowanie daje usuniecie ze wskazaniem wpisu', json_encode($issued) ?: '');
        $this->check->that(count($tasks) === 2 && $tasks[0]['status'] === 'done' && $tasks[1]['operation'] === 'usuniecie',
            'dwa zadania wniosku, ale w różnych cyklach: zapis done, potem usuniecie', json_encode($tasks) ?: '');
        $c->koniec();
    }

    // --- wymiana klucza przy tej samej firmie ---------------------------------------------------------

    private function keyRotation(HttpClient $http): void
    {
        $this->check->scope('wymiana klucza przy tej samej firmie');
        $a = $this->ensureLoaded($http, 'klucz A');
        // Kursor dla każdego zbioru: pusta paczka potwierdza zbiór bez dotykania danych.
        foreach (self::ZBIORY_BEZ_NIEOBECNOSCI as $zbior) {
            $a->send($zbior, []);
        }
        $a->koniec();
        $a->start();
        $cursors = $a->cursors;
        $this->check->that(array_filter($cursors) !== [], 'klucz A: dane wczytane, kursory po stronie connectora', json_encode($cursors) ?: '');
        $r = $a->start();
        // ⚠ Zbiór, dla którego connector nie ma kursora, wraca w trybie pełnym i to jest poprawne —
        // (nieobecności bywają wczytane przez wcześniejszy scenariusz). Tryb ma zależeć od kursora, nie od klucza.
        $trybA = array_map(static fn (array $z): string => (string) $z['tryb'], is_array($r['json']['zbiory'] ?? null) ? $r['json']['zbiory'] : []);
        $zKursorem = array_keys(array_filter($cursors, 'is_string'));
        $this->check->that(array_values(array_unique(array_map(static fn (string $z): string => $trybA[$z] ?? '?', $zKursorem))) === ['zmiany'],
            'klucz A: zbiory z kursorem idą w trybie zmian (' . implode(', ', $zKursorem) . ')', json_encode($trybA) ?: '');

        // zadanie w locie: wydane na kluczu A, niepotwierdzone
        $leave = $this->s->leaveRequests();
        $y = self::YEAR;
        $wniosek = $leave->submit('101', 'urlop:0', "$y-08-10", "$y-08-11");
        $leave->approve($wniosek['id']);
        $r = $a->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $a->runId]);
        $rid = '';
        foreach (is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [] as $z) {
            if ($z['dataOd'] === "$y-08-10") {
                $rid = (string) $z['requestId'];
            }
        }
        $this->check->that($rid !== '', 'klucz A: wydane zadanie, wynik niepotwierdzony');
        $a->koniec();

        // unieważnienie A, nowy klucz B z tą samą firmą
        $keyA = array_values(array_filter($this->s->apiKeys->all(), static fn (array $k): bool => $k['name'] === 'symulator: klucz A'))[0] ?? [];
        $this->s->apiKeyService()->revoke((int) ($keyA['id'] ?? 0), null);
        $b = $this->connector($http, 'klucz B', [self::NIP_A]);
        $b->cursors = $cursors;

        $konflikty = $this->fx->count('conflicts', $this->companyA);
        $rekordy = $this->fx->count('records', $this->companyA);
        $r = $b->start();
        $trybB = array_map(static fn (array $z): string => (string) $z['tryb'], is_array($r['json']['zbiory'] ?? null) ? $r['json']['zbiory'] : []);
        $this->check->that($r['status'] === 200 && $trybB === $trybA,
            'klucz B z tymi samymi kursorami → te same tryby co na kluczu A, BEZ pełnego ładowania', json_encode($trybB) ?: '');
        $this->check->that($this->fx->count('records', $this->companyA) === $rekordy
            && $this->fx->count('conflicts', $this->companyA) === $konflikty, 'wymiana klucza nie ruszyła rekordów ani nie dała konfliktów');

        // klucz A po unieważnieniu
        $r = $a->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $a->runId, 'zbior' => 'slownik', 'paczka' => 1, 'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 401 && ($r['json']['blad']['kod'] ?? '') === 'KLUCZ_NIEPRAWIDLOWY'
            && !isset($r['json']['blad']['stan']), 'unieważniony klucz A → 401 bez ujawniania czegokolwiek', $r['body']);

        // zadanie w locie wraca po wygaśnięciu rezerwacji, z TYM SAMYM requestId
        $this->fx->expireReservation($rid);
        $r = $b->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => $b->runId]);
        $ponowne = array_values(array_filter(is_array($r['json']['zadania'] ?? null) ? $r['json']['zadania'] : [],
            static fn (array $z): bool => $z['dataOd'] === "$y-08-10"));
        $this->check->that(($ponowne[0]['requestId'] ?? '') === $rid,
            'zadanie w locie wraca na kluczu B z tym samym requestId (ochrona przed podwójnym zapisem)', json_encode($ponowne) ?: '');
        $this->check->that(count($this->fx->tasksFor($wniosek['id'])) === 1, 'nadal jedno zadanie wniosku, nie dwa');
        $b->koniec();

        // jedna firma = jeden aktywny klucz
        $byNip = array_column($this->fx->companies(), 'id', 'nip');
        $blad = null;
        try {
            $this->s->apiKeyService()->issue('symulator: drugi na tę samą firmę', [(int) $byNip[self::NIP_A]], null);
        } catch (InvalidArgumentException $e) {
            $blad = $e->getMessage();
        }
        $this->check->that($blad !== null && str_contains($blad, 'jest już obsługiwana przez klucz'),
            'drugi aktywny klucz z tą samą firmą odrzucony: ' . ($blad ?? 'BRAK BŁĘDU'));
        $keyB = array_values(array_filter($this->s->apiKeys->all(), static fn (array $k): bool => $k['name'] === 'symulator: klucz B'))[0] ?? [];
        $nowy = $this->s->apiKeyService()->replace((int) ($keyB['id'] ?? 0), null);
        $this->check->that(($nowy['id'] ?? 0) > 0, 'wydanie nowego klucza nie jest blokowane — to ta sama instalacja');

        // ⚠ BEZ TERMINU (model z 20.09.2026): stary klucz działa, dopóki nowy nie przejmie.
        $stan = array_column($this->s->apiKeys->all(), null, 'id');
        $this->check->that(($stan[(int) $keyB['id']]['status'] ?? '') === 'active',
            'stary klucz działa dalej po wydaniu nowego — bez licznika i bez terminu');
        $this->check->that(($stan[(int) $nowy['id']]['last_used_at'] ?? null) === null,
            'nowy klucz jest „wydany, jeszcze nieużyty”');
        // ⚠ Jawna postać starego klucza pochodzi z obiektu connectora ($b), NIE z listy kluczy —
        // repozytorium trzyma wyłącznie skrót. Poprzednia wersja tego scenariusza sprawdzała
        // `$keyB['key'] ?? 'x'`, czyli klucz „x”, który nie istnieje: test przechodził na 401
        // niezależnie od tego, co robi kod.
        $jawnyB = $b->key;
        $r = (new Connector($http, $jawnyB, self::NIP_A))->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => 'nieznany']);
        $this->check->that($r['status'] === 409,
            'stary klucz nadal uwierzytelnia (409 PRZEBIEG_NIEZNANY), choć nowy jest wydany', (string) $r['status']);

        $c = new Connector($http, $nowy['key'], self::NIP_A);
        $c->keyId = (int) $nowy['id'];
        $c->start();
        $c->koniec();
        $stan = array_column($this->s->apiKeys->all(), null, 'id');
        $this->check->that(($stan[(int) $keyB['id']]['status'] ?? '') === 'revoked',
            'pierwszy domknięty przebieg nowego klucza PRZEJMUJE synchronizację — stary klucz unieważniony');
        $r = (new Connector($http, $jawnyB, self::NIP_A))->get('zadania', ['nip' => self::NIP_A, 'przebiegId' => 'nieznany']);
        $this->check->that($r['status'] === 401, 'stary klucz po przejęciu → 401');
        $this->check->that(($r['json']['blad']['powod'] ?? '') === 'zastapiony_nowym_kluczem',
            'powód w 401: zastapiony_nowym_kluczem (kod KLUCZ_WYGASL bez zmian)');
        $zdarzenia = $this->fx->count('events', 0, 'api.key_superseded');
        $this->check->that($zdarzenia >= 1, 'przejęcie odnotowane w dzienniku zdarzeń (api.key_superseded)');
        $this->s->apiKeyService()->revoke((int) $nowy['id'], null);
    }

    // --- przegląd przerwany ------------------------------------------------------------------------------

    private function interruptedReview(HttpClient $http): void
    {
        $this->check->scope('przegląd przerwany w połowie');
        $c = $this->ensureLoaded($http, 'przeglad');
        $absences = $this->absencesA();
        $t0 = $this->fx->dbTime();
        $r = $c->post('przeglad', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => false,
            'pozycje' => [['id' => 'urlop:12315', 'suma' => $absences['urlop:12315']['suma']]]]);
        $this->check->that($r['status'] === 200, 'paczka 1 przeglądu (1 pozycja z ' . count($absences) . ', ostatnia: false)');
        $interrupted = (string) $c->runId;
        $c->start(); // connector padł i otworzył nowy przebieg
        $this->check->that(($this->fx->run($interrupted)['status'] ?? '') === 'abandoned', 'przerwany przebieg porzucony');
        $this->check->that($this->fx->externalAbsence($this->companyA, 'zasilek:512')['removed'] === false, 'zasilek:512 nietknięty');
        $this->check->that($this->fx->recordState($this->companyA, 'nieobecnosc', 'zasilek:513') === 'active', 'zasilek:513 (spoza paczki 1) aktywny w rejestrze');
        $this->check->that($this->fx->count('requests_changed', 0, $t0) === 0, 'żaden wniosek nie zmienił stanu');

        // kompletny przegląd bez zasilek:512 → dopiero teraz usunięcie
        $conflicts = $this->fx->count('conflicts', $this->companyA);
        $positions = [];
        foreach ($absences as $id => $rec) {
            if ($id !== 'zasilek:512') {
                $positions[] = ['id' => $id, 'suma' => $rec['suma']];
            }
        }
        $r = $c->post('przeglad', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => true, 'pozycje' => $positions]);
        $this->check->that($r['status'] === 200, 'kompletny przegląd (ostatnia: true)');
        $this->check->that($this->fx->externalAbsence($this->companyA, 'zasilek:512')['removed'] === true, 'po kompletnym przeglądzie zasilek:512 usunięty');
        $this->check->that($this->fx->count('conflicts', $this->companyA) === $conflicts, 'bez konfliktów (nieobecność zewnętrzna)');
        $r = $c->post('przeglad', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => true,
            'pozycje' => [['id' => 'urlop:12315', 'suma' => $absences['urlop:12315']['suma']]]]);
        $this->check->that($r['status'] === 200 && $this->fx->recordState($this->companyA, 'nieobecnosc', 'zasilek:513') === 'active',
            'drugi przegląd tego zbioru w tym samym przebiegu nie kasuje niczego');
        $c->koniec();
    }

    // --- powtórki ----------------------------------------------------------------------------------------

    private function repeats(HttpClient $http): void
    {
        $this->check->scope('paczka i wynik powtórzone');
        $c = $this->ensureLoaded($http, 'powtorki');
        $absences = $this->absencesA();
        $new = self::absence('kadrowe:18', '312', 'kadrowe', 0, self::YEAR . '-10-12', self::YEAR . '-10-12', 1, 360, null, 'kadry');
        $batch = ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'zbior' => 'nieobecnosc', 'paczka' => 1, 'ostatnia' => true,
            'rekordy' => [$absences['urlop:12315'], $absences['zasilek:513'], $new]];
        $key = Connector::uuid();
        $r1 = $c->post('dane', $batch, $key);
        $records = $this->fx->count('records', $this->companyA);
        $external = $this->fx->count('external_all', $this->companyA);
        $r2 = $c->post('dane', $batch, $key);
        $this->check->that($r1['status'] === 200 && $r2['body'] === $r1['body'] && ($r2['headers']['x-idempotent-replay'] ?? '') === 'true',
            'ten sam X-Idempotency-Key → ta sama odpowiedź (odtworzona)');
        $r3 = $c->post('dane', $batch);
        $this->check->that($r3['status'] === 200 && ($r3['json']['przyjeto'] ?? 0) === 3, 'ta sama paczka z nowym kluczem → przyjęta ponownie');
        $this->check->that($this->fx->count('records', $this->companyA) === $records && $this->fx->count('external_all', $this->companyA) === $external,
            'bez duplikatów w rejestrze i nieobecnościach');
        $stats = $this->fx->run((string) $c->runId)['stats']['dane']['nieobecnosc'] ?? [];
        $this->check->that(($stats['bezZmian'] ?? 0) >= 3, 'powtórka policzona jako bez zmian', json_encode($stats) ?: '');

        $changed = self::absence('kadrowe:18', '312', 'kadrowe', 0, self::YEAR . '-10-12', self::YEAR . '-10-13', 2, 720, null, 'kadry');
        $c->post('dane', ['rekordy' => [$changed]] + $batch);
        $stats = $this->fx->run((string) $c->runId)['stats']['dane']['nieobecnosc'] ?? [];
        $this->check->that(($stats['zmienione'] ?? 0) >= 1, 'znany id z inną sumą → aktualizacja', json_encode($stats) ?: '');

        $r = $c->post('dane', $batch, false);
        $run = $this->fx->run((string) $c->runId);
        $this->check->that($r['status'] === 200 && ($run['stats']['bezIdempotencji'] ?? 0) >= 1, 'paczka bez X-Idempotency-Key przyjęta i policzona w sync_run');

        // powtórzony wynik: 1.4 raz jeszcze
        $task = $this->fx->task($this->rid['zapis'] ?? '');
        if ($task === null) {
            $this->check->that(false, 'powtórzony wynik — brak zadania z 1.4 (uruchom scenariusz przyklady)');
            $c->koniec();

            return;
        }
        $before = [$this->fx->request((int) $task['leave_request_id']), $this->fx->r2Records((int) $task['leave_request_id']), $this->fx->count('decisions_all')];
        $w = $this->ex->get('1.4', 0)['wyniki'][0];
        $w['requestId'] = $this->rid['zapis'];
        $r = $c->post('wyniki', ['nip' => self::NIP_A, 'przebiegId' => $c->runId, 'wyniki' => [$w]]);
        $after = [$this->fx->request((int) $task['leave_request_id']), $this->fx->r2Records((int) $task['leave_request_id']), $this->fx->count('decisions_all')];
        $this->check->that($r['status'] === 200 && $before === $after, 'powtórzony wynik nie zmienia stanu wniosku, wpisów ani historii');
        $c->koniec();
    }

    // --- scenariusz dodatkowy 1: usunięcie pracownika ------------------------------------------------------

    private function workerRemoved(HttpClient $http): void
    {
        $this->check->scope('usunięcie pracownika z zatrudnieniem i nieobecnościami');
        $c = $this->ensureLoaded($http, 'pracownik');
        $workers = [];
        foreach (['207' => 'P-anna', '311' => 'P-jan'] as $ref => $wid) {
            $p = $this->fx->personOf($this->companyA, (string) $ref);
            // ⚠ `identyfikator` to numer kadrowy, którym posługują się ludzie w kadrach (u klienta: 397).
            // Przechowujemy go jako daną opisową — dopasowanie osób zostaje na `osobaId`.
            $workers[] = self::sum(['id' => $wid, 'osobaId' => $p['osobaId'], 'nazwisko' => $p['nazwisko'], 'imie' => $p['imie'],
                'identyfikator' => (string) $ref === '207' ? '397' : null,
                'zwolniony' => false, 'dataZwolnienia' => null, 'skasowany' => false, 'umowy' => ['oPrace' => true, 'zlecenie' => false, 'oDzielo' => false]]);
        }
        $c->send('pracownik', $workers);
        $osoba = $this->fx->personOf($this->companyA, '207');
        $this->check->that($this->fx->personIdentifier((int) $osoba['id']) === '397',
            'numer kadrowy z pola identyfikator zapisany przy osobie');
        $this->check->that($this->fx->personIdentifier((int) ($this->fx->personOf($this->companyA, '311')['id'])) === null,
            'brak numeru kadrowego nie nadpisuje niczego pustą wartością');
        $c->send('zatrudnienie', [
            self::sum(['id' => '207', 'pracownikId' => 'P-anna', 'aktualne' => true, 'domyslne' => true, 'etat' => 0.5, 'dataRozpoczecia' => '2021-03-01', 'dataZakonczenia' => null, 'kalendarzId' => null]),
            self::sum(['id' => '311', 'pracownikId' => 'P-jan', 'aktualne' => true, 'domyslne' => true, 'etat' => 1, 'dataRozpoczecia' => '2019-01-01', 'dataZakonczenia' => null, 'kalendarzId' => null]),
        ]);
        $c->koniec();

        $snapshot = fn (): array => [
            array_map(fn (int $id): ?array => $this->fx->request($id), $this->req),
            $this->fx->count('decisions_all'), $this->fx->count('r2_records_all'),
        ];
        $before = $snapshot();
        $conflicts = $this->fx->count('conflicts', $this->companyA);
        $c->start();
        $c->send('pracownik', [['id' => 'P-anna', 'usuniety' => true], ['id' => 'P-jan', 'usuniety' => true]]);
        $c->send('zatrudnienie', [['id' => '207', 'usuniety' => true], ['id' => '311', 'usuniety' => true]]);
        $ids = [];
        foreach ($this->absencesA() as $id => $rec) {
            if (in_array($rec['zatrudnienieId'], ['207', '311'], true)) {
                $ids[] = ['id' => $id, 'usuniety' => true];
            }
        }
        $r = $c->send('nieobecnosc', $ids);
        $this->check->that(($r[0]['status'] ?? 0) === 200, 'usunięto ' . count($ids) . ' nieobecności obu pracowników');
        $anna = $this->fx->employment($this->companyA, '207');
        $jan = $this->fx->employment($this->companyA, '311');
        $this->check->that(!$anna['is_active'] && !$jan['is_active'], 'zatrudnienia 207 i 311 nieaktywne');
        $this->check->that($anna['account_status'] === 'active', 'konto Anny aktywne (ma jeszcze zatrudnienie w firmie B)');
        $this->check->that($jan['account_status'] === 'blocked', 'konto Jana zablokowane (wszystkie zatrudnienia nieaktywne)');
        $this->check->that($snapshot() === $before, 'wnioski, decyzje, wpisy R2 i historia nietknięte');
        $this->check->that(($this->fx->externalAbsence($this->companyA, 'zasilek:513')['removed'] ?? false) === true,
            'nieobecność zewnętrzna zatrudnienia nieaktywnego zniknęła z kalendarza (zasilek:513)');
        $events = $this->fx->run((string) $c->runId)['stats']['zdarzenia'] ?? [];
        $this->check->that(($events['nieobecnosciZatrudnieniaNieaktywnego'] ?? 0) > 0, 'zdarzenie zapisane w sync_run', json_encode($events) ?: '');
        $this->check->that($this->fx->count('conflicts', $this->companyA) === $conflicts, 'zero nowych konfliktów');
        $c->koniec();
    }

    // --- scenariusz dodatkowy 2: przesunięcie okna ---------------------------------------------------------

    private function windowShift(HttpClient $http): void
    {
        $this->check->scope('przesunięcie okna: usunięcie nieobecności z roku poza oknem');
        $c = $this->ensureLoaded($http, 'okno');
        $old = self::YEAR - 2; // poza oknem (okno: od 1 stycznia roku poprzedniego) — styczniowe przesunięcie o rok
        $emp = $this->fx->employmentId($this->companyA, '101');
        $cal = new WorkingCalendar();
        $days = array_map(static fn ($d): string => is_string($d) ? $d : $d->format('Y-m-d'),
            $cal->workingDays(new DateTimeImmutable("$old-07-01"), new DateTimeImmutable("$old-07-03")));
        $request = $this->fx->createRequest($emp, $this->fx->leaveTypeId('urlop', 0), "$old-07-01", "$old-07-03", 'confirmed', $days,
            ['days_confirmed' => 3, 'minutes_confirmed' => 1440, 'confirmed_at' => "$old-06-20 10:00:00"]);
        $this->fx->addR2Record($request, 'urlop:7001', "$old-07-01", "$old-07-03", 3, 1440);
        $c->send('nieobecnosc', [
            self::absence('urlop:7001', '101', 'urlop', 0, "$old-07-01", "$old-07-03", 3, 1440, null, 'connector'),
            self::absence('zasilek:7002', '101', 'zasilek', 0, "$old-03-02", "$old-03-06", 5, null, false, 'kadry'),
        ]);
        $c->send('limit', [self::sum(['id' => "L-101-$old", 'zatrudnienieId' => '101', 'rok' => $old, 'rodzaj' => 'wypoczynkowy', 'naliczony' => true,
            'wymiarMin' => 12480, 'zaleglyMin' => 0, 'dostepneNaStartMin' => 12480, 'wykorzystaneMin' => 12480, 'korektyMin' => 0, 'pozostaloMin' => 0,
            'naZadanieWykorzystaneDni' => 4])]);
        $c->koniec();

        $conflicts = $this->fx->count('conflicts', $this->companyA);
        $c->start();
        $r = $c->send('nieobecnosc', [['id' => 'urlop:7001', 'usuniety' => true], ['id' => 'zasilek:7002', 'usuniety' => true]]);
        $c->send('limit', [['id' => "L-101-$old", 'usuniety' => true]]);
        $this->check->that(($r[0]['status'] ?? 0) === 200, 'usunięcia przyjęte');
        $this->check->that($this->fx->count('conflicts', $this->companyA) === $conflicts, 'zero nowych konfliktów');
        $this->check->that($this->fx->recordState($this->companyA, 'nieobecnosc', 'urlop:7001') === 'out_of_window'
            && $this->fx->recordState($this->companyA, 'nieobecnosc', 'zasilek:7002') === 'out_of_window'
            && $this->fx->recordState($this->companyA, 'limit', "L-101-$old") === 'out_of_window', 'rekordy oznaczone jako poza oknem');
        $this->check->that(($this->fx->request($request)['status'] ?? '') === 'confirmed' && !($this->fx->r2Records($request)[0]['removed'] ?? true),
            'wniosek z tamtego roku bez zmian (confirmed, wpis R2 nieusunięty)');
        $ext = $this->fx->externalAbsence($this->companyA, 'zasilek:7002');
        $this->check->that($ext !== null && $ext['out_of_window'] && !$ext['removed'], 'nieobecność zewnętrzna: poza oknem, nie usunięta');
        $this->check->that(($this->fx->balance($emp, $old)['out_of_window'] ?? false) === true, "limit $old: poza oknem, wiersz zostaje");
        $stats = $this->fx->run((string) $c->runId)['stats']['dane'] ?? [];
        $this->check->that(($stats['nieobecnosc']['pozaOknem'] ?? 0) === 2 && ($stats['limit']['pozaOknem'] ?? 0) === 1, 'sync_run: pozaOknem 2 + 1', json_encode($stats) ?: '');
        $c->koniec();
    }

    // --- stan połączenia ---------------------------------------------------------------------------------

    /**
     * Test połączenia w connectorze: cztery stany firmy, wariant bez NIP-u i 401 — a przede wszystkim
     * dowód, że operacja NIE otwiera przebiegu (wcześniej test przedstawiał się jako pełny cykl).
     */
    private function connectionState(HttpClient $http): void
    {
        $this->check->scope('stan połączenia (test połączenia w connectorze)');
        $c = $this->connector($http, 'stan', [self::NIP_A]);
        $runs = $this->fx->count('runs', $this->companyA);

        $r = $c->get('stan', []);
        $this->check->that($r['status'] === 200 && ($r['json']['klucz'] ?? '') === 'aktywny' && ($r['json']['protokol'] ?? 0) === 1
            && is_array($r['json']) && array_key_exists('firma', $r['json']) && $r['json']['firma'] === null,
            'bez nip: sam stan klucza (firma: null)', $r['body']);

        $r = $c->get('stan', ['nip' => self::NIP_A]);
        $this->check->that($r['status'] === 200 && ($r['json']['firma']['stan'] ?? '') === 'ok', 'firma w zakresie klucza → stan ok', $r['body']);
        $this->check->that(!str_contains($r['body'], 'Przykładowa'), 'odpowiedź nie niesie nazwy firmy');

        $this->check->that($this->fx->count('runs', $this->companyA) === $runs,
            'stan NIE otworzył przebiegu (sync_run bez zmian: ' . $runs . ')');

        $r = $c->get('stan', ['nip' => self::NIP_STAN]);
        $this->check->that($r['status'] === 200 && ($r['json']['firma']['stan'] ?? '') === 'firma_nieznana'
            && is_array($r['json']) && $r['json']['ostatniPrzebieg'] === null,
            'firma nieznana → firma_nieznana, ostatniPrzebieg null', $r['body']);

        $c->post('zgloszenie-firmy', ['nip' => self::NIP_STAN, 'nazwa' => 'Firma z testu połączenia sp. z o.o.']);
        $r = $c->get('stan', ['nip' => self::NIP_STAN]);
        $this->check->that(($r['json']['firma']['stan'] ?? '') === 'firma_zgloszona', 'po zgłoszeniu → firma_zgloszona', $r['body']);

        $r = $c->get('stan', ['nip' => self::NIP_B_SIGNUP]);
        $this->check->that(($r['json']['firma']['stan'] ?? '') === 'firma_poza_zakresem',
            'firma istnieje, ale poza zakresem klucza → firma_poza_zakresem', $r['body']);

        $r = $c->get('stan', ['nip' => self::NIP_INVALID]);
        $this->check->that($r['status'] === 422 && ($r['json']['blad']['kod'] ?? '') === 'NIP_NIEPOPRAWNY', 'zła suma kontrolna NIP → 422', $r['body']);

        $zly = new Connector($http, str_repeat('0', 64), self::NIP_A);
        $r = $zly->get('stan', ['nip' => self::NIP_A]);
        $this->check->that($r['status'] === 401 && ($r['json']['blad']['kod'] ?? '') === 'KLUCZ_NIEPRAWIDLOWY', 'zły klucz → 401', $r['body']);

        // ostatniPrzebieg pojawia się dopiero po domkniętym cyklu
        $c->start();
        $c->koniec();
        $r = $c->get('stan', ['nip' => self::NIP_A]);
        $this->check->that(is_string($r['json']['ostatniPrzebieg'] ?? null) && str_ends_with((string) $r['json']['ostatniPrzebieg'], 'Z'),
            'po domkniętym cyklu: ostatniPrzebieg w UTC (…Z)', $r['body']);
        $this->check->that($this->fx->count('runs', $this->companyA) === $runs + 1, 'przebieg policzony raz — z cyklu, nie z testu połączenia');
    }

    // --- odcisk tożsamości -------------------------------------------------------------------------------

    /**
     * Bezpiecznik przed kompletem duplikatów osób: trzy wiersze tabeli zachowania ze zlecenia
     * (pierwszy raz / ten sam / inny) plus dowód, że przy innym odcisku NIC się nie zapisało.
     */
    private function identityFingerprint(HttpClient $http): void
    {
        $this->check->scope('odcisk tożsamości (bezpiecznik przed dublami osób)');
        $c = $this->connector($http, 'tozsamosc', [self::NIP_A]);
        $stan = $this->fx->identity($this->companyA);
        $znany = $stan['identity'] ?? 'a3f91c2e';
        $inny = 'b7d0e1f2';

        // 1. pierwszy raz dla firmy — zapamiętane cicho (gdy wcześniejszy scenariusz już go zapisał, sprawdzamy powtórkę)
        $r = $c->start(['odciskTozsamosci' => $znany]);
        $this->check->that($r['status'] === 200 && ($this->fx->identity($this->companyA)['identity'] ?? '') === $znany,
            'odcisk zapamiętany dla firmy, bez ostrzeżenia i bez pytania', $r['body']);
        $c->koniec();

        // 2. ten sam odcisk — nic się nie dzieje
        $r = $c->start(['odciskTozsamosci' => $znany]);
        $this->check->that($r['status'] === 200 && ($this->fx->identity($this->companyA)['pending'] ?? null) === null,
            'ten sam odcisk: przebieg otwarty, nic do decyzji administratora', $r['body']);
        $c->koniec();

        // 3. inny odcisk — 403 i strumień stoi PRZED pierwszą paczką
        $przebiegi = $this->fx->count('runs', $this->companyA);
        $rekordy = $this->fx->count('records', $this->companyA);
        $zdarzenia = $this->fx->count('events', 0, 'sync.identity_changed');
        $poprzedni = (string) $c->runId;
        $r = $c->start(['odciskTozsamosci' => $inny]);
        $this->check->example('3.10', $r, 403, $this->ex->get('3.10', 0), ['blad.zdarzenieId' => Checker::EVENT_ID]);
        $this->check->that($this->fx->count('runs', $this->companyA) === $przebiegi, 'zmieniony odcisk: przebieg NIE został otwarty');
        $this->check->that($this->fx->count('records', $this->companyA) === $rekordy, 'zmieniony odcisk: ani jeden rekord nie zapisany');
        $this->check->that(($this->fx->identity($this->companyA)['pending'] ?? '') === $inny, 'nowy odcisk czeka na decyzję administratora');
        $this->check->that($this->fx->count('events', 0, 'sync.identity_changed') === $zdarzenia + 1, 'zmiana tożsamości odnotowana w logu zdarzeń');

        // paczka w poprzednim przebiegu też nie przejdzie — przebieg został zamknięty
        $r = $c->post('dane', ['nip' => self::NIP_A, 'przebiegId' => $poprzedni, 'zbior' => 'slownik', 'paczka' => 1,
            'ostatnia' => true, 'rekordy' => []]);
        $this->check->that($r['status'] === 409, 'paczka w starym przebiegu odrzucona (409), więc strumień naprawdę stoi', $r['body']);

        // 4. niepoprawny format — 400, bez zapisu
        $r = $c->start(['odciskTozsamosci' => 'ZZZ']);
        $this->check->that($r['status'] === 400 && ($r['json']['blad']['kod'] ?? '') === 'ZADANIE_NIEPOPRAWNE',
            'odcisk spoza formatu → 400', $r['body']);

        // 5. decyzja administratora odblokowuje strumień
        $this->s->syncDomain()->setIdentityFingerprint($this->companyA, $inny);
        $r = $c->start(['odciskTozsamosci' => $inny]);
        $this->check->that($r['status'] === 200 && ($this->fx->identity($this->companyA)['pending'] ?? null) === null,
            'po akceptacji administratora strumień znów działa', $r['body']);
        $c->koniec();

        // 6. ⚠ ROZJAZD W POPRZEK FIRM JEDNEGO KLUCZA: sekret connectora jest jeden na instalację,
        // więc firmy obsługiwane jednym kluczem muszą mieć ten sam odcisk. Dwa różne = dwie instalacje.
        // To jest STAN WYMAGAJĄCY UWAGI, nie błąd — strumień obu firm ma działać dalej.
        $firmaB = $this->domain->companyByNip(self::NIP_B_SIGNUP)['id'] ?? 0;
        if ($firmaB > 0) {
            $this->freeCompanies([self::NIP_A, self::NIP_B_SIGNUP]);
            $klucz = $this->s->apiKeyService()->issue('symulator: dwie firmy', [$this->companyA, $firmaB], null);
            $ca = new Connector($http, $klucz['key'], self::NIP_A);
            $ca->keyId = (int) $klucz['id'];
            $cb = new Connector($http, $klucz['key'], self::NIP_B_SIGNUP);
            $cb->keyId = (int) $klucz['id'];
            $this->s->syncDomain()->setIdentityFingerprint($this->companyA, $znany);
            $this->s->syncDomain()->setIdentityFingerprint($firmaB, $znany);

            $zdarzenia = $this->fx->count('events', 0, 'sync.identity_split');
            $ra = $ca->start(['odciskTozsamosci' => $znany]);
            $ca->koniec();
            $this->check->that($ra['status'] === 200 && $this->fx->count('events', 0, 'sync.identity_split') === $zdarzenia,
                'odciski zgodne: żadnego ostrzeżenia o dwóch instalacjach', $ra['body']);

            // ⚠ Druga instalacja connectora: firma B jest spójna SAMA ZE SOBĄ (jej zapamiętany odcisk
            // to `$inny` i taki sam przysyła), ale różni się od firmy A. To NIE jest zmiana tożsamości
            // firmy (403), tylko rozjazd w poprzek firm jednego klucza — i tego szukamy.
            $this->s->syncDomain()->setIdentityFingerprint($firmaB, $inny);
            $rb = $cb->start(['odciskTozsamosci' => $inny]);
            $this->check->that($rb['status'] === 200, 'rozjazd odcisków NIE zatrzymuje strumienia drugiej firmy (200)', $rb['body']);
            $cb->koniec();
            $this->check->that($this->fx->count('events', 0, 'sync.identity_split') === $zdarzenia + 1,
                'rozjazd odnotowany w dzienniku zdarzeń (sync.identity_split)');
            $ra = $ca->start(['odciskTozsamosci' => $znany]);
            $ca->koniec();
            $this->check->that($ra['status'] === 200, 'strumień PIERWSZEJ firmy też działa przy rozjeździe (200)', $ra['body']);
            $this->check->that($this->fx->count('events', 0, 'sync.identity_split') === $zdarzenia + 1,
                'zdarzenie zapisane RAZ, nie przy każdym przebiegu');

            // ustanie rozjazdu: administrator naprawia konfigurację, odciski znów zgodne
            $rozwiazane = $this->fx->count('events', 0, 'sync.identity_split_resolved');
            $this->s->syncDomain()->setIdentityFingerprint($firmaB, $znany);
            $rb = $cb->start(['odciskTozsamosci' => $znany]);
            $cb->koniec();
            $this->check->that($rb['status'] === 200
                && $this->fx->count('events', 0, 'sync.identity_split_resolved') === $rozwiazane + 1,
                'ustanie rozjazdu odnotowane (sync.identity_split_resolved)', $rb['body']);
            $this->s->apiKeyService()->revoke((int) $klucz['id'], null);
        }

        // stan wyjściowy dla kolejnych scenariuszy
        $this->s->syncDomain()->setIdentityFingerprint($this->companyA, $znany);
    }

    // --- pola z danych klienta ---------------------------------------------------------------------------

    /**
     * Cztery pola z danych klienta (zlecenie 2026-09-20-pola-limit-normy.md): powiązanie rodzaju z limitem,
     * normy kalendarza jako dzielnik minut na dni oraz reguła „dni: 0 przy źródle kadrowe”.
     */
    private function clientFields(HttpClient $http): void
    {
        $this->check->scope('obciążenie limitu, normy kalendarza, dni przy kadrowe');
        $c = $this->ensureLoaded($http, 'pola');
        $y = self::YEAR;
        $leave = $this->s->leaveRequests();

        // --- słownik: obciazaLimit + obciazaLimitZrodlo
        $c->send('slownik', [
            self::dictionary('urlop', 0, 'urlop wypoczynkowy', true, true, 'wypoczynkowy', 'dane'),
            self::dictionary('urlop', 5, 'urlop wyp. na żądanie', true, true, 'wypoczynkowy', 'domyslne'),
            self::dictionary('urlop', 6, 'ekwiwalent za urlop', false, false, 'wypoczynkowy', 'dane'),
            self::dictionary('kadrowe', 17, 'urlop bezpłatny', true, false, 'wypoczynkowy', 'dane'),
            self::dictionary('zasilek', 0, 'zwolnienie lekarskie', true, false, null, null),
        ]);
        $t0 = $this->fx->leaveType('urlop', 0);
        $this->check->that(($t0['limit_kind'] ?? null) === 'wypoczynkowy' && ($t0['limit_kind_source'] ?? null) === 'dane' && ($t0['counts_to_limit'] ?? false),
            'urlop:0 → limit wypoczynkowy ze źródła „dane”, obciąża limit', json_encode($t0) ?: '');
        $t6 = $this->fx->leaveType('urlop', 6);
        $this->check->that(($t6['limit_kind'] ?? null) === 'wypoczynkowy' && ($t6['counts_to_limit'] ?? false),
            'urlop:6 (ekwiwalent, nie nieobecność) też pomniejsza pulę', json_encode($t6) ?: '');
        $tk = $this->fx->leaveType('kadrowe', 17);
        $this->check->that($tk !== null && $tk['limit_kind'] === null && !$tk['counts_to_limit'],
            'kadrowe: obciazaLimit odrzucone (kadrowe i zasiłki nie obciążają puli)', json_encode($tk) ?: '');
        $stats = $this->fx->run((string) $c->runId)['stats']['zdarzenia'] ?? [];
        $this->check->that(($stats['obciazenieLimituPominiete'] ?? 0) === 1, 'pominięte obciążenie policzone w opisie przebiegu', json_encode($stats) ?: '');
        $tz = $this->fx->leaveType('zasilek', 0);
        $this->check->that($tz !== null && $tz['limit_kind'] === null, 'zasiłek bez powiązania z limitem', json_encode($tz) ?: '');

        // --- kalendarz: normy
        $c->send('kalendarz', [self::sum(['id' => 'kal:2', 'kalendarzId' => '2', 'nazwa' => 'Główny', 'rok' => $y,
            'indywidualne' => false, 'dniNierobocze' => [], 'normaUrlopowaMin' => 720, 'normaDobowaMin' => 720])]);
        $cal = $this->fx->calendar($this->companyA, '2', $y);
        $this->check->that(($cal['leave_norm_min'] ?? 0) === 720 && ($cal['daily_norm_min'] ?? 0) === 720, 'normy kalendarza zapisane', json_encode($cal) ?: '');

        $w = $leave->submit('101', 'urlop:0', "$y-10-12", "$y-10-12");
        $this->check->that($w['minuty'] === 720 && $w['dni'] === 1 && $w['ostrzezenia'] === [],
            'dzień liczony normą kalendarza (720 min), nie stałą 480', json_encode($w) ?: '');

        $w = $leave->submit('101', 'urlop:5', "$y-10-13", "$y-10-13");
        $this->check->that(count($w['ostrzezenia']) === 1 && str_contains($w['ostrzezenia'][0], 'nie jest potwierdzone'),
            'rodzaj z powiązaniem „domyslne”: wniosek przechodzi, ale z ostrzeżeniem', json_encode($w['ostrzezenia']) ?: '');

        // --- brak normy = wartość zapasowa, ale WIDOCZNA
        $c->send('kalendarz', [self::sum(['id' => 'kal:2', 'kalendarzId' => '2', 'nazwa' => 'Główny', 'rok' => $y,
            'indywidualne' => false, 'dniNierobocze' => []])]);
        $stats = $this->fx->run((string) $c->runId)['stats']['zdarzenia'] ?? [];
        $this->check->that(($stats['kalendarzeBezNormy'] ?? 0) >= 1, 'kalendarz bez normy policzony w opisie przebiegu', json_encode($stats) ?: '');
        $w = $leave->submit('101', 'urlop:0', "$y-10-14", "$y-10-14");
        $this->check->that($w['minuty'] === 480 && count($w['ostrzezenia']) === 1 && str_contains($w['ostrzezenia'][0], 'zapasową'),
            'bez normy: wartość zapasowa 480 i ostrzeżenie przy wniosku', json_encode($w) ?: '');

        // --- dni: 0 przy kadrowe to reguła, nie brak danych
        $zdarzenia = $this->fx->count('events', 0, 'sync.kadrowe_with_length');
        $c->send('nieobecnosc', [
            self::absence('kadrowe:8801', '101', 'kadrowe', 17, "$y-10-19", "$y-10-21", 0, 0, null, 'kadry'),
            self::absence('kadrowe:8802', '101', 'kadrowe', 17, "$y-10-26", "$y-10-27", 2, 960, null, 'kadry'),
        ]);
        $a1 = $this->fx->externalAbsence($this->companyA, 'kadrowe:8801');
        $this->check->that(($a1['days'] ?? null) === 0 && ($a1['minutes'] ?? null) === 0, 'kadrowe z dni 0: zapisane jako zero, nie null', json_encode($a1) ?: '');
        $a2 = $this->fx->externalAbsence($this->companyA, 'kadrowe:8802');
        $this->check->that(($a2['days'] ?? null) === 0 && ($a2['minutes'] ?? null) === 0,
            'kadrowe z niezerową długością: do rozliczeń zero (i wpis w logu)', json_encode($a2) ?: '');
        $this->check->that($this->fx->count('events', 0, 'sync.kadrowe_with_length') === $zdarzenia + 1,
            'niezerowa długość przy kadrowe odnotowana w logu zdarzeń (jeden nowy wpis)');
        $blad = null;
        try {
            $leave->submit('101', 'urlop:0', "$y-10-20", "$y-10-20");
        } catch (InvalidArgumentException $e) {
            $blad = $e->getMessage();
        }
        $this->check->that($blad !== null && str_contains($blad, 'Kolizja z nieobecnością'),
            'termin kadrowej nieobecności zablokowany datami, mimo zerowej długości: ' . ($blad ?? 'BRAK BŁĘDU'));
        $c->koniec();
    }

    /**
     * Rekord słownika z powiązaniem z limitem.
     *
     * @return array<string, mixed>
     */
    private static function dictionary(string $source, int $code, string $name, bool $absence, bool $requestable,
        ?string $limitKind, ?string $limitKindSource): array
    {
        return self::sum([
            'id' => $source . ':' . $code, 'zrodlo' => $source, 'kod' => $code, 'nazwa' => $name,
            'tylkoOdczyt' => !$requestable, 'doWniosku' => $requestable, 'nieobecnosc' => $absence,
            'obciazaLimit' => $limitKind, 'obciazaLimitZrodlo' => $limitKindSource,
        ]);
    }

    // --- pomiar -----------------------------------------------------------------------------------------

    private function measure(HttpClient $http, string $target, int $total): void
    {
        $this->check->scope("pomiar: pełne ładowanie {$total} rekordów");
        $c = $this->connector($http, 'pomiar', []);
        $c->post('zgloszenie-firmy', ['nip' => self::NIP_LOAD, 'nazwa' => 'Symulator — pomiar']);
        $signup = array_values(array_filter($this->s->apiKeys->signups(), static fn (array $x): bool => $x['nip'] === self::NIP_LOAD))[0] ?? null;
        $companyId = $this->s->apiKeyService()->approveSignup((int) ($signup['id'] ?? 0), null);
        $c = new Connector($http, $c->key, self::NIP_LOAD);

        $n = intdiv($total, 10);
        $sets = ['pracownik' => [], 'zatrudnienie' => [], 'limit' => [], 'nieobecnosc' => []];
        for ($i = 1; $i <= $n; $i++) {
            $sets['pracownik'][] = self::sum(['id' => "P$i", 'osobaId' => 'sym:' . hash('sha256', "osoba-$i"), 'nazwisko' => "Pomiarowy$i", 'imie' => 'Test',
                'identyfikator' => null, 'zwolniony' => false, 'dataZwolnienia' => null, 'skasowany' => false,
                'umowy' => ['oPrace' => true, 'zlecenie' => false, 'oDzielo' => false]]);
            $sets['zatrudnienie'][] = self::sum(['id' => "Z$i", 'pracownikId' => "P$i", 'aktualne' => true, 'domyslne' => true, 'etat' => 1,
                'dataRozpoczecia' => '2020-01-01', 'dataZakonczenia' => null, 'kalendarzId' => null]);
            $sets['limit'][] = self::sum(['id' => "L$i", 'zatrudnienieId' => "Z$i", 'rok' => self::YEAR, 'rodzaj' => 'wypoczynkowy', 'naliczony' => true,
                'wymiarMin' => 12480, 'zaleglyMin' => 960, 'dostepneNaStartMin' => 13440, 'wykorzystaneMin' => 4800, 'korektyMin' => 0,
                'pozostaloMin' => 8640, 'naZadanieWykorzystaneDni' => 1]);
            for ($k = 0; $k < 7; $k++) {
                $from = (new DateTimeImmutable((self::YEAR - 1) . '-01-05'))->modify('+' . ($k * 50 + $i % 30) . ' days')->format('Y-m-d');
                $sets['nieobecnosc'][] = self::absence("urlop:$i-$k", "Z$i", $k === 3 ? 'zasilek' : 'urlop', 0, $from, $from, 1, $k === 3 ? null : 480,
                    $k === 3 ? false : null, 'kadry');
            }
        }
        $wall = hrtime(true);
        $c->start();
        $per = [];
        foreach ($sets as $zbior => $records) {
            foreach ($c->send($zbior, $records) as $r) {
                $per[$zbior][] = $r['ms'];
                $this->check->that($r['status'] === 200 && $r['ms'] < 30000, sprintf('%s paczka %d: %d ms', $zbior, count($per[$zbior]), (int) $r['ms']), $r['body']);
            }
        }
        $load = (hrtime(true) - $wall) / 1e6;
        $positions = array_map(static fn (array $r): array => ['id' => $r['id'], 'suma' => $r['suma']], $sets['nieobecnosc']);
        $t = hrtime(true);
        $review = $c->send('nieobecnosc', $positions, 500, 'przeglad');
        $reviewMs = (hrtime(true) - $t) / 1e6;
        $this->check->that(array_unique(array_column($review, 'status')) === [200], 'przegląd ' . count($positions) . ' pozycji przyjęty');
        $c->koniec();
        $run = $this->fx->run((string) $c->runId);
        $this->check->that(($run['stats']['oczekujace'] ?? -1) === 0 && ($run['stats']['przeglad']['nieobecnosc']['rozjazd'] ?? -1) === 0,
            'bez rekordów oczekujących i bez rozjazdów po pełnym ładowaniu');
        $this->check->that($this->fx->count('records', $companyId) === $n * 10, 'w rejestrze ' . ($n * 10) . ' rekordów');

        // pojedyncza paczka 500 zmienionych rekordów (aktualizacja — najdroższy przypadek)
        $c->start();
        $changed = array_map(static function (array $r): array {
            $r['dataDo'] = $r['dataOd'];
            $r['zmieniono'] = '2026-09-19T12:00:00+02:00';

            return self::sum($r);
        }, array_slice($sets['nieobecnosc'], 0, 500));
        $one = $c->send('nieobecnosc', $changed)[0];
        $this->check->that($one['status'] === 200 && $one['ms'] < 30000, sprintf('paczka 500 zmienionych rekordów: %d ms', (int) $one['ms']));
        $c->koniec();

        $this->timings[$target] = [
            'rekordy' => $n * 10, 'pelneLadowanieMs' => (int) $load,
            'pracownik500Ms' => (int) max($per['pracownik']), 'zatrudnienie500Ms' => (int) max($per['zatrudnienie']),
            'limit500Ms' => (int) max($per['limit']), 'nieobecnosc500MsMax' => (int) max($per['nieobecnosc']),
            'nieobecnosc500MsSrednio' => (int) (array_sum($per['nieobecnosc']) / count($per['nieobecnosc'])),
            'przeglad' . count($positions) . 'Ms' => (int) $reviewMs, 'aktualizacja500Ms' => (int) $one['ms'],
        ];
    }

    /** Usługi wskazane na inną bazę — konfiguracja z tego samego .env, podmieniona nazwa bazy. */
    private function servicesFor(string $database): Services
    {
        $env = EnvFile::load(EnvFile::path($this->appRoot, []));
        $factory = require $this->appRoot . '/config/config.php';

        return new Services(Config::fromArray($factory(['DB_NAME' => $database] + $env, [], $this->appRoot)));
    }

    /**
     * Czy zbudowana paczka jest starsza niż ostatni commit repozytorium kodu (albo nie istnieje).
     * Czas builda bierzemy z pliku skopiowanego przez build (`composer.json` w paczce), czas commita
     * ze wskaźnika gałęzi w `.git` — bez uruchamiania procesów.
     */
    private function stalePackage(): ?string
    {
        $built = $this->appRoot . '/build/paczka/composer.json';
        if (!is_file($built)) {
            return "Nie ma zbudowanej paczki (build/paczka).\nUruchom: dev/package-vhost.sh";
        }
        $headFile = $this->appRoot . '/.git/HEAD';
        $head = is_file($headFile) ? (string) file_get_contents($headFile) : '';
        $ref = str_starts_with(trim($head), 'ref: ') ? $this->appRoot . '/.git/' . trim(substr(trim($head), 5)) : null;
        $commit = 0;
        foreach ([$ref, $this->appRoot . '/.git/HEAD'] as $file) {
            if (is_string($file) && is_file($file)) {
                $commit = max($commit, (int) filemtime($file));
            }
        }
        clearstatcache(true, $built);
        $buildTime = (int) filemtime($built);
        if ($commit > 0 && $buildTime < $commit) {
            return sprintf("Paczka jest starsza niż ostatni commit (zbudowana %s, commit %s) — test sprawdzałby poprzednią wersję.\n"
                . 'Uruchom: dev/package-vhost.sh', gmdate('H:i:s', $buildTime) . ' UTC', gmdate('H:i:s', $commit) . ' UTC');
        }
        // ⚠ Paczka na innej bazie niż seed = seed idzie w jedno miejsce, żądania w drugie. Objawia się
        // serią 401 (klucz z innej bazy), więc wygląda na błąd kodu, a jest błędem uruchomienia.
        $env = $this->appRoot . '/build/paczka/.env';
        $name = is_file($env) && preg_match('/^DB_NAME=(.*)$/m', (string) file_get_contents($env), $m) === 1 ? trim($m[1]) : null;
        if ($name !== null && $this->database !== null && $name !== $this->database) {
            return sprintf("Paczka stoi na bazie „%s”, a seed idzie do „%s” — żądania trafiałyby gdzie indziej niż dane.\n"
                . 'Uruchom: PACZKA_DB=%s dev/package-vhost.sh', $name, $this->database, $this->database);
        }

        return null;
    }

    /**
     * Kontrakt: każdy blok przykładów z docs/przyklady-api.md zgodny ze schematem z docs/openapi-sync-v1.json,
     * a schematy rekordów w OpenAPI — z config/sync-schema.php (tym samym, którego używa walidator paczek).
     */
    private function contract(): void
    {
        $this->check->scope('kontrakt: przykłady ↔ OpenAPI ↔ config/sync-schema.php');
        $uuid = '8d41e0b2-3c77-4a51-9f02-77b1c6e4a910';
        $map = [
            ['1.0', 0, 'StanResponse'],
            ['1.1', 0, 'StartRequest'], ['1.1', 1, 'StartResponse'], ['1.2', 0, 'DaneRequest'], ['1.2', 1, 'DaneResponse'],
            ['1.6', 0, 'DaneRequest'], ['1.6', 1, 'DaneResponse'], ['1.6', 2, 'DaneRequest'],
            ['1.3', 0, 'ZadaniaResponse'], ['1.4', 0, 'WynikiRequest'], ['1.4', 1, 'WynikiResponse'], ['1.5', 0, 'KoniecRequest'],
            ['1.5', 1, 'KoniecResponse'], ['2.1', 0, 'Wynik'], ['2.2', 0, 'Wynik'], ['2.3', 0, 'Wynik'], ['2.4', 0, 'Wynik'], ['2.5', 0, 'Wynik'],
            ['3.1', 0, 'BladRekordow'], ['3.2', 0, 'BladFirmy'], ['3.3', 0, 'ZgloszenieRequest'], ['3.3', 1, 'ZgloszenieResponse'], ['3.4', 0, 'Blad'],
            ['3.5', 0, 'Blad'], ['3.6', 0, 'BladRozmiaru'], ['3.7', 0, 'BladWersji'], ['3.8', 0, 'Blad'], ['3.9', 0, 'Blad'], ['4.1', 0, 'StartResponse'], ['4.2', 0, 'PrzegladRequest'],
            ['4.2', 1, 'PrzegladResponse'],
        ];
        foreach ($map as [$section, $i, $schema]) {
            // Wartości skrócone w dokumencie („sha256:3b1f…”, przebiegId „…”) zastępujemy pełnymi — kształt zostaje.
            $block = Examples::expandSums($this->ex->get($section, $i));
            if (($block['przebiegId'] ?? null) === '…') {
                $block['przebiegId'] = $uuid;
            }
            $errors = $this->api->validate($schema, $block);
            $this->check->that($errors === [], "przykład {$section}" . ($i > 0 ? " (blok {$i})" : '') . " zgodny ze schematem {$schema}", implode('; ', array_slice($errors, 0, 3)));
        }
        $fields = require $this->appRoot . '/config/sync-schema.php';
        $names = ['firma' => 'Firma', 'pracownik' => 'Pracownik', 'zatrudnienie' => 'Zatrudnienie', 'limit' => 'Limit',
            'nieobecnosc' => 'Nieobecnosc', 'slownik' => 'Slownik', 'kalendarz' => 'Kalendarz'];
        foreach ($fields as $zbior => $spec) {
            $schema = $this->api->schema('Rekord' . $names[$zbior]);
            $required = array_keys(array_filter($spec, static fn (array $f): bool => $f[1]));
            $this->check->that(array_keys($schema['properties'] ?? []) === array_merge(['id', 'suma'], array_keys($spec))
                && ($schema['required'] ?? []) === array_merge(['id', 'suma'], $required), "rekord {$zbior}: pola i wymagalność jak w config/sync-schema.php");
        }
    }

    // --- pomocnicze -------------------------------------------------------------------------------------

    /**
     * Connector w stanie „po cyklach z przykładów”: jeśli scenariusz przyklady nie był uruchomiony w tym celu,
     * rejestr nieobecności firmy A ładujemy teraz (pełne ładowanie nieobecności), żeby scenariusze były niezależne.
     */
    private function ensureLoaded(HttpClient $http, string $name): Connector
    {
        $c = $this->connector($http, $name, [self::NIP_A]);
        $c->start();
        if ($this->fx->recordState($this->companyA, 'nieobecnosc', 'urlop:12315') === null) {
            $c->send('nieobecnosc', array_values($this->absencesA()));
        }

        return $c;
    }

    /**
     * Klucz dla scenariusza. ⚠ Zakres klucza to WYBÓR istniejących firm (rozstrzygnięcie 20.09.2026),
     * więc NIP-y tłumaczymy na identyfikatory; NIP bez firmy w bazie jest błędem scenariusza.
     *
     * @param list<string> $nips
     */
    private function connector(HttpClient $http, string $name, array $nips): Connector
    {
        $byNip = array_column($this->fx->companies(), 'id', 'nip');
        $ids = [];
        foreach ($nips as $nip) {
            if (!isset($byNip[$nip])) {
                throw new RuntimeException('Scenariusz ' . $name . ': nie ma firmy o NIP ' . $nip . ' — klucz obejmuje tylko firmy istniejące.');
            }
            $ids[] = (int) $byNip[$nip];
        }
        $this->freeCompanies($nips);
        $key = $this->s->apiKeyService()->issue('symulator: ' . $name, $ids, null);
        $c = new Connector($http, $key['key'], $nips[0] ?? self::NIP_A);
        $c->keyId = $key['id'];

        return $c;
    }

    /**
     * ⚠ „Jedna firma = jeden aktywny klucz” obowiązuje też symulator: scenariusze idą po kolei na tej samej
     * bazie (seed jest raz na cel), więc klucz poprzedniego scenariusza trzeba unieważnić tak, jak zrobiłby
     * to administrator. Kasujemy wyłącznie klucze symulatora — cudzy klucz oznacza błąd scenariusza.
     *
     * @param list<string> $nips
     */
    private function freeCompanies(array $nips): void
    {
        foreach ($nips as $nip) {
            while (($other = $this->s->apiKeys->activeKeyForNip($nip, null)) !== null) {
                if (!str_starts_with($other['name'], 'symulator:')) {
                    throw new RuntimeException('Firma ' . $nip . ' ma aktywny klucz spoza symulatora: ' . $other['name']);
                }
                $this->s->apiKeyService()->revoke($other['id'], null);
            }
        }
    }

    /**
     * Nieobecności firmy A w R2 po przykładach 1.x i 2.x — sumy spójne z dokumentem (1.2 i 4.2).
     *
     * @return array<string, array<string, mixed>>
     */
    private function absencesA(): array
    {
        $y = self::YEAR;
        $doc = Examples::expandSums($this->ex->get('1.2', 0))['rekordy'];
        $list = [
            'urlop:12315' => $doc[0],
            'zasilek:512' => $doc[1],
            'zasilek:513' => self::absence('zasilek:513', '311', 'zasilek', 0, "$y-09-23", "$y-09-25", 3, null, false, 'kadry'),
            'urlop:901'   => self::absence('urlop:901', '101', 'urlop', 6, "$y-06-30", "$y-06-30", 1, 480, null, 'kadry'),
        ];
        if (($this->rid['zapis'] ?? '') !== '') {
            $list['urlop:12330'] = self::absence('urlop:12330', '207', 'urlop', 0, "$y-10-29", "$y-10-31", 2, 960, null, 'connector', $this->rid['zapis']);
            $list['urlop:12330']['suma'] = Examples::fullSum('sha256:77de…');
            $list['urlop:12331'] = self::absence('urlop:12331', '207', 'urlop', 0, "$y-11-02", "$y-11-03", 2, 960, null, 'connector', $this->rid['zapis']);
        }
        if (($this->rid['obcy'] ?? '') !== '') {
            // Wpis podpisany jako nasz, którego nie wydaliśmy (scenariusz 2.x) — w przeglądzie jest jak każdy inny.
            $list['urlop:97001'] = self::absence('urlop:97001', '101', 'urlop', 0, "$y-05-11", "$y-05-12", 2, 960, null, 'connector',
                'wu-NIEZNANYREQUESTID000000001');
        }
        if (($this->rid['2.1'] ?? '') !== '') {
            $list['urlop:12344'] = self::absence('urlop:12344', '311', 'urlop', 0, "$y-12-28", "$y-12-30", 3, 1440, null, 'connector', $this->rid['2.1']);
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private static function absence(string $id, string $emp, string $source, int $code, string $from, string $to, ?int $days, ?int $minutes,
        ?bool $continuation, string $entry, ?string $requestId = null): array
    {
        return self::sum([
            'id' => $id, 'zatrudnienieId' => $emp, 'rodzaj' => ['zrodlo' => $source, 'kod' => $code], 'dataOd' => $from, 'dataDo' => $to,
            'dni' => $days, 'minuty' => $minutes, 'requestId' => $requestId, 'zrodloWpisu' => $entry, 'kontynuacja' => $continuation,
            'utworzono' => null, 'zmieniono' => null,
        ]);
    }

    /**
     * Suma rekordu jak u connectora: SHA-256 z treści bez `id` i `suma`.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function sum(array $r): array
    {
        $copy = $r;
        unset($copy['suma']);
        $r['suma'] = 'sha256:' . hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $r;
    }

    private static function interval(bool $openTasks): int
    {
        if ($openTasks) {
            return 30;
        }
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('G');

        return $hour >= 22 || $hour < 6 ? 300 : 60;
    }

    /** Baza z seeda: rollback → migrate → seed (API phinxa, bez uruchamiania procesów). */
    /**
     * ⚠ Klucz ustawień dopisany w migracji, a nieuwzględniony w `config/tenant-defaults.php`, zostałby
     * przez reset instalacji **skasowany jako pozostałość**. Po świeżym `migrate` sprawdzamy więc, że
     * każdy klucz z bazy jest albo na liście domyślnych, albo ma zachowany przedrostek.
     */
    private function settingsDefaults(): void
    {
        $config = require $this->appRoot . '/config/tenant-defaults.php';
        $keys = $this->fx->settingKeys();
        $brak = array_values(array_filter($keys, static function (string $key) use ($config): bool {
            foreach ($config['preserved_prefixes'] as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return false;
                }
            }

            return !array_key_exists($key, $config['defaults']);
        }));
        $this->check->scope('reset instalacji: ustawienia domyślne');
        $this->check->that($brak === [], 'każdy klucz tenant_setting po migracji jest w config/tenant-defaults.php',
            $brak === [] ? '' : 'poza listą: ' . implode(', ', $brak));
    }

    private function reseed(): void
    {
        $t = hrtime(true);
        $app = new PhinxApplication();
        $app->setAutoExit(false);
        foreach ([['command' => 'rollback', '--target' => '0'], ['command' => 'migrate'], ['command' => 'seed:run']] as $args) {
            $code = $app->run(new ArrayInput($args + ['--configuration' => $this->appRoot . '/config/phinx.php']), new NullOutput());
            if ($code !== 0) {
                throw new RuntimeException('phinx ' . $args['command'] . ' zakończony kodem ' . $code);
            }
        }
        fwrite($this->out, sprintf("  baza odtworzona z seeda (%.1f s) — hasła kont deweloperskich zmieniły się\n", (hrtime(true) - $t) / 1e9));
    }

    private function summary(): void
    {
        fwrite($this->out, "\n══ Wynik: " . $this->check->passed() . ' sprawdzeń zaliczonych, ' . count($this->check->failures()) . " niezaliczonych\n");
        foreach ($this->check->failures() as $f) {
            fwrite($this->out, '  ✗ ' . $f . "\n");
        }
        foreach ($this->timings as $target => $t) {
            fwrite($this->out, "\n══ Czasy ({$target}):\n");
            foreach ($t as $k => $v) {
                fwrite($this->out, sprintf("  %-26s %s\n", $k, (string) $v));
            }
        }
    }
}
