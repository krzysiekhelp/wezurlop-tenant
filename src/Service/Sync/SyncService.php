<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use DateTimeImmutable;
use DateTimeZone;
use Wezurlop\Db\Connection;
use Wezurlop\Http\Middleware\ApiKeyAuth;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\SyncTaskRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Support\Nip;

/**
 * Operacje API connectora (docs/api-connector.md, protokół 1). STREFA JAWNA — musi być diagnozowalne
 * u klienta. Każda operacja niesie `nip`; kursory, paczki i przebiegi są per firma.
 *
 * - paczka to jedna transakcja: walidacja PRZED zapisem, rekord niezgodny → 422 dla całej paczki,
 * - kursor to token potwierdzenia; `pelny`, gdy nie mamy kursora, connector go nie przysłał albo się różnią,
 * - ⚠ przegląd kontrolny oznacza brakujące rekordy jako usunięte WYŁĄCZNIE po paczce z `ostatnia: true`,
 *   w obrębie tego przebiegu; przerwany przegląd nie kasuje niczego,
 * - wszystko opisane w sync_run: fazy, liczby, pominięte pola, żądania bez X-Idempotency-Key, wynik.
 */
final class SyncService
{
    /** Odciski tożsamości firm jednego klucza się różnią — dwie instalacje connectora. */
    public const EVENT_SPLIT = 'sync.identity_split';
    /** Rozjazd ustał — odciski znów zgodne. */
    public const EVENT_SPLIT_GONE = 'sync.identity_split_resolved';
    /** Wiadomość do skrzynki alertowej tenanta — wysyłana przez kolejkę, nie w żądaniu API. */
    public const JOB_SPLIT_ALERT = 'integration.identity_split_alert';

