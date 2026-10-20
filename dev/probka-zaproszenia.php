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
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\InvitationRepository;
use Wezurlop\Repository\PasswordResetTokenRepository;
use Wezurlop\Security\OneTimeToken;
use Wezurlop\Service\AccountMailService;

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
            // ⚠ Token resetu też jest tokenem: zostawiony znaczyłby czynny link do konta,
            // którego już nie ma (a przy wznowieniu numeracji — do cudzego).
            $z = $pdo->prepare('DELETE FROM password_reset_token WHERE account_id = :k');
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

if ($tryb === 'token') {
    // ⚠ **Czynny link bez wysyłania poczty** (pomiar Z63 część C): ekrany `/zaproszenie`
    // i `/haslo/nowe` wyglądają inaczej z czynnym tokenem niż bez niego — bez niego oddają 410
    // i tego właśnie dotąd nie zmierzyliśmy. Token wydajemy TĄ SAMĄ drogą, co aplikacja
    // (`OneTimeToken` + repozytorium), bo próbka, która liczy skrót własnym sposobem, mierzy
    // siebie. Poczta nie wychodzi: zadania w kolejce nie zakładamy wcale.
    $osoba = (int) ($argv[2] ?? 0);
    if ($osoba === 0) {
        fwrite(STDERR, "Użycie: probka-zaproszenia.php token <osoba>\n");
        exit(1);
    }
    $konta = new AccountRepository($db);
    $st = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
    $st->execute(['p' => $osoba]);
    $konto = (int) $st->fetchColumn();
    if ($konto === 0) {
        $st = $pdo->prepare('SELECT email_private FROM person WHERE id = :p');
        $st->execute(['p' => $osoba]);
        $konto = $konta->createInvited($osoba, (string) $st->fetchColumn());
    }
    $pdo->prepare("UPDATE account SET status = 'invited' WHERE id = :k")->execute(['k' => $konto]);
    $zaproszenie = OneTimeToken::generate();
    (new InvitationRepository($db))->create(
        $konto, $zaproszenie['hash'], AccountMailService::INVITATION_VALID_MINUTES, null);
    $reset = OneTimeToken::generate();
    (new PasswordResetTokenRepository($db))->create(
        $konto, $reset['hash'], AccountMailService::RESET_VALID_MINUTES, null);
    echo json_encode([
        'osoba' => $osoba,
        'konto' => $konto,
        'zaproszenie_token' => $zaproszenie['token'],
        'reset_token' => $reset['token'],
    ], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

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
