<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use PDOStatement;
use Wezurlop\Db\Connection;
use Wezurlop\Service\ZakresWidocznosci;
use Wezurlop\Support\NazwaOsoby;

/**
 * Reguły dodatkowego wglądu w kalendarz i ustawienie zakresu dla przełożonych (zlecenie Z53).
 *
 * ⚠ **Granica firmy jest w KAŻDYM zapytaniu, nie w warunku po pobraniu** (CLAUDE.md §10). Reguła
 * z ALFY nie ma prawa pojawić się w wyniku pytania o BETĘ, nawet gdy ktoś poda jej identyfikator
 * wprost — dlatego `company_id` stoi w `WHERE`, a nie w `if`-ie w serwisie.
 *
 * ⚠ **Zero konkatenacji wartości do SQL-a**: listy identyfikatorów idą przez wygenerowane
 * placeholdery (`:c0, :c1, …`), tak samo jak w `GroupRepository` i `PracownikRepository`.
 *
 * ⚠ **Ten sam `:nazwa` nie powtarza się w jednym zapytaniu.** PDO bez emulacji przygotowań
 * (`ATTR_EMULATE_PREPARES => false`, CLAUDE.md §4) odrzuca drugie wystąpienie tego samego
 * placeholdera błędem HY093 — stąd `:konto_a` / `:konto_b` tam, gdzie wartość jest potrzebna
 * dwa razy.
 */
