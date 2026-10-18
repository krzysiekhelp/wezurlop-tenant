<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Osoby — kartoteka, na której stoi ekran `/admin/konta`.
 *
 * ⚠ Ekran jest listą OSÓB, nie kont: konto jest jedną z kolumn i bywa go brak. Dlatego zawężenie
 * idzie przez `employment`, a nie przez `role_assignment` jak w `AccountRepository::listForAdmin()`.
 * Powód jest praktyczny: osoba zatrudniona w firmie X, której konto nie ma tam przypisania roli,
 * przy dawnym zawężeniu **nie istniała na liście** — a to jest dokładnie ta osoba, którą trzeba
 * zaprosić.
 *
 * @phpstan-type OsobaRow array{person_id: int, name: string, email: ?string, companies: list<string>,
 *     employments_total: int, employments_active: int, account_id: ?int, account_status: string,
 *     etat_spolki: int, etat_nazwy: list<string>, dostepy: int, dostep_nazwy: list<string>,
 *     caly_tenant: bool, spoza_kadr: bool, z_kadr: bool}
 */
final class PersonRepository
{
    /**
     * Biała lista sortowania (CLAUDE.md §4: nazwy kolumn nie da się sparametryzować).
     *
     * @var array<string, string>
     */
    /**
     * Biała lista sortowania (CLAUDE.md §4: nazw kolumn nie da się sparametryzować).
     *
     * ⚠ **Każdy wpis jest LISTĄ kolumn, a nie jednym napisem — i to jest poprawka usterki**
     * (Z30, 18.10.2026). Dawniej `'nazwisko' => 'p.last_name, p.first_name'` sklejało się
     * z kierunkiem na końcu, czyli dawało `ORDER BY p.last_name, p.first_name DESC`: nazwisko
     * zostawało ZAWSZE rosnąco, a odwracało się wyłącznie imię. Zmierzone na `/admin/konta`:
     * `kierunek=asc` i `kierunek=desc` zwracały tę samą pierwszą trójkę osób, a usterki nie było
     * widać, dopóki dwie osoby nie miały tego samego nazwiska. Kierunek dokłada się teraz
     * do KAŻDEJ kolumny z osobna.
     *
     * @var array<string, list<string>>
     */
    private const SORT = [
        'nazwisko' => ['p.last_name', 'p.first_name'],
        'email'    => ['email'],
        'konto'    => ['stan_kolejnosc'],
    ];

    /**
     * Wyrażenia stawiane PRZED kolumnami sortowania i **niepodlegające kierunkowi**.
     *
     * ⚠ `email IS NULL` pcha osoby bez adresu na koniec listy niezależnie od kierunku — i tak ma
     * zostać. Gdyby dostało kierunek razem z kolumną, sortowanie malejąco zaczynałoby się od
     * wszystkich osób BEZ adresu, czyli od pustych wierszy w kolumnie, po której się sortuje.
     *
     * @var array<string, string>
     */
    private const SORT_PRZED = [
        'email' => 'email IS NULL',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Nowa osoba.
     *
     * ⚠ `$spozaKadr` to **oświadczenie**, nie wniosek z braku danych: osoba dodana ręcznie
     * (konto administracyjne) dostaje `outside_hr = 1` i to zostaje w bazie na zawsze. Osoby
     * przychodzące z R2 powstają inną ścieżką (`SyncDomainRepository`) i mają tu `0`.
     */
    public function create(string $firstName, string $lastName, bool $spozaKadr = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO person (first_name, last_name, outside_hr) VALUES (:first, :last, :spoza)'
        );
        $stmt->execute(['first' => $firstName, 'last' => $lastName, 'spoza' => $spozaKadr ? 1 : 0]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Wspólny warunek zakresu.
     *
     * ⚠ `null` = cały tenant. Administrator firmy dostaje identyfikator swojej firmy, a jeśli
     * nie ma aktywnego zatrudnienia — `-1`, które nie pasuje do żadnej firmy, więc widzi pustą
     * listę, a nie wszystko. Uprawnienie nie bierze się z braku danych.
     */
    private const ZAKRES = '(:company_all = 1 OR EXISTS (
            SELECT 1 FROM employment e2 WHERE e2.person_id = p.id AND e2.company_id = :company))';

