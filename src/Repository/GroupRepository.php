<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use PDOException;
use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Grupy — jedyne miejsce z SQL-em o grupach (zlecenie `2026-10-08-grupy-dzialy.md`,
 * zmiana modelu 10.10.2026). Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ **Granica spółki jest WARUNKIEM W ZAPYTANIU, nie `if`-em po pobraniu.** Klucz obcy jej nie
 * wyrazi: `employment_group.group_id → company_group.id` nie wie nic o tym, z której spółki jest
 * zatrudnienie, więc nic w schemacie nie broni przypisać osoby ze spółki A do grupy spółki B.
 * Dlatego **każde** zapytanie dotykające przynależności nosi `e.company_id = g.company_id`
 * albo `e.company_id = :company` — tak samo jak granica spółki w hierarchii i w kalendarzu.
 * ⚠ Dotyczy to także zapytań USUWAJĄCYCH: bez warunku spółki cudzy identyfikator w żądaniu
 * **wypisałby kogoś z jego grupy** w innej spółce. To zapis, nie odczyt — i gorszy.
 *
 * ⚠ **Jedno zatrudnienie należy do WIELU grup** (decyzja Krzysztofa 10.10.2026, migracja
 * `20261010100001`). Klucz główny stoi na PARZE `(employment_id, group_id)`, więc baza pilnuje
 * „jedna przynależność na parę, dowolnie wiele par". Co z tego wynika dla tego pliku:
 *   - przypisanie **nic nie odbiera** — nie ma przenoszenia między grupami i nie ma czego
 *     potwierdzać przed zapisem (§2b zlecenia przestaje obowiązywać),
 *   - zapis jest **dokładaniem brakujących par**, a nie „usuń wszystko i wstaw" — inaczej
 *     dołożenie osoby do jednej grupy czyściłoby jej pozostałe,
 *   - odczyt zwraca **listę** grup na osobę (`grupy`), nie jedną nazwę; dlatego nazwy grup idą
 *     osobnym zapytaniem (`grupyOsob()`), a nie przez `GROUP_CONCAT` — nazwa grupy może
 *     zawierać dowolny znak, więc sklejanie listy w jeden napis psuje się na pierwszym
 *     przecinku w nazwie.
 *
 * ⚠ **Jedno żądanie dotyczy JEDNEJ osoby i jest przyrostowe** (zlecenie 10.10.2026 §3).
 * Zapis całego stanu członkostwa (`ustawCzlonkostwo`) **zniknął razem z kontrolką z polami
 * wyboru**: skoro nie istnieje żądanie zdolne wypisać kogoś poza wskazaną osobą, nie trzeba
 * już mówić zapytaniu, kogo człowiek widział na ekranie (`widoczne[]`). Została jedna para
 * operacji — `przypisz()` dokłada, `usunZGrupy()` zdejmuje — i obie nadal noszą granicę spółki
 * w warunku zapytania, bo małe żądanie nie jest żądaniem mniej sprawdzanym.
 *
 * ⚠ **Grupa jest etykietą, bez kierownika** (decyzja Krzysztofa 08.10.2026) i bez zagnieżdżania.
 */
final class GroupRepository
{
    /** Nazwa zajęta w tej spółce — `UNIQUE (company_id, name)`, nieczuły na wielkość liter. */
    public const POWOD_NAZWA_ZAJETA = 'nazwa_zajeta';

    /** Grupa ma członków — usuwamy tylko puste (§0 zlecenia). */
    public const POWOD_NIEPUSTA = 'niepusta';

    /** Grupy nie ma albo należy do innej spółki — jedna odpowiedź na oba przypadki (§10 reguł). */
    public const POWOD_BRAK = 'brak';

