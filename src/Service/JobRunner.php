<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Throwable;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\SessionRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Service\Mail\MailTransport;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Service\Mail\TransportZDziennikiem;
use Wezurlop\Support\Cisza;
use Wezurlop\Support\Logger;
use Wezurlop\Support\ZadaniaKolejki;

/**
 * Wykonawca kolejki zadań (bin/run-jobs.php, cron). Pracuje porcjami w budżecie czasu mniejszym niż
 * limit wykonania; niewykonane zadania czekają na kolejny przebieg — rzadszy cron to wolniejsza
 * wysyłka, nigdy zgubione zadanie (CLAUDE.md §0).
 */
final class JobRunner
{
    private const BATCH = 10;
    private const LOCK_SECONDS = 120;
    private const MAX_ATTEMPTS = 5;

    public const JOB_SESSION_CLEANUP = 'maintenance.session_cleanup';

    /**
     * Sonda crona: zadanie, którego jedynym zadaniem jest **zostać wykonanym** (zlecenie §2).
     *
     * ⚠ Sprawdza to, czego nie sprawdzi żaden przycisk „uruchom teraz": czy zadanie **postawione
     * teraz** zostanie podjęte przez prawdziwy przebieg crona. Uruchomienie runnera w żądaniu
     * dowodziłoby wyłącznie tego, że PHP działa — przy limicie 30 s to była pułapka, przez którą
     * wysyłka kiedyś wisiała w formularzu.
     *
     * ⚠ Po wykonaniu **znika z kolejki** i zostawia jeden wpis w dzienniku. Sonda nie ma prawa
     * zaśmiecać historii, do której zagląda się przy awarii.
     */
    public const JOB_CRON_PROBE = 'maintenance.cron_probe';

    /** Klucze znaczników sondy w `tenant_setting` — czytają je oba ekrany (`Service\CronStan`). */
    public const USTAWIENIE_SONDA_WYSLANA = 'cron_probe_sent_at';

    public const USTAWIENIE_SONDA_WYNIK = 'cron_probe_done_at';
    private const SESSION_CLEANUP_EVERY = 3600;
    public const JOB_SYNC_CLEANUP = 'maintenance.sync_cleanup';
    private const SYNC_CLEANUP_EVERY = 3600;
    /** Cisza connectora: sprawdzane co godzinę, alert wysyłany RAZ na epizod ciszy (zlecenie 21.09 §2.2). */
    public const JOB_KEY_IDLE_CHECK = 'maintenance.key_idle_check';
    private const KEY_IDLE_CHECK_EVERY = 3600;
    public const JOB_KEY_IDLE_ALERT = 'integration.key_idle_alert';
    /** Klucz wydany i nieużyty dłużej niż własny próg — inna sprawa niż cisza działającego connectora. */
    public const JOB_KEY_UNUSED_ALERT = 'integration.key_unused_alert';
    /**
     * Cisza JEDNEJ firmy przy działającym kluczu (zlecenie Z18). To inna awaria niż cisza klucza:
     * connector żyje, ale strumień tej firmy stoi — np. zatrzymany rozjazdem tożsamości albo
     * zepsutym połączeniem z tą jedną bazą kadrową. Przy ciszy CAŁEGO klucza ten alert się nie
     * odzywa — patrz `checkCompanyIdle()`.
     */
    public const JOB_COMPANY_IDLE_ALERT = 'integration.company_idle_alert';
    private const ALERT_RESET = '1970-01-01 00:00:00';

