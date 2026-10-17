<?php

declare(strict_types=1);

/*
 * **Konto próbne do pomiaru znacznika spółki** (zlecenie `2026-10-10-globalny-wybor-spolki.md`
 * §1: „dowód przez łamanie dla zapamiętanej spółki, do której konto straciło dostęp").
 * Wejście dla bramki ekranowej; **nie jedzie do paczki klienta** (katalog `dev/`).
 *
 * ⚠ **Dlaczego własne konto, a nie istniejące.** Dowód wymaga konta, które widzi DWIE spółki
 * i któremu jedną z nich można **odebrać**. Wszystkie trzy konta z rolą tenantową widzą
 * wszystkie spółki z samej roli — odebranie oznaczałoby zdjęcie roli, czyli zepsucie konta,
 * którym pracuje człowiek. Konta pracownicze mają etaty w obu spółkach, więc odebranie
 * uprawnienia też niczego nie zmienia na liście. Dlatego bramka pisze **na swoich obiektach**
 * (§3 zlecenia): własna osoba, własne konto, własne uprawnienia, wszystko kasowane po pomiarze.
 *
 * ⚠ Uprawnienia nadaje `CalendarVisibility::ustaw()`, czyli ta sama droga co ekran „Dostęp" —
 * nie `INSERT` do `role_assignment`. Próbka, która zapisuje własnym SQL-em, mierzy siebie.
 *
 * Tryby:
 *   utworz           — osoba + konto z hasłem + poziom podglądu w DWÓCH spółkach
 *   odbierz <spolka> — zdjęcie poziomu w jednej spółce (to jest „strata dostępu")
 *   sprzataj         — usunięcie wszystkiego, co ta próbka założyła
 *
 * Wywołanie: `php dev/probka-spolki.php utworz`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\Password;
use Wezurlop\Service\CalendarVisibility;

const NAZWISKO = '__probka_spolki';
// ⚠ Domena `.invalid` jest zarezerwowana i nie istnieje w DNS — adres próbki nie ma jak
// przypadkiem dotrzeć do człowieka, a `bin/check-rules.php` wymaga danych oczywiście fikcyjnych
// poza seedem i testami.
const LOGIN = 'probka-spolki@pomiar.invalid';
// ⚠ **Hasło powstaje LOSOWO przy każdym przebiegu, a nie stoi w pliku** (audyt Z1 z 17.10.2026:
// stała z literałem była sekretem w repozytorium). Konto próbki żyje jedno uruchomienie i jest
// kasowane razem z nią, więc nie ma czego przechowywać: wartość idzie na wyjście skryptu,
// skąd bierze ją człowiek albo pomiar, i nigdzie poza nim nie zostaje.
$HASLO = bin2hex(random_bytes(12));

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
$events = new EventLogRepository($db);
$konta = new AccountRepository($db);
$widocznosc = new CalendarVisibility($permissions, $db, $events);

$osobaId = static function () use ($pdo): ?int {
    $st = $pdo->prepare('SELECT id FROM person WHERE last_name = :n ORDER BY id LIMIT 1');
    $st->execute(['n' => NAZWISKO]);
    $v = $st->fetchColumn();

    return $v === false ? null : (int) $v;
};
$kontoId = static function (?int $person) use ($pdo): ?int {
    if ($person === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM account WHERE person_id = :p ORDER BY id LIMIT 1');
    $st->execute(['p' => $person]);
    $v = $st->fetchColumn();

    return $v === false ? null : (int) $v;
};

$sprzataj = static function () use ($pdo, $osobaId, $kontoId): array {
    $osoba = $osobaId();
    $konto = $kontoId($osoba);
    $ile = ['role' => 0, 'sesje' => 0, 'konto' => 0, 'etaty' => 0, 'osoba' => 0];
    if ($konto !== null) {
        $st = $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :a');
        $st->execute(['a' => $konto]);
        $ile['role'] = $st->rowCount();
        $st = $pdo->prepare('DELETE FROM session WHERE account_id = :a');
        $st->execute(['a' => $konto]);
        $ile['sesje'] = $st->rowCount();
        $st = $pdo->prepare('DELETE FROM account WHERE id = :a');
        $st->execute(['a' => $konto]);
        $ile['konto'] = $st->rowCount();
    }
    if ($osoba !== null) {
        $st = $pdo->prepare('DELETE FROM employment WHERE person_id = :p');
        $st->execute(['p' => $osoba]);
        $ile['etaty'] = $st->rowCount();
        $st = $pdo->prepare('DELETE FROM person WHERE id = :p');
        $st->execute(['p' => $osoba]);
        $ile['osoba'] = $st->rowCount();
    }

    return $ile;
};

$tryb = $argv[1] ?? 'utworz';

if ($tryb === 'sprzataj') {
    echo json_encode(['sprzatniete' => $sprzataj()], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

if ($tryb === 'odbierz') {
    $spolka = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $konto = $kontoId($osobaId());
    if ($spolka === false || $konto === null) {
        fwrite(STDERR, "Użycie: probka-spolki.php odbierz <companyId> (po `utworz`)\n");
        exit(1);
    }
    // Poziom 0 = brak podglądu, czyli spółka wypada z listy `spolkiZPoziomem()`.
    $widocznosc->ustaw($konto, $spolka, 0, null, 'pomiar bramki — odebranie dostępu');
    echo json_encode([
        'konto' => $konto,
        'odebrana' => $spolka,
        'spolki_po' => array_column($widocznosc->spolkiZPoziomem($konto), 'id'),
    ], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

// --- utworz -----------------------------------------------------------------
$sprzataj();
$spolki = $pdo->query('SELECT id FROM company WHERE is_active = 1 ORDER BY id LIMIT 2')
    ->fetchAll(PDO::FETCH_COLUMN);
if (count($spolki) < 2) {
    fwrite(STDERR, "Potrzebne dwie aktywne spółki; jest " . count($spolki) . "\n");
    exit(1);
}
$st = $pdo->prepare(
    "INSERT INTO person (first_name, last_name, outside_hr, created_at)
     VALUES ('Pomiar', :n, 1, UTC_TIMESTAMP())"
);
$st->execute(['n' => NAZWISKO]);
$osoba = (int) $pdo->lastInsertId();
// ⚠ **Etat w PIERWSZEJ spółce jest konieczny, nie ozdobny.** Zmierzone 10.10.2026: konto bez
// ani jednego czynnego zatrudnienia i bez roli tenantowej dostaje 403 na **każdym** ekranie
// (`EmploymentContext` → `no-employment.twig`), więc „konto tylko do podglądu" w ogóle nie wchodzi
// do programu. Scenariusz, który istnieje w praktyce, jest inny: etat w spółce A plus **nadany
// podgląd** w spółce B. Taki właśnie zakładamy — i taki da się potem zepsuć odebraniem B.
$etat = $pdo->prepare(
    'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                             contract_employment, is_active, created_at)
     VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
);
$etat->execute(['osoba' => $osoba, 'spolka' => (int) $spolki[0]]);
$etatId = (int) $pdo->lastInsertId();
$konto = $konta->createInvited($osoba, LOGIN);
// ⚠ Hasło przez `Security\Password`, nie `password_hash()` wprost: to tam siedzi
// przygotowanie wejścia dla bcrypta (SHA-256 + base64, §9 reguł). Własny hash w próbce
// dałby konto, którego aplikacja nie zweryfikuje.
$haslo = new Password(dirname(__DIR__) . '/var/cache/password-dummy.hash');
$konta->setPassword($konto, $haslo->hash($HASLO));
foreach ($spolki as $id) {
    // ⚠ Dwa nadania, bo to dwie różne rzeczy: `employee` daje **wejście do programu**
    // (`app.use`), a poziom widoczności — **podgląd kalendarza spółki**. Zmierzone: konto
    // z samym poziomem dostaje 403 na każdym ekranie, czyli nie da się na nim nic zobaczyć.
    // Rolę nadajemy przez `PermissionRepository::assignRole()`, tę samą, której używa
    // `AccessService`; licznika licencji w próbce nie ruszamy, bo nie jego mierzymy.
    $permissions->assignRole($konto, PermissionRepository::ROLA_PRACOWNIK, (int) $id, null);
    $widocznosc->ustaw($konto, (int) $id, 1, null, 'pomiar bramki — nadanie dostępu');
}

echo json_encode([
    'osoba' => $osoba,
    'konto' => $konto,
    'login' => LOGIN,
    'haslo' => $HASLO,
    'etat' => $etatId,
    'spolka_etatu' => (int) $spolki[0],
    'spolka_podgladu' => (int) $spolki[1],
    'spolki' => array_map('intval', $spolki),
    'spolki_z_poziomem' => array_column($widocznosc->spolkiZPoziomem($konto), 'id'),
], JSON_UNESCAPED_UNICODE), "\n";
