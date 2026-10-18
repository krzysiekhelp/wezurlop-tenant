<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Hierarchia przełożonych — odczyt struktury jednej spółki i zapis pojedynczej krawędzi.
 *
 * ⚠ **Struktura wisi na ZATRUDNIENIU, nie na człowieku.** `employment.supervisor_employment_id`,
 * a zatrudnienie jest osobne dla każdej spółki: osoba pracująca w dwóch spółkach stoi w dwóch
 * drzewach niezależnie i może być kierownikiem w jednej, a szeregowym pracownikiem w drugiej.
 * Dlatego każde zapytanie tutaj jest **per spółka** i nie istnieje metoda „daj hierarchię osoby".
 *
 * ⚠ **Odchylenia liczymy z STANU FAKTYCZNEGO**, nie z pola zapamiętanego przy zapisie
 * hierarchii (zlecenie §7): rola i blokada konta zmieniają się na innych ekranach i przez
 * synchronizację z R2, więc wartość zapamiętana tutaj rozjechałaby się w tydzień.
 */
final class HierarchyRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Spółki do przełącznika — tylko czynne, z liczbą zatrudnień.
     *
     * @return list<array{id: int, nazwa: string, zatrudnionych: int}>
     */
    public function spolki(): array
    {
        // ⚠ Wyrażenie ze STAŁEJ, nie przepisane z pamięci: to drugie miejsce z tą samą regułą
        // rozjechałoby się przy pierwszej zmianie — po to `NazwaSpolki` istnieje.
        $rows = $this->db->pdo()->query(
            'SELECT c.id, ' . NazwaSpolki::SQL . ' AS nazwa,
                    COUNT(e.id) AS zatrudnionych
             FROM company c
             LEFT JOIN employment e ON e.company_id = c.id AND e.is_active = 1
             WHERE c.is_active = 1
             GROUP BY c.id, nazwa
             ORDER BY nazwa'
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'nazwa'         => (string) $r['nazwa'],
            'zatrudnionych' => (int) $r['zatrudnionych'],
        ], $rows);
    }

    /**
     * Wszystkie czynne zatrudnienia spółki z tym, co ekran ma pokazać.
     *
     * ⚠ `druga_spolka`: osoba pracująca także gdzie indziej. Bez tego administrator nie wie,
     * że ta sama osoba stoi w drugim drzewie, i szuka jej tam, gdzie jej nie ma.
     *
     * ⚠ Nazwy stanowiska **nie ma skąd wziąć** — R2 jej nie przysyła (`ksztalt-danych-r2.md`),
     * a my jej nie trzymamy. Zamiast wymyślonego stanowiska podajemy fakty, które mamy:
     * numer pracownika, datę zatrudnienia i drugą spółkę.
     *
     * @return list<array{
     *     id: int, person_id: int, osoba: string, numer: ?string, hired_at: ?string,
     *     supervisor_id: ?int, szczyt: bool, konto_status: ?string, druga_spolka: ?string
     * }>
     */
    public function zatrudnienia(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.person_id, e.employee_number, e.hired_at,
                    e.supervisor_employment_id, e.is_hierarchy_root,
                    p.first_name, p.last_name, a.status AS konto_status,
                    (SELECT " . NazwaSpolki::sqlDla('c2') . "
                       FROM employment e2
                       JOIN company c2 ON c2.id = e2.company_id
                      WHERE e2.person_id = e.person_id AND e2.company_id <> e.company_id
                        AND e2.is_active = 1
                      ORDER BY c2.id LIMIT 1) AS druga_spolka
             FROM employment e
             JOIN person p ON p.id = e.person_id
             LEFT JOIN account a ON a.person_id = e.person_id
             WHERE e.company_id = :company AND e.is_active = 1
             ORDER BY p.last_name, p.first_name, e.id"
        );
        $stmt->execute(['company' => $companyId]);

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'person_id'     => (int) $r['person_id'],
            // ⚠ **Nazwa przez `NazwaOsoby`, a nie surowa z bazy** (zlecenie Z34): program kadrowy
            // przysyła nazwiska WERSALIKAMI, a karta hierarchii pokazuje je obok siebie w drzewie,
            // gdzie wersaliki czyta się dwa razy wolniej i krzyczą z każdego poziomu. Zamiana robi
            // się na serwerze, raz, a nie `text-transform` w arkuszu — bo to **dana**, nie wygląd:
            // tak samo trafia do `title`, do wyszukiwania w panelu i do paska zmian.
            // ⚠ `doWyswietlenia()` zostawia nietknięte nazwiska zapisane świadomie małą literą
            // („von Neumann") — nie jest to zwykłe `ucfirst` (patrz komentarz w tej klasie).
            'osoba'         => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']),
            'numer'         => $r['employee_number'] === null ? null : (string) $r['employee_number'],
            'hired_at'      => $r['hired_at'] === null ? null : (string) $r['hired_at'],
            'supervisor_id' => $r['supervisor_employment_id'] === null
                ? null
                : (int) $r['supervisor_employment_id'],
            'szczyt'        => (bool) $r['is_hierarchy_root'],
            'konto_status'  => $r['konto_status'] === null ? null : (string) $r['konto_status'],
            'druga_spolka'  => $r['druga_spolka'] === null ? null : (string) $r['druga_spolka'],
        ], $stmt->fetchAll());
    }

    /**
     * Lekka lista kandydatów na przełożonego — **z wyszukiwaniem po stronie serwera**.
     *
     * ⚠ Osobne, wąskie zapytanie zamiast pełnego `zatrudnienia()`: okno „Zmień przełożonego"
     * ważyło **44 KB przy 36 osobach** (zmierzone), bo wypisywało wszystkich ze wszystkimi
     * polami karty. Przy stuosobowej firmie taka lista przestaje być kontrolką, a rośnie
     * z każdym nowym pracownikiem — więc zawężanie musi dziać się **w zapytaniu**, nie
     * w przeglądarce.
     *
     * ⚠ Wyszukiwanie po nazwisku i imieniu, przez `LIKE` z placeholderem (`CLAUDE.md` §4) —
     * wzorzec budowany z `%` po stronie PHP, nigdy przez konkatenację w SQL.
     *
     * @param int $limit twardy limit wierszy; `$wiecej` w wyniku mówi, czy coś zostało ucięte
     * @return array{osoby: list<array{id: int, osoba: string}>, razem: int}
     */
    public function kandydaci(int $companyId, string $szukaj, int $limit): array
    {
        // ⚠ Cztery PEŁNE literały zamiast składania zapytania z fragmentów. Poprzednia wersja
        // doklejała warunek i `LIMIT` przez `.` — bramka `check-rules` zgłosiła to jako
        // konkatenację w SQL i miała rację: reguła z §4 nie brzmi „nie doklejaj DANYCH",
        // brzmi „zero konkatenacji", bo dokładnie tam wchodzi pierwsza pomyłka. Limit leci
        // placeholderem, co działa, bo mamy prawdziwe zapytania przygotowane
        // (`ATTR_EMULATE_PREPARES => false`).
        $zeSzukaniem = $szukaj !== '';
        $sqlIle = $zeSzukaniem
            ? "SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.is_active = 1
                  AND CONCAT(p.last_name, ' ', p.first_name) LIKE :szukaj"
            : 'SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.is_active = 1';
        $sqlLista = $zeSzukaniem
            ? "SELECT e.id, p.first_name, p.last_name
                 FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.is_active = 1
                  AND CONCAT(p.last_name, ' ', p.first_name) LIKE :szukaj
                ORDER BY p.last_name, p.first_name, e.id
                LIMIT :limit"
            : 'SELECT e.id, p.first_name, p.last_name
                 FROM employment e JOIN person p ON p.id = e.person_id
                WHERE e.company_id = :company AND e.is_active = 1
                ORDER BY p.last_name, p.first_name, e.id
                LIMIT :limit';

        $ile = $this->db->pdo()->prepare($sqlIle);
        $ile->bindValue('company', $companyId, \PDO::PARAM_INT);
        if ($zeSzukaniem) {
            $ile->bindValue('szukaj', '%' . $szukaj . '%');
        }
        $ile->execute();
        $razem = (int) $ile->fetchColumn();

        $stmt = $this->db->pdo()->prepare($sqlLista);
        $stmt->bindValue('company', $companyId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        if ($zeSzukaniem) {
            $stmt->bindValue('szukaj', '%' . $szukaj . '%');
        }
        $stmt->execute();

        return [
            'osoby' => array_map(static fn (array $r): array => [
                'id'    => (int) $r['id'],
                'osoba' => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                    . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']),
            ], $stmt->fetchAll()),
            'razem' => $razem,
        ];
    }

    /**
     * Zatrudnienie do zapisu — z blokadą wiersza.
     *
     * ⚠ `FOR UPDATE`: dwóch administratorów przestawiających tę samą gałąź w tej samej sekundzie
     * mogłoby ułożyć cykl, którego żaden z nich osobno nie widział (każdy sprawdzał łańcuch
     * sprzed zmiany drugiego).
     *
     * @return array{id: int, company_id: int, supervisor_id: ?int, szczyt: bool, aktywne: bool}|null
     */
    public function doZapisu(int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_id, supervisor_employment_id, is_hierarchy_root, is_active
             FROM employment WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $employmentId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'company_id'    => (int) $row['company_id'],
            'supervisor_id' => $row['supervisor_employment_id'] === null
                ? null
                : (int) $row['supervisor_employment_id'],
            'szczyt'        => (bool) $row['is_hierarchy_root'],
            'aktywne'       => (bool) $row['is_active'],
        ];
    }

    /**
     * Krawędzie hierarchii spółki: `id zatrudnienia => id przełożonego`.
     *
     * Do sprawdzenia cyklu w górę łańcucha — jedno zapytanie zamiast zapytania na każdy szczebel.
     *
     * @return array<int, int>
     */
    public function krawedzie(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supervisor_employment_id FROM employment
             WHERE company_id = :company AND supervisor_employment_id IS NOT NULL'
        );
        $stmt->execute(['company' => $companyId]);
        $mapa = [];
        foreach ($stmt->fetchAll() as $r) {
            $mapa[(int) $r['id']] = (int) $r['supervisor_employment_id'];
        }

        return $mapa;
    }

    /** Zapis jednej krawędzi. Walidację robi serwis — tutaj jest już decyzja, nie pytanie. */
    public function ustaw(int $employmentId, ?int $supervisorId, bool $szczyt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE employment
                SET supervisor_employment_id = :supervisor, is_hierarchy_root = :szczyt
              WHERE id = :id'
        );
        $stmt->bindValue('supervisor', $supervisorId, $supervisorId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue('szczyt', $szczyt ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue('id', $employmentId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Wykrywacz niespójności zamiast ograniczenia `CHECK` — do `/diagnostyka`.
     *
     * ⚠ Istnieje, bo `CHECK (is_hierarchy_root = 0 OR supervisor_employment_id IS NULL)`
     * **nie da się założyć** na tej kolumnie: MySQL odmawia ograniczenia `CHECK` na kolumnie
     * używanej w kluczu obcym z akcją referencyjną (`3823`, zmierzone na 8.4.11 — szczegóły
     * w migracji `20260922120001`). Warunek niepilnowany przez bazę musi mieć coś, co go
     * **mierzy**, a nie tylko kod, który obiecuje go nie łamać.
     */
    public function niespojnosciSzczytu(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM employment
             WHERE is_hierarchy_root = 1 AND supervisor_employment_id IS NOT NULL'
        )->fetchColumn();
    }

    /**
     * Czy katalog uprawnień zna podane uprawnienie.
     *
     * ⚠ Potrzebne do odchylenia „ma podwładnych, nie ma roli przełożonego" (§7). Uprawnień
     * urlopowych **jeszcze nie ma** — przychodzą z Etapem 3 (E3/E4), którego to zlecenie
     * świadomie nie dotyczy (§9). Gdybyśmy liczyli to odchylenie już teraz, zapaliłoby się
     * przy **każdym** przełożonym, bo nikt nie ma uprawnienia, które nie istnieje — czyli
     * lista odchyleń byłaby w całości szumem od pierwszego dnia. Dokładnie to, przed czym
     * przestrzega §5 zlecenia.
     */
    public function uprawnienieIstnieje(string $kod): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM permission WHERE code = :kod LIMIT 1');
        $stmt->execute(['kod' => $kod]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zatrudnienia, których osoba ma uprawnienie zatwierdzania w tej spółce.
     *
     * Zwraca identyfikatory zatrudnień — czyli dokładnie to, czym są węzły drzewa.
     *
     * @return list<int>
     */
    public function zPrawemZatwierdzania(int $companyId, string $kod): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT e.id
             FROM employment e
             JOIN account a ON a.person_id = e.person_id
             JOIN role_assignment ra ON ra.account_id = a.id AND ra.company_id = e.company_id
             JOIN role_permission rp ON rp.role_id = ra.role_id
             JOIN permission p ON p.id = rp.permission_id
             WHERE e.company_id = :company AND e.is_active = 1 AND p.code = :kod'
        );
        $stmt->execute(['company' => $companyId, 'kod' => $kod]);

        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }
}