    public const ZBIORY = ['firma', 'pracownik', 'zatrudnienie', 'limit', 'nieobecnosc', 'slownik', 'kalendarz'];
    public const MAX_RECORDS = 500;
    public const DEFAULT_TASKS = 20;
    public const MAX_TASKS = 100;
    private const SIGNUP_PER_HOUR = 10;
    /** Domyślny czas życia przebiegu bez żadnego żądania; wartość obowiązująca w tenant_setting. */
    public const RUN_TTL_MINUTES = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly SyncRepository $sync,
        private readonly SyncDomainRepository $domain,
        private readonly SyncTaskRepository $tasks,
        private readonly ApiKeyRepository $keys,
        private readonly TenantSettingRepository $settings,
        private readonly SchemaValidator $validator,
        private readonly RecordApplier $applier,
        private readonly TaskService $taskService,
        private readonly EventLogRepository $events,
        private readonly ApiKeyService $keyService,
        private readonly JobRepository $jobs,
    ) {
    }

    // --- start ---------------------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function start(array $key, array $body, bool $idempotencyKey): array
    {
        $company = $this->company($key, $body);
        $connector = [
            'wersjaConnectora' => self::optionalString($body, 'wersjaConnectora', 32),
            'wersjaR2'         => self::optionalString($body, 'wersjaR2', 32),
            'ostatniUdanyCykl' => self::optionalString($body, 'ostatniUdanyCykl', 40),
            'stan'             => self::optionalString($body, 'stan', 24),
            // stanOpis: maks. 200 znaków, bez danych osobowych (przycięty, nie odrzucany)
            'stanOpis'         => is_string($body['stanOpis'] ?? null) ? mb_substr($body['stanOpis'], 0, 200) : null,
        ];
        $theirs = is_array($body['kursory'] ?? null) ? $body['kursory'] : [];
        $this->assertIdentity($company, $body);
        $this->checkIdentityAcrossKey($key);

        return $this->db->transaction(function () use ($key, $company, $connector, $theirs, $idempotencyKey): array {
            $this->sync->ensureStream($company['id'], $connector + ['czas' => gmdate('c')]);
            $uuid = self::uuid4();
            $runId = $this->sync->openRun($uuid, $company['id'], $key['id'], $connector);
            $ours = $this->sync->cursors($company['id']);
            $zbiory = [];
            foreach (self::ZBIORY as $z) {
                $mine = $ours[$z] ?? null;
                $their = is_string($theirs[$z] ?? null) ? $theirs[$z] : null;
                // Jedna reguła: pełny, gdy nie mamy kursora, connector go nie przysłał albo się różnią.
                $full = $mine === null || $their === null || $mine !== $their;
                $zbiory[$z] = ['kursor' => $full ? null : $mine, 'tryb' => $full ? 'pelny' : 'zmiany'];
            }
            $stream = $this->sync->stream($company['id']);
            $hours = max(0.0, (float) $this->settings->get('sync_review_hours', '1'));
            $review = $stream['review_required'] || $stream['review_age_hours'] === null || $stream['review_age_hours'] >= $hours;

            $this->sync->saveRunStats($runId, 'start', [
                'tryby' => array_map(static fn (array $z): string => $z['tryb'], $zbiory),
                'przegladKontrolny' => $review,
                'bezIdempotencji' => $idempotencyKey ? 0 : 1,
            ]);

            return [
                'przebiegId'        => $uuid,
                'zbiory'            => $zbiory,
                'przegladKontrolny' => $review,
                'rozmiarPaczki'     => self::MAX_RECORDS,
                'wycofane'          => $this->tasks->withdrawnRequestIds($company['id']),
            ];
        });
    }

    /**
     * ⚠ Bezpiecznik przed kompletem duplikatów osób (rozstrzygnięcie 20.09.2026). Sekret, z którego
     * connector liczy `osobaId`, mieszka w JEGO konfiguracji — przenosiny same z siebie go nie zachowują.
     * Gdy przepadnie, ci sami ludzie przychodzą z nowymi identyfikatorami i zakładamy drugi komplet osób,
     * a dowiadujemy się o tym po fakcie.
     *
     * Dlatego sprawdzenie jest **w `start`, przed pierwszą paczką**: inny odcisk zatrzymuje strumień
     * TEJ firmy i nie zapisuje ani jednego rekordu. Pozostałe firmy pracują dalej.
     * Brak pola = starszy connector: przyjmujemy bez zastrzeżeń i nie zapisujemy nic.
     *
     * @param array{id: int, nip: string} $company
     * @param array<string, mixed> $body
     */
    private function assertIdentity(array $company, array $body): void
    {
        $sent = $body['odciskTozsamosci'] ?? null;
        if ($sent === null) {
            return;
        }
        if (!is_string($sent) || preg_match('/^[0-9a-f]{8}$/', $sent) !== 1) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Pole odciskTozsamosci: 8 znaków szesnastkowych');
        }
        $known = $this->domain->identityFingerprint($company['id']);
        if ($known['fingerprint'] === null) {
            // Pierwszy raz dla tej firmy — zapamiętujemy cicho, bo nie ma z czym porównywać.
            $this->domain->setIdentityFingerprint($company['id'], $sent);

            return;
        }
        if ($known['fingerprint'] === $sent) {
            return;
        }
        $this->domain->setPendingIdentityFingerprint($company['id'], $sent);
        if ($known['pending'] !== $sent) {
            $this->events->record('sync.identity_changed', null, null, 'sync', 'company', $company['id'], $company['id'],
                ['znany' => $known['fingerprint'], 'nowy' => $sent]);
        }

        throw new ApiError(403, 'TOZSAMOSC_ZMIENIONA',
            'Connector zgłasza inną tożsamość osób niż dotychczas — strumień tej firmy zatrzymany do decyzji administratora',
            ['odcisk' => $sent], [], ['nip' => Nip::doLogu($company['nip']), 'znany' => $known['fingerprint'], 'nowy' => $sent]);
    }

    /**
     * Odcisk tożsamości MUSI być ten sam dla wszystkich firm jednego klucza.
     *
     * ⚠ Sekret, z którego connector liczy `osobaId`, jest jeden na instalację connectora
     * (ustalenie z jego autorem, 20.09.2026). Dwa różne odciski pod jednym kluczem znaczą,
     * że firmy obsługują DWIE instalacje — i wtedy ta sama osoba zatrudniona w obu rozpadnie się
     * u nas na dwie osoby, bez żadnego błędu po drodze.
     *
     * ⚠ To jest STAN WYMAGAJĄCY UWAGI, nie błąd: strumienia nie zatrzymujemy. Dwie instalacje
     * bywają konfiguracją świadomą, a zatrzymanie synchronizacji kadr za coś, co działa, byłoby
     * powtórzeniem błędu licznika nakładki. Administrator widzi to na ekranie i w diagnostyce.
     *
     * @param array{id: int} $key
     */
    private function checkIdentityAcrossKey(array $key): void
    {
        $rozjazd = self::identitySplit($this->domain->companiesForKey($key['id']));
        $poprzedni = $this->events->lastCodeFor('api_key', $key['id'], [self::EVENT_SPLIT, self::EVENT_SPLIT_GONE]);
        if ($rozjazd !== null && $poprzedni !== self::EVENT_SPLIT) {
            $this->events->record(self::EVENT_SPLIT, null, null, 'sync', 'api_key', $key['id'], null, $rozjazd);
            // ⚠ Cicha degradacja danych: wszystko działa, więc nikt tego sam nie zauważy.
            // Wiadomość idzie RAZ, przy wykryciu — kolejna dopiero po ustaniu i ponownym rozjeździe.
            $this->jobs->enqueue(self::JOB_SPLIT_ALERT, [
                'klucz' => (string) ($this->keys->find($key['id'])['name'] ?? '—'),
                'firmy' => (string) $rozjazd['firmy'],
                'osob' => $this->domain->peopleInManyCompanies(),
            ]);

            return;
        }
        if ($rozjazd === null && $poprzedni === self::EVENT_SPLIT) {
            $this->events->record(self::EVENT_SPLIT_GONE, null, null, 'sync', 'api_key', $key['id'], null, []);
        }
    }

    /**
     * @param list<array{id: int, nip: string, name: string, fingerprint: ?string}> $companies
     * @return array<string, scalar|null>|null null = odciski zgodne (albo jest tylko jeden)
     */
    public static function identitySplit(array $companies): ?array
    {
        $odciski = [];
        foreach ($companies as $c) {
            if ($c['fingerprint'] !== null) {
                $odciski[$c['fingerprint']][] = $c['nip'];
            }
        }
        if (count($odciski) < 2) {
            return null;
        }
        $opis = [];
        foreach ($odciski as $odcisk => $nipy) {
            $opis[] = $odcisk . ': ' . implode(', ', $nipy);
        }

        return ['odciskow' => count($odciski), 'firmy' => implode(' | ', $opis)];
    }

    // --- dane ----------------------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{przyjeto: int, kursor: string, pominietePola: int}
     */
    public function dane(array $key, array $body, bool $idempotencyKey): array
    {
        $company = $this->company($key, $body);
        $run = $this->run($company, $body);
        [$zbior, $ostatnia] = $this->batchEnvelope($body);
        $records = $body['rekordy'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Brak listy rekordów');
        }
        if (count($records) > self::MAX_RECORDS) {
            throw self::tooLarge();
        }
        $checked = $this->validator->validate($zbior, $records);
        if ($checked['errors'] !== []) {
            // Paczka odrzucona w całości; connector usuwa wskazane pozycje i ponawia resztę.
            throw new ApiError(422, 'REKORDY_NIEPOPRAWNE', 'Paczka zawiera rekordy niezgodne ze schematem', [
                'pozycje' => array_slice($checked['errors'], 0, 500),
            ]);
        }

        return $this->db->transaction(function () use ($company, $run, $zbior, $ostatnia, $checked, $idempotencyKey): array {
            $counts = [];
            foreach ($checked['records'] as $record) {
                $result = $this->applier->apply($company['id'], $company['nip'], $zbior, $record, $run['id']);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
            $pending = $this->applier->resolvePending($company['id'], $company['nip'], $run['id']);
            $cursor = $this->sync->advanceCursor($company['id'], $zbior);

            $stats = $run['stats'];
            foreach ($counts as $result => $n) {
                $stats['dane'][$zbior][$result] = ($stats['dane'][$zbior][$result] ?? 0) + $n;
            }
            $stats['dane'][$zbior]['paczki'] = ($stats['dane'][$zbior]['paczki'] ?? 0) + 1;
            $stats['pominietePola'] = ($stats['pominietePola'] ?? 0) + $checked['skipped'];
            $stats['oczekujace'] = $pending['pending'];
            $stats['rozwiazaneOczekujace'] = ($stats['rozwiazaneOczekujace'] ?? 0) + $pending['resolved'];
            $stats = $this->mergeEvents($stats, $this->applier->takeEvents());
            $stats = self::countIdempotency($stats, $idempotencyKey);
            // Pełne ładowanie zbioru zakończone → przegląd kontrolny w następnym przebiegu.
            if ($ostatnia && ($stats['tryby'][$zbior] ?? '') === 'pelny') {
                $this->sync->setReviewRequired($company['id'], true);
            }
            $this->sync->saveRunStats($run['id'], 'dane', $stats);

            return ['przyjeto' => count($checked['records']), 'kursor' => $cursor, 'pominietePola' => $checked['skipped']];
        });
    }

    // --- przegląd kontrolny ------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{przyjeto: int, kursor: string}
     */
    public function przeglad(array $key, array $body, bool $idempotencyKey): array
    {
        $company = $this->company($key, $body);
        $run = $this->run($company, $body);
        [$zbior, $ostatnia] = $this->batchEnvelope($body);
        $positions = $body['pozycje'] ?? null;
        if (!is_array($positions) || !array_is_list($positions)) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Brak listy pozycji');
        }
        if (count($positions) > self::MAX_RECORDS) {
            throw self::tooLarge();
        }
        $errors = [];
        $clean = [];
        foreach ($positions as $p) {
            $id = is_array($p) ? ($p['id'] ?? null) : null;
            $sum = is_array($p) ? ($p['suma'] ?? null) : null;
            if (!is_string($id) || $id === '' || mb_strlen($id) > SchemaValidator::ID_MAX) {
                $errors[] = ['id' => is_string($id) ? $id : null, 'kod' => $id === null ? 'POLE_WYMAGANE' : 'ZLY_TYP', 'pole' => 'id'];
            } elseif (!is_string($sum) || preg_match('/^sha256:[0-9a-f]{64}$/', $sum) !== 1) {
                $errors[] = ['id' => $id, 'kod' => $sum === null ? 'POLE_WYMAGANE' : 'ZLY_TYP', 'pole' => 'suma'];
            } else {
                $clean[$id] = $sum;
            }
        }
        if ($errors !== []) {
            throw new ApiError(422, 'REKORDY_NIEPOPRAWNE', 'Paczka zawiera pozycje niezgodne ze schematem', ['pozycje' => $errors]);
        }

        return $this->db->transaction(function () use ($company, $run, $zbior, $ostatnia, $clean, $idempotencyKey): array {
            $stats = self::countIdempotency($run['stats'], $idempotencyKey);
            // Nigdy dwa przeglądy tego samego zbioru w jednym przebiegu.
            if ($this->sync->reviewCompleted($run['id'], $zbior)) {
                $stats['przeglad'][$zbior]['powtorzony'] = ($stats['przeglad'][$zbior]['powtorzony'] ?? 0) + 1;
                $this->sync->saveRunStats($run['id'], 'przeglad', $stats);

                return ['przyjeto' => count($clean), 'kursor' => $this->sync->advanceCursor($company['id'], $zbior)];
            }
            $this->sync->markSeen($run['id'], $zbior, array_keys($clean));
            // Rozjazd sum albo rekord, którego u nas nie ma → ten zbiór wymaga pełnego ładowania.
            $ours = $this->sync->records($company['id'], $zbior, array_keys($clean));
            $mismatch = 0;
            foreach ($clean as $id => $sum) {
                $known = $ours[$id] ?? null;
                if ($known === null || !in_array($known['state'], ['active', 'pending'], true) || ($known['state'] === 'active' && $known['checksum'] !== $sum)) {
                    $mismatch++;
                }
            }
            $stats['przeglad'][$zbior]['pozycje'] = ($stats['przeglad'][$zbior]['pozycje'] ?? 0) + count($clean);
            $stats['przeglad'][$zbior]['rozjazd'] = ($stats['przeglad'][$zbior]['rozjazd'] ?? 0) + $mismatch;

            if ($ostatnia) {
                // ⚠ Dopiero kompletny przegląd domyka usunięcia — i tylko w obrębie TEGO przebiegu.
                $missing = $this->sync->missingInReview($company['id'], $run['id'], $zbior);
                $counts = [];
                foreach ($missing as $id) {
                    $r = $this->applier->apply($company['id'], $company['nip'], $zbior, ['id' => $id, 'usuniety' => true], $run['id']);
                    $counts[$r] = ($counts[$r] ?? 0) + 1;
                }
                $stats['przeglad'][$zbior]['brakujace'] = count($missing);
                foreach ($counts as $r => $n) {
                    $stats['przeglad'][$zbior][$r] = $n;
                }
                $this->sync->finishReview($run['id'], $zbior);
                $this->sync->markReviewDone($company['id']);
                if (($stats['przeglad'][$zbior]['rozjazd'] ?? 0) > 0) {
                    // Rozjazd: następny cykl pobierze ten zbiór w całości, a `koniec` poprosi o pełny przegląd.
                    $this->sync->setReviewRequired($company['id'], true);
                    $this->sync->resetCursor($company['id'], $zbior);
                    $stats['pelnyPrzegladWymagany'] = true;
                }
                // Wygaszenie oczekujących: po pełnym przeglądzie nadal nie mają na czym wisieć.
                foreach ($this->sync->expirePending($company['id'], $run['started_at'], $run['id']) as $orphan) {
                    $this->sync->conflict($company['id'], $run['id'], null, 'record_orphaned',
                        'Rekord ' . $orphan['zbior'] . ' ' . $orphan['record_id'] . ' wskazuje na nieistniejący rekord (' . ($orphan['pending_reason'] ?? '?')
                        . ') po ' . $orphan['pending_attempts'] . ' próbach', $orphan);
                    $stats['wygaszoneOczekujace'] = ($stats['wygaszoneOczekujace'] ?? 0) + 1;
                }
                $stats['oczekujace'] = $this->sync->countPending($company['id']);
            }
            $stats = $this->mergeEvents($stats, $this->applier->takeEvents());
            $cursor = $this->sync->advanceCursor($company['id'], $zbior);
            $this->sync->saveRunStats($run['id'], 'przeglad', $stats);

            return ['przyjeto' => count($clean), 'kursor' => $cursor];
        });
    }

    // --- zadania -------------------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * ⚠ `$nipWAdresie` to **licznik wycofywanej drogi**, nie zachowanie: mówi tylko tyle, że ten
     * connector przysłał NIP w adresie (`?nip=`) zamiast w nagłówku `X-Nip`. Bez tego licznika etap
     * drugi — usunięcie obsługi `?nip=` — opierałby się na domyśle „chyba już wszyscy przeszli",
     * a tu jedno ustawienie w connectorze u klienta decyduje o tym, czy synchronizacja stanie.
     * Liczymy w `zadania`, bo ta operacja jedzie w KAŻDYM cyklu; `stan` to ręczny test połączenia
     * i nie należy do żadnego przebiegu, więc nie ma gdzie go policzyć.
     *
     * @param array<string, mixed> $query
     * @return array{zadania: list<array<string, mixed>>}
     */
    public function zadania(array $key, array $query, bool $nipWAdresie = false): array
    {
        $company = $this->company($key, $query);
        $run = $this->run($company, $query);
        $limit = self::DEFAULT_TASKS;
        if (isset($query['limit'])) {
            $parsed = filter_var($query['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed === false) {
                throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Parametr limit musi być liczbą dodatnią');
            }
            $limit = min(self::MAX_TASKS, $parsed);
        }

        return $this->db->transaction(function () use ($company, $run, $limit, $nipWAdresie): array {
            // Faza weryfikacji na danych, które właśnie przyszły, potem wydanie.
            $prepared = $this->taskService->prepare($company['id'], $run['id']);
            $issued = $this->taskService->issue($company['id'], $run['id'], $limit);
            $stats = $run['stats'];
            $stats['zadaniaWydane'] = ($stats['zadaniaWydane'] ?? 0) + count($issued);
            $stats['zadaniaUtworzone'] = ($stats['zadaniaUtworzone'] ?? 0) + $prepared['utworzone'];
            $stats['konflikty'] = ($stats['konflikty'] ?? 0) + $prepared['konflikty'];
            // ⚠ Pole jedzie ZAWSZE, także z zerem: „0" znaczy „ten przebieg w całości poszedł
            // nagłówkiem", a brak pola znaczyłby „nie wiadomo". Przy wyłączaniu `?nip=` różnica
            // między tymi dwoma zdaniami jest całą wartością tego licznika.
            $stats['nipWAdresie'] = ($stats['nipWAdresie'] ?? 0) + ($nipWAdresie ? 1 : 0);
            $this->sync->saveRunStats($run['id'], 'zadania', $stats);

            return ['zadania' => $issued];
        });
    }

    // --- wyniki --------------------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{przyjeto: int}
     */
    public function wyniki(array $key, array $body, bool $idempotencyKey): array
    {
        $company = $this->company($key, $body);
        $run = $this->run($company, $body);
        $items = $body['wyniki'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Brak listy wyników');
        }
        if (count($items) > self::MAX_RECORDS) {
            throw self::tooLarge();
        }
        [$valid, $errors] = self::validateResults($items);
        if ($errors !== []) {
            throw new ApiError(422, 'REKORDY_NIEPOPRAWNE', 'Wyniki niezgodne ze schematem', ['pozycje' => $errors]);
        }

        return $this->db->transaction(function () use ($company, $run, $valid, $idempotencyKey): array {
            $accepted = 0;
            $stats = self::countIdempotency($run['stats'], $idempotencyKey);
            foreach ($valid as $w) {
                $result = $this->taskService->applyResult($company['id'], $run['id'], $w);
                $key = $result ?? 'nieznanyRequestId';
                $stats['wyniki'][$key] = ($stats['wyniki'][$key] ?? 0) + 1;
                if ($result !== null) {
                    $accepted++;
                }
            }
            $this->sync->saveRunStats($run['id'], 'wyniki', $stats);

            return ['przyjeto' => $accepted];
        });
    }

    // --- koniec --------------------------------------------------------------------------------------

    /**
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{nastepnyInterwal: int, pelnyPrzegladWymagany: bool}
     */
    public function koniec(array $key, array $body, bool $idempotencyKey): array
    {
        $company = $this->company($key, $body);
        $run = $this->run($company, $body);
        // Podsumowanie connectora — wyłącznie znane pola, liczby i identyfikatory (bez danych osobowych).
        $summary = [];
        foreach (['wyslano', 'usuniete'] as $f) {
            $summary[$f] = is_array($body[$f] ?? null) ? array_filter($body[$f], 'is_int') : [];
        }
        $summary['odrzucone'] = is_array($body['odrzucone'] ?? null) ? array_values(array_map(static fn (mixed $o): array => [
            'zbior' => is_array($o) && is_string($o['zbior'] ?? null) ? mb_substr($o['zbior'], 0, 16) : null,
            'id' => is_array($o) && is_string($o['id'] ?? null) ? mb_substr($o['id'], 0, 64) : null,
            'zdarzenieId' => is_array($o) && is_string($o['zdarzenieId'] ?? null) ? mb_substr($o['zdarzenieId'], 0, 32) : null,
        ], array_slice($body['odrzucone'], 0, 500))) : [];
        foreach (['zadaniaPobrane', 'wynikiWyslane', 'pominietePola', 'czasMs'] as $f) {
            $summary[$f] = is_int($body[$f] ?? null) ? $body[$f] : null;
        }

        return $this->db->transaction(function () use ($key, $company, $run, $summary, $idempotencyKey): array {
            $stats = self::countIdempotency($run['stats'], $idempotencyKey);
            $stats['oczekujace'] = $this->sync->countPending($company['id']);
            $this->sync->saveRunStats($run['id'], 'koniec', $stats);
            $this->sync->closeRun($run['id'], $summary);
            // ⚠ Domknięty przebieg na nowym kluczu = przesiadka się udała, więc przejęcie następuje TU,
            // a nie po upływie czasu. Czas jest tylko bezpiecznikiem, gdyby przesiadka utknęła.
            $this->keyService->przejmij($key['id']);
            $stream = $this->sync->stream($company['id']);

            return [
                'nastepnyInterwal'      => $this->nextInterval($company['id']),
                'pelnyPrzegladWymagany' => $stream['review_required'],
            ];
        });
    }

    // --- zgłoszenie firmy ------------------------------------------------------------------------------

    /**
     * Wąska operacja: wyłącznie `nip` i `nazwa`, własny limit, bez przebiegu, bez zakładania firmy.
     * Trzy stany (docs/api-connector.md 3.1): `oczekuje` (202), `zatwierdzona` — NIP już w zakresie klucza,
     * connector wraca do `start` w tym samym cyklu (200), `odrzucona` — connector przestaje zgłaszać (200).
     * Powtórka nigdy nie tworzy duplikatu.
     *
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{status: string}
     */
    public function zgloszenieFirmy(array $key, array $body): array
    {
        $name = $body['nazwa'] ?? null;
        if (!is_string($body['nip'] ?? null) || !is_string($name) || trim($name) === '' || mb_strlen($name) > 200) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Wymagane pola: nip i nazwa (do 200 znaków)');
        }
        $nip = self::nip($body);
        if ($this->keys->hit($key['id'], 'signup', 3600) > self::SIGNUP_PER_HOUR) {
            throw new ApiError(429, 'ZA_SZYBKO', 'Zwolnij', [], ['Retry-After' => (string) (3600 - time() % 3600)]);
        }
        // ⚠ O odpowiedzi decyduje ISTNIENIE firmy, nie sama lista NIP-ów przy kluczu (rozstrzygnięcie
        // 20.09.2026). Dwa źródła prawdy o obsługiwanych firmach dawały pętlę: `start` mówił „niezatwierdzona",
        // a `zgloszenie-firmy` „zatwierdzona", bez śladu w zgłoszeniach.
        if ($this->domain->companyByNip($nip) !== null && $this->keys->hasNip($key['id'], $nip)) {
            return ['status' => 'zatwierdzona'];
        }
        $existing = $this->keys->signupByNip($nip);
        if ($existing !== null && $existing['status'] === 'rejected') {
            // Decyzja administratora obowiązuje, dopóki jej nie cofnie (ekran /admin/integracja).
            return ['status' => 'odrzucona'];
        }
        // ⚠ Każda ścieżka poza `zatwierdzona` zostawia wiersz zgłoszenia — także wtedy, gdy firma u nas jest,
        // ale poza zakresem tego klucza: administrator musi mieć co zatwierdzić.
        $this->keys->signup($nip, trim($name), $key['id']);

        return ['status' => 'oczekuje'];
    }

    // --- stan połączenia ------------------------------------------------------------------------------

    /**
     * „Test połączenia” w connectorze: czy klucz działa i co z firmą. ⚠ NIE otwiera przebiegu, nie rusza
     * kursorów i nie wchodzi do statystyki przebiegów (rozstrzygnięcie 20.09.2026) — wcześniej test
     * przedstawiał się jako pełny cykl, który niczego nie zrobił.
     *
     * `nip` jest opcjonalny: bez niego odpowiadamy samym stanem klucza, więc connector może sprawdzić
     * poświadczenia, zanim pozna NIP firmy. Nazwy firmy nie zwracamy nigdy.
     *
     * @param array{id: int} $key
     * @param array<string, mixed> $query
     * @return array{protokol: int, klucz: string, firma: ?array{stan: string}, ostatniPrzebieg: ?string}
     */
    public function stan(array $key, array $query): array
    {
        $out = ['protokol' => ApiKeyAuth::PROTOCOL_VERSIONS[0], 'klucz' => 'aktywny', 'firma' => null, 'ostatniPrzebieg' => null];
        if (!isset($query['nip']) || (is_string($query['nip']) && trim($query['nip']) === '')) {
            return $out;
        }
        $nip = self::nip($query);
        $company = $this->domain->companyByNip($nip);
        $out['firma'] = ['stan' => match (true) {
            $company !== null && $this->keys->hasNip($key['id'], $nip) => 'ok',
            $company !== null => 'firma_poza_zakresem',
            $this->keys->signupByNip($nip) !== null => 'firma_zgloszona',
            default => 'firma_nieznana',
        }];
        if ($company !== null) {
            $last = $this->sync->lastClosedRunAt((int) $company['id']);
            $out['ostatniPrzebieg'] = $last === null ? null : str_replace(' ', 'T', $last) . 'Z';
        }

        return $out;
    }

    /**
     * NIP z żądania: normalizacja, potem suma kontrolna. ⚠ Przed sprawdzeniem zakresu klucza — numer
     * niepoprawny dostaje 422, a nie odpowiedź odróżniającą „w zakresie” od „poza zakresem”.
     *
     * @param array<string, mixed> $body
     */
    private static function nip(array $body): string
    {
        if (!is_string($body['nip'] ?? null)) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Brak pola nip');
        }
        $nip = Nip::parse($body['nip']);
        if ($nip === null) {
            throw new ApiError(422, 'NIP_NIEPOPRAWNY', 'Numer NIP jest niepoprawny');
        }

        return $nip;
    }

    // --- wspólne ---------------------------------------------------------------------------------------

    /**
     * Firma obsługiwana tym kluczem: musi ISTNIEĆ u nas i być w zakresie klucza.
     *
     * ⚠ Odpowiedź 403 niesie `stan` (`firma_nieznana` | `firma_zgloszona` | `firma_poza_zakresem`), bo to
     * connector pokazuje ten komunikat człowiekowi przy wdrożeniu i od tych słów zależy, czy kliknie
     * właściwą rzecz, czy zadzwoni. Rozluźnienie zasady „nie ujawniamy, czy zasób istnieje" jest tu
     * świadome i wąskie: po drugiej stronie stoi UWIERZYTELNIONY connector tego klienta, a w odpowiedzi
     * jest sam stan — nigdy nazwa firmy. NIP idzie wyłącznie do logu.
     *
     * @param array{id: int} $key
     * @param array<string, mixed> $body
     * @return array{id: int, nip: string}
     */
    private function company(array $key, array $body): array
    {
        $nip = self::nip($body);
        $company = $this->domain->companyByNip($nip);
        $inScope = $this->keys->hasNip($key['id'], $nip);
        if ($company !== null && $inScope) {
            return ['id' => $company['id'], 'nip' => $nip];
        }
        [$stan, $komunikat] = match (true) {
            $company !== null => ['firma_poza_zakresem',
                'Firma jest poza zakresem tego klucza — administrator musi dopiąć ją do klucza w aplikacji'],
            $this->keys->signupByNip($nip) !== null => ['firma_zgloszona',
                'Firma zgłoszona, czeka na zatwierdzenie przez administratora w aplikacji'],
            default => ['firma_nieznana', 'Firma nie jest jeszcze zgłoszona — wyślij zgloszenie-firmy'],
        };

        throw new ApiError(403, 'FIRMA_NIEZATWIERDZONA', $komunikat, ['stan' => $stan], [], ['nip' => Nip::doLogu($nip), 'stan' => $stan]);
    }

    /**
     * @param array{id: int} $company
     * @param array<string, mixed> $body
     * @return array{id: int, run_uuid: string, company_id: int, status: string, started_at: string, stats: array<string, mixed>}
     */
    private function run(array $company, array $body): array
    {
        $uuid = $body['przebiegId'] ?? null;
        $ttl = (int) $this->settings->get('sync_run_ttl_minutes', (string) self::RUN_TTL_MINUTES);
        $state = is_string($uuid) && strlen($uuid) <= 36
            ? $this->sync->runState($uuid, $company['id'], $ttl)
            : ['run' => null, 'powod' => SyncRepository::REASON_UNKNOWN];
        if ($state['run'] === null) {
            // ⚠ `powod` jest informacją do diagnozy, nie sterowaniem: connector zachowuje się tak samo
            // (zamyka cykl, otwiera nowy przebieg) niezależnie od wartości.
            throw new ApiError(409, 'PRZEBIEG_NIEZNANY', 'Nieznany albo zamknięty przebieg — otwórz nowy',
                ['powod' => $state['powod'] ?? SyncRepository::REASON_UNKNOWN]);
        }

        return $state['run'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: bool}
     */
    private function batchEnvelope(array $body): array
    {
        $zbior = $body['zbior'] ?? null;
        if (!is_string($zbior) || !$this->validator->knows($zbior)) {
            throw new ApiError(400, 'ZBIOR_NIEZNANY', 'Nieznany zbiór');
        }
        if (!is_int($body['paczka'] ?? null) || $body['paczka'] < 1 || !is_bool($body['ostatnia'] ?? null)) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Wymagane pola: paczka (liczba ≥ 1) i ostatnia (true/false)');
        }

        return [$zbior, $body['ostatnia']];
    }

    /**
     * @param list<mixed> $items
     * @return array{0: list<array{requestId: string, status: string, kod: ?string, komunikat: ?string, zapisy: list<array{id: string, dataOd: string, dataDo: string, dni: ?int, minuty: ?int}>}>, 1: list<array{id: ?string, kod: string, pole: string}>}
     */
    private static function validateResults(array $items): array
    {
        $valid = [];
        $errors = [];
        foreach ($items as $w) {
            $rid = is_array($w) ? ($w['requestId'] ?? null) : null;
            if (!is_string($rid) || !TaskService::isValidRequestId($rid)) {
                $errors[] = ['id' => is_string($rid) ? $rid : null, 'kod' => $rid === null ? 'POLE_WYMAGANE' : 'ZLY_TYP', 'pole' => 'requestId'];
                continue;
            }
            $status = $w['status'] ?? null;
            if (!is_string($status)) {
                $errors[] = ['id' => $rid, 'kod' => 'POLE_WYMAGANE', 'pole' => 'status'];
                continue;
            }
            if (!TaskService::isKnownStatus($status)) {
                $errors[] = ['id' => $rid, 'kod' => 'ZLA_WARTOSC', 'pole' => 'status'];
                continue;
            }
            foreach (['kod' => 64, 'komunikat' => 1000] as $f => $max) {
                if (isset($w[$f]) && (!is_string($w[$f]) || mb_strlen($w[$f]) > $max)) {
                    $errors[] = ['id' => $rid, 'kod' => 'ZLY_TYP', 'pole' => $f];
                    continue 2;
                }
            }
            $zapisy = $w['zapisy'] ?? [];
            if (!is_array($zapisy) || !array_is_list($zapisy)) {
                $errors[] = ['id' => $rid, 'kod' => 'ZLY_TYP', 'pole' => 'zapisy'];
                continue;
            }
            $clean = [];
            foreach ($zapisy as $z) {
                $ok = is_array($z) && is_string($z['id'] ?? null) && $z['id'] !== '' && mb_strlen($z['id']) <= SchemaValidator::ID_MAX
                    && SchemaValidator::isDate($z['dataOd'] ?? null) && SchemaValidator::isDate($z['dataDo'] ?? null)
                    && (!isset($z['dni']) || is_int($z['dni'])) && (!isset($z['minuty']) || is_int($z['minuty']));
                if (!$ok) {
                    $errors[] = ['id' => $rid, 'kod' => 'ZLY_TYP', 'pole' => 'zapisy'];
                    continue 2;
                }
                $clean[] = ['id' => $z['id'], 'dataOd' => $z['dataOd'], 'dataDo' => $z['dataDo'], 'dni' => $z['dni'] ?? null, 'minuty' => $z['minuty'] ?? null];
            }
            $valid[] = ['requestId' => $rid, 'status' => $status, 'kod' => $w['kod'] ?? null, 'komunikat' => $w['komunikat'] ?? null, 'zapisy' => $clean];
        }

        return [$valid, $errors];
    }

    /** Krócej, gdy czekają zadania; dłużej w nocy (czas polski). Nigdy częściej niż 30 s. */
    private function nextInterval(int $companyId): int
    {
        if ($this->tasks->countOpenTasks($companyId) > 0) {
            return 30;
        }
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('G');

        return $hour >= 22 || $hour < 6 ? 300 : 60;
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, int> $events
     * @return array<string, mixed>
     */
    private function mergeEvents(array $stats, array $events): array
    {
        foreach ($events as $k => $n) {
            $stats['zdarzenia'][$k] = ($stats['zdarzenia'][$k] ?? 0) + $n;
        }

        return $stats;
    }

    /**
     * Licznik żądań bez X-Idempotency-Key — jeśli connector przestanie go wysyłać, widać to w statystyce.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private static function countIdempotency(array $stats, bool $present): array
    {
        if (!$present) {
            $stats['bezIdempotencji'] = ($stats['bezIdempotencji'] ?? 0) + 1;
        }

        return $stats;
    }

    /** @param array<string, mixed> $body */
    private static function optionalString(array $body, string $field, int $max): ?string
    {
        return is_string($body[$field] ?? null) ? mb_substr($body[$field], 0, $max) : null;
    }

    private static function tooLarge(): ApiError
    {
        return new ApiError(413, 'PACZKA_ZA_DUZA', 'Przekroczony limit', ['maks' => ['rekordy' => self::MAX_RECORDS, 'bajty' => ApiBody::MAX_BYTES]]);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
