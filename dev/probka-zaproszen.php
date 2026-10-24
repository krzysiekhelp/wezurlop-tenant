<?php

declare(strict_types=1);

/*
 * **Osoby próbne do pomiaru okna „Wyślij zaproszenia"** (zlecenie Z84 cz. C, 24.10.2026).
 * Wejście dla pomiaru ekranowego; **nie jedzie do paczki klienta** (katalog `dev/`).
 *
 * ⚠ **Po co osobna próbka.** Okno potwierdzenia pokazuje listę osób Z ADRESEM E-MAIL i bez
 * konta, a `dev/probka-zespolu.php` zakłada ludzi bez adresów (konto ma tylko przełożony).
 * Pomiar na prawdziwych pracownikach jest wykluczony z dwóch powodów naraz: wysyłka
 * zaproszenia idzie POCZTĄ na prawdziwy adres i zakłada konto, które zajmuje licencję —
 * czyli obie rzeczy nieodwracalne, i obie na cudzych danych.
 *
 * ⚠ **Adresy w domenie `example.invalid`** — zarezerwowanej normą (RFC 2606) i nierozwiązywalnej
 * w DNS. Gdyby pomiar faktycznie wysłał pocztę, nie ma gdzie dojść; a w raporcie z pomiaru
 * sprawdzamy dodatkowo, że w kolejce nie stanął ani jeden inny adresat.
 *
 * ⚠ **Trzydzieści dwie osoby, nie trzy**: zlecenie żąda pomiaru okna z listą 30+ pozycji
 * (przewijanie wewnątrz, przyklejony nagłówek, stopka nad krawędzią ekranu). Dwie z nich
 * są BEZ adresu — to one mają trafić do zdania „N osób pominięto — brak adresu e-mail",
 * a nie do listy.
 *
 * ⚠ Nazwisko ze znacznikiem (`__probka_zapros`) jest kotwicą sprzątania: łapie wszystko,
 * co próbka założyła, także po przerwanym przebiegu, gdy `finally` nie zdążyło się wykonać.
 *
 * Tryby:
 *   utworz    — 30 osób z adresem i aktywnym etatem plus 2 osoby bez adresu
 *   sprzataj  — usunięcie wszystkiego, co próbka założyła (po znaczniku nazwiska)
 *
 * Wywołanie: `php dev/probka-zaproszen.php utworz`, wynik w JSON.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;

const NAZWISKO = '__probka_zapros';
/** Ile osób Z ADRESEM — tyle, żeby lista w oknie musiała się przewijać (zlecenie Z84 C). */
const Z_ADRESEM = 30;
/** Ile osób BEZ adresu — dla zdania „N osób pominięto". */
const BEZ_ADRESU = 2;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
if (($env['APP_ENV'] ?? '') !== 'dev') {
    fwrite(STDERR, "Próbka pisze do bazy — działa wyłącznie na APP_ENV=dev.\n");
    exit(2);
}
$db = new Connection([
    'host' => (string) $env['DB_HOST'],
    'port' => (int) $env['DB_PORT'],
    'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'],
    'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();

$osobyProbki = static function () use ($pdo): array {
    $st = $pdo->prepare('SELECT id FROM person WHERE last_name = :n ORDER BY id');
    $st->execute(['n' => NAZWISKO]);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

$sprzataj = static function () use ($pdo, $osobyProbki): array {
    $osoby = $osobyProbki();
    if ($osoby === []) {
        return ['osob' => 0, 'kont' => 0];
    }
    // ⚠ Placeholdery z LICZNIKA, nie z danych — lista `IN (…)` nie da się sparametryzować
    // jednym znakiem (CLAUDE.md §4, ta sama postać co w `probka-zespolu.php`).
    $miejsca = array_map(static fn (int $i): string => ':o' . $i, array_keys($osoby));
    $parametry = [];
    foreach ($osoby as $i => $id) {
        $parametry['o' . $i] = $id;
    }
    $krok = static function (string $sql, array $par) use ($pdo): int {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($par);

            return $st->rowCount();
        } catch (Throwable) {
            return 0;
        }
    };
    $kont = 0;
    // Konta, które mógł założyć pomiar (wysyłka zaproszenia albo nadanie dostępu) — razem
    // z tym, co na nich wisi, i razem z zadaniami poczty wysłanymi na ich adresy.
    $st = $pdo->prepare('SELECT id, email FROM account WHERE person_id IN (' . implode(',', $miejsca) . ')');
    $st->execute($parametry);
    foreach ($st->fetchAll() as $konto) {
        foreach (['session', 'role_assignment', 'login_attempt', 'event_log', 'backup_code',
                  'invitation'] as $tabela) {
            $krok(sprintf('DELETE FROM `%s` WHERE account_id = :k', $tabela), ['k' => $konto['id']]);
        }
        // ⚠ Zadania poczty kasujemy po `account_id` Z ŁADUNKU, nie po adresie. Zmierzone
        // 24.10.2026: ładunek `mail.invitation` trzyma `{"account_id": N, "created_by": M}`
        // i adresu nie ma w nim wcale, więc dopasowanie po adresie nie usuwało niczego —
        // po sprzątnięciu zostawały zadania wskazujące na skasowane konto, a kolejka miała je
        // dalej do wysłania. Adres zostaje jako druga siatka, dla ładunków innego kształtu.
        // ⚠ `CAST(... AS UNSIGNED)`, nie samo `JSON_EXTRACT`: PDO bez emulacji wysyła
        // parametr jako NAPIS, a napis porównany z liczbą JSON nie zgadza się nigdy —
        // zmierzone, usuwało zero wierszy i zostawiało zadania po skasowanym koncie.
        $krok('DELETE FROM job
                WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.account_id\')) AS UNSIGNED) = :k',
            ['k' => $konto['id']]);
        $krok('DELETE FROM job WHERE payload LIKE :a', ['a' => '%' . $konto['email'] . '%']);
        $kont += $krok('DELETE FROM account WHERE id = :k', ['k' => $konto['id']]);
    }
    $krok('DELETE FROM employment WHERE person_id IN (' . implode(',', $miejsca) . ')', $parametry);
    $osob = $krok('DELETE FROM person WHERE id IN (' . implode(',', $miejsca) . ')', $parametry);

    return ['osob' => $osob, 'kont' => $kont];
};

$tryb = $argv[1] ?? '';
if ($tryb === 'sprzataj') {
    echo json_encode($sprzataj(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}
if ($tryb !== 'utworz') {
    fwrite(STDERR, "Użycie: probka-zaproszen.php utworz|sprzataj\n");
    exit(2);
}

// ⚠ Ślady po poprzednim przebiegu sprzątamy PRZED utworzeniem: adres konta jest `UNIQUE`,
// więc zostawiona próbka wywróciłaby `utworz` i pomiar nie wykonałby się wcale.
$sprzataj();

$spolka = (int) $pdo->query(
    'SELECT company_id FROM employment WHERE is_active = 1 GROUP BY company_id
      ORDER BY COUNT(*) DESC LIMIT 1'
)->fetchColumn();
if ($spolka === 0) {
    fwrite(STDERR, "Brak firmy z aktywnymi zatrudnieniami — próbki nie da się założyć.\n");
    exit(1);
}

$nowaOsoba = static function (string $imie, ?string $adres) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO person (first_name, last_name, email_private, outside_hr, created_at)
         VALUES (:i, :n, :a, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['i' => $imie, 'n' => NAZWISKO, 'a' => $adres]);

    return (int) $pdo->lastInsertId();
};
$nowyEtat = static function (int $osoba, int $spolka) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                 contract_employment, is_active, created_at)
         VALUES (:o, :c, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['o' => $osoba, 'c' => $spolka]);

    return (int) $pdo->lastInsertId();
};

$zAdresem = [];
foreach (range(1, Z_ADRESEM) as $nr) {
    $osoba = $nowaOsoba('Zapros' . $nr, 'probka-zapros-' . $nr . '@example.invalid');
    $nowyEtat($osoba, $spolka);
    $zAdresem[] = $osoba;
}
$bezAdresu = [];
foreach (range(1, BEZ_ADRESU) as $nr) {
    $osoba = $nowaOsoba('BezAdresu' . $nr, null);
    $nowyEtat($osoba, $spolka);
    $bezAdresu[] = $osoba;
}

echo json_encode([
    'spolka' => $spolka,
    'z_adresem' => $zAdresem,
    'bez_adresu' => $bezAdresu,
    'domena' => 'example.invalid',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