    /**
     * Zadania cykliczne: typ → co ile sekund. **Jedno źródło dla przebiegu i dla ekranu kolejki**
     * (zlecenie Z43): `run()` zamawia stąd, a `/ustawienia/poczta` stąd wie, że usunięte zadanie
     * tego typu wróci w następnym przebiegu. Dwie listy tych samych typów rozjechałyby się przy
     * pierwszym nowym zadaniu, a ekran obiecywałby powrót zadania, które nie wraca.
     *
     * @var array<string, int>
     */
    public const CYKLICZNE = [
        self::JOB_SESSION_CLEANUP => self::SESSION_CLEANUP_EVERY,
        Canary::JOB_CHECK         => Canary::CHECK_EVERY,
        self::JOB_SYNC_CLEANUP    => self::SYNC_CLEANUP_EVERY,
        self::JOB_KEY_IDLE_CHECK  => self::KEY_IDLE_CHECK_EVERY,
    ];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly AccountMailService $accountMail,
        private readonly SessionRepository $sessions,
        /**
         * ⚠ FABRYKA, nie gotowy transport: w produkcji brak konfiguracji SMTP rzuca wyjątek,
         * a to nie ma prawa wywrócić całej kolejki — sprzątanie sesji, sygnał kontrolny
         * i sprawdzenia kluczy muszą chodzić dalej. Wyjątek dotyczy zadania pocztowego
         * i kończy się jego ponowieniem, nie martwym runnerem.
         */
        private readonly TransportFactory $transportFactory,
        private readonly Logger $logger,
        private readonly Canary $canary,
        private readonly SyncRepository $sync,
        private readonly ApiKeyRepository $apiKeys,
        private readonly TenantSettingRepository $settings,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * Przebieg crona: zadania według priorytetu, w budżecie czasu; reszta zostaje następnemu przebiegowi.
     *
     * @return array{done: int, retried: int, failed: int, skipped: int}
     */
    public function run(float $budgetSeconds): array
    {
        // Zadania cykliczne zapisujemy w tej samej kolejce — rzadszy cron to rzadsze sprzątanie, nic więcej.
        foreach (self::CYKLICZNE as $typ => $coIleSekund) {
            $this->jobs->ensureScheduled($typ, $coIleSekund);
        }

        return $this->process($budgetSeconds, 255, PHP_INT_MAX);
    }

    /**
     * Domknięcie kolejki po odpowiedzi (QUEUE_ON_REQUEST): wyłącznie zadania pilne, najwyżej $maxJobs.
     *
     * @return array{done: int, retried: int, failed: int, skipped: int}
     */
    public function runUrgent(float $budgetSeconds, int $maxJobs = 1): array
    {
        return $this->process($budgetSeconds, JobPriority::URGENT, $maxJobs);
    }

    /** @return array{done: int, retried: int, failed: int, skipped: int} */
    private function process(float $budgetSeconds, int $maxPriority, int $maxJobs): array
    {
        $stats = ['done' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        $deadline = microtime(true) + $budgetSeconds;
        $taken = 0;

        while (microtime(true) < $deadline && $taken < $maxJobs) {
            $batch = $this->jobs->claim(min(self::BATCH, $maxJobs - $taken), self::LOCK_SECONDS, $maxPriority);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $job) {
                if (microtime(true) >= $deadline) {
                    // Zadanie zarezerwowane, ale bez czasu na wykonanie — wraca do kolejki bez kary.
                    $this->jobs->markRetry($job['id'], 'przerwane: koniec budżetu czasu', 0);
                    continue;
                }
                $stats[$this->execute($job)]++;
                $taken++;
            }
        }

        return $stats;
    }

    /** Porcjami: najwyżej 10 × 1000 wierszy w jednym zadaniu. */
    private function cleanupSessions(): bool
    {
        $deleted = 0;
        for ($i = 0; $i < 10; $i++) {
            $batch = $this->sessions->deleteStale(1000);
            $deleted += $batch;
            if ($batch < 1000) {
                break;
            }
        }
        $this->logger->info('Sprzątanie sesji', ['deleted' => $deleted]);

        return true;
    }

    /** API connectora: odpowiedzi idempotencji starsze niż 7 dni i ślady przeglądów z zamkniętych przebiegów. */
    private function cleanupSync(): bool
    {
        $deleted = ['odpowiedzi' => 0, 'przeglad' => 0];
        for ($i = 0; $i < 10; $i++) {
            $a = $this->sync->deleteOldResponses(1000);
            $b = $this->sync->deleteStaleReviewRows(5000);
            $deleted['odpowiedzi'] += $a;
            $deleted['przeglad'] += $b;
            if ($a < 1000 && $b < 5000) {
                break;
            }
        }
        $this->logger->info('Sprzątanie synchronizacji', $deleted);

        return true;
    }