    /**
     * Warunek szukania osoby — **JEDEN placeholder, nie dwa takie same**.
     *
     * ⚠ To nie jest styl, to naprawa błędu 500 (zmierzone 10.10.2026). Dotąd stało tu
     * `(p.last_name LIKE :szukaj OR p.first_name LIKE :szukaj)` — ten sam placeholder dwa razy.
     * PDO **z prawdziwymi zapytaniami przygotowanymi** (`ATTR_EMULATE_PREPARES => false`,
     * obowiązkowe w §4 reguł) nie pozwala użyć nazwanego placeholdera więcej niż raz i wywraca
     * zapytanie z `SQLSTATE[HY093] Invalid parameter number`. Działa to wyłącznie w emulacji,
     * czyli w trybie, którego u nas nie ma. Usterka siedziała tu od 08.10 i nikt jej nie widział,
     * bo w to pole nigdy nikt nie wpisał litery — ani człowiek (było przycięte kartą), ani
     * bramka (żadne kryterium w nim nie pisało).
     *
     * ⚠ Sklejenie nazwiska z imieniem daje przy okazji to, czego nie dawał `OR`: szuka się
     * „Dramiński Marek", a nie tylko jednego z członów.
     */
    private const SZUKANIE = "CONCAT(p.last_name, ' ', p.first_name) LIKE :szukaj";

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Grupy spółki z licznikami.
     *
     * ⚠ **Dwa liczniki, nie jeden.** Wygaszenie zatrudnienia nie czyści przynależności, więc
     * w grupie zostają ludzie, którzy już nie pracują. „12 osób" byłoby wtedy nieprawdą —
     * stąd osobno `osob` i `nieaktywnych`. Inaczej za pół roku „Produkcja" ma dwadzieścia osób,
     * z których połowa odeszła.
     * ⚠ Przy wielu grupach na osobę te liczniki dalej są **per grupa** — ta sama osoba liczy się
     * w każdej grupie, do której należy, i to jest poprawne: licznik mówi „ilu ludzi ma tę
     * etykietę", a nie „ilu ludzi jest tylko tutaj".
     *
     * @return list<array{id: int, nazwa: string, osob: int, nieaktywnych: int}>
     */
    public function lista(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT g.id, g.name,
                    COUNT(eg.employment_id) AS osob,
                    SUM(CASE WHEN e.is_active = 0 THEN 1 ELSE 0 END) AS nieaktywnych
               FROM company_group g
               LEFT JOIN employment_group eg ON eg.group_id = g.id
               LEFT JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
              WHERE g.company_id = :company
              GROUP BY g.id, g.name
              ORDER BY g.name'
        );
        $stmt->execute(['company' => $companyId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nazwa' => (string) $r['name'],
            'osob' => (int) $r['osob'],
            'nieaktywnych' => (int) $r['nieaktywnych'],
        ], $stmt->fetchAll());
    }

    /**
     * Ilu ludzi jest **bez żadnej grupy** — stan policzalny, nie pusta komórka (§0 zlecenia).
     *
     * ⚠ Przy wielu grupach na osobę „bez grupy" znaczy **ani jednej** przynależności — dlatego
     * `NOT EXISTS`, a nie „grupa jest NULL" w złączeniu: osoba z dwiema grupami dałaby wtedy
     * dwa wiersze i nie wiadomo, czy ją liczyć.
     * ⚠ Liczymy wyłącznie **czynne** zatrudnienia: „bez grupy" to lista do zrobienia, a osoba
     * wygaszona nie jest zadaniem. Nieaktywnych bez grupy nie ma po co pokazywać — w przeciwieństwie
     * do nieaktywnych **w grupie**, których widać, bo zawyżają licznik grupy.
     *
     * ⚠ Zwraca też **nazwiska**, bo pasek „Bez grupy" na liście grup rozwija się w nazwiska
     * (§4 zlecenia: pasek jest klikalny). Jedno zapytanie na oba pytania — licznik to
     * `count()` z tej samej listy, więc liczba i lista nie mają jak się rozjechać. Przy
     * załodze spółki liczonej w setkach to nadal jedno zapytanie bez złączenia z grupami.
     *
     * @return array{osob: int, sklad: list<string>}
     */
    public function bezGrupy(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.first_name, p.last_name FROM employment e
               JOIN person p ON p.id = e.person_id
              WHERE e.company_id = :company AND e.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM employment_group eg WHERE eg.employment_id = e.id)
              ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute(['company' => $companyId]);
        $sklad = array_map(static fn (array $r): string => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
            . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']), $stmt->fetchAll());

        return ['osob' => count($sklad), 'sklad' => $sklad];
    }

    /**
     * **Liczby na pasek kafli ekranu grup** (zlecenie Z31, 18.10.2026).
     *
     * ⚠ Liczymy OSOBY, nie przypisania: osoba w trzech grupach to jeden człowiek z etykietą,
     * a nie trzech ludzi. Suma liczników przy grupach jest wyższa niż „osób w grupach" i to jest
     * poprawne — mówią o czym innym (patrz komentarz na górze `groups.twig`).
     *
     * ⚠ Zakres ten sam, co w `bezGrupy()`: **czynne zatrudnienia w tej firmie**. Inaczej dwa
     * kafle stojące obok siebie liczyłyby dwa różne zbiory, a różnica wyglądałaby na błąd.
     *
     * ⚠ Jedno zapytanie na trzy liczby, bo trzy zapytania w pętli renderowania to trzy razy
     * ten sam `JOIN` — a te liczby czyta się raz, na górze ekranu.
     *
     * @return array{grup: int, w_grupach: int, w_kilku: int}
     */
    public function statystyki(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM company_group g WHERE g.company_id = :company1) AS grup,
                (SELECT COUNT(DISTINCT e.person_id) FROM employment e
                  WHERE e.company_id = :company2 AND e.is_active = 1
                    AND EXISTS (SELECT 1 FROM employment_group eg WHERE eg.employment_id = e.id)) AS w_grupach,
                (SELECT COUNT(*) FROM (
                    SELECT e.person_id FROM employment e
                      JOIN employment_group eg ON eg.employment_id = e.id
                     WHERE e.company_id = :company3 AND e.is_active = 1
                     GROUP BY e.person_id
                    HAVING COUNT(DISTINCT eg.group_id) > 1) AS wielo) AS w_kilku'
        );
        // ⚠ Ten sam parametr trzy razy pod trzema nazwami: PDO bez emulacji nie pozwala użyć
        // jednego `:name` w kilku miejscach zapytania (HY093).
        $stmt->execute(['company1' => $companyId, 'company2' => $companyId, 'company3' => $companyId]);
        $r = $stmt->fetch() ?: [];

        return [
            'grup' => (int) ($r['grup'] ?? 0),
            'w_grupach' => (int) ($r['w_grupach'] ?? 0),
            'w_kilku' => (int) ($r['w_kilku'] ?? 0),
        ];
    }

    /**
     * **Spółka, do której należy grupa** — dla adresu `/admin/grupy/{id}` bez `?spolka=`
     * (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1: „serwer wyprowadza spółkę z samego
     * zasobu").
     *
     * ⚠ Zawężenie do spółek konta jest **w zapytaniu**, nie w `if` po pobraniu (CLAUDE.md §10):
     * identyfikator grupy z adresu nie ma prawa powiedzieć nic o cudzej spółce. Pusta lista
     * dozwolonych znaczy „brak dostępu", więc zwracamy `null` bez pytania bazy.
     *
     * @param list<int> $dozwoloneSpolki
     */
    public function spolkaGrupy(int $groupId, array $dozwoloneSpolki): ?int
    {
        $dozwolone = array_values(array_filter($dozwoloneSpolki, static fn (int $id): bool => $id >= 1));
        if ($dozwolone === []) {
            return null;
        }
        // ⚠ `FIND_IN_SET`, a nie `IN (...)` sklejane w pętli: lista spółek zmienia DŁUGOŚĆ,
        // więc `IN` wymagałoby budowania SQL-a ze zmiennej — czego §4 zakazuje i co wyłapuje
        // `bin/check-rules.php`. Tu zapytanie jest stałym łańcuchem, a cała lista jedzie
        // **jedną wartością w placeholderze**; warunek zostaje w zapytaniu (§10), nie w `if`
        // po pobraniu. Wartości to nasze własne, przefiltrowane liczby całkowite.
        $stmt = $this->db->pdo()->prepare(
            'SELECT company_id FROM company_group WHERE id = :id AND FIND_IN_SET(company_id, :lista)'
        );
        $stmt->bindValue('id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue('lista', implode(',', $dozwolone));
        $stmt->execute();
        $wartosc = $stmt->fetchColumn();

        return $wartosc === false ? null : (int) $wartosc;
    }

    /** @return array{id: int, nazwa: string}|null */
    public function find(int $groupId, int $companyId): ?array
    {
        // ⚠ Spółka w warunku, nie po pobraniu: inaczej `?grupa=` z cudzej spółki dałoby podgląd
        // jej grupy (IDOR, CLAUDE.md §10).
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM company_group WHERE id = :id AND company_id = :company'
        );
        $stmt->execute(['id' => $groupId, 'company' => $companyId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'nazwa' => (string) $row['name']] : null;
    }

    /** @return array{id: int}|string identyfikator nowej grupy albo powód odmowy */
    public function utworz(int $companyId, string $nazwa, ?int $przez): array|string
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO company_group (company_id, name, created_at, created_by)
             VALUES (:company, :nazwa, UTC_TIMESTAMP(), :przez)'
        );

        try {
            $stmt->execute(['company' => $companyId, 'nazwa' => $nazwa, 'przez' => $przez]);
        } catch (PDOException $e) {
            // 23000 = naruszenie ograniczenia; tu możliwe jest tylko jedno — `uq_company_group_name`.
            if ($e->getCode() === '23000') {
                return self::POWOD_NAZWA_ZAJETA;
            }

            throw $e;
        }

        return ['id' => (int) $this->db->pdo()->lastInsertId()];
    }

    public function zmienNazwe(int $groupId, int $companyId, string $nazwa): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE company_group SET name = :nazwa WHERE id = :id AND company_id = :company'
        );

        try {
            $stmt->execute(['nazwa' => $nazwa, 'id' => $groupId, 'company' => $companyId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return self::POWOD_NAZWA_ZAJETA;
            }

            throw $e;
        }

        return $stmt->rowCount() === 0 ? self::POWOD_BRAK : null;
    }

    /**
     * Usunięcie grupy — **tylko pustej** (§0 zlecenia).
     *
     * ⚠ **Dwie warstwy, nie jedna.** Warunek `NOT EXISTS` w zapytaniu jest pierwszą; drugą jest
     * klucz obcy `ON DELETE RESTRICT`, bo aplikację da się ominąć bezpośrednim SQL-em, a klucza
     * nie. Raport pokazuje obie: po wstrzyknięciu usterki (pominięcie warunku) odmowę wystawia
     * baza.
     * ⚠ `rowCount() === 0` znaczy „nie usunięto" i ma **dwie** przyczyny — grupa niepusta albo
     * grupa z innej spółki. Rozdzielamy je dopiero po sprawdzeniu, która zachodzi, bo komunikat
     * „grupa ma członków" przy cudzej grupie ujawniłby, że taka grupa istnieje.
     */
    public function usun(int $groupId, int $companyId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM company_group
              WHERE id = :id AND company_id = :company
                AND NOT EXISTS (SELECT 1 FROM employment_group eg WHERE eg.group_id = :id2)'
        );
        $stmt->execute(['id' => $groupId, 'company' => $companyId, 'id2' => $groupId]);
        if ($stmt->rowCount() === 1) {
            return null;
        }

        return $this->find($groupId, $companyId) === null ? self::POWOD_BRAK : self::POWOD_NIEPUSTA;
    }

    /**
     * Członkowie grupy — **tylko z jej spółki**, z pozostałymi grupami każdej osoby.
     *
     * ⚠ Pozostałe grupy są tu po to, żeby ekran mówił prawdę o nowym modelu: osoba należy
     * do wielu grup, więc wykaz członków, który o tym milczy, kazałby zakładać, że jest
     * tylko tutaj.
     *
     * ⚠ **Szukanie po tej samej stronie co w prawej kolumnie** (aneks 10.10.2026 §3): obie
     * kolumny mają być lustrzane, a to znaczy także **jedną drogą szukania**. Filtrowanie lewej
     * kolumny w przeglądarce (tak, jak było w prototypie) dałoby dwa miejsca decydujące o tym
     * samym — i to drugie, które przy pierwszej zmianie modelu rozjedzie się z zapytaniem.
     *
     * @return list<array{id: int, osoba: string, czynne: bool, grupy: list<array{id: int, nazwa: string}>}>
     */
    public function czlonkowie(int $groupId, int $companyId, string $szukaj = '', int $limit = 200): array
    {
        $params = ['grupa' => $groupId, 'company' => $companyId];
        $sql = 'SELECT e.id, e.is_active, p.first_name, p.last_name
                  FROM employment_group eg
                  JOIN company_group g ON g.id = eg.group_id
                  JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
                  JOIN person p ON p.id = e.person_id
                 WHERE eg.group_id = :grupa AND g.company_id = :company';
        if ($szukaj !== '') {
            $sql .= ' AND ' . self::SZUKANIE;
            $params['szukaj'] = '%' . $szukaj . '%';
        }
        $sql .= ' ORDER BY e.is_active DESC, p.last_name, p.first_name
                  LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $wiersze = $stmt->fetchAll();
        $grupy = $this->grupyOsob($companyId, array_map(static fn (array $r): int => (int) $r['id'], $wiersze));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']),
            'czynne' => (bool) $r['is_active'],
            'grupy' => $grupy[(int) $r['id']] ?? [],
        ], $wiersze);
    }

    /**
     * Ilu członków grupy pasuje do szukanego tekstu — do nagłówka „N z M" w lewej kolumnie.
     *
     * ⚠ Dwa wywołania (z filtrem i bez) zamiast jednego z `GROUP BY`: nagłówek ma podać
     * **obie** liczby, a wyliczanie „razem" z długości listy kłamałoby przy limicie.
     */
    public function ileWGrupie(int $groupId, int $companyId, string $szukaj = ''): int
    {
        $params = ['grupa' => $groupId, 'company' => $companyId];
        $sql = 'SELECT COUNT(*) FROM employment_group eg
                  JOIN company_group g ON g.id = eg.group_id
                  JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
                  JOIN person p ON p.id = e.person_id
                 WHERE eg.group_id = :grupa AND g.company_id = :company';
        if ($szukaj !== '') {
            $sql .= ' AND ' . self::SZUKANIE;
            $params['szukaj'] = '%' . $szukaj . '%';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * **Pozostali w spółce** — ci, którzy do tej grupy NIE należą (prawa kolumna ekranu grupy).
     *
     * ⚠ `NOT EXISTS`, a nie `LEFT JOIN … IS NULL`: przy wielu grupach na osobę złączenie
     * dałoby tyle wierszy, ile przynależności, a `LIMIT` ucinałby w środku jednej osoby.
     *
     * ⚠ **WYŁĄCZNIE czynne zatrudnienia** (zmierzone przez Krzysztofa 10.10.2026 wieczorem).
     * Dotąd lista oferowała też wygaszone — z plakietką, ale z przyciskiem `+`. Zlecenie 08.10
     * §8a mówi tylko, że **wygaszenie nie czyści istniejącej** przynależności; nadawania nowych
     * nikt nie przewidywał, a etykieta nadana komuś, kto nie pracuje, nie ma znaczenia — za to
     * zawyża liczniki i zostaje w grupie na zawsze. Ten warunek jest **połową** roboty:
     * drugą jest ten sam warunek w `przypisz()`, bo lista to UI, a odmowę ma wystawiać zapytanie
     * (CLAUDE.md §10). Gdyby kiedyś trzeba było dopisać wygaszonego do grupy historycznej,
     * miejscem na to jest **jawny przełącznik „pokaż wygaszone"**, a nie milczenie listy.
     *
     * @return list<array{id: int, osoba: string, czynne: bool, grupy: list<array{id: int, nazwa: string}>}>
     */
    public function osobyPozaGrupa(int $groupId, int $companyId, string $szukaj = '', int $limit = 100): array
    {
        $params = ['company' => $companyId, 'grupa' => $groupId];
        $sql = 'SELECT e.id, e.is_active, p.first_name, p.last_name
                  FROM employment e
                  JOIN person p ON p.id = e.person_id
                 WHERE e.company_id = :company AND e.is_active = 1
                   AND NOT EXISTS (SELECT 1 FROM employment_group eg
                                    WHERE eg.employment_id = e.id AND eg.group_id = :grupa)';
        if ($szukaj !== '') {
            $sql .= ' AND ' . self::SZUKANIE;
            $params['szukaj'] = '%' . $szukaj . '%';
        }
        $sql .= ' ORDER BY e.is_active DESC, p.last_name, p.first_name, e.id
                  LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $wiersze = $stmt->fetchAll();
        $grupy = $this->grupyOsob($companyId, array_map(static fn (array $r): int => (int) $r['id'], $wiersze));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']),
            'czynne' => (bool) $r['is_active'],
            'grupy' => $grupy[(int) $r['id']] ?? [],
        ], $wiersze);
    }

    /**
     * Ilu ludzi jest poza grupą — **osobno dla szukanego tekstu i bez niego**, bo nagłówek
     * kolumny mówi „7 z 32", a nie „7".
     */
    public function ilePozaGrupa(int $groupId, int $companyId, string $szukaj = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM employment e JOIN person p ON p.id = e.person_id
                 WHERE e.company_id = :company AND e.is_active = 1
                   AND NOT EXISTS (SELECT 1 FROM employment_group eg
                                    WHERE eg.employment_id = e.id AND eg.group_id = :grupa)';
        $params = ['company' => $companyId, 'grupa' => $groupId];
        if ($szukaj !== '') {
            $sql .= ' AND ' . self::SZUKANIE;
            $params['szukaj'] = '%' . $szukaj . '%';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Skład wszystkich grup spółki — **jedno zapytanie na cały ekran listy** (§4 zlecenia).
     *
     * ⚠ Zapytanie na wiersz byłoby tu nie tylko wolniejsze, ale i niewidoczne w pomiarze:
     * dziesięć grup to dziesięć zapytań, a sto grup — sto. Nazwiska przycina **widok**,
     * nie baza: limit w zapytaniu per grupa wymagałby funkcji okienkowych, których nie wolno
     * używać przy nieznanej wersji silnika (§0 reguł).
     *
     * @return array<int, list<string>> grupa → nazwiska członków, w kolejności wyświetlania
     */
    /**
     * **Grupy JEDNEGO zatrudnienia** — podpis roli w karcie „Dostęp" na „Moim koncie" (Z69).
     *
     * ⚠ Osobna metoda, mimo że `skladGrup()` czyta tę samą tabelę: tamta zwraca grupy całej
     * firmy z nazwiskami osób (do ekranu grup), więc żeby wyciągnąć z niej grupy jednej osoby,
     * trzeba by przeczytać całą załogę i dopasować po nazwisku — czyli po **danej osobowej**,
     * która do tego nie jest unikalna. To zapytanie pyta o parę `zatrudnienie + firma`.
     *
     * ⚠ Firma w warunku jest konieczna, choć `employment_id` jest unikalne: grupa należy
     * do firmy, więc bez tego warunku dołożenie kiedyś grup międzyfirmowych przeciekłoby
     * nazwą grupy z innej firmy (CLAUDE.md §10 — warunek w zapytaniu, nie `if` po pobraniu).
     *
     * @return list<string>
     */
    public function grupyZatrudnienia(int $employmentId, int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT g.name
               FROM employment_group eg
               JOIN company_group g ON g.id = eg.group_id
              WHERE eg.employment_id = :employment AND g.company_id = :company
              ORDER BY g.name'
        );
        $stmt->execute(['employment' => $employmentId, 'company' => $companyId]);

        return array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll());
    }

    public function skladGrup(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT eg.group_id, e.is_active, p.first_name, p.last_name
               FROM employment_group eg
               JOIN company_group g ON g.id = eg.group_id
               JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
               JOIN person p ON p.id = e.person_id
              WHERE g.company_id = :company
              ORDER BY eg.group_id, e.is_active DESC, p.last_name, p.first_name'
        );
        $stmt->execute(['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['group_id']][] = NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']);
        }

        return $out;
    }

    /**
     * Przypisanie osób do grupy — **jedyne miejsce, w którym przynależność powstaje**.
     *
     * ⚠ **Dokładamy brakujące pary, nic nie odbierając.** Przy wielu grupach na zatrudnienie
     * „usuń dotychczasowe i wstaw" czyściłoby pozostałe grupy osoby — a to jest dokładnie ta
     * cicha zmiana, której §2b zlecenia kazał unikać krokiem potwierdzenia. Teraz nie ma czego
     * potwierdzać, bo nie ma czego odbierać.
     *
     * ⚠ **Wygaszone zatrudnienie się nie wstawia** (`e.is_active = 1`, 10.10.2026 wieczorem).
     * Lista go nie oferuje, ale lista jest UI — odmowę ma wystawiać zapytanie, bo żądanie da się
     * wysłać z ręki (CLAUDE.md §10). ⚠ To **nie usuwa** istniejących przynależności
     * wygaszonych — §8a zlecenia 08.10 zostaje bez zmian: kto był w grupie, zanim go wygaszono,
     * w niej zostaje i liczy się w `nieaktywnych`. Zmieniło się tylko to, że **nowej** etykiety
     * nie nadamy komuś, kto nie pracuje.
     *
     * ⚠ `NOT EXISTS` na tabeli docelowej, a nie `ON DUPLICATE KEY UPDATE`: składnia z `VALUES()`
     * jest w MySQL 8 deprecjonowana, a nowa (alias wiersza) nie istnieje w MariaDB (§0 reguł —
     * jeden kod na oba silniki). ⚠ Bez tego warunku pojedynczy duplikat wywracałby **cały**
     * zapis błędem klucza głównego, czyli jedno powtórzone kliknięcie gubiłoby całą resztę listy.
     *
     * @param list<int> $employmentIds
     * @return array{dodani: list<int>, pominieci: list<int>}
     */
    public function przypisz(int $groupId, int $companyId, array $employmentIds, ?int $przez): array
    {
        if ($employmentIds === []) {
            return ['dodani' => [], 'pominieci' => []];
        }

        return $this->db->transaction(
            fn (): array => $this->przypiszBezTransakcji($groupId, $companyId, $employmentIds, $przez)
        );
    }

    /**
     * Ciało przypisania **bez własnej transakcji**.
     *
     * ⚠ Powód jest techniczny i zmierzony: `Connection::transaction()` woła `beginTransaction()`
     * bezwarunkowo, a PDO/MySQL nie zna transakcji zagnieżdżonych — wersja z transakcją
     * w transakcji wywracała zapis. Rozdział ciała od opakowania został po `ustawCzlonkostwo()`
     * (zapis dodania i wypisania w jednej transakcji) i zostaje: pierwsze zagnieżdżone
     * wywołanie wróci przy pierwszej operacji złożonej, a koszt tego rozdziału to jedna
     * prywatna metoda.
     *
     * @param list<int> $employmentIds
     * @return array{dodani: list<int>, pominieci: list<int>}
     */
    private function przypiszBezTransakcji(int $groupId, int $companyId, array $employmentIds, ?int $przez): array
    {
        $przed = $this->czlonkostwaWGrupie($groupId, $companyId, $employmentIds);

        $wstaw = $this->db->pdo()->prepare(
            'INSERT INTO employment_group (employment_id, group_id, assigned_at, assigned_by)
             SELECT e.id, g.id, UTC_TIMESTAMP(), :przez
               FROM employment e
               JOIN company_group g ON g.id = :grupa
              WHERE e.id IN (' . self::inSql($employmentIds) . ')
                AND e.company_id = g.company_id
                AND g.company_id = :company
                AND e.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM employment_group eg
                                 WHERE eg.employment_id = e.id AND eg.group_id = g.id)'
        );
        $wstaw->execute(
            self::inParams($employmentIds)
            + ['grupa' => $groupId, 'company' => $companyId, 'przez' => $przez]
        );

        $po = $this->czlonkostwaWGrupie($groupId, $companyId, $employmentIds);
        // ⚠ Pominięci to NIE błąd do przemilczenia: identyfikator z innej spółki albo
        // nieistniejący nie przechodzi przez warunek w `INSERT … SELECT`, a ekran ma o tym
        // powiedzieć, zamiast pokazać sukces na niepełnym zapisie.
        $pominieci = array_values(array_filter(
            $employmentIds,
            static fn (int $id): bool => !in_array($id, $po, true),
        ));

        return [
            'dodani' => array_values(array_diff($po, $przed)),
            'pominieci' => $pominieci,
        ];
    }

    /**
     * Usunięcie osób z grupy — z warunkiem spółki i grupy.
     *
     * ⚠ `eg.group_id = :grupa` nie jest tu wygodą, tylko granicą: bez niego wypisanie z jednej
     * grupy zdejmowałoby osobie **wszystkie** etykiety, czyli zmieniało stan, o którym ekran
     * nic nie mówi.
     *
     * @param list<int> $employmentIds
     * @return list<int> faktycznie usunięci
     */
    public function usunZGrupy(int $groupId, int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $byli = $this->czlonkostwaWGrupie($groupId, $companyId, $employmentIds);
        $stmt = $this->db->pdo()->prepare(
            'DELETE eg FROM employment_group eg
               JOIN employment e ON e.id = eg.employment_id
               JOIN company_group g ON g.id = eg.group_id AND g.company_id = e.company_id
              WHERE eg.employment_id IN (' . self::inSql($employmentIds) . ')
                AND eg.group_id = :grupa AND e.company_id = :company'
        );
        $stmt->execute(self::inParams($employmentIds) + ['grupa' => $groupId, 'company' => $companyId]);
        $po = $this->czlonkostwaWGrupie($groupId, $companyId, $employmentIds);

        return array_values(array_diff($byli, $po));
    }

    /**
     * Przynależności podanych zatrudnień — **tylko tych z tej spółki**.
     *
     * @param list<int> $employmentIds
     * @return array<int, array{grupy: list<array{id: int, nazwa: string}>, osoba: string, czynne: bool}>
     */
    public function przynaleznosci(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.is_active, p.first_name, p.last_name
               FROM employment e
               JOIN person p ON p.id = e.person_id
              WHERE e.id IN (' . self::inSql($employmentIds) . ') AND e.company_id = :company'
        );
        $stmt->execute(self::inParams($employmentIds) + ['company' => $companyId]);
        $wiersze = $stmt->fetchAll();
        $grupy = $this->grupyOsob($companyId, array_map(static fn (array $r): int => (int) $r['id'], $wiersze));
        $out = [];
        foreach ($wiersze as $r) {
            $out[(int) $r['id']] = [
                'grupy' => $grupy[(int) $r['id']] ?? [],
                'osoba' => NazwaOsoby::doWyswietlenia((string) $r['last_name'])
                    . ' ' . NazwaOsoby::doWyswietlenia((string) $r['first_name']),
                'czynne' => (bool) $r['is_active'],
            ];
        }

        return $out;
    }

    /**
     * Grupy podanych zatrudnień — **jedno zapytanie na całą listę**, nie jedno na osobę.
     *
     * @param list<int> $employmentIds
     * @return array<int, list<array{id: int, nazwa: string}>>
     */
    private function grupyOsob(int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT eg.employment_id, g.id, g.name
               FROM employment_group eg
               JOIN company_group g ON g.id = eg.group_id
               JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
              WHERE eg.employment_id IN (' . self::inSql($employmentIds) . ')
                AND g.company_id = :company
              ORDER BY g.name'
        );
        $stmt->execute(self::inParams($employmentIds) + ['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['employment_id']][] = ['id' => (int) $r['id'], 'nazwa' => (string) $r['name']];
        }

        return $out;
    }

    /**
     * Które z podanych zatrudnień są **w tej grupie** — stan przed i po zapisem mierzy się tym
     * samym zapytaniem, więc wynik mówi, co się faktycznie stało, a nie co miało się stać.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    private function czlonkostwaWGrupie(int $groupId, int $companyId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT eg.employment_id
               FROM employment_group eg
               JOIN company_group g ON g.id = eg.group_id
               JOIN employment e ON e.id = eg.employment_id AND e.company_id = g.company_id
              WHERE eg.employment_id IN (' . self::inSql($employmentIds) . ')
                AND eg.group_id = :grupa AND g.company_id = :company'
        );
        $stmt->execute(self::inParams($employmentIds) + ['grupa' => $groupId, 'company' => $companyId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<int> $ids */
    private static function inSql(array $ids): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':g' . $i, array_keys(array_values($ids))));
    }

    /**
     * @param list<int> $ids
     * @return array<string, int>
     */
    private static function inParams(array $ids): array
    {
        $out = [];
        foreach (array_values($ids) as $i => $id) {
            $out['g' . $i] = $id;
        }

        return $out;
    }
}
