<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use DateTimeImmutable;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\SyncTaskRepository;
use Wezurlop\Service\AccessService;

/**
 * Zastosowanie rekordów z R2 do danych aplikacji (docs/etap3-logika-urlopowa.md rozdz. 3b i 5b). STREFA JAWNA.
 *
 * ⚠⚠ `usuniety: true` znaczy „wypadł z zakresu synchronizacji”, NIE „skasowano dane”:
 * - pracownik/zatrudnienie → zatrudnienie nieaktywne; konto blokowane, gdy nieaktywne są WSZYSTKIE,
 * - nieobecność osoby w zakresie → powiązany wniosek do `conflict`,
 * - nieobecność albo limit z roku spoza okna → „poza oknem”, BEZ konfliktu (sprawdzamy rok, zanim oznaczymy),
 * - osób, wniosków, decyzji i historii nie kasujemy nigdy.
 *
 * Rekord wskazujący na coś, czego jeszcze nie ma (np. zatrudnienie przed pracownikiem), czeka jako
 * „oczekujący” — z licznikiem prób i wygaszeniem po pełnym przeglądzie (SyncService).
 */
final class RecordApplier
{
    public const RESULT_NEW = 'nowe';
    public const RESULT_CHANGED = 'zmienione';
    public const RESULT_UNCHANGED = 'bezZmian';
    public const RESULT_DELETED = 'usuniete';
    public const RESULT_OUT_OF_WINDOW = 'pozaOknem';
    public const RESULT_PENDING = 'oczekujace';

    /** Stany wniosku, dla których zniknięcie wpisu w kadrach to konflikt do wyjaśnienia. */
    private const LIVE_REQUEST = ['approved', 'confirmed', 'cancel_requested', 'sync_failed'];

    private int $runId = 0;

    /** @var array<string, int> zdarzenia do statystyk przebiegu (konflikty, zablokowane konta …) */
    private array $events = [];

    public function __construct(
        private readonly SyncRepository $sync,
        private readonly SyncDomainRepository $domain,
        private readonly SyncTaskRepository $tasks,
        private readonly CompanyCalendarRepository $calendars,
        private readonly EventLogRepository $eventLog,
        private readonly JobRepository $jobs,
        private readonly SyncWindow $window,
        // ⚠ Limit licencji czyta `AccessService` — ta sama droga, co nadanie dostępu
        // i odblokowanie ręczne. Trzy liczniki tej samej rzeczy rozjechałyby się przy pierwszej
        // zmianie reguły (zlecenie Z87).
        private readonly AccessService $access,
    ) {
    }

    /** @return array<string, int> */
    public function takeEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @param array<string, mixed> $record rekord po walidacji (albo {id, usuniety})
     * @return string wynik (stała RESULT_*)
     */
    public function apply(int $companyId, string $nip, string $zbior, array $record, int $runId): string
    {
        $this->runId = $runId;
        $known = $this->sync->record($companyId, $zbior, (string) $record['id']);
        if (($record['usuniety'] ?? false) === true) {
            return $this->delete($companyId, $zbior, (string) $record['id'], $known);
        }
        // Znany rekord, ta sama suma, stan aktywny — nic do zrobienia (idempotencja paczki).
        if ($known !== null && $known['state'] === 'active' && $known['checksum'] === $record['suma']) {
            return self::RESULT_UNCHANGED;
        }
        $result = match ($zbior) {
            'firma'        => $this->firma($companyId, $nip, $record),
            'pracownik'    => $this->pracownik($companyId, $record, $known),
            'zatrudnienie' => $this->zatrudnienie($companyId, $record, $known),
            'limit'        => $this->limit($companyId, $record),
            'nieobecnosc'  => $this->nieobecnosc($companyId, $record, $known),
            'slownik'      => $this->slownik($companyId, $record),
            'kalendarz'    => $this->kalendarz($companyId, $record),
        };
        if ($result === self::RESULT_PENDING) {
            return $result;
        }

        return $known === null || $known['state'] !== 'active' ? self::RESULT_NEW : self::RESULT_CHANGED;
    }

