<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Odczyt dla **widoku pracownika** — pulpit (`/`) i „Mój kalendarz” (`/kalendarz`).
 * Strefa jawna (CLAUDE.md §15), wyłącznie SELECT.
 *
 * ⚠ Dlaczego to nie jest `DataViewRepository`, choć czyta te same tabele: **pytanie jest inne.**
 * Ekran kadrowej odpowiada „co przysłał program kadrowy” — łącznie z wpisami wycofanymi
 * i z ekwiwalentami, bo to jest materiał do audytu. Pracownik pyta „jakie mam nieobecności”,
 * więc wpis wycofany i rozliczenie płacowe nie mają prawa się pokazać. Dołożenie tych warunków
 * do wspólnego zapytania odebrałoby kadrowej audyt; wspólny parametr „a teraz filtruj inaczej”
 * dałby jedno zapytanie z dwoma znaczeniami. Zapytania są więc osobne i to jest świadome.
 *
 * ⚠ Nieobecność = `leave_type.is_absence = 1`. Dziś ten warunek wycina dokładnie `urlop` kod 6 i 8
 * (ekwiwalent za urlop wypoczynkowy i dodatkowy — rozliczenie płacowe, nie nieobecność), ale
 * warunek stoi na **polu ze słownika**, nie na liście kodów: kolejny ekwiwalent przysłany przez R2
 * wypadnie z kalendarza sam, bez zmiany w naszym kodzie.
 */