    /**
     * Cisza connectora: klucz aktywny, a od progu nie było ani jednego żądania.
     *
     * ⚠ Alert ma pójść RAZ na epizod, nie co godzinę — slot zajmujemy warunkowo, a gdy connector
     * znów się odezwie, kasujemy znacznik, żeby następna cisza znów wysłała jedną wiadomość.
     */
    private function checkKeyIdle(): bool
    {
        $prog = (int) $this->settings->get('key_idle_alert_hours', '24');
        if ($prog <= 0) {
            return true;
        }
        $milczace = [];
        $podZywymKluczem = [];
        foreach ($this->apiKeys->all() as $key) {
            if ($key['status'] !== 'active') {
                continue;
            }
            // ⚠ Alert dotyczy klucza, który ZAMILKŁ. Klucz nigdy nieużyty czeka na wklejenie
            // w connectorze i widać go na ekranie — nie jest powodem do wysyłania alarmu.
            $milczy = $key['last_used_at'] !== null
                && Cisza::przekroczona($key['last_used_at'], (string) $key['created_at'], $prog);
            if ($milczy) {
                $milczace[$key['name']] = Cisza::godziny((string) $key['last_used_at']);

                continue;
            }
            // ⚠ Firmy klucza, który się odzywa — tylko one mogą dostać alert o ciszy JEDNEJ firmy.
            // Firmy klucza milczącego opisuje już alert wyżej i drugi list byłby tą samą wiadomością
            // powiedzianą dwa razy (zlecenie Z18: przy ciszy całego klucza nie wychodzą trzy maile).
            foreach ($this->apiKeys->nips($key['id']) as $nip) {
                $podZywymKluczem[$nip] = true;
            }
        }
        $this->checkKeyUnused();
        $this->checkCompanyIdle($prog, $podZywymKluczem);
        if ($milczace === []) {
            // Connector znów się odzywa — następna cisza ma prawo wysłać alert od nowa.
            if ($this->settings->get('key_idle_alert_last_at', self::ALERT_RESET) !== self::ALERT_RESET) {
                $this->settings->set('key_idle_alert_last_at', self::ALERT_RESET, null);
            }

            return true;
        }
        // Slot zajmowany warunkowym UPDATE-em: dwa równoległe przebiegi nie wyślą dwóch wiadomości.
        if (!$this->settings->claimAlertSlot('key_idle_alert_last_at', max(1, $prog) * 3600)) {
            return true;
        }
        $this->events->record('integration.key_idle', null, null, 'system', 'api_key', null, null,
            ['prog_godzin' => $prog, 'klucze' => $milczace]);
        $this->jobs->enqueue(self::JOB_KEY_IDLE_ALERT, ['prog_godzin' => $prog, 'klucze' => $milczace]);
        $this->logger->info('Cisza connectora', ['prog_godzin' => $prog, 'klucze' => $milczace]);

        return true;
    }

    /**
     * Cisza JEDNEJ firmy: klucz się odzywa, a ta firma nie ma domkniętego przebiegu od progu.
     *
     * ⚠ **To jest inna awaria niż cisza klucza i dlatego ma własny alert.** Cisza klucza znaczy
     * „connector nie chodzi” — sprawdza się maszynę i usługę. Cisza jednej firmy przy żywym kluczu
     * znaczy „connector chodzi, ale TEN strumień stoi” — sprawdza się ekran integracji tej firmy
     * (zatrzymany rozjazd tożsamości, zerwane połączenie z jedną bazą kadrową). Przy jednym kluczu
     * na dwie firmy — a tak stoi u klienta odniesienia — pierwszy alert nie zauważyłby niczego,
     * bo `last_used_at` klucza jest świeże od drugiej firmy.
     *
     * ⚠ Liczymy od **domkniętego przebiegu**, nie od żądania: connector, który się łączy, a którego
     * przebiegi padają, odnawiałby znacznik żądania w nieskończoność i cisza nigdy by nie wyszła.
     *
     * ⚠ Firma, która **nigdy** nie miała domkniętego przebiegu, jest pomijana świadomie: to nie jest
     * cisza, tylko połączenie, które się jeszcze nie zaczęło — mówi o niej `key_unused` i ekran
     * integracji. Alarmowanie o niej zrobiłoby z każdej nowej firmy awarię.
     *
     * @param array<string, true> $podZywymKluczem NIP-y firm obsługiwanych przez klucz, który się odzywa
     */
    private function checkCompanyIdle(int $prog, array $podZywymKluczem): void
    {
        if ($prog <= 0 || $podZywymKluczem === []) {
            return;
        }
        foreach ($this->sync->streams() as $firma) {
            if (!isset($podZywymKluczem[$firma['nip']])) {
                continue;
            }
            $ostatni = $this->sync->lastClosedRunAt($firma['id']);
            if ($ostatni === null) {
                continue;
            }
            $godzin = Cisza::godziny($ostatni);
            if ($godzin < $prog) {
                // Firma znów się synchronizuje — następna cisza ma prawo wysłać alert od nowa.
                $this->sync->clearIdleAlert($firma['id']);

                continue;
            }
            // Slot zajmowany warunkowym UPDATE-em na wierszu strumienia: jedna wiadomość na epizod
            // i na firmę, także przy dwóch równoległych przebiegach crona.
            if (!$this->sync->claimIdleAlertSlot($firma['id'], max(1, $prog) * 3600)) {
                continue;
            }
            $this->events->record('integration.company_idle', null, null, 'system', 'company', $firma['id'], $firma['id'],
                ['prog_godzin' => $prog, 'godzin' => $godzin]);
            $this->jobs->enqueue(self::JOB_COMPANY_IDLE_ALERT, [
                'firma' => $firma['name'],
                'godzin' => $godzin,
                'prog_godzin' => $prog,
                'ostatni_przebieg' => $ostatni,
            ]);
            $this->logger->info('Cisza firmy', ['firma_id' => $firma['id'], 'godzin' => $godzin, 'prog_godzin' => $prog]);
        }
    }