    /**
     * Filtr po spółce z nagłówka listy (§7 zlecenia).
     *
     * ⚠ Stoi OBOK warunku zakresu, a nie zamiast niego: administrator firmy z filtrem na obcą
     * spółkę dostaje przecięcie, czyli pustkę — filtr zawęża to, co i tak mu wolno widzieć.
     * ⚠ Filtruje po ZATRUDNIENIU, nie po dostępie: „pokaż ludzi z Piaskarza" to pytanie o kadry,
     * a nie o to, komu nadaliśmy dostęp — i etykieta na ekranie mówi to wprost.
     */
    private const FILTR_SPOLKI = '(:filter_all = 1 OR EXISTS (
            SELECT 1 FROM employment e4 WHERE e4.person_id = p.id AND e4.company_id = :filter))';

    private const SZUKANIE = "(:search = ''
            OR p.last_name LIKE :like OR p.first_name LIKE :like2 OR a.email LIKE :like3
            OR p.email_private LIKE :like4)";

    /**
     * ⚠ „do odblokowania" to konto `blocked`, które ma znów aktywne zatrudnienie. Bez tego stanu
     * administrator nie wie, kogo odblokować, i dowiaduje się telefonem od pracownika (§5 zlecenia).
     */
    private const STAN_SQL = "CASE
            WHEN a.id IS NULL THEN 'brak'
            WHEN a.status = 'blocked' AND EXISTS (
                SELECT 1 FROM employment e3 WHERE e3.person_id = p.id AND e3.is_active = 1
            ) THEN 'do_odblokowania'
            ELSE a.status END";

    /**
     * @return list<OsobaRow>
     */
    public function listForAdmin(
        ?int $companyId,
        string $search,
        string $sort,
        bool $asc,
        int $limit,
        int $offset,
        ?int $filtrSpolki = null,
    ): array {
        return $this->listaZWarunkiem('1 = 1', [], $companyId, $search, $sort, $asc, $limit, $offset, $filtrSpolki);
    }

    /**
     * Wspólne zapytanie listy — jeden kształt wiersza dla całej strony i dla pojedynczego
     * wiersza odświeżanego przez htmx.
     *
     * ⚠ `$warunek` to **literał z tej klasy**, nigdy wejście użytkownika: wartości idą wyłącznie
     * przez `$extra` i placeholdery (CLAUDE.md §4).
     *
     * @param array<string, int|string> $extra
     * @return list<OsobaRow>
     */
    private function listaZWarunkiem(
        string $warunek,
        array $extra,
        ?int $companyId,
        string $search,
        string $sort,
        bool $asc,
        int $limit,
        int $offset,
        ?int $filtrSpolki = null,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id AS person_id, p.first_name, p.last_name,
                    COALESCE(a.email, p.email_private) AS email,
                    a.id AS account_id,
                    ' . self::STAN_SQL . " AS stan,
                    CASE " . self::STAN_SQL . " WHEN 'do_odblokowania' THEN 0 WHEN 'brak' THEN 1
                        WHEN 'niezaproszone' THEN 2 WHEN 'invited' THEN 3 WHEN 'blocked' THEN 4
                        ELSE 5 END AS stan_kolejnosc,
                    (SELECT COUNT(*) FROM employment e WHERE e.person_id = p.id) AS employments_total,
                    (SELECT COUNT(*) FROM employment e WHERE e.person_id = p.id AND e.is_active = 1) AS employments_active,
                    (SELECT GROUP_CONCAT(DISTINCT " . NazwaSpolki::SQL . " ORDER BY " . NazwaSpolki::SQL . " SEPARATOR '\\n')
                       FROM employment e JOIN company c ON c.id = e.company_id
                      WHERE e.person_id = p.id) AS companies,
                    -- ⚠ Mianownik kontrolki dostępu: liczba RÓŻNYCH aktywnych spółek, nie wierszy
                    -- zatrudnienia. Osoba z dwiema umowami w tej samej spółce ma jedną spółkę,
                    -- a `COUNT(*)` pokazalby stan 1 z 2 przy pelnym dostepie.
                    (SELECT COUNT(DISTINCT e.company_id)
                       FROM employment e JOIN company c ON c.id = e.company_id
                      WHERE e.person_id = p.id AND e.is_active = 1 AND c.is_active = 1) AS etat_spolki,
                    (SELECT GROUP_CONCAT(DISTINCT " . NazwaSpolki::SQL . " ORDER BY " . NazwaSpolki::SQL . " SEPARATOR '\\n')
                       FROM employment e JOIN company c ON c.id = e.company_id
                      WHERE e.person_id = p.id AND e.is_active = 1 AND c.is_active = 1) AS etat_nazwy,
                    -- Licznik: nadane dostępy do konkretnych spółek (`company_id >= 1`).
                    (SELECT COUNT(*) FROM role_assignment ra
                      WHERE ra.account_id = a.id AND ra.company_id >= 1) AS dostepy,
                    (SELECT GROUP_CONCAT(DISTINCT " . NazwaSpolki::SQL . " ORDER BY " . NazwaSpolki::SQL . " SEPARATOR '\\n')
                       FROM role_assignment ra JOIN company c ON c.id = ra.company_id
                      WHERE ra.account_id = a.id AND ra.company_id >= 1) AS dostep_nazwy,
                    -- UWAGA: spoza kadr to OSWIADCZENIE z kolumny, a z kadr - istnienie wiazania
                    -- tozsamosci. Gdy prawdziwe sa oba (osoba dodana recznie, potem dopasowana
                    -- do R2), wygrywa wiazanie: jest nowsza i mocniejsza informacja.
                    -- (Bez polskich znakow i cudzyslowow: to wnetrze lancucha PHP w cudzyslowach.)
                    p.outside_hr AS spoza_kadr,
                    EXISTS (SELECT 1 FROM person_external_ref x WHERE x.person_id = p.id) AS z_kadr,
                    -- Administrator tenanta: `company_id = 0` znaczy wszystkie spółki, także przyszłe.
                    EXISTS (SELECT 1 FROM role_assignment ra
                             WHERE ra.account_id = a.id AND ra.company_id = 0) AS caly_tenant
             FROM person p
             LEFT JOIN account a ON a.person_id = p.id
             WHERE " . $warunek . ' AND ' . self::ZAKRES . ' AND ' . self::FILTR_SPOLKI . ' AND ' . self::SZUKANIE . '
             ORDER BY ' . self::orderSql($sort, $asc) . ', p.id
             LIMIT :limit OFFSET :offset'
        );
        $this->bindScope($stmt, $companyId, $search, $filtrSpolki);
        foreach ($extra as $nazwa => $wartosc) {
            $stmt->bindValue($nazwa, $wartosc, is_int($wartosc) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'person_id' => (int) $r['person_id'],
            // Tabela — forma „nazwisko imię" (system wizualny §9).
            'name'      => NazwaOsoby::nazwiskoImie((string) $r['last_name'], (string) $r['first_name']),
            'email'     => $r['email'] === null ? null : (string) $r['email'],
            'companies' => $r['companies'] === null ? [] : explode("\n", (string) $r['companies']),
            'employments_total'  => (int) $r['employments_total'],
            'employments_active' => (int) $r['employments_active'],
            'account_id'     => $r['account_id'] === null ? null : (int) $r['account_id'],
            'account_status' => (string) $r['stan'],
            'etat_spolki'    => (int) $r['etat_spolki'],
            'etat_nazwy'     => $r['etat_nazwy'] === null ? [] : explode("\n", (string) $r['etat_nazwy']),
            'dostepy'        => (int) $r['dostepy'],
            'dostep_nazwy'   => $r['dostep_nazwy'] === null ? [] : explode("\n", (string) $r['dostep_nazwy']),
            'caly_tenant'    => (bool) $r['caly_tenant'],
            'spoza_kadr'     => (bool) $r['spoza_kadr'],
            'z_kadr'         => (bool) $r['z_kadr'],
        ], $stmt->fetchAll());
    }

    public function countForAdmin(?int $companyId, string $search, ?int $filtrSpolki = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM person p LEFT JOIN account a ON a.person_id = p.id
             WHERE ' . self::ZAKRES . ' AND ' . self::FILTR_SPOLKI . ' AND ' . self::SZUKANIE
        );
        $this->bindScope($stmt, $companyId, $search, $filtrSpolki);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Licznik stanów do nagłówka ekranu.
     *
     * @return array<string, int>
     */
    public function stateCounters(?int $companyId, ?int $filtrSpolki = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::STAN_SQL . ' AS stan, COUNT(*) AS ile,
                    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM role_assignment ra
                        WHERE ra.account_id = a.id) THEN 1 ELSE 0 END) AS bez_dostepu
             FROM person p LEFT JOIN account a ON a.person_id = p.id
             WHERE ' . self::ZAKRES . ' AND ' . self::FILTR_SPOLKI . '
             GROUP BY stan'
        );
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->bindValue('filter_all', $filtrSpolki === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('filter', $filtrSpolki ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        // ⚠ `bez_dostepu` to NOWY kafelek z §9: „bez konta" przestało znaczyć „bez dostępu",
        // odkąd konto może istnieć w stanie `niezaproszone`. Liczymy osoby bez ANI JEDNEGO
        // przypisania — także administratorów bez roli, bo i oni nic nie widzą.
        //
        // ⚠ `osoby` liczymy TUTAJ, z sumy stanów, a nie osobnym zapytaniem: stany są rozłączne
        // (jedna osoba = jeden stan), więc suma jest liczbą osób w tym samym zbiorze,
        // który opisują pozostałe kafelki. Dzięki temu rząd kafelków opisuje JEDEN zbiór
        // i nie rośnie liczba zapytań na stronę.
        $out = ['osoby' => 0, 'brak' => 0, 'niezaproszone' => 0, 'invited' => 0, 'active' => 0,
            'blocked' => 0, 'do_odblokowania' => 0, 'bez_dostepu' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['stan']] = (int) $row['ile'];
            $out['bez_dostepu'] += (int) $row['bez_dostepu'];
            $out['osoby'] += (int) $row['ile'];
        }

        return $out;
    }

    /**
     * Jeden wiersz listy — po zapisie adresu przez htmx (fragment renderuje ten sam szablon
     * co wiersz w tabeli, więc nie ma dwóch prawd o wyglądzie wiersza).
     *
     * ⚠ Zakres w zapytaniu, nie w `if` po pobraniu (CLAUDE.md §10): `null` znaczy, że osoby
     * spoza zakresu administratora po prostu nie ma, a nie że jest i jej nie pokazujemy.
     *
     * @return OsobaRow|null
     */
    public function rowForAdmin(int $personId, ?int $companyId): ?array
    {
        $rows = $this->listaZWarunkiem('p.id = :person', ['person' => $personId], $companyId, '', 'nazwisko', true, 1, 0);

        return $rows[0] ?? null;
    }

    /**
     * Dane potrzebne do wysłania partii zaproszeń — jedno zapytanie na całą partię.
     *
     * ⚠ Zwraca **tylko osoby w zakresie administratora**. Identyfikatory przychodzą z formularza,
     * czyli od użytkownika, więc osoba spoza zakresu nie może się tu pojawić nawet wtedy, gdy ktoś
     * podmieni `value` w polu wyboru (IDOR, CLAUDE.md §10). Osoba nieobecna w wyniku jest
     * pomijana jako spoza zakresu — bez ujawniania, czy w ogóle istnieje.
     *
     * `spolki` to spółki z **aktywnego** zatrudnienia: dokładnie te, do których przełącznik
     * z paska zaznaczania nadaje dostęp.
     *
     * ⚠ Zwracamy **stan konta**, nie flagę „ma konto": od §4 istnienie konta nie przesądza,
     * czy zaproszenie wolno wysłać — konto `niezaproszone` zaproszenie właśnie **przestawia**.
     *
     * @param list<int> $personIds
     * @return array<int, array{person_id: int, name: string, email: ?string, konto_status: ?string, spolki: list<int>}>
     */
    public function forInvitation(array $personIds, ?int $companyId): array
    {
        if ($personIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id AS person_id, p.first_name, p.last_name, p.email_private,
                    a.id AS account_id, a.status AS konto_status,
                    (SELECT GROUP_CONCAT(DISTINCT e.company_id)
                       FROM employment e JOIN company c ON c.id = e.company_id
                      WHERE e.person_id = p.id AND e.is_active = 1 AND c.is_active = 1) AS spolki
             FROM person p
             LEFT JOIN account a ON a.person_id = p.id
             WHERE p.id IN (' . self::nazwanePlaceholdery($personIds) . ') AND ' . self::ZAKRES
        );
        // ⚠ Placeholdery NAZWANE, nie `?`: w tym zapytaniu są już `:company_all` i `:company`,
        // a PDO nie pozwala mieszać nazwanych z pozycyjnymi w jednym zapytaniu (zmierzone:
        // SQLSTATE[HY093] „mixed named and positional parameters").
        foreach (array_values($personIds) as $i => $id) {
            $stmt->bindValue('p' . $i, $id, PDO::PARAM_INT);
        }
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $spolki = $row['spolki'] === null ? [] : explode(',', (string) $row['spolki']);
            $out[(int) $row['person_id']] = [
                'person_id' => (int) $row['person_id'],
                'name'      => NazwaOsoby::nazwiskoImie((string) $row['last_name'], (string) $row['first_name']),
                'email'     => $row['email_private'] === null ? null : (string) $row['email_private'],
                'konto_status' => $row['account_id'] === null ? null : (string) $row['konto_status'],
                'spolki'    => array_map(static fn (string $v): int => (int) $v, $spolki),
            ];
        }

        return $out;
    }

    /**
     * Dane z kadr do panelu osoby — **tylko do odczytu** (etap 3 zlecenia `konta-budowa-2`).
     *
     * ⚠ Jedno zapytanie na wszystkie zatrudnienia osoby, razem z limitem urlopu na bieżący rok.
     * Limit jest w MINUTACH (`leave_balance.entitlement_min`) i tak zostaje — na dni przelicza
     * warstwa widoku, bo długość dnia pracy bierze się z etatu, nie z bazy.
     *
     * @return list<array{company: string, nip: string, fte_numerator: int, fte_denominator: int,
     *     hired_at: ?string, terminated_at: ?string, aktywne: bool, limit_min: ?int, limit_rok: int}>
     */
    public function panelEmployments(int $personId, int $rok): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . NazwaSpolki::SQL . ' AS company, c.nip,
                    e.fte_numerator, e.fte_denominator, e.hired_at, e.terminated_at, e.is_active,
                    (SELECT SUM(lb.entitlement_min) FROM leave_balance lb
                      WHERE lb.employment_id = e.id AND lb.year = :rok AND lb.kind = :kind) AS limit_min
             FROM employment e JOIN company c ON c.id = e.company_id
             WHERE e.person_id = :person
             ORDER BY e.is_active DESC, ' . NazwaSpolki::SQL . ', e.id'
        );
        // ⚠ `wypoczynkowy` to rodzaj limitu, który klient nazywa „urlopem" — pozostałe
        // (np. `opieka_art188`) mają własne reguły i nie sumują się z nim.
        $stmt->execute(['person' => $personId, 'rok' => $rok, 'kind' => 'wypoczynkowy']);

        return array_map(static fn (array $r): array => [
            'company'         => (string) $r['company'],
            'nip'             => (string) $r['nip'],
            'fte_numerator'   => (int) $r['fte_numerator'],
            'fte_denominator' => (int) $r['fte_denominator'],
            'hired_at'        => $r['hired_at'] === null ? null : (string) $r['hired_at'],
            'terminated_at'   => $r['terminated_at'] === null ? null : (string) $r['terminated_at'],
            'aktywne'         => (bool) $r['is_active'],
            'limit_min'       => $r['limit_min'] === null ? null : (int) $r['limit_min'],
            'limit_rok'       => $rok,
        ], $stmt->fetchAll());
    }

    /**
     * Nadany dostęp jako pary rola + spółka — tak, jak stoi w bazie.
     *
     * ⚠ W bazie rola i spółka są JEDNYM wierszem (`role_assignment`), więc osoba w dwóch
     * spółkach ma dwa wiersze. Panel pokazuje to wprost, bo inaczej „rola" wyglądałaby
     * na własność osoby, a jest własnością pary osoba–spółka.
     *
     * @return list<array{rola: string, spolka: ?string, company_id: int}>
     */
    public function accessRows(int $personId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.name AS rola, ra.company_id, ' . NazwaSpolki::SQL . ' AS spolka
             FROM role_assignment ra
             JOIN account a ON a.id = ra.account_id
             JOIN role r ON r.id = ra.role_id
             LEFT JOIN company c ON c.id = ra.company_id
             WHERE a.person_id = :person
             ORDER BY ra.company_id, r.name'
        );
        $stmt->execute(['person' => $personId]);

        return array_map(static fn (array $r): array => [
            'rola'       => (string) $r['rola'],
            'company_id' => (int) $r['company_id'],
            'spolka'     => $r['spolka'] === null ? null : (string) $r['spolka'],
        ], $stmt->fetchAll());
    }

    /**
     * Dane osoby i jej konta do panelu.
     *
     * ⚠ `z_kadr` to **istnienie wiązania tożsamości** (`person_external_ref`), a nie brak
     * czegokolwiek: tylko ono dowodzi, że osoba przyszła z programu kadrowego. Odwrotnej
     * informacji („spoza kadr") stąd NIE wyprowadzam — to ma być własna kolumna (etap 4),
     * bo brak wiersza w innej tabeli nie jest oświadczeniem.
     *
     * @return array{person_id: int, name: string, phone: ?string, email_kontaktowy: ?string,
     *     z_kadr: bool, spoza_kadr: bool, account_id: ?int, account_email: ?string, account_status: ?string,
     *     last_login_at: ?string, totp: bool, sesje: int, powod_blokady: ?string,
     *     blokada_od: ?string}|null
     */
    public function panelPerson(int $personId, ?int $companyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.first_name, p.last_name, p.phone, p.email_private,
                    EXISTS (SELECT 1 FROM person_external_ref x WHERE x.person_id = p.id) AS z_kadr,
                    p.outside_hr AS spoza_kadr,
                    a.id AS account_id, a.email AS account_email, a.status, a.last_login_at,
                    a.totp_enabled_at, a.blocked_reason, a.blocked_at,
                    -- ⚠ Ten sam warunek co `SessionRepository::activeForAccount()`: liczba w panelu
                    -- musi zgadzać się z listą urządzeń, bo inaczej dwa ekrany mówią o tym samym
                    -- koncie dwie różne rzeczy.
                    (SELECT COUNT(*) FROM session s
                      WHERE s.account_id = a.id AND s.revoked_at IS NULL
                        AND s.expires_at > UTC_TIMESTAMP()) AS sesje
             FROM person p
             LEFT JOIN account a ON a.person_id = p.id
             WHERE p.id = :person AND ' . self::ZAKRES
        );
        $stmt->bindValue('person', $personId, PDO::PARAM_INT);
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        $r = $stmt->fetch();
        if (!is_array($r)) {
            return null;
        }

        return [
            'person_id'        => (int) $r['id'],
            'name'             => NazwaOsoby::imieNazwisko((string) $r['first_name'], (string) $r['last_name']),
            'phone'            => $r['phone'] === null ? null : (string) $r['phone'],
            'email_kontaktowy' => $r['email_private'] === null ? null : (string) $r['email_private'],
            'z_kadr'           => (bool) $r['z_kadr'],
            'spoza_kadr'       => (bool) $r['spoza_kadr'],
            'account_id'       => $r['account_id'] === null ? null : (int) $r['account_id'],
            'account_email'    => $r['account_email'] === null ? null : (string) $r['account_email'],
            'account_status'   => $r['status'] === null ? null : (string) $r['status'],
            'last_login_at'    => $r['last_login_at'] === null ? null : (string) $r['last_login_at'],
            'totp'             => $r['totp_enabled_at'] !== null,
            'powod_blokady'    => $r['blocked_reason'] === null ? null : (string) $r['blocked_reason'],
            'blokada_od'       => $r['blocked_at'] === null ? null : (string) $r['blocked_at'],
            'sesje'            => (int) $r['sesje'],
        ];
    }

    /**
     * Zapis danych kontaktowych z panelu: adres i telefon.
     *
     * ⚠ Telefon nie ma kolumny „źródło" i jej nie dokładam: `person.phone` nie jest zapisywany
     * przez nic poza tym miejscem (sprawdzone — kolumna nie występuje w żadnym innym zapytaniu
     * w `src/`), więc jedynym możliwym źródłem numeru jest administrator. Kolumna będzie
     * potrzebna w dniu, w którym numer zacznie przychodzić z R2 — nie wcześniej.
     */
    public function updateContact(int $personId, ?string $email, ?string $phone, ?int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE person p SET p.email_private = :email, p.phone = :phone
             WHERE p.id = :person AND ' . self::ZAKRES
        );
        $stmt->bindValue('email', $email);
        $stmt->bindValue('phone', $phone);
        $stmt->bindValue('person', $personId, PDO::PARAM_INT);
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowForAdmin($personId, $companyId) !== null;
    }

    /**
     * Spółki do kontrolki dostępu: **wszystkie aktywne spółki tenanta** z dwiema flagami —
     * czy osoba ma tam aktywne zatrudnienie i czy ma nadany dostęp.
     *
     * ⚠ Jedno zapytanie, nie jedno na spółkę. I ⚠ zawsze WSZYSTKIE spółki, nie tylko te
     * z etatem: rozwinięta kontrolka ma pokazywać także spółki, do których nadać dostępu
     * NIE MOŻNA, z podanym powodem. Lista, na której brakuje spółki, wygląda jak usterka;
     * lista z wygaszonym wierszem i dopiskiem „bez etatu" tłumaczy regułę bez instrukcji.
     *
     * @return list<array{company_id: int, name: string, ma_etat: bool, ma_dostep: bool}>
     */
    public function companiesForAccess(int $personId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id AS company_id, ' . NazwaSpolki::SQL . ' AS name,
                    EXISTS (SELECT 1 FROM employment e
                             WHERE e.person_id = :person AND e.company_id = c.id AND e.is_active = 1) AS ma_etat,
                    EXISTS (SELECT 1 FROM role_assignment ra
                              JOIN account a ON a.id = ra.account_id
                             WHERE a.person_id = :person2 AND ra.company_id = c.id) AS ma_dostep
             FROM company c
             WHERE c.is_active = 1
             ORDER BY ' . NazwaSpolki::SQL . ', c.id'
        );
        $stmt->execute(['person' => $personId, 'person2' => $personId]);

        return array_map(static fn (array $r): array => [
            'company_id' => (int) $r['company_id'],
            'name'       => (string) $r['name'],
            'ma_etat'    => (bool) $r['ma_etat'],
            'ma_dostep'  => (bool) $r['ma_dostep'],
        ], $stmt->fetchAll());
    }

    /** Spółki do filtra listy — biała lista wartości, nie tekst z URL-a (§7 zlecenia). */
    public function companiesForFilter(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT c.id, ' . NazwaSpolki::SQL . ' AS name FROM company c
             WHERE c.is_active = 1 ORDER BY ' . NazwaSpolki::SQL . ', c.id'
        );

        return array_map(static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll());
    }

    /**
     * Adres kontaktowy wpisany przez administratora.
     *
     * ⚠ Piszemy do `person.email_private`, kolumny, której **nic dotąd nie zapisywało** — czytały
     * ją tylko lista i wyszukiwanie. Synchronizacja z R2 jej nie dotyka (connector nie przesyła
     * adresów), więc nie ma tu konfliktu z danymi kadrowymi: to jest nasz adres kontaktowy,
     * a nie pole przepisane z programu kadrowego. Po powstaniu konta adres logowania stoi
     * w `account.email` i on wygrywa na liście.
     *
     * @return bool czy wiersz istniał w zakresie administratora
     */
    public function updateContactEmail(int $personId, ?string $email, ?int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE person p SET p.email_private = :email WHERE p.id = :person AND ' . self::ZAKRES
        );
        $stmt->bindValue('email', $email);
        $stmt->bindValue('person', $personId, PDO::PARAM_INT);
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        // ⚠ `rowCount()` nie wystarcza: zapis tej samej wartości daje 0 zmienionych wierszy,
        // a to nie jest „osoby nie ma". Dlatego istnienie sprawdzamy osobno, tym samym zakresem.
        return $this->rowForAdmin($personId, $companyId) !== null;
    }

    private function bindScope(\PDOStatement $stmt, ?int $companyId, string $search, ?int $filtrSpolki = null): void
    {
        $like = '%' . self::escapeLike($search) . '%';
        $stmt->bindValue('company_all', $companyId === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId ?? 0, PDO::PARAM_INT);
        $stmt->bindValue('filter_all', $filtrSpolki === null ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('filter', $filtrSpolki ?? 0, PDO::PARAM_INT);
        $stmt->bindValue('search', $search);
        foreach (['like', 'like2', 'like3', 'like4'] as $name) {
            $stmt->bindValue($name, $like);
        }
    }

    /**
     * Nazwy placeholderów `:p0, :p1…` dla `IN (...)` — generowane z liczby elementów,
     * nigdy z danych.
     *
     * @param list<int> $values
     */
    private static function nazwanePlaceholdery(array $values): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':p' . $i, array_keys(array_values($values))));
    }

    /** `LIKE` z wejścia użytkownika: `%` i `_` tracą znaczenie wzorca. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * `ORDER BY` z białej listy, z kierunkiem dołożonym do KAŻDEJ kolumny.
     *
     * ⚠ Kolumny pochodzą wyłącznie ze stałej `SORT`, a nieznany klucz spada na „nazwisko" —
     * do zapytania nie trafia ani jeden znak z wejścia użytkownika (CLAUDE.md §4).
     */
    private static function orderSql(string $sort, bool $asc): string
    {
        $kolumny = self::SORT[$sort] ?? self::SORT['nazwisko'];
        $kierunek = $asc ? ' ASC' : ' DESC';
        $czesci = array_map(static fn (string $k): string => $k . $kierunek, $kolumny);
        $przed = self::SORT_PRZED[$sort] ?? null;

        return ($przed === null ? '' : $przed . ', ') . implode(', ', $czesci);
    }
}