    /**
     * Ponowna próba rekordów oczekujących firmy.
     *
     * @return array{resolved: int, pending: int}
     */
    public function resolvePending(int $companyId, string $nip, int $runId): array
    {
        $resolved = 0;
        // Kilka przejść: rozwiązany pracownik odblokowuje zatrudnienie, a to limity i nieobecności.
        for ($pass = 0; $pass < 3; $pass++) {
            $progress = 0;
            foreach ($this->sync->pendingRecords($companyId, 2000) as $p) {
                $payload = $p['record']['payload'];
                if ($payload === null) {
                    continue;
                }
                if ($this->apply($companyId, $nip, $p['zbior'], $payload, $runId) !== self::RESULT_PENDING) {
                    $progress++;
                }
            }
            $resolved += $progress;
            if ($progress === 0) {
                break;
            }
        }

        return ['resolved' => $resolved, 'pending' => $this->sync->countPending($companyId)];
    }

    // --- zbiory ----------------------------------------------------------------------------------

    /** @param array<string, mixed> $r */
    private function firma(int $companyId, string $nip, array $r): string
    {
        // Rekord `firma` strumienia opisuje tę firmę (id = NIP); nazwa z R2 wygrywa.
        if ($r['id'] === $nip) {
            $this->domain->renameCompany($companyId, (string) $r['nazwa']);
        }
        $this->sync->saveRecord($companyId, 'firma', $r['id'], $r['suma'], 'active', $companyId, null, $this->runId);

        return self::RESULT_CHANGED;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $known
     */
    private function pracownik(int $companyId, array $r, ?array $known): string
    {
        $identifier = is_string($r['identyfikator'] ?? null) && $r['identyfikator'] !== '' ? (string) $r['identyfikator'] : null;
        $personId = $known['local_id'] ?? null;
        if ($personId === null) {
            $personId = $this->matchPerson($companyId, $r, $identifier);
        }
        // ⚠ Nazwisko i numer kadrowy odświeżamy ZAWSZE, także dla osoby dopasowanej po `osobaId`:
        // inaczej osoba znana z innej firmy albo z poprzedniego przebiegu nigdy nie dostałaby numeru.
        $this->domain->updatePersonNames($personId, (string) $r['imie'], (string) $r['nazwisko'], $identifier);
        // Data zwolnienia (wypowiedzenie) zapamiętana przy rekordzie — zatrudnienia tej kartoteki ją dziedziczą.
        $termination = $r['zwolniony'] === true ? $r['dataZwolnienia'] : null;
        // Rodzaje umów przychodzą w kartotece, a dotyczą zatrudnienia w tej firmie — jadą tą samą
        // drogą co data zwolnienia. ⚠ Kilka naraz jest normalne (etat + zlecenie), to nie wybór jednego.
        $contracts = self::contracts($r['umowy'] ?? null);
        $this->sync->saveRecord($companyId, 'pracownik', $r['id'], $r['suma'], 'active', $personId, null, $this->runId,
            ['dataZwolnienia' => $termination, 'umowy' => $contracts]);
        foreach ($this->sync->recordIdsByParent($companyId, 'zatrudnienie', $r['id']) as $employmentRef) {
            $emp = $this->sync->record($companyId, 'zatrudnienie', $employmentRef);
            if ($emp !== null && $emp['local_id'] !== null && $emp['state'] === 'active') {
                $this->domain->setTermination($emp['local_id'], $termination);
                $this->domain->setContractKinds($emp['local_id'], $contracts);
            }
        }

        return self::RESULT_CHANGED;
    }

    /**
     * Dopasowanie osoby między firmami po osobaId (HMAC liczony u klienta). ⚠ Ten sam skrót, a inne imię
     * i nazwisko → NIE łączymy (literówka w PESEL-u dałaby komuś wgląd w cudze dane) — osobna osoba
     * i zadanie dla administratora. Brak osobaId → osobna osoba, bez łączenia.
     *
     * @param array<string, mixed> $r
     */
    private function matchPerson(int $companyId, array $r, ?string $identifier = null): int
    {
        $external = is_string($r['osobaId']) && $r['osobaId'] !== '' ? $r['osobaId'] : null;
        if ($external === null) {
            $this->count('osobyBezIdentyfikatora');
            $this->sync->conflict($companyId, $this->runId, null, 'person_without_id',
                'Pracownik bez identyfikatora osoby (brak PESEL w kadrach) — nie łączymy między firmami', ['pracownikId' => $r['id']]);

            return $this->domain->createPerson((string) $r['imie'], (string) $r['nazwisko'], null, false, $identifier);
        }
        $existing = $this->domain->personByExternalRef($external);
        if ($existing === null) {
            return $this->domain->createPerson((string) $r['imie'], (string) $r['nazwisko'], $external, true, $identifier);
        }
        if (self::sameName($existing['first_name'], (string) $r['imie']) && self::sameName($existing['last_name'], (string) $r['nazwisko'])) {
            return $existing['id'];
        }
        $this->count('kolizjeTozsamosci');
        $this->sync->conflict($companyId, $this->runId, null, 'person_identity_mismatch',
            'Ten sam identyfikator osoby, inne imię i nazwisko — osoby NIE połączone, wymaga decyzji administratora',
            ['pracownikId' => $r['id'], 'osobaIstniejaca' => $existing['id']]);

        return $this->domain->createPerson((string) $r['imie'], (string) $r['nazwisko'], $external, false, $identifier);
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $known
     */
    private function zatrudnienie(int $companyId, array $r, ?array $known): string
    {
        $worker = $this->sync->record($companyId, 'pracownik', (string) $r['pracownikId']);
        if ($worker === null || $worker['state'] !== 'active' || $worker['local_id'] === null) {
            return $this->pending($companyId, 'zatrudnienie', $r, (string) $r['pracownikId'], 'brak pracownika ' . $r['pracownikId']);
        }
        $employmentId = $known['local_id'] ?? $this->domain->employmentByRef($companyId, (string) $r['id'])['id'] ?? null;
        // Koniec zatrudnienia: wcześniejsza z dat — koniec umowy albo ostatni dzień pracy przy wypowiedzeniu.
        $dates = array_filter([$r['dataZakonczenia'], $this->workerTermination($companyId, (string) $r['pracownikId'])]);
        $ended = $dates === [] ? null : min($dates);
        $employmentId = $this->domain->upsertEmployment($companyId, $employmentId, [
            'person_id' => $worker['local_id'], 'ref' => (string) $r['id'], 'fte' => $r['etat'], 'hired_at' => $r['dataRozpoczecia'],
            'ended_at' => $ended, 'calendar_ref' => $r['kalendarzId'], 'active' => $r['aktualne'] === true,
        ]);
        // Zatrudnienie może przyjść PO kartotece — rodzaje umów dobieramy z zapamiętanego rekordu,
        // inaczej nowe zatrudnienie czekałoby na nie do następnej zmiany kartoteki.
        $this->domain->setContractKinds($employmentId, $this->workerContracts($companyId, (string) $r['pracownikId']));
        // ⚠ SYMETRIA: `deactivate()` niżej blokuje konto, gdy gasną wszystkie zatrudnienia — tutaj
        // jest droga powrotna. Bez niej pracownik, który wrócił, zostawał bez dostępu na zawsze.
        // Odblokowanie zdejmuje wyłącznie blokadę z synchronizacji; ręcznej automat nie rusza.
        if ($r['aktualne'] === true) {
            $this->powrotDoPracy($worker['local_id'], $companyId);
            // ⚠ **TRZECIA DROGA łamania rozdziału z `etap3` §8c — jedyna, której nikt nie wywoła
            // ręcznie.** Konto wisi na osobie i zatrudnienie też wisi na osobie, więc gdy kadry
            // zatrudnią administratora (właściciel przechodzi na etat, księgowa z zewnątrz
            // dostaje umowę), regułę łamie connector, nie człowiek — a warunek w `assignRole()`
            // tego nie widzi, bo nikt niczego nie nadaje.
            // ⚠ **Zatrudnienie wchodzi i tak** (reguła 1): odrzucenie go byłoby liczeniem
            // za kadry i zgubiłoby prawdziwą informację. Zapisujemy zdarzenie, a trwałą,
            // blokującą pozycję pokazuje `/diagnostyka` — ta czyta STAN, więc zniknie sama,
            // kiedy człowiek sprawę rozwiąże (reguła 3: rozwiązanie należy do człowieka).
            $adminId = $this->domain->kontoAdministracyjneOsoby($worker['local_id']);
            if ($adminId !== null) {
                $this->count('rozdzialRolNaruszony');
                $this->eventLog->record('access.admin_employed', null, null, 'sync', 'account', $adminId, $companyId,
                    ['zatrudnienie' => $employmentId]);
            }
        }
        $this->sync->saveRecord($companyId, 'zatrudnienie', $r['id'], $r['suma'], 'active', $employmentId, (string) $r['pracownikId'], $this->runId);

        return self::RESULT_CHANGED;
    }

    /** @param array<string, mixed> $r */
    private function limit(int $companyId, array $r): string
    {
        $employmentId = $this->employmentFor($companyId, (string) $r['zatrudnienieId']);
        if ($employmentId === null) {
            return $this->pending($companyId, 'limit', $r, (string) $r['zatrudnienieId'], 'brak zatrudnienia ' . $r['zatrudnienieId']);
        }
        $balanceId = $this->domain->upsertBalance($employmentId, $r);
        $this->sync->saveRecord($companyId, 'limit', $r['id'], $r['suma'], 'active', $balanceId, (string) $r['zatrudnienieId'], $this->runId);

        return self::RESULT_CHANGED;
    }

    /** @param array<string, mixed> $r */
    private function nieobecnosc(int $companyId, array $r, ?array $known): string
    {
        $ref = (string) $r['zatrudnienieId'];
        $employment = $this->employmentRowFor($companyId, $ref);
        if ($employment === null) {
            return $this->pending($companyId, 'nieobecnosc', $r, $ref, 'brak zatrudnienia ' . $ref);
        }
        $typeId = $this->domain->leaveTypeId((string) $r['rodzaj']['zrodlo'], (int) $r['rodzaj']['kod']);

        // Nasz wniosek zapisany przez connector (requestId) albo znany zapis z potwierdzenia → lista zapisów wniosku.
        $request = $this->tasks->requestForR2Record($companyId, is_string($r['requestId']) ? $r['requestId'] : null, (string) $r['id']);
        if ($request !== null) {
            $changed = $this->tasks->upsertR2Record($request['leave_request_id'], [
                'id' => (string) $r['id'], 'dataOd' => $r['dataOd'], 'dataDo' => $r['dataDo'], 'dni' => $r['dni'], 'minuty' => $r['minuty'],
            ]);
            if ($request['status'] === 'confirmed') {
                // Kadry zmieniły nasz wpis (ten sam id, inne daty/dni) — dni i minuty z WSZYSTKICH części.
                $totals = $this->tasks->confirmedTotals($request['leave_request_id']);
                $this->tasks->setRequestStatus($request['leave_request_id'], 'confirmed', 'confirmed', null, [
                    'days_confirmed' => $totals['days'], 'minutes_confirmed' => $totals['minutes'],
                ] + ($changed ? ['changed_in_r2' => 1] : []));
                if ($changed) {
                    $this->count('zmienioneWKadrach');
                    $this->eventLog->record('leave.changed_in_r2', null, null, 'sync', 'leave_request', $request['leave_request_id'], $companyId);
                }
            }
            $this->sync->saveRecord($companyId, 'nieobecnosc', $r['id'], $r['suma'], 'active', $request['leave_request_id'], $ref, $this->runId);

            return self::RESULT_CHANGED;
        }

        // ⚠ Źródło `kadrowe` (urlop bezpłatny, wychowawczy, nieusprawiedliwiona, służba wojskowa) nie ma
        // w programie kadrowym wyliczonej długości — 84 wpisy w dwóch firmach, bez wyjątku, mają zero dni
        // i zero minut. To reguła, nie brak danych: termin blokujemy datami `dataOd`–`dataDo`, a do rozliczeń
        // taka nieobecność wnosi zero. Zapisujemy zero jawnie, żeby nikt nie wziął `null` za „nie wiemy”.
        if ((string) $r['rodzaj']['zrodlo'] === 'kadrowe') {
            if (($r['dni'] ?? 0) !== 0 || ($r['minuty'] ?? 0) !== 0) {
                $this->count('kadroweZDlugoscia');
                $this->eventLog->record('sync.kadrowe_with_length', null, null, 'sync', 'external_absence', null, $companyId,
                    ['wpis' => (string) $r['id'], 'dni' => $r['dni'], 'minuty' => $r['minuty']]);
            }
            $r['dni'] = 0;
            $r['minuty'] = 0;
        }
        $absenceId = $this->domain->upsertExternalAbsence($companyId, [
            'employment_id' => $employment['id'], 'person_id' => $employment['person_id'], 'leave_type_id' => $typeId, 'record' => $r,
        ]);
        $this->sync->saveRecord($companyId, 'nieobecnosc', $r['id'], $r['suma'], 'active', $absenceId, $ref, $this->runId);
        // ⚠ Wpis podpisany jako NASZ (`zrodloWpisu: connector`), którego nie wydaliśmy: albo pozostałość po
        // testach, albo DRUGA INSTALACJA pisząca do tej samej bazy kadrowej. To drugie jest poważne i ma być
        // widoczne. Informacja dla kadr, nie konflikt — wniosku to nie dotyczy (rozstrzygnięcie 20.09.2026).
        // Raz na rekord: tylko przy pierwszym pojawieniu się w rejestrze.
        if ($known === null && ($r['zrodloWpisu'] ?? null) === 'connector') {
            $this->count('obceWpisyConnectora');
            $this->eventLog->record('sync.foreign_connector_entry', null, null, 'sync', 'external_absence', $absenceId, $companyId, [
                'wpis' => (string) $r['id'], 'zatrudnienieId' => $ref,
                'requestId' => is_string($r['requestId']) ? $r['requestId'] : null,
            ]);
            $this->jobs->enqueue(TaskService::JOB_HR_ALERT, ['kind' => 'obcy_wpis', 'wpis' => (string) $r['id'], 'leave_request_id' => null]);
        }

        return self::RESULT_CHANGED;
    }

    /** @param array<string, mixed> $r */
    private function slownik(int $companyId, array $r): string
    {
        $source = (string) $r['zrodlo'];
        $kind = is_string($r['obciazaLimit'] ?? null) && $r['obciazaLimit'] !== '' ? (string) $r['obciazaLimit'] : null;
        // ⚠ Kadrowe i zasiłki nie obciążają puli urlopowej (84 wpisy w dwóch firmach, bez wyjątku).
        // Gdyby przyszło inaczej, nie zgadujemy i nie zapisujemy — odnotowujemy, bo to zmiana w programie kadrowym.
        if ($kind !== null && $source !== 'urlop') {
            $kind = null;
            $this->count('obciazenieLimituPominiete');
            $this->eventLog->record('sync.limit_kind_ignored', null, null, 'sync', 'leave_type', null, $companyId,
                ['rodzaj' => $source . ':' . (int) $r['kod'], 'obciazaLimit' => (string) $r['obciazaLimit']]);
        }
        $kindSource = is_string($r['obciazaLimitZrodlo'] ?? null) ? (string) $r['obciazaLimitZrodlo'] : null;
        if ($kind !== null && $kindSource === null) {
            // Pole nie przyszło — powiązanie znamy, pochodzenia nie. Traktujemy jak niepotwierdzone.
            $kindSource = 'domyslne';
        }
        $id = $this->domain->upsertLeaveType($source, (int) $r['kod'], (string) $r['nazwa'],
            $r['tylkoOdczyt'] === true, $r['doWniosku'] === true, $r['nieobecnosc'] === true, $kind, $kindSource);
        $this->sync->saveRecord($companyId, 'slownik', $r['id'], $r['suma'], 'active', $id, null, $this->runId);

        return self::RESULT_CHANGED;
    }

    /** @param array<string, mixed> $r */
    private function kalendarz(int $companyId, array $r): string
    {
        $leaveNorm = is_int($r['normaUrlopowaMin'] ?? null) && $r['normaUrlopowaMin'] > 0 ? (int) $r['normaUrlopowaMin'] : null;
        $dailyNorm = is_int($r['normaDobowaMin'] ?? null) && $r['normaDobowaMin'] > 0 ? (int) $r['normaDobowaMin'] : null;
        // ⚠ Kalendarz bez normy znaczy, że minuty na dni dzielimy wartością zapasową. Ma to być widoczne
        // w opisie przebiegu i na diagnostyce, a nie ciche — u firmy z dniówką 12 h myliłby się każdy wniosek.
        if ($leaveNorm === null) {
            $this->count('kalendarzeBezNormy');
        }
        $this->calendars->upsert($companyId, (string) $r['kalendarzId'], (int) $r['rok'], (string) $r['nazwa'],
            $r['indywidualne'] === true, $r['dniNierobocze'], $leaveNorm, $dailyNorm);
        $this->sync->saveRecord($companyId, 'kalendarz', $r['id'], $r['suma'], 'active', null, (string) $r['kalendarzId'], $this->runId);

        return self::RESULT_CHANGED;
    }

    // --- usunięcia: „wypadł z zakresu” -------------------------------------------------------------

    /** @param array<string, mixed>|null $known */
    private function delete(int $companyId, string $zbior, string $id, ?array $known): string
    {
        if ($known === null) {
            // Nieznany w rejestrze, ale nieobecność może istnieć lokalnie (dane sprzed rejestru, odtworzona
            // kopia) — ta sama reguła co dla znanej. Zapamiętujemy stan, żeby powtórka niczego nie zmieniała.
            $state = $zbior === 'nieobecnosc' ? $this->deleteAbsence($companyId, $id) : 'deleted';
            $this->sync->saveRecord($companyId, $zbior, $id, null, $state, null, null, $this->runId);

            return $state === 'out_of_window' ? self::RESULT_OUT_OF_WINDOW : self::RESULT_DELETED;
        }
        if (in_array($known['state'], ['deleted', 'out_of_window'], true)) {
            return $known['state'] === 'deleted' ? self::RESULT_DELETED : self::RESULT_OUT_OF_WINDOW;
        }
        $state = 'deleted';
        switch ($zbior) {
            case 'pracownik':
                // Wszystkie zatrudnienia tej kartoteki nieaktywne; osoba i historia zostają.
                foreach ($this->sync->recordIdsByParent($companyId, 'zatrudnienie', $id) as $employmentRef) {
                    $emp = $this->sync->record($companyId, 'zatrudnienie', $employmentRef);
                    if ($emp !== null && $emp['local_id'] !== null) {
                        $this->deactivate($companyId, $emp['local_id']);
                    }
                }
                break;
            case 'zatrudnienie':
                if ($known['local_id'] !== null) {
                    $this->deactivate($companyId, $known['local_id']);
                }
                break;
            case 'limit':
                $state = $this->deleteLimit($companyId, $known);
                break;
            case 'nieobecnosc':
                $state = $this->deleteAbsence($companyId, $id);
                break;
            case 'kalendarz':
                if ($known['parent_ref'] !== null && preg_match('/:(\d{4})$/', $id, $m) === 1) {
                    $this->calendars->delete($companyId, $known['parent_ref'], (int) $m[1]);
                }
                break;
            default:
                // firma, slownik: typy i nazwy zostają (historia wniosków się do nich odwołuje).
                break;
        }
        $this->sync->setRecordState($companyId, $zbior, $id, $state, $this->runId);

        return $state === 'out_of_window' ? self::RESULT_OUT_OF_WINDOW : self::RESULT_DELETED;
    }

    private function deactivate(int $companyId, int $employmentId): void
    {
        $personId = $this->domain->deactivateEmployment($employmentId);
        $this->count('zatrudnieniaNieaktywne');
        $this->eventLog->record('employment.deactivated_by_sync', null, null, 'sync', 'employment', $employmentId, $companyId);
        // ⚠ Konto blokujemy dopiero, gdy nieaktywne są WSZYSTKIE zatrudnienia osoby (wielofirmowość).
        if ($personId !== null && $this->domain->activeEmploymentCount($personId) === 0) {
            $blokada = $this->domain->blockAccountOfPerson($personId);
            if ($blokada !== null) {
                $this->count('kontaZablokowane');
                $this->eventLog->record('account.blocked_by_sync', null, null, 'sync', 'account', $blokada['konto'], $companyId);
                // ⚠ Ten sam wpis co przy blokadzie ręcznej: jeden, z licznikiem. Bez niego historia
                // mówiłaby, że konto zablokowano, ale nie że komuś przerwano pracę w trakcie.
                if ($blokada['sesje'] > 0) {
                    $this->eventLog->record('session.revoked', null, null, 'sync', 'account', $blokada['konto'], $companyId,
                        ['count' => $blokada['sesje'], 'powod' => 'blokada', 'zrodlo' => 'sync']);
                }
            }
        }
    }

    /** @param array<string, mixed> $known */
    private function deleteLimit(int $companyId, array $known): string
    {
        $balance = $known['local_id'] === null ? null : $this->domain->balance($known['local_id']);
        if ($balance === null) {
            return 'deleted';
        }
        if ($balance['year'] < $this->window->oldestYear()) {
            // Rok wypadł z okna synchronizacji — historia, bez konfliktu.
            $this->domain->markBalanceOutOfWindow($known['local_id']);

            return 'out_of_window';
        }
        // Rok w oknie: lustro R2 — wiersz znika, ekran pokaże „limit nie został jeszcze naliczony”, nie zero.
        $this->domain->deleteBalance($known['local_id']);
        $this->count('limityUsuniete');
        if ($this->domain->activeRequestsInYear($balance['employment_id'], $balance['year']) > 0) {
            // Informacja dla kadr, nie konflikt: zniknął limit, na który ktoś już rezerwuje dni.
            $this->count('limityUsunieteZWnioskami');
            $this->eventLog->record('sync.limit_removed_with_requests', null, null, 'sync', 'employment', $balance['employment_id'], $companyId,
                ['rok' => $balance['year']]);
        }

        return 'deleted';
    }

    private function deleteAbsence(int $companyId, string $id): string
    {
        $request = $this->tasks->requestForR2Record($companyId, null, $id);
        $external = $request === null ? $this->domain->externalAbsence($companyId, $id) : null;
        $end = $request['date_to'] ?? $external['date_to'] ?? null;
        // ⚠ Sprawdzamy rok rekordu, ZANIM cokolwiek oznaczymy: styczniowe przesunięcie okna to nie konflikt.
        if ($end !== null && $this->window->isOutside(new DateTimeImmutable($end))) {
            if ($request === null) {
                $this->domain->markExternalAbsence($companyId, $id, true);
            }

            return 'out_of_window';
        }
        // Zatrudnienie już nieaktywne (docs/etap3-logika-urlopowa.md 5b): zdarzenie w sync_run, wpis znika
        // z kalendarza; ⚠ konfliktu nie tworzymy i historii wniosku nie ruszamy (stan, wpisy R2, decyzje).
        if (($request ?? $external) !== null && !($request ?? $external)['employment_active']) {
            $this->count('nieobecnosciZatrudnieniaNieaktywnego');
            if ($request === null) {
                $this->domain->markExternalAbsence($companyId, $id, false);
            }

            return 'deleted';
        }
        if ($request !== null) {
            $this->tasks->markR2RecordRemoved($request['leave_request_id'], $id);
            if (in_array($request['status'], self::LIVE_REQUEST, true)) {
                $this->tasks->setRequestStatus($request['leave_request_id'], $request['status'], 'conflict', 'wpis usunięty w kadrach',
                    ['r2_error_code' => null, 'r2_error_text' => 'Wpis usunięty w kadrach']);
                $this->sync->conflict($companyId, $this->runId, $request['leave_request_id'], 'r2_entry_removed', 'Wpis usunięty w kadrach',
                    ['r2RecordId' => $id]);
                $this->count('konflikty');
            }

            return 'deleted';
        }
        $this->domain->markExternalAbsence($companyId, $id, false);

        return 'deleted';
    }

    // --- pomocnicze ----------------------------------------------------------------------------------

    /** @param array<string, mixed> $r */
    private function pending(int $companyId, string $zbior, array $r, string $parentRef, string $reason): string
    {
        $this->sync->saveRecord($companyId, $zbior, (string) $r['id'], (string) $r['suma'], 'pending', null, $parentRef, $this->runId, $r, $reason);

        return self::RESULT_PENDING;
    }

    private function employmentFor(int $companyId, string $ref): ?int
    {
        return $this->employmentRowFor($companyId, $ref)['id'] ?? null;
    }

    /** @return array{id: int, person_id: int}|null */
    private function employmentRowFor(int $companyId, string $ref): ?array
    {
        $record = $this->sync->record($companyId, 'zatrudnienie', $ref);
        if ($record !== null && $record['state'] === 'pending') {
            return null;
        }
        $row = $this->domain->employmentByRef($companyId, $ref);

        return $row === null ? null : ['id' => $row['id'], 'person_id' => $row['person_id']];
    }

    private function workerTermination(int $companyId, string $workerRef): ?string
    {
        $worker = $this->sync->record($companyId, 'pracownik', $workerRef);
        $date = $worker['payload']['dataZwolnienia'] ?? null;

        return is_string($date) ? $date : null;
    }

    /**
     * **Powrót do pracy: odblokowanie konta albo odmowa z braku licencji** (zlecenie Z87,
     * decyzja Krzysztofa z 24.10.2026).
     *
     * ⚠ Do tego dnia automat zdejmował blokadę bez pytania o limit, więc powrót pracownika
     * mógł wprowadzić instalację ponad licencję. Reguła jest teraz ta sama, co przy odblokowaniu
     * ręcznym (Z85) i przy nadaniu dostępu — bo to ta sama rzecz: dostęp, który zacznie się
     * liczyć. Pytamy O KONTO, więc najpierw trzeba wiedzieć, które to konto (`syncBlockedAccountOf()`).
     *
     * ⚠ **Licznik `FOR UPDATE` działa w transakcji paczki**, nie w osobnej: sprawdzenie wolnego
     * miejsca i zajęcie go muszą być jedną decyzją, inaczej dwie firmy synchronizowane równolegle
     * wpuściłyby dwie osoby na jedno miejsce.
     *
     * ⚠ **Automat NIE ponawia.** Odmowa zostaje w dzienniku, konto zostaje `blocked`/`sync`,
     * a odblokowanie jest od tej chwili decyzją człowieka (ekran blokady, Z85). Wyłącza system,
     * włącza człowiek.
     *
     * ⚠ Konto bez dostępów do firm i licencja bez limitu przechodzą jak dotąd — `brakujeNaOdblokowanie()`
     * zwraca wtedy zero i nie ma czego odmawiać.
     */
    private function powrotDoPracy(int $personId, int $companyId): void
    {
        $accountId = $this->domain->syncBlockedAccountOf($personId);
        if ($accountId === null) {
            return;
        }
        if ($this->access->brakujeNaOdblokowanie($accountId) > 0) {
            // ⚠ Licznik idzie do statystyk PRZEBIEGU (`sync_run.stats`), bo alert ma być jeden
            // na przebieg, a przebieg to wiele żądań HTTP — licznik w pamięci ginie z żądaniem.
            $this->count('kontaBezLicencji');
            $this->eventLog->record('account.auto_unblock_refused', null, null, 'sync', 'account',
                $accountId, $companyId);

            return;
        }
        if ($this->domain->unblockAccountAfterReturn($personId) !== null) {
            $this->count('kontaOdblokowane');
            $this->eventLog->record('account.unblocked_by_sync', null, null, 'sync', 'account', $accountId, $companyId);
        }
    }

    /** @return array{oPrace: bool, zlecenie: bool, oDzielo: bool} */
    private function workerContracts(int $companyId, string $workerRef): array
    {
        $worker = $this->sync->record($companyId, 'pracownik', $workerRef);

        return self::contracts($worker['payload']['umowy'] ?? null);
    }

    /** @return array{oPrace: bool, zlecenie: bool, oDzielo: bool} */
    private static function contracts(mixed $umowy): array
    {
        $flaga = static fn (string $klucz): bool => is_array($umowy) && ($umowy[$klucz] ?? null) === true;

        return ['oPrace' => $flaga('oPrace'), 'zlecenie' => $flaga('zlecenie'), 'oDzielo' => $flaga('oDzielo')];
    }

    private function count(string $key): void
    {
        $this->events[$key] = ($this->events[$key] ?? 0) + 1;
    }

    private static function sameName(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