    /**
     * Klucz wydany i nigdy nieużyty dłużej niż własny próg (domyślnie 72 h).
     *
     * ⚠ To nie jest awaria — dotychczasowy klucz działa. Wiadomość ma o tym mówić w pierwszym
     * zdaniu, inaczej uczy ludzi ignorować alerty.
     */
    private function checkKeyUnused(): void
    {
        $prog = (int) $this->settings->get('key_unused_alert_hours', '72');
        if ($prog <= 0) {
            return;
        }
        $czekajace = [];
        foreach ($this->apiKeys->all() as $key) {
            if ($key['status'] === 'active' && $key['last_used_at'] === null
                && Cisza::przekroczona(null, (string) $key['created_at'], $prog)) {
                $czekajace[$key['name']] = Cisza::godziny((string) $key['created_at']);
            }
        }
        if ($czekajace === []) {
            if ($this->settings->get('key_unused_alert_last_at', self::ALERT_RESET) !== self::ALERT_RESET) {
                $this->settings->set('key_unused_alert_last_at', self::ALERT_RESET, null);
            }

            return;
        }
        if (!$this->settings->claimAlertSlot('key_unused_alert_last_at', max(1, $prog) * 3600)) {
            return;
        }
        $this->events->record('integration.key_unused', null, null, 'system', 'api_key', null, null,
            ['prog_godzin' => $prog, 'klucze' => $czekajace]);
        $this->jobs->enqueue(self::JOB_KEY_UNUSED_ALERT, ['prog_godzin' => $prog, 'klucze' => $czekajace]);
        $this->logger->info('Klucz wydany i nieużyty', ['prog_godzin' => $prog, 'klucze' => $czekajace]);
    }

    private function checkCanary(): bool
    {
        $result = $this->canary->check();
        $this->logger->info('Kanarek storage/', $result);

        return true;
    }

