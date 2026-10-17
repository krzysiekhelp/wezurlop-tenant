<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDOException;
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
     * Zapis nazwy wyświetlanej. Puste wejście znaczy „pokazuj nazwę z R2" i zapisuje `NULL`.
     *
     * ⚠ Jednoznaczność pilnuje **baza** (`uq_company_display_name`), a nie sprawdzenie przed
     * zapisem: „policz, czy zajęta, potem zapisz" to wyścig, w którym dwóch administratorów
     * ustawia tę samą nazwę dwóm spółkom i oba przechodzą. Łapiemy naruszenie klucza
     * i zamieniamy je na czytelną odmowę.
     *
     * ⚠ `NULL`, nie `''`: kont… spółek bez nazwy wyświetlanej może być wiele, a `UNIQUE`
     * nie liczy `NULL`-i. Zmierzone przed migracją: przy `''` druga taka spółka odbija się
     * o `1062 Duplicate entry ''`.
     *
     * @return string|null powód odmowy albo null przy zapisie
     */
    public function setDisplayName(int $companyId, ?string $nazwa): ?string
    {
        $wartosc = $nazwa === null || trim($nazwa) === '' ? null : trim($nazwa);

        return $this->db->transaction(function () use ($companyId, $wartosc): ?string {
            // ⚠ **Kolizja z nazwą z kadr INNEJ spółki — sprawdzenie w TEJ SAMEJ transakcji
            // co zapis** (zlecenie 08.10 §5). `UNIQUE` tego nie pilnuje, bo porównanie idzie
            // między dwiema różnymi kolumnami różnych wierszy, a sprawdzenie „policz, potem
            // zapisz" poza transakcją byłoby wyścigiem — tym samym, przed którym broni się
            // komentarz wyżej.
            // ⚠ `FOR UPDATE` nie jest ozdobą: blokuje pasujący wiersz (a w InnoDB także lukę,
            // gdy nic nie pasuje) do końca transakcji, więc drugi zapis tej samej nazwy czeka
            // i widzi już nasz stan, zamiast przechodzić obok.
            // ⚠ Czyszczenie nazwy (`null`) kolizji nie tworzy — wtedy ten odczyt pomijamy.
            if ($wartosc !== null) {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT id FROM company WHERE id <> :id AND name = :nazwa LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['id' => $companyId, 'nazwa' => $wartosc]);
                if ($stmt->fetchColumn() !== false) {
                    return self::POWOD_KOLIZJA_Z_KADRAMI;
                }
            }

            $stmt = $this->db->pdo()->prepare('UPDATE company SET display_name = :nazwa WHERE id = :id');
            try {
                $stmt->execute(['nazwa' => $wartosc, 'id' => $companyId]);
            } catch (PDOException $e) {
                // 23000 = naruszenie ograniczenia; tu możliwe jest tylko jedno — klucz unikalny nazwy.
                if ($e->getCode() === '23000') {
                    return self::POWOD_ZAJETA;
                }

                throw $e;
            }

            return null;
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
