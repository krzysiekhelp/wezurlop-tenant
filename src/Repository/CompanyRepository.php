<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Spółki tenanta — na razie wyłącznie to, czego potrzebuje ekran `Ustawienia → Spółki`
 * (zlecenie `konta-lista-dostep` §8).
 *
 * ⚠ Nazwa z R2 (`company.name`) jest **tylko do odczytu**: przychodzi z programu kadrowego
 * i następna synchronizacja i tak ją nadpisze. Nazwa wyświetlana (`display_name`) jest nasza —
 * synchronizacja jej nie dotyka i nic z niej nie wychodzi do R2.
 */
final class CompanyRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Odmowa zapisu: ta nazwa wyświetlana jest już zajęta przez inną spółkę. */
    public const POWOD_ZAJETA = 'zajeta';

    /**
     * Nazwa wyświetlana równa **nazwie z programu kadrowego innej spółki**.
     *
     * ⚠ Osobny powód, nie ten sam co `POWOD_ZAJETA`: człowiek musi wiedzieć, z czym koliduje,
     * bo w ekranie ustawień widzi tylko kolumnę nazw wyświetlanych — a kolizja jest z kolumną
     * obok, której nie edytuje.
     */
    public const POWOD_KOLIZJA_Z_KADRAMI = 'kolizja_kadry';

    /** Odmowa zapisu: w bazie nie ma firmy o tym identyfikatorze. */
    public const POWOD_NIEZNANA = 'nieznana';

    /**
     * Spółki z liczbą osób — do ekranu ustawień.
     *
     * ⚠ Liczymy **osoby**, nie zatrudnienia: osoba z dwiema umowami w jednej spółce to jeden
     * człowiek, a to on jest jednostką, o którą pyta administrator.
     *
     * @return list<array{id: int, nazwa_r2: string, nip: string, nazwa_wyswietlana: ?string, aktywna: bool, osoby: int}>
     */
    public function listWithCounts(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT c.id, c.name, c.nip, c.display_name, c.is_active,
                    (SELECT COUNT(DISTINCT e.person_id) FROM employment e
                      WHERE e.company_id = c.id AND e.is_active = 1) AS osoby
             FROM company c
             ORDER BY c.is_active DESC, ' . NazwaSpolki::SQL . ', c.id'
        );

        return array_map(static fn (array $r): array => [
            'id'                => (int) $r['id'],
            'nazwa_r2'          => (string) $r['name'],
            // ⚠ NIP rozstrzyga tam, gdzie nazwy w grupie kapitałowej różnią się jednym słowem.
            // Kolumna jest w bazie od Etapu 3 (klucz mapowania firm) — nowego pola
            // do synchronizacji nie dokładam.
            'nip'               => (string) $r['nip'],
            'nazwa_wyswietlana' => $r['display_name'] === null ? null : (string) $r['display_name'],
            'aktywna'           => (bool) $r['is_active'],
            'osoby'             => (int) $r['osoby'],
        ], $stmt->fetchAll());
    }

    /**
     * Zapis nazwy wyświetlanej JEDNEJ firmy. Puste wejście znaczy „pokazuj nazwę z R2".
     *
     * ⚠ Od Z29 to **cienka nakładka na `setDisplayNames()`**, a nie druga implementacja: ekran
     * ustawień zapisuje wszystkie firmy jednym żądaniem, a ta metoda została dla wywołań
     * jednostkowych (próbka kolizji w `dev/`). Dwa miejsca z tą samą regułą jednoznaczności
     * rozjechałyby się przy pierwszej zmianie — i to nie teoretycznie, bo reguł jest trzy.
     *
     * @return string|null powód odmowy albo null przy zapisie
     */
    public function setDisplayName(int $companyId, ?string $nazwa): ?string
    {
        return $this->setDisplayNames([$companyId => $nazwa])[$companyId] ?? null;
    }

    /**
     * **Zapis nazw wyświetlanych WIELU firm naraz — jedna transakcja, wszystko albo nic**
     * (zlecenie Z29, 18.10.2026: okno „Nazwy wyświetlane firm" zapisuje cały zestaw jednym
     * przyciskiem).
     *
     * ⚠ **Dlaczego zbiorczo, a nie pętlą po `setDisplayName()`:** jednoznaczność trzeba
     * sprawdzić dla CAŁEGO zestawu po zmianie, a nie dla każdej firmy osobno. Zamiana nazw
     * między dwiema firmami („ALFA" ↔ „BETA") w pętli odbija się o klucz unikalny na pierwszym
     * zapisie, choć stan końcowy jest poprawny. I odwrotnie: dwa pola wypełnione tą samą nazwą
     * przeszłyby pierwszy zapis i wywróciły się na drugim, zostawiając połowę zmian.
     *
     * ⚠ **Wszystkie wiersze firm idą pod blokadę** (`FOR UPDATE`) i dopiero wtedy liczymy stan
     * docelowy: inaczej między sprawdzeniem a zapisem mieści się cudzy zapis. Sprawdzenie
     * obejmuje też firmy **spoza formularza**, bo ich nazwy współtworzą zbiór, w którym
     * ma panować jednoznaczność.
     *
     * ⚠ Porównania idą **bez wielkości liter** (`mb_strtolower`), bo tak porównuje je baza
     * (`utf8mb4_unicode_ci`). Gdyby porównywać dokładnie, „alfa" przeszłoby nasze sprawdzenie
     * i odbiło się dopiero o klucz unikalny — czyli błędem technicznym zamiast czytelną odmową.
     *
     * ⚠ Przy choćby jednej odmowie **nie zapisujemy NICZEGO** i wychodzimy przed pierwszym
     * `UPDATE`. Transakcja domyka się wtedy pusta, co jest tańsze niż wycofanie, a dla
     * wywołującego znaczy dokładnie to samo: stan bazy jest sprzed żądania.
     *
     * ⚠ Naruszenia klucza unikalnego **nie łapiemy tutaj**: po blokadzie wszystkich wierszy
     * i sprawdzeniu całego zestawu 23000 może znaczyć już tylko prawdziwy wyścig na poziomie
     * bazy, a nie pomyłkę człowieka. Wyjątek leci dalej, transakcja się wycofuje i administrator
     * dostaje stronę błędu z identyfikatorem zdarzenia — uczciwiej niż „nazwa zajęta" przy polu,
     * w którym nic złego nie wpisał.
     *
     * @param  array<int, ?string> $nazwy id firmy → nazwa wyświetlana (null albo '' = nazwa z R2)
     * @return array<int, string>         id firmy → powód odmowy; pusta tablica = zapisano
     */
    public function setDisplayNames(array $nazwy): array
    {
        if ($nazwy === []) {
            return [];
        }

        return $this->db->transaction(function () use ($nazwy): array {
            $stmt = $this->db->pdo()->query('SELECT id, name, display_name FROM company FOR UPDATE');
            /** @var array<int, array{nazwa: string, wyswietlana: ?string}> $firmy */
            $firmy = [];
            foreach ($stmt->fetchAll() as $wiersz) {
                $firmy[(int) $wiersz['id']] = [
                    'nazwa'       => (string) $wiersz['name'],
                    'wyswietlana' => $wiersz['display_name'] === null ? null : (string) $wiersz['display_name'],
                ];
            }

            $bledy = [];
            foreach (array_keys($nazwy) as $id) {
                if (!isset($firmy[$id])) {
                    $bledy[$id] = self::POWOD_NIEZNANA;
                }
            }
            if ($bledy !== []) {
                return $bledy;
            }

            // Stan docelowy CAŁEGO zbioru: firmy z formularza biorą nową wartość, pozostałe
            // zostają przy swojej — i jedne, i drugie wchodzą do sprawdzenia jednoznaczności.
            $docelowe = [];
            foreach ($firmy as $id => $firma) {
                $docelowe[$id] = array_key_exists($id, $nazwy)
                    ? (trim((string) $nazwy[$id]) === '' ? null : trim((string) $nazwy[$id]))
                    : $firma['wyswietlana'];
            }

            $klucz = static fn (string $tekst): string => mb_strtolower($tekst);

            // ⚠ **Kolejność sprawdzeń ma znaczenie i nie jest przypadkowa.** Nazwa potrafi
            // kolidować na oba sposoby naraz — tak jest, gdy inna firma ma nazwę wyświetlaną
            // równą swojej nazwie z kadr, a człowiek wpisze właśnie ją. Pierwszeństwo ma wtedy
            // kolizja z programem kadrowym, bo ona jest mniej oczywista: kolidująca wartość stoi
            // w kolumnie, której w oknie nie widać i której się nie edytuje. „Tej nazwy używa już
            // inna firma" kazałoby szukać jej wśród nazw wyświetlanych, gdzie jej nie ma.
            // 1. nazwa wyświetlana równa nazwie z programu kadrowego INNEJ firmy
            foreach ($docelowe as $id => $wartosc) {
                if ($wartosc === null) {
                    continue;
                }
                foreach ($firmy as $innyId => $firma) {
                    if ($innyId !== $id && $klucz($firma['nazwa']) === $klucz($wartosc)) {
                        $bledy[$id] = self::POWOD_KOLIZJA_Z_KADRAMI;
                        break;
                    }
                }
            }

            // 2. dwie firmy z tą samą nazwą wyświetlaną
            $zajete = [];
            foreach ($docelowe as $id => $wartosc) {
                if ($wartosc === null) {
                    continue;
                }
                $k = $klucz($wartosc);
                if (isset($zajete[$k])) {
                    // ⚠ Błąd przypisujemy polu Z FORMULARZA: kolizję widać przy tym, co człowiek
                    // właśnie wpisał, a nie przy firmie, której w oknie nie dotykał.
                    $winny = array_key_exists($id, $nazwy) ? $id : $zajete[$k];
                    if (!isset($bledy[$winny])) {
                        $bledy[$winny] = self::POWOD_ZAJETA;
                    }
                    continue;
                }
                $zajete[$k] = $id;
            }

            if ($bledy !== []) {
                return $bledy;
            }

            $zapis = $this->db->pdo()->prepare('UPDATE company SET display_name = :nazwa WHERE id = :id');
            foreach (array_keys($nazwy) as $id) {
                // Wiersza bez zmiany nie dotykamy — inaczej dziennik zdarzeń dostawałby wpis
                // „z X na X" za każdym kliknięciem „Zapisz nazwy".
                if ($docelowe[$id] === $firmy[$id]['wyswietlana']) {
                    continue;
                }
                $zapis->execute(['nazwa' => $docelowe[$id], 'id' => $id]);
            }

            return [];
        });
    }

    /** @return array{id: int, nazwa_r2: string, nazwa_wyswietlana: ?string}|null */
    /**
     * **Pełna nazwa z R2** spółki, w której konto ma aktywne zatrudnienie — do wiadomości.
     *
     * ⚠ Świadomie **nie** nazwa wyświetlana (`NazwaSpolki`): w wiadomości urzędowej liczy się
     * nazwa, którą pracownik ma na umowie. Nasza krótka nazwa służy do tego, żeby zmieścić się
     * w kolumnie tabeli, a nie do tego, żeby przedstawiać pracodawcę.
     *
     * ⚠ Nazwa firmy w wiadomości nie jest ozdobą: bez niej zaproszenie wygląda jak phishing
     * i tak zostanie potraktowane (zlecenie `szablon-poczty` §4).
     */
    public function r2NameForAccount(int $accountId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.name FROM employment e
             JOIN account a ON a.person_id = e.person_id
             JOIN company c ON c.id = e.company_id
             WHERE a.id = :account AND e.is_active = 1 AND c.is_active = 1
             ORDER BY c.name, e.id LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);
        $nazwa = $stmt->fetchColumn();

        return is_string($nazwa) && $nazwa !== '' ? $nazwa : null;
    }

    /**
     * Pełna nazwa z R2, gdy tenant ma **dokładnie jedną** spółkę.
     *
     * ⚠ Ścieżka zapasowa dla kont bez zatrudnienia (administrator tenanta, kadry „spoza kadr").
     * Przy dwóch i więcej spółkach zwracamy `null`, bo zgadywanie, w imieniu której spółki
     * piszemy, jest gorsze niż napisanie bez nazwy.
     */
    public function singleR2Name(): ?string
    {
        $rows = $this->db->pdo()->query('SELECT name FROM company WHERE is_active = 1 LIMIT 2')->fetchAll();

        return count($rows) === 1 && is_string($rows[0]['name']) && $rows[0]['name'] !== ''
            ? $rows[0]['name']
            : null;
    }

    public function find(int $companyId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, display_name FROM company WHERE id = :id');
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch();

        return is_array($row)
            ? ['id' => (int) $row['id'], 'nazwa_r2' => (string) $row['name'],
                'nazwa_wyswietlana' => $row['display_name'] === null ? null : (string) $row['display_name']]
            : null;
    }
}
