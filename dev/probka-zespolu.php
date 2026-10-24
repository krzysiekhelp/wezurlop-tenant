<?php

declare(strict_types=1);

/*
 * **Struktura próbna do pomiaru DODATNIEJ strony reguły „Zespół"** (zlecenie Z71, 21.10.2026).
 * Wejście dla bramki ekranowej; **nie jedzie do paczki klienta** (katalog `dev/`).
 *
 * ⚠ **Po co to istnieje.** Kryterium `przelacznik_zespolu` (Z70) mierzyło dotąd tylko stronę
 * ujemną: konto z wglądem w całą firmę NIE MA pozycji „Zespół". Strony dodatniej nie dało się
 * zmierzyć, bo jedyny przełożony z niepustą gałęzią w bazie roboczej to **prawdziwa osoba
 * z prawdziwymi podwładnymi**, a dopisanie hasła do jej konta do pliku kont deweloperskich
 * byłoby otwarciem dostępu do prawdziwych danych kadrowych (rozstrzygnięcie Krzysztofa
 * z 21.10.2026 — wprost odmówił). Dlatego bramka zakłada **własną gałąź na własnych
 * obiektach**, mierzy ją i kasuje.
 *
 * ⚠ **Podwładni NIE DOSTAJĄ kont, choć zlecenie o nich mówiło — i to jest świadome.**
 * Widok zespołu buduje się z ZATRUDNIEŃ (`HierarchyRepository::krawedzie()` czyta
 * `employment.supervisor_employment_id`), więc konta podwładnych nie zmieniłyby ani jednej
 * mierzonej liczby. Zmieniłyby natomiast coś innego: każde konto to **zajęte miejsce
 * licencyjne** (`AccountRepository::countLicensedAccesses()`), a próbka chodzi na instancji,
 * która jest używana — trzy konta więcej na czas pomiaru mogą wyczerpać limit licencji
 * i wywrócić zupełnie inny ekran. Konto ma więc tylko przełożony, bo tylko on się loguje.
 *
 * ⚠ **Nazwiska jawnie fikcyjne, jedno na wszystkie wiersze** (`__probka_zespolu`): to jest
 * znacznik, po którym `sprzataj` rozpoznaje WSZYSTKO, co próbka założyła — także po przerwanym
 * przebiegu, gdy `finally` nie zdążyło się wykonać. Dzięki temu śmieć po zabitym pomiarze
 * usuwa następne uruchomienie, a nie człowiek ręcznie (lekcja z testu „Mojego konta", 21.10).
 *
 * ⚠ **Nieobecności dołożone 24.10.2026 (zlecenie Z83) i są warunkiem, nie ozdobą.** Kryterium
 * `zasieg` sprawdza NAJWAŻNIEJSZĄ rzecz na siatce zespołu: że pasek CUDZEJ osoby mówi tylko
 * „nieobecny" i nie wypuszcza nazwy rodzaju ze słownika kadr, czyli danej o zdrowiu. Bez ani
 * jednego paska to sprawdzenie przechodzi NA PUSTO i nie dowodzi niczego — a dotąd brało paski
 * prawdziwych ludzi, czyli mierzyło cudze dane kadrowe. Jedna nieobecność na osobę wystarcza:
 * mierzymy treść paska, nie ich liczbę.
 * ⚠ Rodzaj bierzemy **pierwszy z listy rodzajów nieobecności** (`leave_type.is_absence = 1`),
 * bo nazwa rodzaju jest tu nieistotna — istotne, że NIE WOLNO jej zobaczyć u cudzej osoby.
 *
 * ⚠ **Niczego istniejącego nie ruszamy**: osoby, zatrudnienia i hierarchia prawdziwych ludzi
 * nie są czytane do zapisu ani przestawiane. Przełożony próbki jest korzeniem swojej własnej
 * gałęzi (`supervisor_employment_id = NULL`), więc nie podwiesza się pod niczyją strukturę.
 *
 * Tryby:
 *   utworz    — osoba-przełożony z etatem, kontem (hasło losowe) i rolą `employee`
 *               plus trzech podwładnych z etatami podwieszonymi pod jego etat
 *               oraz po jednej NIEOBECNOŚCI dla każdego z nich i dla przełożonego
 *   sprzataj  — usunięcie wszystkiego, co próbka założyła (po znaczniku nazwiska)
 *   liczniki  — same liczby wierszy w tabelach, których próbka dotyka (stan przed i po)
 *
 * Wywołanie: `php dev/probka-zespolu.php utworz`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\Password;

const NAZWISKO = '__probka_zespolu';
const LOGIN = 'probka-zespolu@pomiar.invalid';
/** Ilu podwładnych zakłada próbka — liczba jest też oczekiwaniem pomiaru. */
const PODWLADNYCH = 3;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
$db = new Connection([
    'host' => (string) $env['DB_HOST'],
    'port' => (int) $env['DB_PORT'],
    'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'],
    'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();
$permissions = new PermissionRepository($db);

/** Liczniki tabel, których próbka dotyka — do porównania stanu przed i po (Z71). */
$liczniki = static function () use ($pdo): array {
    $jedna = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    return [
        'osoby' => $jedna('SELECT COUNT(*) FROM person'),
        'zatrudnienia' => $jedna('SELECT COUNT(*) FROM employment'),
        'konta' => $jedna('SELECT COUNT(*) FROM account'),
        'hierarchia' => $jedna('SELECT COUNT(*) FROM employment WHERE supervisor_employment_id IS NOT NULL'),
        'sesje' => $jedna('SELECT COUNT(*) FROM session'),
        'role' => $jedna('SELECT COUNT(*) FROM role_assignment'),
        'nieobecnosci' => $jedna('SELECT COUNT(*) FROM external_absence'),
    ];
};

/** Wszystkie osoby próbki — po znaczniku nazwiska, więc łapie też ślady po przerwanym przebiegu. */
$osobyProbki = static function () use ($pdo): array {
    $st = $pdo->prepare('SELECT id FROM person WHERE last_name = :n ORDER BY id');
    $st->execute(['n' => NAZWISKO]);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

$sprzataj = static function () use ($pdo, $osobyProbki): array {
    $osoby = $osobyProbki();
    if ($osoby === []) {
        return ['osob' => 0, 'etatow' => 0, 'kont' => 0];
    }
    // ⚠ **Wszystko przez PLACEHOLDERY, także liczby z własnego zapytania** (CLAUDE.md §4,
    // wyłapane przez `bin/check-rules.php` przy pierwszej wersji tego pliku). Lista `IN (…)`
    // nie da się sparametryzować jednym znakiem, więc **budujemy tyle placeholderów, ile jest
    // wierszy** — nazwy pochodzą z LICZNIKA, nie z danych, i to jest cała różnica między
    // białą listą a konkatenacją.
    // ⚠ Lista placeholderów zostaje TABLICĄ, a składa się ją `implode()` **w miejscu użycia**:
    // tak robią repozytoria (`LeaveRequestRepository`, `CalendarAccessRepository::inSql()`)
    // i tylko taka postać przechodzi `bin/check-rules.php` — wzorzec zakazuje doklejania
    // ZMIENNEJ do literału SQL, bo po zmiennej nie widać, czy niesie placeholdery czy dane.
    $miejsca = array_map(static fn (int $i): string => ':o' . $i, array_keys($osoby));
    $parametry = [];
    foreach ($osoby as $i => $id) {
        $parametry['o' . $i] = $id;
    }
    // ⚠ Kolejność ma znaczenie: najpierw to, co wskazuje na etaty i konta, potem one, na końcu
    // osoby. Każde usunięcie osobno i odporne na błąd poprzedniego — po przerwanym przebiegu
    // część wierszy może już nie istnieć, a sprzątanie nie ma prawa stanąć na pierwszym braku.
    $kont = 0;
    $etatow = 0;
    $krok = static function (string $sql, array $par) use ($pdo): int {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($par);

            return $st->rowCount();
        } catch (Throwable) {
            return 0;
        }
    };
    // Konta próbki (po osobach) — razem z sesjami, rolami i wpisami, które mogły powstać.
    $st = $pdo->prepare('SELECT id FROM account WHERE person_id IN (' . implode(',', $miejsca) . ')');
    $st->execute($parametry);
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
        foreach (['session', 'role_assignment', 'login_attempt', 'event_log', 'backup_code'] as $tabela) {
            // ⚠ Nazwa tabeli z białej listy w pętli, nigdy z danych — jedyna rzecz, której
            // nie da się podać placeholderem (CLAUDE.md §4).
            $krok(sprintf('DELETE FROM `%s` WHERE account_id = :k', $tabela), ['k' => $konto]);
        }
        $kont += $krok('DELETE FROM account WHERE id = :k', ['k' => $konto]);
    }
    // ⚠ Nieobecności PRZED etatami — wskazują i etat, i osobę, więc klucz obcy nie pozwoliłby
    // usunąć żadnego z nich pierwszym. Kasujemy po OSOBIE próbki, nie po znaczniku w treści:
    // `r2_record_id` da się przeoczyć przy zmianie formatu, a osoba jest tą samą kotwicą,
    // co dla całego sprzątania.
    $krok('DELETE FROM external_absence WHERE person_id IN (' . implode(',', $miejsca) . ')',
        $parametry);
    // ⚠ Podwieszenie zdejmujemy PRZED usunięciem etatów: etat przełożonego jest wskazywany
    // przez etaty podwładnych, a klucz obcy nie pozwoliłby go usunąć pierwszym.
    $krok('UPDATE employment SET supervisor_employment_id = NULL WHERE person_id IN ('
        . implode(',', $miejsca) . ')', $parametry);
    $etatow += $krok('DELETE FROM employment_group WHERE employment_id IN
        (SELECT id FROM employment WHERE person_id IN (' . implode(',', $miejsca) . '))', $parametry);
    $etatow += $krok('DELETE FROM employment WHERE person_id IN (' . implode(',', $miejsca) . ')',
        $parametry);
    $osob = $krok('DELETE FROM person WHERE id IN (' . implode(',', $miejsca) . ')', $parametry);

    return ['osob' => $osob, 'etatow' => $etatow, 'kont' => $kont];
};

$tryb = $argv[1] ?? '';

if ($tryb === 'liczniki') {
    echo json_encode($liczniki(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

if ($tryb === 'sprzataj') {
    echo json_encode($sprzataj() + ['liczniki' => $liczniki()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

if ($tryb !== 'utworz') {
    fwrite(STDERR, "Użycie: probka-zespolu.php utworz|sprzataj|liczniki\n");
    exit(1);
}

// ⚠ Ślady po poprzednim przebiegu sprzątamy PRZED utworzeniem, nie po: adres konta jest
// `UNIQUE`, więc zostawiona próbka wywróciłaby `utworz` i pomiar nie wykonałby się wcale.
$sprzataj();
$przed = $liczniki();

// ⚠ **Hasło losowe na jedno uruchomienie, nigdzie nie zapisywane** (audyt Z1): konto żyje tyle,
// ile pomiar, i ginie razem z nim. Wartość idzie na wyjście skryptu — stamtąd bierze ją bramka —
// i nie trafia ani do pliku kont, ani do raportu.
$haslo = bin2hex(random_bytes(12));

// ⚠ Firma z NAJWIĘKSZĄ liczbą etatów: gałąź próbki (4 osoby) musi być MNIEJSZA niż cała firma,
// bo inaczej reguła `zespolMaSens()` słusznie schowałaby pozycję „Zespół" i pomiar mierzyłby
// coś innego, niż zamierza.
$spolka = (int) $pdo->query(
    'SELECT c.id FROM company c
       JOIN employment e ON e.company_id = c.id AND e.is_active = 1
      WHERE c.is_active = 1
      GROUP BY c.id
      ORDER BY COUNT(*) DESC
      LIMIT 1'
)->fetchColumn();
if ($spolka === 0) {
    fwrite(STDERR, "Brak firmy z aktywnymi zatrudnieniami — próbki nie da się założyć.\n");
    exit(1);
}

$nowaOsoba = static function (string $imie) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO person (first_name, last_name, outside_hr, created_at)
         VALUES (:imie, :n, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['imie' => $imie, 'n' => NAZWISKO]);

    return (int) $pdo->lastInsertId();
};
$nowyEtat = static function (int $osoba, int $spolka, ?int $przelozony) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO employment (person_id, company_id, supervisor_employment_id, fte_numerator,
                                 fte_denominator, fte, contract_employment, is_active, created_at)
         VALUES (:osoba, :spolka, :szef, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['osoba' => $osoba, 'spolka' => $spolka, 'szef' => $przelozony]);

    return (int) $pdo->lastInsertId();
};

$szefOsoba = $nowaOsoba('Przełożony');
$szefEtat = $nowyEtat($szefOsoba, $spolka, null);

$st = $pdo->prepare(
    "INSERT INTO account (person_id, email, password_hash, status, created_at)
     VALUES (:osoba, :mail, :hash, 'active', UTC_TIMESTAMP())"
);
$st->execute(['osoba' => $szefOsoba, 'mail' => LOGIN, 'hash' => (new Password(dirname(__DIR__) . '/var/cache/password-dummy.hash'))->hash($haslo)]);
$szefKonto = (int) $pdo->lastInsertId();

// ⚠ Rola `employee` jest warunkiem DOSTĘPU do firmy (`EmploymentRepository::accessibleForAccount`),
// nie ozdobą: bez niej konto z etatem nie zobaczy kalendarza tej firmy wcale. Nadajemy ją drogą
// repozytorium, nie `INSERT`-em — próbka, która zapisuje własnym SQL-em, mierzy siebie.
$permissions->assignRole($szefKonto, 'employee', $spolka, null);

$podwladni = [];
foreach (range(1, PODWLADNYCH) as $nr) {
    $osoba = $nowaOsoba('Podwładny' . $nr);
    $podwladni[] = ['osoba' => $osoba, 'etat' => $nowyEtat($osoba, $spolka, $szefEtat)];
}

// ⚠ **Nieobecności: po jednej na każdy etat próbki, w BIEŻĄCYM miesiącu.** Bieżący, bo widok
// zespołu otwiera się na nim domyślnie — nieobecność w innym miesiącu byłaby niewidoczna,
// dopóki pomiar nie przestawi okresu, a wtedy „pasek jest ogólny" zależałoby od nawigacji.
// ⚠ Trzy dni z rzędu, od 10. do 12.: mieszczą się w każdym miesiącu i w żadnym nie wypadają
// na samej krawędzi siatki.
$rodzaj = (int) $pdo->query('SELECT id FROM leave_type WHERE is_absence = 1 ORDER BY id LIMIT 1')
    ->fetchColumn();
$nieobecnosci = [];
if ($rodzaj > 0) {
    $wstawNieobecnosc = static function (int $osoba, int $etat, int $spolka, int $rodzaj) use ($pdo): int {
        $st = $pdo->prepare(
            'INSERT INTO external_absence (company_id, employment_id, person_id, leave_type_id,
                                           r2_record_id, date_from, date_to, days, minutes,
                                           entry_source, out_of_window, fetched_at)
             VALUES (:spolka, :etat, :osoba, :rodzaj, :znak,
                     DATE_FORMAT(UTC_DATE(), "%Y-%m-10"), DATE_FORMAT(UTC_DATE(), "%Y-%m-12"),
                     3, 0, "kadry", 0, UTC_TIMESTAMP())'
        );
        // ⚠ Znacznik w `r2_record_id` jest jawnie próbny: wiersz z `entry_source = "kadry"`
        // bez takiego znacznika wyglądałby jak dana przysłana przez connector.
        $st->execute(['spolka' => $spolka, 'etat' => $etat, 'osoba' => $osoba,
            'rodzaj' => $rodzaj, 'znak' => NAZWISKO . ':' . $etat]);

        return (int) $pdo->lastInsertId();
    };
    $nieobecnosci[] = $wstawNieobecnosc($szefOsoba, $szefEtat, $spolka, $rodzaj);
    foreach ($podwladni as $kto) {
        $nieobecnosci[] = $wstawNieobecnosc($kto['osoba'], $kto['etat'], $spolka, $rodzaj);
    }
}

$st = $pdo->prepare('SELECT COUNT(*) FROM employment WHERE company_id = :spolka AND is_active = 1');
$st->execute(['spolka' => $spolka]);
$wFirmie = (int) $st->fetchColumn();

echo json_encode([
    'login' => LOGIN,
    'haslo' => $haslo,
    'spolka' => $spolka,
    'szef_osoba' => $szefOsoba,
    'szef_etat' => $szefEtat,
    'szef_konto' => $szefKonto,
    'podwladni' => $podwladni,
    // Gałąź = przełożony + jego podwładni; tyle wierszy ma pokazać widok „Zespół".
    'galaz' => PODWLADNYCH + 1,
    // Miesiąc nieobecności w postaci, którą rozumie adres kalendarza (`&miesiac=YYYY-MM`).
    'miesiac' => gmdate('Y-m'),
    'nieobecnosci' => $nieobecnosci,
    'rodzaj_nieobecnosci' => $rodzaj,
    'etatow_w_firmie' => $wFirmie,
    'liczniki_przed' => $przed,
    'liczniki_po' => $liczniki(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
