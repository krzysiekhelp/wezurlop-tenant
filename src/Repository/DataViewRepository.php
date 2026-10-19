<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use Wezurlop\Support\NazwaOsoby;
use Wezurlop\Db\Connection;

/**
 * Odczyt danych przyszłych z programu kadrowego — ekrany `/dane` (firmy, pracownicy, karta, kalendarz,
 * stan synchronizacji). STREFA JAWNA, **wyłącznie SELECT**: program kadrowy jest źródłem prawdy,
 * a pole edytowalne u nas byłoby gwarantowanym rozjazdem przy najbliższym cyklu.
 *
 * ⚠ Zakres firm (`$companies`) jest **częścią każdego zapytania**, nie warunkiem po pobraniu (CLAUDE.md §10):
 * `null` znaczy „konto ma przypisanie na całego tenanta”, lista — tylko te firmy.
 */
final class DataViewRepository
{
    /** Kolumny sortowania listy pracowników — biała lista, bo nazwy kolumn nie da się sparametryzować (§4). */
    /**
     * ⚠ **Każdy wpis jest LISTĄ kolumn** — ta sama poprawka, co w `PersonRepository::SORT`
     * (Z30, 18.10.2026). Sklejanie kierunku na końcu napisu `'p.last_name, p.first_name'` dawało
     * `ORDER BY p.last_name, p.first_name DESC`, czyli nazwisko zawsze rosnąco. Usterka siedziała
     * w dwóch repozytoriach naraz, bo wzorzec był przepisany — nie znaleziona jedną zmianą.
     *
     * @var array<string, list<string>>
     */
    private const EMPLOYEE_SORT = [
        'nazwisko'     => ['p.last_name', 'p.first_name'],
        'zatrudnienie' => ['e.hired_at'],
        'etat'         => ['e.fte'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Firmy z licznikiem pracowników, czasem ostatniej udanej synchronizacji i kluczem, który je obsługuje.
     *
     * @param list<int>|null $companies
     * @return list<array{id: int, nip: string, name: string, employees: int, last_sync: ?string, key_name: ?string, signup: ?string}>
     */
    public function companies(?array $companies): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.nip, c.name,
                    (SELECT COUNT(*) FROM employment e WHERE e.company_id = c.id AND e.is_active = 1) AS employees,
                    (SELECT MAX(r.finished_at) FROM sync_run r WHERE r.company_id = c.id AND r.status = 'closed') AS last_sync,
                    (SELECT k.name FROM api_key_company kc JOIN api_key k ON k.id = kc.api_key_id
                      WHERE kc.nip = c.nip AND k.status = 'active' ORDER BY k.id DESC LIMIT 1) AS key_name,
                    (SELECT s.status FROM company_signup s WHERE s.nip = c.nip ORDER BY s.id DESC LIMIT 1) AS signup
             FROM company c
             WHERE " . self::scopeSql($companies, 'c.id') . "
             ORDER BY c.name"
        );
        $stmt->execute(self::scopeParams($companies));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'], 'nip' => (string) $row['nip'], 'name' => (string) $row['name'],
                'employees' => (int) $row['employees'],
                'last_sync' => $row['last_sync'] === null ? null : (string) $row['last_sync'],
                'key_name' => $row['key_name'] === null ? null : (string) $row['key_name'],
                'signup' => $row['signup'] === null ? null : (string) $row['signup'],
            ];
        }

        return $out;
    }

    /** @param list<int>|null $companies */
    public function company(int $companyId, ?array $companies): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.nip, c.name FROM company c WHERE c.id = :id AND ' . self::scopeSql($companies, 'c.id')
        );
        $stmt->execute(self::scopeParams($companies) + ['id' => $companyId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'nip' => (string) $row['nip'], 'name' => (string) $row['name']] : null;
    }

    /**
     * Pracownicy firmy: szukanie po nazwisku, sortowanie z białej listy, stronicowanie.
     *
     * @return list<array<string, mixed>>
     */
    public function employees(int $companyId, string $search, string $sort, bool $asc, int $limit, int $offset, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.person_id, e.r2_employment_ref, p.r2_identifier, e.hired_at, e.terminated_at,
                    e.fte, e.r2_calendar_ref, e.is_active, p.first_name, p.last_name,
                    (SELECT cc.name FROM company_calendar cc
                      WHERE cc.company_id = e.company_id AND cc.calendar_ref = e.r2_calendar_ref
                      ORDER BY cc.year DESC LIMIT 1) AS calendar_name,
                    (SELECT COALESCE(SUM(a.days), 0) FROM external_absence a
                      WHERE a.employment_id = e.id AND a.removed_at IS NULL
                        AND a.date_from >= :year_from AND a.date_from <= :year_to) AS absence_days
             FROM employment e
             JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :company AND (:search = '' OR p.last_name LIKE :like OR p.first_name LIKE :like2)
             ORDER BY " . self::employeeOrderSql($sort, $asc) . "
             LIMIT :limit OFFSET :offset"
        );
        $like = '%' . self::escapeLike($search) . '%';
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('year_from', $year . '-01-01');
        $stmt->bindValue('year_to', $year . '-12-31');
        $stmt->bindValue('search', $search);
        $stmt->bindValue('like', $like);
        $stmt->bindValue('like2', $like);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'person_id' => (int) $row['person_id'],
            'ref' => (string) $row['r2_employment_ref'],
            'identyfikator' => $row['r2_identifier'] === null ? null : (string) $row['r2_identifier'],
            // ⚠ Tylko do wyświetlenia (§9): sortowanie i wyszukiwanie idą po kolumnach źródłowych.
            'name' => NazwaOsoby::nazwiskoImie((string) $row['last_name'], (string) $row['first_name']),
            'hired_at' => $row['hired_at'] === null ? null : (string) $row['hired_at'],
            'terminated_at' => $row['terminated_at'] === null ? null : (string) $row['terminated_at'],
            'fte' => $row['fte'] === null ? null : (float) $row['fte'],
            'calendar' => $row['calendar_name'] === null ? (string) ($row['r2_calendar_ref'] ?? '') : (string) $row['calendar_name'],
            'absence_days' => (int) $row['absence_days'],
            'active' => (bool) $row['is_active'],
        ], $stmt->fetchAll());
    }

    public function countEmployees(int $companyId, string $search): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :company AND (:search = '' OR p.last_name LIKE :like OR p.first_name LIKE :like2)"
        );
        $like = '%' . self::escapeLike($search) . '%';
        $stmt->execute(['company' => $companyId, 'search' => $search, 'like' => $like, 'like2' => $like]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Osoba widziana przez pryzmat zakresu firm — konto z jedną firmą nie zobaczy osoby z drugiej.
     *
     * @param list<int>|null $companies
     * @return array{id: int, name: string, external: ?string}|null
     */
    public function person(int $personId, ?array $companies): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.first_name, p.last_name, p.external_person_id, p.r2_identifier
             FROM person p JOIN employment e ON e.person_id = p.id
             WHERE p.id = :id AND ' . self::scopeSql($companies, 'e.company_id') . ' LIMIT 1'
        );
        $stmt->execute(self::scopeParams($companies) + ['id' => $personId]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            // Nagłówek karty: forma „imię nazwisko" (§9).
            'name' => NazwaOsoby::imieNazwisko((string) $row['first_name'], (string) $row['last_name']),
            // ⚠ Identyfikator osoby (HMAC z PESEL-u, §14) pokazujemy skrócony — pełnego nie ma potrzeby wyświetlać.
            'external' => $row['external_person_id'] === null ? null : mb_substr((string) $row['external_person_id'], 0, 12),
            // Numer kadrowy z programu kadrowego (`identyfikator`) — dana opisowa, nie klucz dopasowania.
            'identyfikator' => $row['r2_identifier'] === null ? null : (string) $row['r2_identifier'],
        ] : null;
    }

    /**
     * Zatrudnienia osoby (może ich być kilka, także w dwóch firmach).
     *
     * @param list<int>|null $companies
     * @return list<array<string, mixed>>
     */
    public function employments(int $personId, ?array $companies): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.company_id, c.name AS company, c.nip, e.r2_employment_ref,
                    e.hired_at, e.terminated_at, e.fte, e.r2_calendar_ref, e.is_active, e.deactivated_at,
                    e.contract_employment, e.contract_mandate, e.contract_work,
                    (SELECT cc.leave_norm_min FROM company_calendar cc
                      WHERE cc.company_id = e.company_id AND cc.calendar_ref = e.r2_calendar_ref AND cc.leave_norm_min IS NOT NULL
                      ORDER BY cc.year DESC LIMIT 1) AS leave_norm_min,
                    (SELECT cc.name FROM company_calendar cc
                      WHERE cc.company_id = e.company_id AND cc.calendar_ref = e.r2_calendar_ref
                      ORDER BY cc.year DESC LIMIT 1) AS calendar_name,
                    (SELECT sr.updated_at FROM sync_record sr
                      WHERE sr.company_id = e.company_id AND sr.zbior = 'zatrudnienie' AND sr.local_id = e.id
                      ORDER BY sr.updated_at DESC LIMIT 1) AS updated_at
             FROM employment e JOIN company c ON c.id = e.company_id
             WHERE e.person_id = :id AND " . self::scopeSql($companies, 'e.company_id') . "
             ORDER BY e.is_active DESC, e.hired_at DESC"
        );
        $stmt->execute(self::scopeParams($companies) + ['id' => $personId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'company_id' => (int) $row['company_id'], 'company' => (string) $row['company'],
            'nip' => (string) $row['nip'], 'ref' => (string) $row['r2_employment_ref'],
            'hired_at' => $row['hired_at'] === null ? null : (string) $row['hired_at'],
            'terminated_at' => $row['terminated_at'] === null ? null : (string) $row['terminated_at'],
            'fte' => $row['fte'] === null ? null : (float) $row['fte'],
            'calendar' => $row['r2_calendar_ref'] === null ? null : (string) $row['r2_calendar_ref'],
            // Nazwa rozkładu czasu pracy ze zbioru `kalendarz`; gdy jeszcze nie przyszedł — zostaje sam identyfikator.
            'calendar_name' => $row['calendar_name'] === null ? null : (string) $row['calendar_name'],
            'leave_norm_min' => $row['leave_norm_min'] === null ? null : (int) $row['leave_norm_min'],
            // Kiedy program kadrowy ostatnio przysłał zmianę tego zatrudnienia — dana techniczna,
            // więc na karcie schodzi pod zwijany pasek, a nie obok etatu.
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
            // Rodzaje umów: kilka naraz jest normalne, więc lista, nie jedna wartość.
            'umowy' => array_values(array_filter([
                (bool) $row['contract_employment'] ? 'o pracę' : null,
                (bool) $row['contract_mandate'] ? 'zlecenie' : null,
                (bool) $row['contract_work'] ? 'o dzieło' : null,
            ])),
            'active' => (bool) $row['is_active'],
            // ⚠ Data wygaszenia jedzie do widoku, bo bez niej napis „zakończone" nie mówi,
            // **kiedy i dlaczego** — a to jest ta połowa prawdy, przez którą ekran wyglądał
            // na sprzeczny z „Zatrudniony do: —" (zmierzone 10.10.2026 na zatrudnieniu #88).
            'deactivated_at' => $row['deactivated_at'] === null ? null : (string) $row['deactivated_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Salda limitów zatrudnień — wszystkie lata, jednym zapytaniem.
     *
     * @param list<int> $employmentIds
     * @return list<array<string, mixed>>
     */
    public function balances(array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_id, year, kind, accrued, entitlement_min, carried_over_min, available_start_min,
                    used_min, adjustments_min, remaining_min, on_demand_used_days, out_of_window
             FROM leave_balance WHERE employment_id IN (' . self::inSql($employmentIds, 'e') . ') ORDER BY year DESC, kind'
        );
        $stmt->execute(self::inParams($employmentIds, 'e'));

        return array_map(static fn (array $row): array => [
            'employment_id' => (int) $row['employment_id'], 'year' => (int) $row['year'], 'kind' => (string) $row['kind'],
            'accrued' => (bool) $row['accrued'],
            'entitlement_min' => self::intOrNull($row['entitlement_min']),
            'carried_over_min' => self::intOrNull($row['carried_over_min']),
            'available_start_min' => self::intOrNull($row['available_start_min']),
            'used_min' => self::intOrNull($row['used_min']),
            'adjustments_min' => self::intOrNull($row['adjustments_min']),
            'remaining_min' => self::intOrNull($row['remaining_min']),
            'on_demand_days' => self::intOrNull($row['on_demand_used_days']),
            'out_of_window' => (bool) $row['out_of_window'],
        ], $stmt->fetchAll());
    }

    /**
     * Nieobecności zatrudnień, najnowsze pierwsze, ze stronicowaniem — karta pracownika z 657 wpisami
     * w bazie nie może ich renderować wszystkich.
     *
     * @param list<int> $employmentIds
     * @return list<array<string, mixed>>
     */
    public function absences(array $employmentIds, int $limit, int $offset, ?int $year = null): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, a.employment_id, a.r2_record_id, a.date_from, a.date_to, a.days, a.minutes,
                    a.entry_source, a.continuation, a.out_of_window, a.removed_at, a.r2_modified_at, a.r2_created_at,
                    t.name AS type_name, t.source AS type_source, t.code AS type_code, t.limit_kind
             FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id
             WHERE a.employment_id IN (" . self::inSql($employmentIds, 'e') . ")
               AND (:year IS NULL OR YEAR(a.date_from) = :year2)
             ORDER BY a.date_from DESC, a.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach (self::inParams($employmentIds, 'e') as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('year', $year, $year === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('year2', $year ?? 0, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'employment_id' => (int) $row['employment_id'],
            'record_id' => (string) $row['r2_record_id'],
            'from' => (string) $row['date_from'], 'to' => (string) $row['date_to'],
            'days' => self::intOrNull($row['days']), 'minutes' => self::intOrNull($row['minutes']),
            'source' => (string) $row['entry_source'],
            'type' => (string) $row['type_name'], 'type_source' => (string) $row['type_source'],
            'continuation' => $row['continuation'] === null ? null : (bool) $row['continuation'],
            'limit_kind' => $row['limit_kind'] === null ? null : (string) $row['limit_kind'],
            'out_of_window' => (bool) $row['out_of_window'],
            'removed' => $row['removed_at'] !== null,
            'modified_at' => $row['r2_modified_at'] === null ? (string) ($row['r2_created_at'] ?? '') : (string) $row['r2_modified_at'],
        ], $stmt->fetchAll());
    }

    /** @param list<int> $employmentIds */
    /**
     * Ile nieobecności i ile dni roboczych — stopka karty nieobecności (§5.2).
     *
     * @param list<int> $employmentIds
     * @return array{liczba: int, dni: int, wycofanych: int, wszystkich: int} `liczba`/`dni`
     *     liczą **obowiązujące** nieobecności, `wycofanych` — te z `removed_at`, `wszystkich` —
     *     razem (do stronicowania tabeli, która pokazuje jedne i drugie)
     */
    public function absenceSummary(array $employmentIds, ?int $year = null): array
    {
        if ($employmentIds === []) {
            return ['liczba' => 0, 'dni' => 0, 'wycofanych' => 0, 'wszystkich' => 0];
        }
        // ⚠ **WYCOFANA NIEOBECNOŚĆ NIE JEST NIEOBECNOŚCIĄ** — naprawa sprzeczności zmierzonej
        // przez Krzysztofa 10.10.2026: lista `/dane/firma/3` pokazywała dla tej samej osoby
        // **0 dni**, a jej karta **44 dni**. Oba liczniki sumowały to samo pole `days`, tylko
        // lista miała `removed_at IS NULL`, a to zapytanie nie miało tego warunku wcale.
        // Zmierzone na zatrudnieniu #88: 19 nieobecności, **wszystkie wycofane**, 76 dni łącznie,
        // z czego 44 dni w 2026 — czyli karta pokazywała dni, których w kadrach już nie ma.
        //
        // ⚠ **Ale wycofanych nie przemilczamy**: tabela pod spodem pokazuje je z plakietką
        // „wycofana", bo to ekran do oglądania danych z R2 i wycofanie jest tu informacją
        // (wyjaśnia, dlaczego kalendarz się zmienił). Dlatego **trzy liczby, nie jedna**:
        // `liczba`/`dni` to nieobecności obowiązujące, `wycofanych` mówi o wycofanych,
        // a `wszystkich` służy stronicowaniu tabeli, która pokazuje jedne i drugie. Licznik,
        // który nie zgadza się z tabelą pod nim, jest tą samą chorobą co licznik, którego nikt
        // nie przelicza.
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS wszystkich,
                    SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) AS liczba,
                    COALESCE(SUM(CASE WHEN removed_at IS NULL THEN days ELSE 0 END), 0) AS dni,
                    SUM(CASE WHEN removed_at IS NULL THEN 0 ELSE 1 END) AS wycofanych
               FROM external_absence
              WHERE employment_id IN (' . self::inSql($employmentIds, 'e') . ')
                AND (:year IS NULL OR YEAR(date_from) = :year2)'
        );
        $stmt->execute(self::inParams($employmentIds, 'e') + ['year' => $year, 'year2' => $year ?? 0]);
        $row = $stmt->fetch();

        return [
            'liczba' => (int) ($row['liczba'] ?? 0),
            'dni' => (int) ($row['dni'] ?? 0),
            'wycofanych' => (int) ($row['wycofanych'] ?? 0),
            'wszystkich' => (int) ($row['wszystkich'] ?? 0),
        ];
    }

    /** @param list<int> $employmentIds @return list<int> lata, z których są nieobecności (malejąco) */
    public function absenceYears(array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(date_from) AS rok FROM external_absence
             WHERE employment_id IN (' . self::inSql($employmentIds, 'e') . ') ORDER BY rok DESC'
        );
        $stmt->execute(self::inParams($employmentIds, 'e'));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Przebiegi synchronizacji — lista dla ekranu stanu.
     *
     * @param list<int>|null $companies
     * @return list<array<string, mixed>>
     */
    public function runs(?array $companies, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id, r.run_uuid, r.company_id, c.name AS company, r.status, r.phase, r.connector_version,
                    r.r2_version, r.started_at, r.finished_at, r.stats,
                    TIMESTAMPDIFF(SECOND, r.started_at, COALESCE(r.finished_at, r.updated_at)) AS seconds,
                    (SELECT COUNT(*) FROM sync_conflict f WHERE f.sync_run_id = r.id) AS conflicts
             FROM sync_run r JOIN company c ON c.id = r.company_id
             WHERE " . self::scopeSql($companies, 'r.company_id') . "
             ORDER BY r.id DESC
             LIMIT :limit"
        );
        foreach (self::scopeParams($companies) as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::runRow(...), $stmt->fetchAll());
    }

    /** @param list<int>|null $companies */
    public function run(int $runId, ?array $companies): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id, r.run_uuid, r.company_id, c.name AS company, r.status, r.phase, r.connector_version,
                    r.r2_version, r.started_at, r.finished_at, r.stats,
                    TIMESTAMPDIFF(SECOND, r.started_at, COALESCE(r.finished_at, r.updated_at)) AS seconds,
                    (SELECT COUNT(*) FROM sync_conflict f WHERE f.sync_run_id = r.id) AS conflicts
             FROM sync_run r JOIN company c ON c.id = r.company_id
             WHERE r.id = :id AND " . self::scopeSql($companies, 'r.company_id')
        );
        $stmt->execute(self::scopeParams($companies) + ['id' => $runId]);
        $row = $stmt->fetch();

        return is_array($row) ? self::runRow($row) : null;
    }

    /**
     * Rekordy dotknięte w przebiegu: dodane (`created_run_id`), zmienione i usunięte (`updated_run_id`).
     * ⚠ To jest odpowiedź na pytanie „kadry zmieniły trzy rzeczy — czy przyszły dokładnie trzy rekordy”.
     *
     * @return list<array{zbior: string, record_id: string, state: string, change: string, updated_at: string}>
     */
    public function runRecords(int $runId, int $companyId, int $limit, int $offset): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT zbior, record_id, state, updated_at,
                    CASE WHEN created_run_id = :run2 THEN 'dodany'
                         WHEN state IN ('deleted', 'out_of_window') THEN 'usuniety'
                         ELSE 'zmieniony' END AS change_kind
             FROM sync_record
             WHERE company_id = :company AND updated_run_id = :run
             ORDER BY zbior, record_id
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('run', $runId, PDO::PARAM_INT);
        $stmt->bindValue('run2', $runId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'zbior' => (string) $row['zbior'], 'record_id' => (string) $row['record_id'],
            'state' => (string) $row['state'], 'change' => (string) $row['change_kind'],
            'updated_at' => (string) $row['updated_at'],
        ], $stmt->fetchAll());
    }

    /** @return array{dodany: int, zmieniony: int, usuniety: int, razem: int} */
    public function runRecordCounts(int $runId, int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(created_run_id = :run2) AS dodane,
                    SUM(created_run_id <> :run3 OR created_run_id IS NULL) AS pozostale,
                    SUM(state IN ('deleted', 'out_of_window') AND (created_run_id <> :run4 OR created_run_id IS NULL)) AS usuniete,
                    COUNT(*) AS razem
             FROM sync_record WHERE company_id = :company AND updated_run_id = :run"
        );
        $stmt->execute(['company' => $companyId, 'run' => $runId, 'run2' => $runId, 'run3' => $runId, 'run4' => $runId]);
        $row = $stmt->fetch();
        $deleted = (int) ($row['usuniete'] ?? 0);

        return [
            'dodany' => (int) ($row['dodane'] ?? 0),
            'zmieniony' => (int) ($row['pozostale'] ?? 0) - $deleted,
            'usuniety' => $deleted,
            'razem' => (int) ($row['razem'] ?? 0),
        ];
    }

    /**
     * Konflikty przebiegu z powodem i wskazaniem rekordu.
     *
     * @return list<array{id: int, kind: string, reason: string, request: ?int, created_at: string, resolved: bool}>
     */
    public function conflicts(int $runId, int $companyId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, kind, reason, leave_request_id, created_at, resolved_at
             FROM sync_conflict WHERE sync_run_id = :run AND company_id = :company
             ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue('run', $runId, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'reason' => (string) $row['reason'],
            'request' => self::intOrNull($row['leave_request_id']), 'created_at' => (string) $row['created_at'],
            'resolved' => $row['resolved_at'] !== null,
        ], $stmt->fetchAll());
    }

    /** @return array{zbior: string, record_id: string}|null rekord wskazany przez konflikt (jeśli znany) */
    private static function runRow(array $row): array
    {
        $stats = is_string($row['stats']) ? json_decode((string) $row['stats'], true) : [];

        return [
            'id' => (int) $row['id'], 'uuid' => (string) $row['run_uuid'], 'company_id' => (int) $row['company_id'],
            'company' => (string) $row['company'], 'status' => (string) $row['status'], 'phase' => (string) $row['phase'],
            'connector' => (string) ($row['connector_version'] ?? ''), 'r2' => (string) ($row['r2_version'] ?? ''),
            'started_at' => (string) $row['started_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            'seconds' => self::intOrNull($row['seconds']),
            'conflicts' => (int) $row['conflicts'],
            'stats' => is_array($stats) ? $stats : [],
        ];
    }

    /**
     * Zakres firm jako warunek SQL. ⚠ Do zapytania trafiają wyłącznie **nazwy placeholderów**
     * wyliczone z liczby firm — wartości idą przez `execute()` (CLAUDE.md §4).
     *
     * @param list<int>|null $companies
     */
    private static function scopeSql(?array $companies, string $column): string
    {
        if ($companies === null) {
            return '1 = 1';
        }
        if ($companies === []) {
            return '1 = 0';
        }

        return $column . ' IN (' . self::inSql($companies, 'c') . ')';
    }

    /**
     * @param list<int>|null $companies
     * @return array<string, int>
     */
    private static function scopeParams(?array $companies): array
    {
        return $companies === null || $companies === [] ? [] : self::inParams($companies, 'c');
    }

    /** @param list<int> $ids */
    private static function inSql(array $ids, string $prefix): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':' . $prefix . $i, array_keys(array_values($ids))));
    }

    /**
     * @param list<int> $ids
     * @return array<string, int>
     */
    private static function inParams(array $ids, string $prefix): array
    {
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params[$prefix . $i] = (int) $id;
        }

        return $params;
    }

    /** Sortowanie listy pracowników — wyłącznie z białej listy (§4). */
    private static function employeeOrderSql(string $sort, bool $asc): string
    {
        $kolumny = self::EMPLOYEE_SORT[$sort] ?? self::EMPLOYEE_SORT['nazwisko'];
        $kierunek = $asc ? ' ASC' : ' DESC';

        return implode(', ', array_map(static fn (string $k): string => $k . $kierunek, $kolumny)) . ', e.id';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** `LIKE` z wejścia użytkownika: `%` i `_` tracą znaczenie wzorca. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
