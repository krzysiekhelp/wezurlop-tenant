<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Dziennik zdarzeń. `detail` nigdy nie zawiera hasła, tokenu, sekretu TOTP ani kodu zapasowego (CLAUDE.md §9).
 */
final class EventLogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, scalar|null> $detail */
    /** Liczba zdarzeń danego kodu w ostatnich $hours godzinach (diagnostyka). */
    public function countRecent(string $eventCode, int $hours): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM event_log WHERE event_code = :code AND occurred_at >= UTC_TIMESTAMP() - INTERVAL :hours HOUR'
        );
        $stmt->bindValue('code', $eventCode);
        $stmt->bindValue('hours', max(1, min($hours, 24 * 90)), \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ostatni kod zdarzenia dla podmiotu spośród podanych — po nim poznajemy, czy stan się ZMIENIŁ
     * (wykrycie kontra ustanie). Dzięki temu nie trzeba osobnej kolumny na „widzieliśmy to już”.
     *
     * @param list<string> $eventCodes
     */
    public function lastCodeFor(string $subjectType, int $subjectId, array $eventCodes): ?string
    {
        if ($eventCodes === []) {
            return null;
        }
        // ⚠ Do zapytania trafiają wyłącznie wygenerowane nazwy placeholderów (`:k0`, `:k1`…),
        // nigdy wartości — te idą przez `execute()` (CLAUDE.md §4).
        $stmt = $this->db->pdo()->prepare(
            'SELECT event_code FROM event_log
             WHERE subject_type = :stype AND subject_id = :sid AND event_code IN (' . self::placeholders($eventCodes) . ')
             ORDER BY id DESC LIMIT 1'
        );
        $params = ['stype' => $subjectType, 'sid' => $subjectId];
        foreach ($eventCodes as $i => $code) {
            $params['k' . $i] = $code;
        }
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /**
     * Lista nazw placeholderów dla `IN (...)`. Wartości nie dotyka — buduje wyłącznie `:k0, :k1…`.
     *
     * @param list<string> $codes
     */
    private static function placeholders(array $codes): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':k' . $i, array_keys(array_values($codes))));
    }

    /**
     * Strona dziennika do ekranu audytowego (etap 6).
     *
     * ⚠ „Kto" rozwiązujemy z `account_id` przez konto → osobę, a `actor_label` zostaje **tylko**
     * dla wpisów bez konta (`sync`, `narzedzie`). Zmierzone przed budową ekranu: `actor_label`
     * jest `NULL` w 427 z 481 wpisów, więc kolumna oparta na nim byłaby pusta dla 89% historii.
     *
     * ⚠ Nazwisko rozwiązuje się **w chwili czytania**, więc po zmianie nazwiska historia pokaże
     * nowe — decyzja z odbioru, nie skutek uboczny: audyt ma wskazywać osobę, a nie napis
     * sprzed roku. Utrwalanie etykiety przy zapisie byłoby osobną zmianą.
     *
     * ⚠ `ip_hash` **nie wychodzi** z repozytorium do widoku: to skrót, więc adresu z niego nie
     * odczyta nikt, a 64 znaki w kolumnie tylko zasłaniałyby treść (CLAUDE.md §3 — do szablonu
     * idzie wyłącznie to, co ma być pokazane).
     *
     * @return list<array<string, mixed>>
     */
    /** @param list<string> $prefiksy prefiksy kodów zdarzeń (filtr rodzaju, Z36) */
    public function page(?int $accountId, ?int $personId, int $limit, int $offset, array $prefiksy = []): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.occurred_at, e.event_code, e.account_id, e.actor_label,
                    e.subject_type, e.subject_id, e.company_id, e.detail,
                    ap.first_name AS aktor_imie, ap.last_name AS aktor_nazwisko,
                    sap.first_name AS podmiot_konto_imie, sap.last_name AS podmiot_konto_nazwisko,
                    sp.first_name AS podmiot_osoba_imie, sp.last_name AS podmiot_osoba_nazwisko,
                    ' . NazwaSpolki::SQL . ' AS spolka
             FROM event_log e
             LEFT JOIN account a   ON a.id = e.account_id
             LEFT JOIN person ap   ON ap.id = a.person_id
             LEFT JOIN account sa  ON e.subject_type = \'account\' AND sa.id = e.subject_id
             LEFT JOIN person sap  ON sap.id = sa.person_id
             LEFT JOIN person sp   ON e.subject_type = \'person\' AND sp.id = e.subject_id
             LEFT JOIN company c   ON c.id = e.company_id
             WHERE ' . self::filtrSql($accountId) . ' AND ' . self::rodzajSql($prefiksy) . '
             ORDER BY e.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach (self::filtrParams($accountId, $personId) as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }
        foreach (self::rodzajParams($prefiksy) as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'kiedy'      => (string) $row['occurred_at'],
            'kod'        => (string) $row['event_code'],
            'konto_id'   => $row['account_id'] === null ? null : (int) $row['account_id'],
            'aktor'      => self::osoba($row['aktor_imie'], $row['aktor_nazwisko']),
            'aktor_etykieta' => $row['actor_label'] === null ? null : (string) $row['actor_label'],
            'podmiot_typ' => $row['subject_type'] === null ? null : (string) $row['subject_type'],
            'podmiot_id' => $row['subject_id'] === null ? null : (int) $row['subject_id'],
            'podmiot'    => self::osoba($row['podmiot_konto_imie'], $row['podmiot_konto_nazwisko'])
                ?? self::osoba($row['podmiot_osoba_imie'], $row['podmiot_osoba_nazwisko']),
            'spolka'     => $row['spolka'] === null ? null : (string) $row['spolka'],
            'szczegoly'  => self::szczegoly($row['detail']),
            // ⚠ Odkodowane szczegóły dla `ZdanieZdarzenia` (Z36): `szczegoly` spłaszcza tablice
            // do JSON-a na potrzeby `title`, a zdanie potrzebuje ich jako tablic — inaczej
            // „dodani" wróciłyby na ekran w postaci, której ten ekran miał się pozbyć.
            'detale'     => self::detale($row['detail']),
        ], $stmt->fetchAll());
    }

    /** @param list<string> $prefiksy */
    public function countPage(?int $accountId, ?int $personId, array $prefiksy = []): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM event_log e WHERE ' . self::filtrSql($accountId)
            . ' AND ' . self::rodzajSql($prefiksy)
        );
        foreach (self::filtrParams($accountId, $personId) as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }
        foreach (self::rodzajParams($prefiksy) as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Konta, które w dzienniku w ogóle występują — lista wyboru do filtra.
     *
     * ⚠ Nie lista wszystkich kont tenanta: filtrowanie po koncie, którego w dzienniku nie ma,
     * daje puste tabele i pytanie „czy ekran działa". Lista jest z definicji krótsza niż
     * historia i nie rośnie z liczbą pracowników bez aktywności.
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function accountsInLog(): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT a.id, p.first_name, p.last_name, a.email
             FROM account a
             JOIN person p ON p.id = a.person_id
             WHERE EXISTS (SELECT 1 FROM event_log e WHERE e.account_id = a.id)
                OR EXISTS (SELECT 1 FROM event_log e WHERE e.subject_type = 'account' AND e.subject_id = a.id)
                OR EXISTS (SELECT 1 FROM event_log e WHERE e.subject_type = 'person' AND e.subject_id = a.person_id)
             ORDER BY p.last_name, p.first_name, a.id"
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id'    => (int) $row['id'],
            'nazwa' => self::osoba($row['first_name'], $row['last_name']) ?? ('konto #' . $row['id']),
        ], $rows);
    }

    /**
     * Warunek filtra rodzaju — `event_code LIKE 'auth.%' OR …` (zlecenie Z36 §3).
     *
     * ⚠ **Każdy prefiks dostaje WŁASNY placeholder** (`:rodzaj0`, `:rodzaj1`, …). Przy
     * `ATTR_EMULATE_PREPARES => false` ten sam `:nazwa` użyty dwa razy kończy się błędem HY093,
     * więc numerowanie nie jest ozdobą. Same prefiksy pochodzą z `KodyZdarzen`, nie z adresu.
     *
     * @param list<string> $prefiksy
     */
    private static function rodzajSql(array $prefiksy): string
    {
        if ($prefiksy === []) {
            return '1 = 1';
        }
        $warunki = [];
        foreach (array_keys($prefiksy) as $i) {
            $warunki[] = 'e.event_code LIKE :rodzaj' . $i;
        }

        return '(' . implode(' OR ', $warunki) . ')';
    }

    /**
     * @param list<string> $prefiksy
     * @return array<string, string>
     */
    private static function rodzajParams(array $prefiksy): array
    {
        $out = [];
        foreach ($prefiksy as $i => $prefiks) {
            // ⚠ `%` doklejamy TUTAJ, nie w SQL-u: wartość idzie placeholderem w całości,
            // więc `_` i `%` z nazwy rodziny nie mają jak zmienić wzorca (CLAUDE.md §4).
            $out['rodzaj' . $i] = $prefiks . '%';
        }

        return $out;
    }

    /**
     * Warunek filtra — konto jako **sprawca albo podmiot**, plus zdarzenia jego osoby.
     *
     * ⚠ Trzy członki, nie jeden, bo inaczej historia konta nie zawierałaby jego własnej blokady:
     * przy `account.blocked_manually` sprawcą jest administrator, a zablokowane konto jest
     * dopiero podmiotem. Ekran, na który wchodzi się z panelu osoby, ma pokazać wszystko,
     * co tej osoby dotyczyło — także to, co zrobiono JEJ, nie tylko to, co zrobiła.
     */
    private static function filtrSql(?int $accountId): string
    {
        if ($accountId === null) {
            return '1 = 1';
        }

        return '(e.account_id = :konto
                 OR (e.subject_type = \'account\' AND e.subject_id = :konto_podmiot)
                 OR (e.subject_type = \'person\' AND e.subject_id = :osoba))';
    }

    /** @return array<string, int> */
    private static function filtrParams(?int $accountId, ?int $personId): array
    {
        if ($accountId === null) {
            return [];
        }

        // Osoba nieznana (konto bez kartoteki) — podstawiamy 0, które nie istnieje jako identyfikator.
        return ['konto' => $accountId, 'konto_podmiot' => $accountId, 'osoba' => $personId ?? 0];
    }

    private static function osoba(mixed $imie, mixed $nazwisko): ?string
    {
        if (!is_string($imie) || !is_string($nazwisko)) {
            return null;
        }
        $nazwa = NazwaOsoby::imieNazwisko($imie, $nazwisko);

        return $nazwa === '' ? null : $nazwa;
    }

    /**
     * Ładunek jako pary „klucz: wartość" do wyświetlenia.
     *
     * ⚠ Audyt 481 wpisów przed uruchomieniem ekranu: w ładunkach są wyłącznie liczniki, flagi
     * i etykiety techniczne — zero adresów, PESEL-i, NIP-ów, telefonów i tokenów. Dlatego
     * pokazujemy je bez filtra. ⚠ Gdy pojawi się pierwszy ładunek budowany z danych
     * użytkownika, właściwym miejscem reguły jest **zapis**, nie to wyświetlanie (CLAUDE.md §14).
     *
     * @return list<array{klucz: string, wartosc: string}>
     */
    /**
     * Szczegóły wpisu jako tablica, bez spłaszczania — wyłącznie dla budowania zdania.
     *
     * @return array<string, mixed>
     */
    private static function detale(mixed $detail): array
    {
        if (!is_string($detail) || $detail === '') {
            return [];
        }
        $dane = json_decode($detail, true);

        return is_array($dane) ? $dane : [];
    }

    private static function szczegoly(mixed $detail): array
    {
        if (!is_string($detail) || $detail === '') {
            return [];
        }
        $dane = json_decode($detail, true);
        if (!is_array($dane)) {
            return [];
        }
        $out = [];
        foreach ($dane as $klucz => $wartosc) {
            $out[] = [
                'klucz'   => (string) $klucz,
                'wartosc' => match (true) {
                    is_bool($wartosc)   => $wartosc ? 'tak' : 'nie',
                    is_scalar($wartosc) => (string) $wartosc,
                    $wartosc === null   => '—',
                    default             => json_encode($wartosc, JSON_UNESCAPED_UNICODE) ?: '—',
                },
            ];
        }

        return $out;
    }

    /**
     * Zapis jednego wpisu do dziennika.
     *
     * ⚠ **REGUŁA TREŚCI `$detail` — dwa dopuszczalne rodzaje wpisu i jeden niedopuszczalny**
     * (rozstrzygnięcie 08.10.2026, po stracie danych opisanej niżej):
     *
     *   1. wpis opisujący **ZMIANĘ** niesie wartość **przed i po** (`z` / `na`, albo
     *      `poziom_przed` / `poziom`) — bo pytanie, które się przy nim zadaje, brzmi
     *      „co tam było wcześniej";
     *   2. wpis opisujący **ZDARZENIE** (logowanie, odmowa, próba, przebieg crona) niesie to,
     *      co się stało — bo nie ma „wartości przed";
     *   3. ⚠ **„coś się zmieniło" bez wartości — np. `['ustawiono' => true]` — jest USTERKĄ,
     *      nie stylem.** To rejestr, który wygląda na działający i nie zapisuje nic użytecznego,
     *      czyli ta sama rodzina co warunek nie łapiący niczego i miernik liczący na pustym
     *      zbiorze. Zmierzony koszt: 06.10.2026 skrypt nadpisał nazwę wyświetlaną spółki wpisaną
     *      ręcznie i odtworzyć jej **nie było z czego**, bo wpis mówił tylko „ustawiono".
     *
     * ⚠ **Wyjątek, który NIE jest usterką i ma taki zostać.** Gdy zmieniane pole jest hasłem,
     * sekretem, identyfikatorem krajowym albo inną daną osobową (CLAUDE.md §14), do dziennika
     * idzie **wyłącznie fakt zmiany** — i wtedy wariant „że się zmieniło" jest poprawny.
     * Dotyczy to dziś: `person.email_set`, `person.contact_changed` (dane kontaktowe osoby),
     * `auth.password_changed` i rodziny `2fa.*` (sekret TOTP, kody zapasowe), oraz pola `haslo`
     * w `tenant.mail_settings_changed`. **Nie „naprawiaj" ich dopisaniem wartości.**
     */
    public function record(
        string $eventCode,
        ?int $accountId,
        ?string $ipHash,
        ?string $actorLabel = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $companyId = null,
        array $detail = [],
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO event_log (occurred_at, account_id, company_id, actor_label, event_code,
                subject_type, subject_id, ip_hash, detail)
             VALUES (UTC_TIMESTAMP(), :account, :company, :actor, :code, :stype, :sid, :ip, :detail)'
        );
        $stmt->execute([
            'account' => $accountId,
            'company' => $companyId,
            'actor'   => $actorLabel,
            'code'    => $eventCode,
            'stype'   => $subjectType,
            'sid'     => $subjectId,
            'ip'      => $ipHash,
            'detail'  => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
