<?php

declare(strict_types=1);

/*
 * **Osoby próbne do pomiaru zaproszeń** (zlecenie Z64, 20.10.2026). Wejście dla pomiaru
 * w przeglądarce i dla raportu; `dev/` nie jedzie do paczki klienta.
 *
 * ⚠ **Dlaczego własne osoby, a nie istniejące.** Zlecenie mówi wprost: stany sprawdzamy na
 * kontach PRÓBNYCH, które sam zakładam i kasuję. Pomiar na prawdziwej osobie z kadr znaczyłby
 * założenie jej konta i wysłanie zaproszenia na jej adres — czyli zmianę w danych kadrowych
 * i list do człowieka, który o nic nie prosił.
 *
 * ⚠ Osoba ma `outside_hr = 1` i **czynny etat**, bo panel pokazuje tylko osoby w zasięgu firmy
 * z belki. Bez etatu nie weszłaby na listę ani do panelu.
 *
 * Tryby:
 *   utworz           — dwie osoby: jedna BEZ adresu, jedna z adresem (oba fikcyjne, `.invalid`)
 *   stan             — co jest w bazie dla osób próbnych: konto, adres, token, zadania w kolejce
 *   sprzataj         — usunięcie wszystkiego, co ta próbka założyła (razem z kontami i tokenami)
 *
 * Wywołanie: `php dev/probka-zaproszenia.php utworz`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;

const NAZWISKO_BEZ = '__probka_zapro_bez';
const NAZWISKO_Z = '__probka_zapro_z';
// ⚠ Domena `.invalid` jest zarezerwowana i nie istnieje w DNS: zaproszenie wysłane w pomiarze
// nie ma jak dotrzeć do człowieka. Adresu drugiej osoby nie zgadujemy — jest losowy sufiks,
// żeby dwa przebiegi nie zderzyły się o klucz unikalny `account.email`.
$ADRES_Z = 'probka-zapro-' . bin2hex(random_bytes(3)) . '@pomiar.invalid';

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

/** @return list<int> */
$osoby = static function () use ($pdo): array {
    $st = $pdo->prepare('SELECT id FROM person WHERE last_name IN (:a, :b) ORDER BY id');
    $st->execute(['a' => NAZWISKO_BEZ, 'b' => NAZWISKO_Z]);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

$sprzataj = static function () use ($pdo, $osoby): array {
    $ile = ['zadania' => 0, 'tokeny' => 0, 'role' => 0, 'konta' => 0, 'etaty' => 0, 'osoby' => 0];
    foreach ($osoby() as $osoba) {
        $st = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
        $st->execute(['p' => $osoba]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
            // ⚠ Zadania w kolejce kasujemy PO identyfikatorze konta w treści zadania: zostawione
            // wyszłyby przy następnym przebiegu crona na adres osoby, której już nie ma.
            $z = $pdo->prepare('DELETE FROM job WHERE type LIKE :typ AND payload LIKE :igla');
            // ⚠ Wzorzec ze SPACJĄ po dwukropku: `json_encode` z `JSON_PRETTY_PRINT`-em w kolejce
            // zapisuje `{"account_id": 200, …}`. Bez spacji wzorzec nie trafiał w nic i próbka
            // meldowała „zero zadań w kolejce" przy dwóch zadaniach czekających na crona
            // (zmierzone 20.10.2026 — i to był pomiar, który mógł wysłać pocztę).
            $z->execute(['typ' => 'mail.%', 'igla' => '%"account_id": ' . $konto . '%']);
            $ile['zadania'] += $z->rowCount();
            $z = $pdo->prepare('DELETE FROM invitation WHERE account_id = :k');
            $z->execute(['k' => $konto]);
            $ile['tokeny'] += $z->rowCount();
            $z = $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k');
            $z->execute(['k' => $konto]);
            $ile['role'] += $z->rowCount();
            $z = $pdo->prepare('DELETE FROM account WHERE id = :k');
            $z->execute(['k' => $konto]);
            $ile['konta'] += $z->rowCount();
        }
        $z = $pdo->prepare('DELETE FROM employment WHERE person_id = :p');
        $z->execute(['p' => $osoba]);
        $ile['etaty'] += $z->rowCount();
        $z = $pdo->prepare('DELETE FROM person WHERE id = :p');
        $z->execute(['p' => $osoba]);
        $ile['osoby'] += $z->rowCount();
    }

    return $ile;
};

/** Stan osób próbnych — to, czego dotyczą twierdzenia zlecenia. */
$stan = static function () use ($pdo, $osoby): array {
    $out = [];
    foreach ($osoby() as $osoba) {
        $st = $pdo->prepare('SELECT last_name, email_private FROM person WHERE id = :p');
        $st->execute(['p' => $osoba]);
        $p = $st->fetch();
        $st = $pdo->prepare('SELECT id, email, status FROM account WHERE person_id = :p');
        $st->execute(['p' => $osoba]);
        $konto = $st->fetch();
        $tokenow = 0;
        $zadan = 0;
        if ($konto !== false) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM invitation WHERE account_id = :k');
            $st->execute(['k' => (int) $konto['id']]);
            $tokenow = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM job WHERE type = :typ AND payload LIKE :igla');
            $st->execute(['typ' => 'mail.invitation', 'igla' => '%"account_id": ' . (int) $konto['id'] . '%']);
            $zadan = (int) $st->fetchColumn();
        }
        $out[] = [
            'osoba' => $osoba,
            'rodzaj' => $p['last_name'] === NAZWISKO_BEZ ? 'bez adresu' : 'z adresem',
            'adres_osoby' => $p['email_private'],
            'konto' => $konto === false ? null : (int) $konto['id'],
            'adres_konta' => $konto === false ? null : $konto['email'],
            'stan_konta' => $konto === false ? null : $konto['status'],
            'tokenow' => $tokenow,
            'zadan_w_kolejce' => $zadan,
        ];
    }

    return $out;
};

$tryb = $argv[1] ?? 'utworz';

if ($tryb === 'sprzataj') {
    echo json_encode(['sprzatniete' => $sprzataj()], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
if ($tryb === 'stan') {
    echo json_encode(['stan' => $stan()], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

// --- utworz -----------------------------------------------------------------
$sprzataj();
$spolka = (int) $pdo->query('SELECT id FROM company WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($spolka === 0) {
    fwrite(STDERR, "Brak czynnej firmy\n");
    exit(1);
}
$nowa = static function (string $nazwisko, ?string $adres) use ($pdo, $spolka): array {
    $st = $pdo->prepare(
        "INSERT INTO person (first_name, last_name, email_private, outside_hr, created_at)
         VALUES ('Pomiar', :n, :mail, 1, UTC_TIMESTAMP())"
    );
    $st->execute(['n' => $nazwisko, 'mail' => $adres]);
    $osoba = (int) $pdo->lastInsertId();
    $st = $pdo->prepare(
        'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                 contract_employment, is_active, created_at)
         VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['osoba' => $osoba, 'spolka' => $spolka]);

    return ['osoba' => $osoba, 'etat' => (int) $pdo->lastInsertId()];
};

$bez = $nowa(NAZWISKO_BEZ, null);
$z = $nowa(NAZWISKO_Z, $ADRES_Z);

echo json_encode([
    'spolka' => $spolka,
    'bez_adresu' => $bez,
    'z_adresem' => $z + ['adres' => $ADRES_Z],
    'stan' => $stan(),
], JSON_UNESCAPED_UNICODE), "\n";