    /** @param array{id: int, type: string, payload: array<string, mixed>, attempts: int} $job */
    private function execute(array $job): string
    {
        try {
            $delivered = match ($job['type']) {
                AccountMailService::JOB_PASSWORD_RESET,
                AccountMailService::JOB_INVITATION => $this->accountMail->deliver($job['type'], $job['payload'], $this->transport($job)),
                RateLimiter::JOB_SECURITY_ALERT     => $this->accountMail->deliverSecurityAlert($job['payload'], $this->transport($job)),
                Sync\TaskService::JOB_HR_ALERT,
                Sync\TaskService::JOB_HR_ALERT_URGENT => $this->accountMail->deliverHrAlert($job['payload'], $this->transport($job)),
                self::JOB_SESSION_CLEANUP           => $this->cleanupSessions(),
                self::JOB_CRON_PROBE                => $this->cronProbe($job['id']),
                self::JOB_SYNC_CLEANUP              => $this->cleanupSync(),
                Canary::JOB_CHECK                   => $this->checkCanary(),
                self::JOB_KEY_IDLE_CHECK            => $this->checkKeyIdle(),
                self::JOB_KEY_IDLE_ALERT            => $this->accountMail->deliverIntegrationAlert($job['payload'], $this->transport($job)),
                self::JOB_KEY_UNUSED_ALERT          => $this->accountMail->deliverUnusedKeyAlert($job['payload'], $this->transport($job)),
                self::JOB_COMPANY_IDLE_ALERT        => $this->accountMail->deliverCompanyIdleAlert($job['payload'], $this->transport($job)),
                Sync\SyncService::JOB_SPLIT_ALERT    => $this->accountMail->deliverIdentitySplitAlert($job['payload'], $this->transport($job)),
                default => throw new \DomainException('Nieznany typ zadania: ' . $job['type']),
            };
            $this->jobs->markDone($job['id']);

            return $delivered ? 'done' : 'skipped';
        } catch (Throwable $e) {
            $error = $e::class . ': ' . $e->getMessage();
            $this->logger->error('Zadanie nie powiodło się', [
                'job_id'  => $job['id'],
                'type'    => $job['type'],
                'attempt' => $job['attempts'],
                'error'   => $error,
            ]);
            if ($job['attempts'] >= self::MAX_ATTEMPTS || $e instanceof \DomainException) {
                $this->jobs->markFailed($job['id'], $error);

                return 'failed';
            }
            // Rosnące odstępy: 1, 4, 9, 16 minut.
            $this->jobs->markRetry($job['id'], $error, 60 * $job['attempts'] ** 2);

            return 'retried';
        }
    }

    /**
     * Wykonanie sondy crona: znacznik czasu w ustawieniach, jeden wpis w dzienniku, koniec.
     *
     * ⚠ Wiersz zadania **usuwamy**, a nie oznaczamy jako wykonany: sonda ma nie zostawiać śladu
     * w kolejce (§2.1 zlecenia). Znacznik w `tenant_setting` wystarcza obu ekranom, a dziennik
     * dostaje dokładnie jeden wpis.
     */
    private function cronProbe(int $jobId): bool
    {
        $this->settings->set(self::USTAWIENIE_SONDA_WYNIK, gmdate('Y-m-d H:i:s'), null);
        $this->events->record('maintenance.cron_probe', null, null, 'cron');
        $this->jobs->forget($jobId);

        return true;
    }

    /**
     * Transport poczty rozwiązywany **przy zadaniu**, nie przy budowie runnera.
     *
     * ⚠ Znacznik udanej wysyłki dokłada sama fabryka (`TransportZeZnacznikiem`) — tu wystarczy
     * o niego nie zapomnieć, a nie budować go drugi raz. Ekran ustawień idzie tą samą drogą,
     * więc wysyłka próbna przez SMTP też odświeża znacznik.
     *
     * ⚠ **Wpis w dzienniku zakłada `TransportZDziennikiem`, a nie `execute()`** (Z43 §C):
     * odbiorcę zna wyłącznie wiadomość, a nie typ zadania. Zadanie, które poczty nie wysyła,
     * dostaje transport bez dekoratora — inaczej sprzątanie sesji zostawiałoby wpis o wysyłce.
     */
    /**
     * @param array{id: int, type: string, payload: array<string, mixed>, attempts: int} $job
     */
    private function transport(array $job): MailTransport
    {
        $transport = $this->transportFactory->transport();
        $rodzaj = ZadaniaKolejki::wiadomosc($job['type']);
        if ($rodzaj === '') {
            // Zadanie bez wiadomości (sprzątanie, sprawdzenia) — nie ma czego zapisywać.
            return $transport;
        }

        // ⚠ Sprawcą jest `system`, nie `cron`, i to nie jest drobiazg językowy: katalog zdań
        // stawia sprawcę na początku zdania, a „Zadanie cykliczne wysłał(a)" nie zgadza się
        // z żadną formą polskiego orzeczenia. Numer zadania w szczegółach mówi, że wiadomość
        // poszła z kolejki — a `integration.key_idle` z tego samego przebiegu zapisuje się
        // tym samym sprawcą od 21.09.2026.
        return new TransportZDziennikiem(
            $transport,
            $this->events,
            $rodzaj,
            ZadaniaKolejki::wiadomoscDopelniacz($job['type']),
            'system',
            $job['id'],
            null,
            $this->transportFactory->rodzaj() === 'plik' ? 'zrzut do pliku' : null,
        );
    }
}