final class CalendarAccessRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * **Reguły obowiązujące WIDZA** — te, w których jest po stronie „kto widzi": wskazany
     * z imienia (konto) albo przez grupę, do której należy jego zatrudnienie w tej firmie.
     *
     * ⚠ Przynależność liczy się **tu i teraz**, zapytaniem — nie z listy zapisanej przy regule.
     * Osoba dopisana do grupy jutro jest objęta regułą jutro, bez dotykania reguły (§1 zlecenia).
     *
     * @return list<array{id: int, scope: string, target_kind: string, grupy: list<int>}>
     */
    public function regulyWidza(int $companyId, int $accountId, ?int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            // ⚠ `subject_group_id` jest tu po to, żeby panel osoby mógł powiedzieć, PRZEZ KTÓRĄ
            // grupę ta osoba widzi (zlecenie Z58, karta „Widoczność kalendarza"). Reguła wybrana
            // zapytaniem nie mówi sama, które z pięciu dopasowań ją wpuściło.
            'SELECT r.id, r.scope, r.target_kind, r.subject_kind, r.subject_group_id
               FROM calendar_access_rule r
              WHERE r.company_id = :company
                AND ( (r.subject_kind = :kto_konto AND r.subject_account_id = :konto)
                   OR (r.subject_kind = :kto_przelozony AND r.subject_employment_id = :etat_a)
                   OR (r.subject_kind = :kto_przelozeni)
                   OR (r.subject_kind = :kto_admini)
                   OR (r.subject_kind = :kto_grupa AND r.subject_group_id IN (
                         SELECT eg.group_id FROM employment_group eg
                          WHERE eg.employment_id = :etat_b)) )
              ORDER BY r.id'
        );
        $stmt->execute([
            'company' => $companyId,
            'kto_konto' => ZakresWidocznosci::KTO_KONTO,
            'konto' => $accountId,
            'kto_przelozony' => ZakresWidocznosci::KTO_PRZELOZONY,
            'kto_przelozeni' => ZakresWidocznosci::KTO_PRZELOZENI,
            // ⚠ Reguła administratorów wchodzi do zbioru ZAWSZE, a odsiewa ją dopiero
            // `dlaOsoby()` — po uprawnieniu widza, nie po zapytaniu. Dzięki temu „kto jest
            // administratorem" rozstrzyga jedno miejsce, a nie dwa (zapytanie i funkcja).
            'kto_admini' => ZakresWidocznosci::KTO_ADMINI,
            'kto_grupa' => ZakresWidocznosci::KTO_GRUPA,
            // ⚠ `0` zamiast `null`: konto bez zatrudnienia w tej firmie nie należy do żadnej
            // grupy i nie jest niczyim przełożonym; `0` nie trafi w żaden prawdziwy wiersz.
            // ⚠ Dwa różne placeholdery na tę samą wartość — PDO bez emulacji odrzuca drugie
            // wystąpienie tej samej nazwy (HY093).
            'etat_a' => $employmentId ?? 0,
            'etat_b' => $employmentId ?? 0,
        ]);

        return $this->zGrupami($stmt->fetchAll());
    }

    /**
     * Wszystkie reguły firmy — do ekranu i do kafli.
     *
     * @return list<array{id: int, scope: string, target_kind: string, grupy: list<int>,
     *     subject_kind: string, subject_account_id: int, subject_group_id: int}>
     */
    public function reguly(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.scope, r.target_kind, r.subject_kind, r.subject_account_id,
                    r.subject_employment_id, r.subject_group_id
               FROM calendar_access_rule r
              WHERE r.company_id = :company
              ORDER BY r.subject_kind, r.id'
        );
        $stmt->execute(['company' => $companyId]);

        return $this->zGrupami($stmt->fetchAll());
    }

    /** Jedna reguła tej firmy — albo `null`, gdy cudza lub nieistniejąca (jedna odpowiedź na oba). */
    public function regula(int $ruleId, int $companyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.scope, r.target_kind, r.subject_kind, r.subject_account_id,
                    r.subject_employment_id, r.subject_group_id
               FROM calendar_access_rule r
              WHERE r.id = :id AND r.company_id = :company'
        );
        $stmt->execute(['id' => $ruleId, 'company' => $companyId]);
        $wiersz = $stmt->fetch();

        return is_array($wiersz) ? ($this->zGrupami([$wiersz])[0] ?? null) : null;
    }

    /**
     * Dołożenie list grup do wierszy reguł — **jedno zapytanie na komplet**, nie jedno na regułę.
     *
     * @param list<array<string, mixed>> $wiersze
     * @return list<array<string, mixed>>
     */
    private function zGrupami(array $wiersze): array
    {
        if ($wiersze === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $wiersze);
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_id, group_id FROM calendar_access_rule_group
              WHERE rule_id IN (' . self::inSql($ids, 'r') . ') ORDER BY group_id'
        );
        $stmt->execute(self::inParams($ids, 'r'));
        $grupy = [];
        foreach ($stmt->fetchAll() as $g) {
            $grupy[(int) $g['rule_id']][] = (int) $g['group_id'];
        }
        // ⚠ Osoby celu jadą w TYM SAMYM polu `grupy`, bo reguła ma dokładnie jeden rodzaj celu
        // i dwa pola oznaczałyby stan, w którym oba są pełne. Rodzaj celu mówi `target_kind`.
        $osoby = $this->db->pdo()->prepare(
            'SELECT rule_id, employment_id FROM calendar_access_rule_person
              WHERE rule_id IN (' . self::inSql($ids, 'r') . ') ORDER BY employment_id'
        );
        $osoby->execute(self::inParams($ids, 'r'));
        foreach ($osoby->fetchAll() as $o) {
            $grupy[(int) $o['rule_id']][] = (int) $o['employment_id'];
        }

        return array_map(static function (array $r) use ($grupy): array {
            $out = [
                'id' => (int) $r['id'],
                'scope' => (string) $r['scope'],
                'target_kind' => (string) $r['target_kind'],
                'grupy' => $grupy[(int) $r['id']] ?? [],
            ];
            foreach (['subject_kind', 'subject_account_id', 'subject_employment_id', 'subject_group_id'] as $pole) {
                if (array_key_exists($pole, $r)) {
                    $out[$pole] = $pole === 'subject_kind' ? (string) $r[$pole] : (int) $r[$pole];
                }
            }

            return $out;
        }, $wiersze);
    }

    /**
     * Zapis reguły — **jedna transakcja, podpis pilnowany kluczem unikalnym**.
     *
     * ⚠ Zwraca `null`, gdy reguła o tym samym podpisie już w tej firmie jest. Nie sprawdzamy
     * tego osobnym `SELECT`-em przed zapisem: dwa równoległe żądania policzyłyby wtedy ten sam
     * stan i oba przeszłyby. Rozstrzyga klucz unikalny, czyli baza.
     *
     * @param list<int> $grupyCelu
     * @return int|null identyfikator nowej reguły albo `null` przy duplikacie
     */
    public function dodaj(
        int $companyId,
        string $ktoRodzaj,
        int $ktoId,
        string $czyjeRodzaj,
        array $grupyCelu,
        string $zakres,
        ?int $przez
    ): ?int {
        $grupyCelu = ZakresWidocznosci::uporzadkuj($grupyCelu);
        $podpis = ZakresWidocznosci::podpis($ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres);

        return $this->db->transaction(function () use (
            $companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, $przez, $podpis
        ): ?int {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO calendar_access_rule
                    (company_id, subject_kind, subject_account_id, subject_employment_id,
                     subject_group_id, target_kind, scope, signature, created_at, created_by)
                 VALUES (:company, :kto, :konto, :etat, :grupa, :cel, :zakres, :podpis, NOW(), :przez)'
            );
            $stmt->execute([
                'company' => $companyId,
                'kto' => $ktoRodzaj,
                'konto' => $ktoRodzaj === ZakresWidocznosci::KTO_KONTO ? $ktoId : 0,
                'etat' => $ktoRodzaj === ZakresWidocznosci::KTO_PRZELOZONY ? $ktoId : 0,
                'grupa' => $ktoRodzaj === ZakresWidocznosci::KTO_GRUPA ? $ktoId : 0,
                'cel' => $czyjeRodzaj,
                'zakres' => $zakres,
                'podpis' => $podpis,
                'przez' => $przez,
            ]);
            if ($stmt->rowCount() === 0) {
                return null;                      // ten sam podpis już jest — duplikat
            }
            $id = (int) $pdo->lastInsertId();
            $this->zapiszCel($id, $czyjeRodzaj, $grupyCelu);

            return $id;
        });
    }

    /**
     * Zmiana istniejącej reguły — **ten sam zestaw trzech części, co przy dodawaniu**.
     *
     * @param list<int> $grupyCelu
     * @return bool `false`, gdy po zmianie powstałby duplikat innej reguły tej firmy
     */
    public function zmien(
        int $ruleId,
        int $companyId,
        string $ktoRodzaj,
        int $ktoId,
        string $czyjeRodzaj,
        array $grupyCelu,
        string $zakres
    ): bool {
        $grupyCelu = ZakresWidocznosci::uporzadkuj($grupyCelu);
        $podpis = ZakresWidocznosci::podpis($ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres);

        return $this->db->transaction(function () use (
            $ruleId, $companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, $podpis
        ): bool {
            $pdo = $this->db->pdo();
            $zajety = $pdo->prepare(
                'SELECT id FROM calendar_access_rule
                  WHERE company_id = :company AND signature = :podpis AND id <> :id'
            );
            $zajety->execute(['company' => $companyId, 'podpis' => $podpis, 'id' => $ruleId]);
            if ($zajety->fetchColumn() !== false) {
                return false;
            }
            $stmt = $pdo->prepare(
                'UPDATE calendar_access_rule
                    SET subject_kind = :kto, subject_account_id = :konto,
                        subject_employment_id = :etat, subject_group_id = :grupa,
                        target_kind = :cel, scope = :zakres, signature = :podpis, updated_at = NOW()
                  WHERE id = :id AND company_id = :company'
            );
            $stmt->execute([
                'kto' => $ktoRodzaj,
                'konto' => $ktoRodzaj === ZakresWidocznosci::KTO_KONTO ? $ktoId : 0,
                'etat' => $ktoRodzaj === ZakresWidocznosci::KTO_PRZELOZONY ? $ktoId : 0,
                'grupa' => $ktoRodzaj === ZakresWidocznosci::KTO_GRUPA ? $ktoId : 0,
                'cel' => $czyjeRodzaj,
                'zakres' => $zakres,
                'podpis' => $podpis,
                'id' => $ruleId,
                'company' => $companyId,
            ]);
            // Oba cele kasujemy przed zapisem nowego: reguła mogła zmienić rodzaj celu.
            $pdo->prepare('DELETE FROM calendar_access_rule_group WHERE rule_id = :id')
                ->execute(['id' => $ruleId]);
            $pdo->prepare('DELETE FROM calendar_access_rule_person WHERE rule_id = :id')
                ->execute(['id' => $ruleId]);
            $this->zapiszCel($ruleId, $czyjeRodzaj, $grupyCelu);

            return true;
        });
    }

    /**
     * Zapis celu reguły — grup albo osób, zależnie od jej rodzaju.
     *
     * ⚠ Jedna metoda na oba, bo to jedna decyzja: reguła ma **dokładnie jeden** rodzaj celu.
     * Dwie metody wołane niezależnie otwierałyby stan, w którym w bazie leżą i grupy, i osoby.
     *
     * @param list<int> $cel
     */
    private function zapiszCel(int $ruleId, string $czyjeRodzaj, array $cel): void
    {
        if ($cel === []) {
            return;
        }
        // ⚠ **Dwa pełne literały zapytań, a nie jedno sklejane z nazwy tabeli** — nawet gdy nazwa
        // pochodzi z białej listy. Bramka `check-rules` zabrania konkatenacji w SQL bez wyjątków
        // i ma rację: wyjątek „ale to stała" jest tym, przez które za pół roku przechodzi zmienna.
        // Ta sama reguła, co przy szukaniu w `GroupRepository::osobyPozaGrupa()`.
        $stmt = $czyjeRodzaj === ZakresWidocznosci::CZYJE_OSOBY
            ? $this->db->pdo()->prepare(
                'INSERT INTO calendar_access_rule_person (rule_id, employment_id) VALUES (:rule, :co)'
            )
            : $this->db->pdo()->prepare(
                'INSERT INTO calendar_access_rule_group (rule_id, group_id) VALUES (:rule, :co)'
            );
        foreach ($cel as $id) {
            $stmt->execute(['rule' => $ruleId, 'co' => $id]);
        }
    }

    /**
     * Czynne zatrudnienia z podanej listy — **tylko z tej firmy i tylko aktywne**.
     *
     * ⚠ To tutaj zwolniona osoba przestaje być widoczna, bez sprzątania reguł (decyzja
     * Krzysztofa 19.10.2026). Wiersz w regule zostaje, żeby administrator zobaczył nazwisko
     * przekreślone i sam zdecydował — ale dostęp znika z dnia na dzień, sam.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    public function czynneZatrudnienia(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id FROM employment e
              WHERE e.id IN (' . self::inSql($employmentIds, 'e') . ')
                AND e.company_id = :company AND e.is_active = 1'
        );
        $stmt->execute(self::inParams($employmentIds, 'e') + ['company' => $companyId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return bool `false`, gdy reguła nie istnieje albo należy do innej firmy */
    public function usun(int $ruleId, int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM calendar_access_rule WHERE id = :id AND company_id = :company'
        );
        $stmt->execute(['id' => $ruleId, 'company' => $companyId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reguły dotknięte usunięciem grupy — **czytane PRZED usunięciem**, żeby było o czym
     * zapisać w dzienniku (§1 zlecenia: oba przypadki idą do dziennika).
     *
     * @return array{kto: list<int>, czyje: list<array{id: int, zostanie: int}>}
     */
    public function regulyZGrupa(int $companyId, int $groupId): array
    {
        $pdo = $this->db->pdo();
        $kto = $pdo->prepare(
            'SELECT id FROM calendar_access_rule
              WHERE company_id = :company AND subject_kind = :rodzaj AND subject_group_id = :grupa'
        );
        $kto->execute([
            'company' => $companyId,
            'rodzaj' => ZakresWidocznosci::KTO_GRUPA,
            'grupa' => $groupId,
        ]);
        $czyje = $pdo->prepare(
            'SELECT r.id,
                    (SELECT COUNT(*) FROM calendar_access_rule_group g2
                      WHERE g2.rule_id = r.id AND g2.group_id <> :grupa_b) AS zostanie
               FROM calendar_access_rule r
               JOIN calendar_access_rule_group g ON g.rule_id = r.id
              WHERE r.company_id = :company AND g.group_id = :grupa_a'
        );
        // ⚠ Dwa różne placeholdery na tę samą wartość — PDO bez emulacji nie przyjmie
        // drugiego wystąpienia `:grupa` (HY093).
        $czyje->execute(['company' => $companyId, 'grupa_a' => $groupId, 'grupa_b' => $groupId]);

        return [
            'kto' => array_map('intval', $kto->fetchAll(PDO::FETCH_COLUMN)),
            'czyje' => array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'zostanie' => (int) $r['zostanie']],
                $czyje->fetchAll(),
            ),
        ];
    }

    /**
     * Sprzątnięcie po usuniętej grupie: reguły z tą grupą po stronie „kto" znikają, po stronie
     * „czyje" grupa wypada z listy, a reguła bez ani jednej grupy znika razem z nią.
     *
     * ⚠ **Robi to kod, nie kaskada bazy** — bo o każdym z tych skutków trzeba zapisać
     * w dzienniku zdarzeń, a kaskada zrobiłaby to po cichu.
     * ⚠ **Podpis przeliczany po skróceniu listy**, inaczej klucz unikalny pilnowałby napisu,
     * który nie opisuje już tej reguły, i dwie reguły mogłyby się zejść do identycznych.
     *
     * @param list<int> $usunKto identyfikatory reguł do skasowania (grupa po stronie „kto")
     * @param list<array{id: int, zostanie: int}> $czyje reguły z grupą po stronie „czyje"
     */
    public function poUsunieciuGrupy(int $companyId, int $groupId, array $usunKto, array $czyje): void
    {
        $this->db->transaction(function () use ($companyId, $groupId, $usunKto, $czyje): void {
            $pdo = $this->db->pdo();
            foreach ($usunKto as $id) {
                $this->usun($id, $companyId);
            }
            $zdejmij = $pdo->prepare(
                'DELETE FROM calendar_access_rule_group WHERE rule_id = :rule AND group_id = :grupa'
            );
            foreach ($czyje as $r) {
                $zdejmij->execute(['rule' => $r['id'], 'grupa' => $groupId]);
                if ($r['zostanie'] === 0) {
                    $this->usun($r['id'], $companyId);
                    continue;
                }
                $regula = $this->regula($r['id'], $companyId);
                if ($regula === null) {
                    continue;
                }
                $pdo->prepare('UPDATE calendar_access_rule SET signature = :podpis, updated_at = NOW() WHERE id = :id')
                    ->execute([
                        'podpis' => ZakresWidocznosci::podpis(
                            $regula['subject_kind'],
                            $regula['subject_kind'] === ZakresWidocznosci::KTO_KONTO
                                ? $regula['subject_account_id'] : $regula['subject_group_id'],
                            $regula['target_kind'],
                            $regula['grupy'],
                            $regula['scope'],
                        ),
                        'id' => $r['id'],
                    ]);
            }
        });
    }

    /**
     * Zatrudnienia należące do wskazanych grup — **tylko czynne i tylko z tej firmy**.
     *
     * @param list<int> $grupy
     * @return list<int>
     */
    public function zatrudnieniaGrup(int $companyId, array $grupy): array
    {
        if ($grupy === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            // ⚠ Kolumny porządkujące MUSZĄ być w `SELECT` razem z `DISTINCT` — inaczej MySQL
            // odrzuca zapytanie (3065, `ONLY_FULL_GROUP_BY`). Zmierzone: 500 przy regule na grupę
            // w kroku 2. Porządek po nazwisku zostaje, bo ta sama lista bywa pokazywana człowiekowi.
            'SELECT DISTINCT e.id, p.last_name, p.first_name
               FROM employment_group eg
               JOIN employment e ON e.id = eg.employment_id
               JOIN person p ON p.id = e.person_id
              WHERE eg.group_id IN (' . self::inSql($grupy, 'g') . ')
                AND e.company_id = :company AND e.is_active = 1
              ORDER BY p.last_name, p.first_name, e.id'
        );
        $stmt->execute(self::inParams($grupy, 'g') + ['company' => $companyId]);

        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    /**
     * Grupy wskazanych zatrudnień — mapa `zatrudnienie => [grupa, …]`, jedno zapytanie na komplet.
     *
     * @param list<int> $employmentIds
     * @return array<int, list<int>>
     */
    public function grupyZatrudnien(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT eg.employment_id, eg.group_id
               FROM employment_group eg
               JOIN employment e ON e.id = eg.employment_id
              WHERE eg.employment_id IN (' . self::inSql($employmentIds, 'e') . ')
                AND e.company_id = :company'
        );
        $stmt->execute(self::inParams($employmentIds, 'e') + ['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['employment_id']][] = (int) $r['group_id'];
        }

        return $out;
    }

    /**
     * Firmy, w których to konto ma **jakąkolwiek** regułę wglądu — biała lista ekranu kalendarza
     * dla konta bez zatrudnienia. Zastępuje dawne pytanie o uprawnienie `calendar.view_all`.
     *
     * ⚠ Reguła „na grupę" też się tu liczy, ale wyłącznie wtedy, gdy konto ma w tej firmie
     * zatrudnienie — bo grupy są grupami zatrudnień, nie kont.
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function spolkiZRegula(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT c.id, c.name
               FROM calendar_access_rule r
               JOIN company c ON c.id = r.company_id
              WHERE (r.subject_kind = :kto_konto AND r.subject_account_id = :konto)
                 OR (r.subject_kind = :kto_grupa AND r.subject_group_id IN (
                       SELECT eg.group_id
                         FROM employment_group eg
                         JOIN employment e ON e.id = eg.employment_id
                         JOIN person p ON p.id = e.person_id
                         JOIN account a ON a.person_id = p.id
                        WHERE a.id = :konto_b AND e.is_active = 1 AND e.company_id = r.company_id))
              ORDER BY c.name'
        );
        $stmt->execute([
            'kto_konto' => ZakresWidocznosci::KTO_KONTO,
            'konto' => $accountId,
            'kto_grupa' => ZakresWidocznosci::KTO_GRUPA,
            'konto_b' => $accountId,
        ]);

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nazwa' => (string) $r['name']],
            $stmt->fetchAll(),
        );
    }

    /**
     * Czynne zatrudnienia tego konta w tej firmie.
     *
     * @return list<int>
     */
    public function etatyKonta(int $companyId, int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id FROM employment e
               JOIN person p ON p.id = e.person_id
               JOIN account a ON a.person_id = p.id
              WHERE a.id = :konto AND e.company_id = :company AND e.is_active = 1'
        );
        $stmt->execute(['konto' => $accountId, 'company' => $companyId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * **Reguła domyślna firmy** („Wszyscy przełożeni › Swoi podwładni") — albo `null`, gdy
     * administrator jeszcze jej nie zapisał.
     *
     * ⚠ Brak wiersza znaczy „nie rozstrzygnięto", a to ma znaczyć dokładnie to samo, co
     * `bez_szczegolow`. Domyślność stosuje JEDNO miejsce w kodzie
     * (`CalendarVisibility::zakresPrzelozonych()`), a nie `DEFAULT` kolumny i kod naraz.
     */
    public function regulaStala(int $companyId, string $ktoRodzaj): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.scope, r.target_kind, r.subject_kind, r.subject_account_id,
                    r.subject_employment_id, r.subject_group_id
               FROM calendar_access_rule r
              WHERE r.company_id = :company AND r.subject_kind = :rodzaj'
        );
        $stmt->execute(['company' => $companyId, 'rodzaj' => $ktoRodzaj]);
        $wiersz = $stmt->fetch();

        return is_array($wiersz) ? ($this->zGrupami([$wiersz])[0] ?? null) : null;
    }

    /**
     * Cel i podpis obu reguł stałych — **w jednym miejscu**, bo to jedyne, czym się różnią.
     *
     * @var array<string, array{string, string}>
     */
    private const STALE = [
        ZakresWidocznosci::KTO_PRZELOZENI => [ZakresWidocznosci::CZYJE_PODWLADNI, ZakresWidocznosci::PODPIS_DOMYSLNEJ],
        ZakresWidocznosci::KTO_ADMINI => [ZakresWidocznosci::CZYJE_FIRMA, ZakresWidocznosci::PODPIS_ADMINOW],
    ];

    /** Czy ten rodzaj podmiotu to reguła stała firmy (jedna, nieusuwalna). */
    public static function stala(string $ktoRodzaj): bool
    {
        return array_key_exists($ktoRodzaj, self::STALE);
    }

    /**
     * Zapis zakresu reguły domyślnej — **zakłada ją przy pierwszym rozstrzygnięciu**.
     *
     * ⚠ Nie `ON DUPLICATE KEY UPDATE`: podpis reguły domyślnej celowo NIE zawiera zakresu
     * (`ZakresWidocznosci::PODPIS_DOMYSLNEJ`), więc zmiana zakresu trafiałaby w ten sam klucz
     * i wszystko by działało — ale tylko do chwili, w której ktoś zmieni format podpisu.
     * Jawne „znajdź albo załóż" nie zależy od tej subtelności.
     */
    public function ustawStala(int $companyId, string $ktoRodzaj, string $zakres, ?int $przez): void
    {
        [$cel, $podpis] = self::STALE[$ktoRodzaj];
        $this->db->transaction(function () use ($companyId, $ktoRodzaj, $cel, $podpis, $zakres, $przez): void {
            $pdo = $this->db->pdo();
            $istnieje = $this->regulaStala($companyId, $ktoRodzaj);
            if ($istnieje === null) {
                $pdo->prepare(
                    'INSERT INTO calendar_access_rule
                        (company_id, subject_kind, subject_account_id, subject_employment_id,
                         subject_group_id, target_kind, scope, signature, created_at, created_by)
                     VALUES (:company, :rodzaj, 0, 0, 0, :cel, :zakres, :podpis, NOW(), :przez)'
                )->execute([
                    'company' => $companyId,
                    'rodzaj' => $ktoRodzaj,
                    'cel' => $cel,
                    'zakres' => $zakres,
                    'podpis' => $podpis,
                    'przez' => $przez,
                ]);

                return;
            }
            $pdo->prepare(
                'UPDATE calendar_access_rule SET scope = :zakres, updated_at = NOW()
                  WHERE id = :id AND company_id = :company'
            )->execute(['zakres' => $zakres, 'id' => $istnieje['id'], 'company' => $companyId]);
        });
    }

    /**
     * Przełożeni tej firmy — zatrudnienia, które **dziś** mają choć jednego podwładnego.
     *
     * ⚠ Lista jest do WYBORU w oknie reguły, a nie do rozstrzygania dostępu: o tym, kto kogo
     * widzi, decyduje przejście gałęzi przy odczycie. Przełożony, który jutro straci
     * podwładnych, zniknie z tej listy, a jego reguła zostanie i po prostu nic nie da —
     * tak samo jak reguła dla osoby, która jeszcze podwładnych nie ma.
     *
     * @return list<array{id: int, osoba: string, podwladnych: int}>
     */
    public function przelozeni(int $companyId, string $szukaj, int $limit, int $offset): array
    {
        $pdo = $this->db->pdo();
        $stmt = $szukaj === ''
            ? $pdo->prepare(self::SQL_PRZELOZENI . ' ORDER BY nazwisko, imie, id LIMIT :limit OFFSET :offset')
            : $pdo->prepare(
                self::SQL_PRZELOZENI . ' AND CONCAT(p.last_name, " ", p.first_name) LIKE :wzor
                 ORDER BY nazwisko, imie, id LIMIT :limit OFFSET :offset'
            );
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $wiersze = $stmt->fetchAll();
        // ⚠ Grupy jednym zapytaniem na całą stronę listy, nie jednym na wiersz — druga linia
        // wiersza ma brzmieć „3 podwładnych · Magazyn · Produkcja", a dwadzieścia zapytań na
        // dwadzieścia wierszy to ta sama choroba, co licznik liczony w pętli.
        $grupy = $this->nazwyGrup($companyId, array_map(static fn (array $r): int => (int) $r['id'], $wiersze));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $r['nazwisko'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['imie']),
            'podwladnych' => (int) $r['podwladnych'],
            'grupy' => $grupy[(int) $r['id']] ?? [],
        ], $wiersze);
    }

    public function ilePrzelozonych(int $companyId, string $szukaj): int
    {
        $pdo = $this->db->pdo();
        $sql = 'SELECT COUNT(*) FROM (' . self::SQL_PRZELOZENI
            . ($szukaj === '' ? '' : ' AND CONCAT(p.last_name, " ", p.first_name) LIKE :wzor') . ') t';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Nazwy grup wskazanych zatrudnień — mapa `zatrudnienie => [nazwa, …]`.
     *
     * @param list<int> $employmentIds
     * @return array<int, list<string>>
     */
    public function nazwyGrup(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT eg.employment_id, g.name
               FROM employment_group eg
               JOIN company_group g ON g.id = eg.group_id
              WHERE eg.employment_id IN (' . self::inSql($employmentIds, 'e') . ')
                AND g.company_id = :company
              ORDER BY g.name'
        );
        $stmt->execute(self::inParams($employmentIds, 'e') + ['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['employment_id']][] = (string) $r['name'];
        }

        return $out;
    }

    /** Ilu przełożonych ma dziś ta firma — do podpisu „Wszyscy przełożeni". */
    public function ilePrzelozonychRazem(int $companyId): int
    {
        return $this->ilePrzelozonych($companyId, '');
    }

    /**
     * ⚠ Przełożony = zatrudnienie, pod którym **ktoś stoi w hierarchii TEJ firmy**. Warunek
     * `e2.company_id` jest w podzapytaniu, a nie po pobraniu: krawędź między firmami nie
     * powinna istnieć, ale gdyby w danych zastanych była, nie ma prawa zrobić z kogoś
     * przełożonego w cudzej firmie.
     */
    private const SQL_PRZELOZENI = <<<'SQL'
        SELECT e.id, p.last_name AS nazwisko, p.first_name AS imie,
               (SELECT COUNT(*) FROM employment e2
                 WHERE e2.supervisor_employment_id = e.id AND e2.company_id = e.company_id
                   AND e2.is_active = 1) AS podwladnych
          FROM employment e
          JOIN person p ON p.id = e.person_id
         WHERE e.company_id = :company AND e.is_active = 1
           AND EXISTS (SELECT 1 FROM employment e3
                        WHERE e3.supervisor_employment_id = e.id AND e3.company_id = e.company_id
                          AND e3.is_active = 1)
        SQL;

    /**
     * Kandydaci do „KTO WIDZI › Osoba": pracownicy firmy z niezablokowanym kontem **oraz** konta
     * z nadanym dostępem do tej firmy, bez zatrudnienia (decyzja Krzysztofa 19.10.2026).
     *
     * ⚠ Szukanie i stronicowanie idą **po stronie serwera** (§4 zlecenia): lista bywa dłuższa
     * niż załoga, a doczytywanie w przeglądarce znaczyłoby, że filtr osób mieszka w dwóch
     * miejscach. Pełne literały zapytań zamiast składania z fragmentów — ta sama reguła co
     * w `GroupRepository::osobyPozaGrupa()`.
     *
     * @return list<array{id: int, osoba: string, etat: bool}>
     */
    public function kandydaciKonta(int $companyId, string $szukaj, int $limit, int $offset): array
    {
        $pdo = $this->db->pdo();
        $stmt = $szukaj === ''
            ? $pdo->prepare(self::SQL_KANDYDACI . ' ORDER BY nazwisko, imie, id LIMIT :limit OFFSET :offset')
            : $pdo->prepare(
                self::SQL_KANDYDACI . ' HAVING CONCAT(nazwisko, " ", imie) LIKE :wzor
                 ORDER BY nazwisko, imie, id LIMIT :limit OFFSET :offset'
            );
        $this->parametryKandydatow($stmt, $companyId);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $r['nazwisko'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['imie']),
            'etat' => (bool) $r['etat'],
            'zrodlo' => (string) $r['zrodlo'],
        ], $stmt->fetchAll());
    }

    public function ileKandydatow(int $companyId, string $szukaj): int
    {
        $pdo = $this->db->pdo();
        $sql = 'SELECT COUNT(*) FROM (' . self::SQL_KANDYDACI
            . ($szukaj === '' ? '' : ' HAVING CONCAT(nazwisko, " ", imie) LIKE :wzor') . ') t';
        $stmt = $pdo->prepare($sql);
        $this->parametryKandydatow($stmt, $companyId);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * ⚠ Jedno zapytanie na dwa zbiory, bo to **jedna lista** dla człowieka: pracownik firmy
     * i konto z nadanym dostępem stoją obok siebie, a różnicę niesie plakietka „bez etatu".
     * Konto zablokowane nie wchodzi do żadnego z nich — reguła dla niego i tak by nie działała
     * (§1 zlecenia), więc pokazywanie go byłoby zaproszeniem do nadania dostępu, który nic nie da.
     */
    private const SQL_KANDYDACI = <<<'SQL'
        SELECT a.id, p.last_name AS nazwisko, p.first_name AS imie, 1 AS etat, 'etat' AS zrodlo
          FROM account a
          JOIN person p ON p.id = a.person_id
          JOIN employment e ON e.person_id = p.id AND e.company_id = :company AND e.is_active = 1
         WHERE a.blocked_at IS NULL
         GROUP BY a.id, p.last_name, p.first_name
        UNION
        SELECT a.id, p.last_name AS nazwisko, p.first_name AS imie, 0 AS etat, 'dostep' AS zrodlo
          FROM account a
          JOIN person p ON p.id = a.person_id
          JOIN role_assignment ra ON ra.account_id = a.id AND ra.company_id = :company_b
          JOIN role r ON r.id = ra.role_id AND r.code = 'employee'
         WHERE a.blocked_at IS NULL
           AND NOT EXISTS (SELECT 1 FROM employment e2
                            WHERE e2.person_id = p.id AND e2.company_id = ra.company_id AND e2.is_active = 1)
         GROUP BY a.id, p.last_name, p.first_name
        UNION
        -- ⚠ Trzecia gałąź: konta z przypisaniem NA CAŁY TENANT (`company_id = 0`), czyli stojące
        -- ponad firmami — administratorzy i konta z dawnymi nadaniami obejmującymi cały tenant.
        -- Rola `employee` jest wyłączona, bo przypisanie pracownicze bez firmy nie daje dostępu
        -- do niczego (docs/szkielet-tenanta.md rozdz. 9) — reszta ról tenantowych daje.
        -- ⚠ Bez tej gałęzi reguła założona takiemu kontu przez migrację miała martwy ołówek:
        -- `WidocznoscService::sprawdzPodmiot()` pyta o kandydaturę przy KAŻDYM zapisie, więc
        -- zmiana zakresu kończyła się odmową „podmiot". Zmierzone 20.10.2026.
        SELECT a.id, p.last_name AS nazwisko, p.first_name AS imie, 0 AS etat, 'tenant' AS zrodlo
          FROM account a
          JOIN person p ON p.id = a.person_id
          JOIN role_assignment ra2 ON ra2.account_id = a.id AND ra2.company_id = 0
          JOIN role r2 ON r2.id = ra2.role_id AND r2.code <> 'employee'
         WHERE a.blocked_at IS NULL
           AND NOT EXISTS (SELECT 1 FROM employment e3
                            WHERE e3.person_id = p.id AND e3.company_id = :company_c AND e3.is_active = 1)
           AND NOT EXISTS (SELECT 1 FROM role_assignment ra3
                             JOIN role r3 ON r3.id = ra3.role_id AND r3.code = 'employee'
                            WHERE ra3.account_id = a.id AND ra3.company_id = :company_d)
         GROUP BY a.id, p.last_name, p.first_name
        SQL;

    /**
     * **Osoby wskazane w regułach — razem ze zwolnionymi** (dopisek z 19.10.2026).
     *
     * ⚠ To jedyne zapytanie o zatrudnienia, które **nie filtruje po `is_active`** — i właśnie
     * po to istnieje. Zwolnienie nie sprząta reguł (decyzja Krzysztofa): dostęp znika sam, bo
     * każde inne zapytanie bierze wyłącznie czynne, a wiersz zostaje, żeby administrator
     * zobaczył przekreślone nazwisko i sam zdecydował, co z regułą zrobić. Gdyby i to zapytanie
     * pomijało zwolnionych, reguła pokazywałaby pusty cel bez śladu, dlaczego.
     *
     * ⚠ Granica firmy jest w zapytaniu, nie w kodzie wyżej.
     *
     * ⚠ **Kolejność alfabetyczna bierze się stąd**, a nie z sortowania w PHP: porządek polskich
     * znaków zna kolacja bazy (`utf8mb4_unicode_ci`), a `strcmp` postawiłby „Ą" za „Z". Mapa
     * wraca w tej kolejności, więc wiersz reguły wylicza nazwiska alfabetycznie, chodząc po niej,
     * a nie po identyfikatorach — te mówią tylko, kto wszedł do reguły wcześniej.
     *
     * @param list<int> $employmentIds
     * @return array<int, array{nazwa: string, czynne: bool}>
     */
    public function osobyRegul(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.is_active, p.last_name AS nazwisko, p.first_name AS imie
               FROM employment e
               JOIN person p ON p.id = e.person_id
              WHERE e.company_id = :company
                AND e.id IN (' . self::inSql($employmentIds, 'e') . ')
              ORDER BY p.last_name, p.first_name, e.id'
        );
        $stmt->execute(self::inParams($employmentIds, 'e') + ['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = [
                'nazwa' => NazwaOsoby::doWyswietlenia((string) $r['nazwisko'])
                    . ' ' . NazwaOsoby::doWyswietlenia((string) $r['imie']),
                'czynne' => (int) $r['is_active'] === 1,
            ];
        }

        return $out;
    }

    /**
     * **Osoby do „CZYJE NIEOBECNOŚCI › Osoby"** — czynne zatrudnienia tej firmy.
     *
     * ⚠ **Zatrudnienia, nie konta.** Reguła wskazuje etat (tak samo jak przy przełożonym), bo
     * jedna osoba miewa etat w dwóch firmach, a reguła należy do jednej. Lista z kroku 1 (konta)
     * nie nadaje się tu nawet jako punkt wyjścia: tam chodzi o to, KTO patrzy, więc są na niej
     * też konta bez etatu w tej firmie — a ich nieobecności w tej firmie nie istnieją.
     *
     * ⚠ `$pomin` wyklucza podmiot z kroku 1: dostępu do samego siebie się nie nadaje (własne
     * dane każdy i tak widzi w pełni). Idzie placeholderami, nie sklejaniem wartości.
     *
     * @param list<int> $pomin zatrudnienia do pominięcia (podmiot reguły)
     * @return list<array{id: int, nazwa: string, opis: string, etat: bool}>
     */
    public function osobyFirmy(int $companyId, string $szukaj, int $limit, int $offset, array $pomin = []): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SQL_OSOBY
            . ($szukaj === '' ? '' : ' AND CONCAT(p.last_name, " ", p.first_name) LIKE :wzor')
            . ($pomin === [] ? '' : ' AND e.id NOT IN (' . self::inSql($pomin, 'x') . ')')
            . ' ORDER BY nazwisko, imie, id LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        foreach (self::inParams($pomin, 'x') as $nazwa => $wartosc) {
            $stmt->bindValue($nazwa, $wartosc, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $wiersze = $stmt->fetchAll();
        // Grupy jednym zapytaniem na całą stronę listy, nie jednym na wiersz (jak u przełożonych).
        $grupy = $this->nazwyGrup($companyId, array_map(static fn (array $r): int => (int) $r['id'], $wiersze));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nazwa' => NazwaOsoby::doWyswietlenia((string) $r['nazwisko'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['imie']),
            'opis' => implode(' · ', $grupy[(int) $r['id']] ?? []),
            'etat' => true,
        ], $wiersze);
    }

    /** @param list<int> $pomin */
    public function ileOsobFirmy(int $companyId, string $szukaj, array $pomin = []): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM (' . self::SQL_OSOBY
            . ($szukaj === '' ? '' : ' AND CONCAT(p.last_name, " ", p.first_name) LIKE :wzor')
            . ($pomin === [] ? '' : ' AND e.id NOT IN (' . self::inSql($pomin, 'x') . ')')
            . ') t'
        );
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        if ($szukaj !== '') {
            $stmt->bindValue('wzor', '%' . $szukaj . '%');
        }
        foreach (self::inParams($pomin, 'x') as $nazwa => $wartosc) {
            $stmt->bindValue($nazwa, $wartosc, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * ⚠ Kolumny sortowania **są w SELECT**, bo inaczej MySQL w trybie `ONLY_FULL_GROUP_BY`
     * odmawia (3065) — ta sama pułapka, co przy liście przełożonych.
     */
    private const SQL_OSOBY = <<<'SQL'
        SELECT e.id, p.last_name AS nazwisko, p.first_name AS imie
          FROM employment e
          JOIN person p ON p.id = e.person_id
         WHERE e.company_id = :company AND e.is_active = 1
        SQL;

    /**
     * Parametry zapytania o kandydatów — **jedno miejsce**, bo zapytanie ma ich pięć i jedzie
     * w dwóch metodach (lista i licznik). ⚠ Ta sama wartość pod kilkoma nazwami: PDO bez emulacji
     * odrzuca powtórzony placeholder (HY093).
     */
    private function parametryKandydatow(PDOStatement $stmt, int $companyId): void
    {
        foreach (['company', 'company_b', 'company_c', 'company_d'] as $nazwa) {
            $stmt->bindValue($nazwa, $companyId, PDO::PARAM_INT);
        }
    }

    /**
     * **Ile kont obejmuje reguła „Administratorzy"** — do drugiej linii wiersza na liście reguł.
     *
     * ⚠ Liczy się po UPRAWNIENIU, nie po nazwie roli, i tak samo jak przy odczycie dostępu:
     * konto z tym uprawnieniem w tej firmie **albo na całym tenancie** (`company_id = 0`).
     * Gdyby ekran liczył inaczej niż egzekwowanie, wiersz mówiłby „3 konta" tam, gdzie widzą
     * cztery osoby.
     */
    /**
     * Nazwy grup po numerach — **do opisu źródeł dostępu w panelu osoby** (zlecenie Z58).
     *
     * ⚠ Zawężone do firmy, bo grupa należy do firmy: numer z innej firmy ma zniknąć, a nie
     * oddać nazwę (CLAUDE.md §10 — warunek w zapytaniu, nie `if` po odczycie).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function nazwyGrupPoId(int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT g.id, g.name FROM company_group g
              WHERE g.company_id = :company AND g.id IN (' . self::inSql($ids, 'g') . ')
              ORDER BY g.name'
        );
        $stmt->execute(self::inParams($ids, 'g') + ['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }

        return $out;
    }

    public function ileAdministratorow(int $companyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT ra.account_id)
               FROM role_assignment ra
               JOIN role_permission rp ON rp.role_id = ra.role_id
               JOIN permission p ON p.id = rp.permission_id AND p.code = :uprawnienie
               JOIN account a ON a.id = ra.account_id AND a.blocked_at IS NULL
              WHERE ra.company_id IN (0, :company)'
        );
        $stmt->execute(['uprawnienie' => ZakresWidocznosci::UPRAWNIENIE_ADMINA, 'company' => $companyId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param list<int> $ids */
    private static function inSql(array $ids, string $prefix): string
    {
        return implode(', ', array_map(
            static fn (int $i): string => ':' . $prefix . $i,
            array_keys(array_values($ids)),
        ));
    }

    /**
     * @param list<int> $ids
     * @return array<string, int>
     */
    private static function inParams(array $ids, string $prefix): array
    {
        $out = [];
        foreach (array_values($ids) as $i => $id) {
            $out[$prefix . $i] = $id;
        }

        return $out;
    }
}