final class PracownikRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Zatrudnienie do nagłówka pulpitu — z warunkiem właściciela **w zapytaniu** (CLAUDE.md §10).
     *
     * ⚠ Kontekst sprawdza już `Middleware\EmploymentContext`, a to zapytanie sprawdza go drugi raz.
     * Jedno zapytanie więcej jest tańsze niż założenie „ktoś wcześniej to sprawdził”: gdyby kolejność
     * warstw kiedykolwiek się zmieniła, pulpit dalej nie pokaże cudzego zatrudnienia.
     *
     * @return array<string, mixed>|null
     */
    public function mojeZatrudnienie(int $employmentId, int $accountId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.company_id, ' . NazwaSpolki::SQL . ' AS company, e.employee_number, e.fte, e.hired_at,
                    e.r2_calendar_ref, p.first_name, p.last_name,
                    (SELECT cc.name FROM company_calendar cc
                      WHERE cc.company_id = e.company_id AND cc.calendar_ref = e.r2_calendar_ref
                      ORDER BY cc.year DESC LIMIT 1) AS calendar_name
             FROM employment e
               JOIN company c ON c.id = e.company_id
               JOIN person p ON p.id = e.person_id
               JOIN account a ON a.person_id = e.person_id
             WHERE e.id = :id AND a.id = :account AND e.is_active = 1 AND c.is_active = 1'
        );
        $stmt->execute(['id' => $employmentId, 'account' => $accountId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'company' => (string) $row['company'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $row['first_name']) . ' '
                . NazwaOsoby::doWyswietlenia((string) $row['last_name']),
            'employee_number' => $row['employee_number'] === null || $row['employee_number'] === ''
                ? null : (string) $row['employee_number'],
            'fte' => $row['fte'] === null ? null : (float) $row['fte'],
            'hired_at' => $row['hired_at'] === null ? null : (string) $row['hired_at'],
            'calendar' => $row['r2_calendar_ref'] === null || $row['r2_calendar_ref'] === ''
                ? null : (string) $row['r2_calendar_ref'],
            'calendar_name' => $row['calendar_name'] === null ? null : (string) $row['calendar_name'],
        ];
    }

    /**
     * Saldo limitów jednego zatrudnienia za jeden rok — wszystkie rodzaje (`wypoczynkowy`,
     * `opieka_art188`).
     *
     * ⚠ `accrued` (`naliczony` w R2) jedzie razem z liczbami i jest ważniejszy od nich: gdy kadry
     * nie założyły jeszcze limitu, wiersz **istnieje** z zerami, a ekran nie ma prawa pokazać „0 dni”
     * (zlecenie §3.1).
     *
     * @return list<array<string, mixed>>
     */
    public function saldo(int $employmentId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT kind, accrued, entitlement_min, carried_over_min, available_start_min,
                    used_min, adjustments_min, remaining_min, on_demand_used_days, out_of_window
             FROM leave_balance WHERE employment_id = :id AND year = :year ORDER BY kind'
        );
        $stmt->execute(['id' => $employmentId, 'year' => $year]);

        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['kind'],
            'naliczony' => (bool) $row['accrued'],
            'wymiar_min' => self::intOrNull($row['entitlement_min']),
            'zalegly_min' => self::intOrNull($row['carried_over_min']),
            'na_start_min' => self::intOrNull($row['available_start_min']),
            'wykorzystane_min' => self::intOrNull($row['used_min']),
            'korekty_min' => self::intOrNull($row['adjustments_min']),
            'pozostalo_min' => self::intOrNull($row['remaining_min']),
            'na_zadanie_dni' => self::intOrNull($row['on_demand_used_days']),
            'poza_oknem' => (bool) $row['out_of_window'],
        ], $stmt->fetchAll());
    }

    /**
     * Lata, dla których cokolwiek mamy — z limitów **i** z nieobecności.
     *
     * ⚠ Z obu źródeł, nie z kalendarza: rok, w którym ktoś miał nieobecności, ale nie ma limitu
     * (albo odwrotnie), też musi dać się wybrać. Sztywne „bieżący i poprzedni” było założeniem
     * o danych — ta sama pomyłka, którą naprawialiśmy na karcie pracownika.
     *
     * @return list<int> malejąco
     */
    public function lata(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rok FROM (
                 SELECT year AS rok FROM leave_balance WHERE employment_id = :id
                 UNION
                 SELECT YEAR(a.date_from) AS rok FROM external_absence a
                   JOIN leave_type t ON t.id = a.leave_type_id
                  WHERE a.employment_id = :id2 AND a.removed_at IS NULL AND t.is_absence = 1
             ) AS lata ORDER BY rok DESC'
        );
        $stmt->execute(['id' => $employmentId, 'id2' => $employmentId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Moje nieobecności — najnowsze pierwsze, bez wpisów wycofanych i bez ekwiwalentów.
     *
     * @return list<array<string, mixed>>
     */
    public function nieobecnosci(int $employmentId, ?int $year, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.date_from, a.date_to, a.days, a.minutes, a.continuation, a.entry_source,
                    a.out_of_window, t.name AS type_name, t.source AS type_source, t.limit_kind
             FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id
             WHERE a.employment_id = :id AND a.removed_at IS NULL AND t.is_absence = 1
               AND (:year IS NULL OR YEAR(a.date_from) = :year2)
             ORDER BY a.date_from DESC, a.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('id', $employmentId, PDO::PARAM_INT);
        $stmt->bindValue('year', $year, $year === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('year2', $year ?? 0, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::wierszNieobecnosci(...), $stmt->fetchAll());
    }

    /**
     * Ile nieobecności i ile dni roboczych w danym roku — podpis kafelka i stopka tabeli.
     *
     * @return array{liczba: int, dni: int}
     */
    public function podsumowanie(int $employmentId, ?int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS liczba, COALESCE(SUM(a.days), 0) AS dni
             FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id
             WHERE a.employment_id = :id AND a.removed_at IS NULL AND t.is_absence = 1
               AND (:year IS NULL OR YEAR(a.date_from) = :year2)'
        );
        $stmt->execute(['id' => $employmentId, 'year' => $year, 'year2' => $year ?? 0]);
        $row = $stmt->fetch();

        return ['liczba' => (int) ($row['liczba'] ?? 0), 'dni' => (int) ($row['dni'] ?? 0)];
    }

    /**
     * Najbliższa nieobecność: pierwsza, która **jeszcze się nie zaczęła**.
     *
     * ⚠ Warunek to `date_from >= dziś`, dokładnie jak w zleceniu (§1) — nie „trwająca lub przyszła”.
     * Kafelek odpowiada na pytanie „kiedy mnie nie będzie”, a nieobecność trwająca dziś jest
     * odpowiedzią na inne pytanie i pokazana w niej data byłaby przeszła.
     *
     * @return array<string, mixed>|null
     */
    public function najblizsza(int $employmentId, string $dzis): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.date_from, a.date_to, a.days, a.minutes, a.continuation, a.entry_source,
                    a.out_of_window, t.name AS type_name, t.source AS type_source, t.limit_kind
             FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id
             WHERE a.employment_id = :id AND a.removed_at IS NULL AND t.is_absence = 1
               AND a.date_from >= :dzis
             ORDER BY a.date_from, a.id LIMIT 1'
        );
        $stmt->execute(['id' => $employmentId, 'dzis' => $dzis]);
        $row = $stmt->fetch();

        return is_array($row) ? self::wierszNieobecnosci($row) : null;
    }

    /**
     * Nieobecności zachodzące na okres — siatka miesiąca i wiersze zespołu.
     *
     * ⚠ **Dwie warstwy, każda pilnuje innej granicy.** Granicę SPÓŁKI pilnuje warunek
     * `e.company_id` w tym zapytaniu — dzięki temu cudza spółka nie przejdzie nawet wtedy,
     * gdy lista identyfikatorów byłaby błędna (zmierzone na wstrzykniętej usterce: przy usuniętym
     * filtrze zasięgu wyciekła gałąź w obrębie spółki, ale nie druga spółka). Granicy GAŁĘZI
     * tym zapytaniem wyrazić się nie da — hierarchia to inna tabela i inne przejście — więc jej
     * pilnuje `ZasiegPodgladu::przefiltruj()` i identyfikatory **muszą** przez niego przejść.
     * Dlatego jedynym wejściem do tej metody jest `KalendarzPracownika`.
     *
     * @param list<int> $employmentIds
     * @return list<array<string, mixed>>
     */
    public function nieobecnosciZakres(int $companyId, array $employmentIds, string $od, string $do): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.employment_id, a.date_from, a.date_to, a.days, a.minutes, a.continuation,
                    a.entry_source, a.out_of_window, t.name AS type_name, t.source AS type_source, t.limit_kind
             FROM external_absence a
               JOIN leave_type t ON t.id = a.leave_type_id
               JOIN employment e ON e.id = a.employment_id
             WHERE e.company_id = :company
               AND a.employment_id IN (' . self::inSql($employmentIds) . ')
               AND a.removed_at IS NULL AND t.is_absence = 1
               AND a.date_from <= :do AND a.date_to >= :od
             ORDER BY a.date_from, a.id'
        );
        $stmt->execute(self::inParams($employmentIds) + ['company' => $companyId, 'od' => $od, 'do' => $do]);

        return array_map(static function (array $row): array {
            $wiersz = self::wierszNieobecnosci($row);
            $wiersz['employment_id'] = (int) $row['employment_id'];

            return $wiersz;
        }, $stmt->fetchAll());
    }

    /**
     * Osoby do kontrolki wyboru i do wierszy zespołu — **tylko podane zatrudnienia i tylko z tej
     * spółki**. Warunek spółki jest w zapytaniu, a nie ufa liście wejściowej.
     *
     * @param list<int> $employmentIds
     * @return list<array{id: int, osoba: string, przelozony: ?int, ja: bool}>
     */
    public function osoby(int $companyId, array $employmentIds, int $jaId): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.supervisor_employment_id, p.first_name, p.last_name
             FROM employment e JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :company AND e.id IN (' . self::inSql($employmentIds) . ')
             ORDER BY CASE WHEN e.id = :ja THEN 0 ELSE 1 END, p.last_name, p.first_name, e.id'
        );
        // ⚠ Własny wiersz PIERWSZY, potem alfabetycznie: to „Mój kalendarz", więc szukając
        // siebie nie mam przeglądać listy. `CASE WHEN` zamiast wyrażenia logicznego w `ORDER BY`,
        // bo to składnia standardowa, a nie zależna od silnika (CLAUDE.md §0).
        $stmt->execute(self::inParams($employmentIds) + ['company' => $companyId, 'ja' => $jaId]);

        return array_map(self::wierszOsoby($jaId), $stmt->fetchAll());
    }

    /**
     * Wszystkie czynne zatrudnienia spółki — zasięg „Firma" (poziom widoczności ≥ 1).
     *
     * ⚠ Granicę spółki pilnuje `company_id` **w zapytaniu** i to jedyna granica w tej aplikacji,
     * która ani razu nie przeciekła — także w dowodzie przez łamanie z 22.09, gdzie po usunięciu
     * filtru gałęzi wyciekł zasięg, ale druga spółka nie (`docs/etap3-logika-urlopowa.md` §8b).
     * Dlatego zasięg firmowy powstaje TU, a nie przez rozluźnienie przejścia po hierarchii.
     *
     * @return list<int> posortowane nazwiskiem, żeby limit wyświetlania obcinał przewidywalnie
     */
    public function zatrudnieniaSpolki(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id FROM employment e JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :company AND e.is_active = 1
             ORDER BY p.last_name, p.first_name, e.id'
        );
        $stmt->execute(['company' => $companyId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Osoby z zasięgu do kontrolki wyboru — z **szukaniem po stronie serwera** i limitem.
     *
     * ⚠ To ten sam mechanizm co szukanie kandydata na przełożonego w hierarchii
     * (`HierarchyRepository::kandydaci`), nie drugi: pełne literały zapytań zamiast składania
     * z fragmentów (bramka `check-rules` — „zero konkatenacji w SQL"), wzorzec i limit
     * placeholderami. Przy 53 osobach w spółce lista bez szukajki jest ścianą.
     *
     * @param list<int> $employmentIds zasięg — **już** policzony po stronie serwera
     * @return array{osoby: list<array{id: int, osoba: string, przelozony: ?int, ja: bool}>, razem: int}
     */
    public function osobyZasiegu(int $companyId, array $employmentIds, int $jaId, string $szukaj, int $limit): array
    {
        if ($employmentIds === []) {
            return ['osoby' => [], 'razem' => 0];
        }
        $zeSzukaniem = $szukaj !== '';
        $sqlIle = $zeSzukaniem
            ? "SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.id IN (" . self::inSql($employmentIds) . ")
                  AND CONCAT(p.last_name, ' ', p.first_name) LIKE :szukaj"
            : 'SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.id IN (' . self::inSql($employmentIds) . ')';
        $sqlLista = $zeSzukaniem
            ? "SELECT e.id, e.supervisor_employment_id, p.first_name, p.last_name
                 FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.id IN (" . self::inSql($employmentIds) . ")
                  AND CONCAT(p.last_name, ' ', p.first_name) LIKE :szukaj
                ORDER BY CASE WHEN e.id = :ja THEN 0 ELSE 1 END, p.last_name, p.first_name, e.id
                LIMIT :limit"
            : 'SELECT e.id, e.supervisor_employment_id, p.first_name, p.last_name
                 FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.id IN (' . self::inSql($employmentIds) . ')
                ORDER BY CASE WHEN e.id = :ja THEN 0 ELSE 1 END, p.last_name, p.first_name, e.id
                LIMIT :limit';

        $parametry = self::inParams($employmentIds) + ['company' => $companyId];
        $ile = $this->db->pdo()->prepare($sqlIle);
        foreach ($parametry as $nazwa => $wartosc) {
            $ile->bindValue($nazwa, $wartosc, PDO::PARAM_INT);
        }
        if ($zeSzukaniem) {
            $ile->bindValue('szukaj', '%' . $szukaj . '%');
        }
        $ile->execute();
        $razem = (int) $ile->fetchColumn();

        $stmt = $this->db->pdo()->prepare($sqlLista);
        foreach ($parametry + ['ja' => $jaId] as $nazwa => $wartosc) {
            $stmt->bindValue($nazwa, $wartosc, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        if ($zeSzukaniem) {
            $stmt->bindValue('szukaj', '%' . $szukaj . '%');
        }
        $stmt->execute();

        return ['osoby' => array_map(self::wierszOsoby($jaId), $stmt->fetchAll()), 'razem' => $razem];
    }

    /**
     * Nazwiska i imiona dla CAŁEGO zasięgu — mapa `id zatrudnienia → „Nazwisko Imię"`.
     *
     * ⚠ **Potrzebna, żeby podpis „przełożony: …" mógł podać nazwisko także wtedy, gdy przełożony
     * nie jest akurat na liście.** Dotąd nazwy brały się z wiersza w przekazanym zbiorze, więc
     * odznaczenie przełożonego w kontrolce, zawężenie szukaniem albo próg wyświetlania zamieniały
     * jego nazwisko na „inna osoba w gałęzi" — zmierzone: 5 wierszy z nazwiskiem przed
     * odznaczeniem Siwca, 5 wierszy z zastępnikiem po.
     *
     * ⚠ **Granica prywatności zostaje, tylko stoi we właściwym miejscu.** Mapa powstaje
     * z `$employmentIds`, czyli z zasięgu policzonego przez `ZasiegPodgladu`, i z warunkiem
     * spółki **w zapytaniu**. Nazwisko kogoś spoza zasięgu nadal nie ma jak tu wejść — a to
     * było jedyne uzasadnienie starego rozwiązania. Mieszało ono dwie różne rzeczy: „nie wolno
     * mi tej osoby widzieć" i „tej osoby nie ma teraz na ekranie".
     *
     * @param list<int> $employmentIds zasięg — **już** policzony po stronie serwera
     * @return array<int, string>
     */
    public function nazwyZasiegu(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, p.first_name, p.last_name
             FROM employment e JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :company AND e.id IN (' . self::inSql($employmentIds) . ')'
        );
        $stmt->execute(self::inParams($employmentIds) + ['company' => $companyId]);
        $nazwy = [];
        foreach ($stmt->fetchAll() as $row) {
            $nazwy[(int) $row['id']] = NazwaOsoby::doWyswietlenia((string) $row['last_name']) . ' '
                . NazwaOsoby::doWyswietlenia((string) $row['first_name']);
        }

        return $nazwy;
    }

    /** @return list<string> dni nierobocze z rozkładu czasu pracy (zbiór `kalendarz` z R2) */
    public function dniNierobocze(int $companyId, ?string $calendarRef, int $year): array
    {
        if ($calendarRef === null || $calendarRef === '') {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT non_working_days FROM company_calendar
             WHERE company_id = :company AND calendar_ref = :ref AND year = :year'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $calendarRef, 'year' => $year]);
        $value = $stmt->fetchColumn();
        $days = is_string($value) ? json_decode($value, true) : null;

        return is_array($days) ? array_values(array_filter($days, 'is_string')) : [];
    }

    /**
     * Jeden kształt wiersza osoby dla obu zapytań (lista wierszy i kontrolka) — inaczej szablon
     * dostawałby dwa różne zestawy pól i `strict_variables` wywracałby jeden z widoków.
     *
     * @return callable(array<string, mixed>): array{id: int, osoba: string, przelozony: ?int, ja: bool}
     */
    private static function wierszOsoby(int $jaId): callable
    {
        return static fn (array $row): array => [
            'id' => (int) $row['id'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $row['last_name']) . ' '
                . NazwaOsoby::doWyswietlenia((string) $row['first_name']),
            'przelozony' => $row['supervisor_employment_id'] === null ? null : (int) $row['supervisor_employment_id'],
            'ja' => (int) $row['id'] === $jaId,
        ];
    }

    /** @return array<string, mixed> */
    private static function wierszNieobecnosci(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'od' => (string) $row['date_from'],
            'do' => (string) $row['date_to'],
            'dni' => self::intOrNull($row['days']),
            'minuty' => self::intOrNull($row['minutes']),
            'rodzaj' => (string) $row['type_name'],
            'zrodlo' => (string) $row['type_source'],
            // `null` znaczy „R2 nie przysłał pola”, a nie „nie jest kontynuacją” — i te dwa stany
            // trzeba rozróżniać, bo od tego zależy, czy wolno cokolwiek sklejać (zlecenie §3.4).
            'kontynuacja' => $row['continuation'] === null ? null : (bool) $row['continuation'],
            'wpis' => (string) $row['entry_source'],
            'limit_kind' => $row['limit_kind'] === null ? null : (string) $row['limit_kind'],
            'poza_oknem' => (bool) $row['out_of_window'],
        ];
    }

    /** @param list<int> $ids */
    private static function inSql(array $ids): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':e' . $i, array_keys(array_values($ids))));
    }

    /**
     * @param list<int> $ids
     * @return array<string, int>
     */
    private static function inParams(array $ids): array
    {
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params['e' . $i] = (int) $id;
        }

        return $params;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
