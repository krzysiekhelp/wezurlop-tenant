<?php

declare(strict_types=1);

/*
 * **Osoby próbne w każdym stanie konta** — do pomiaru panelu osoby na ŻYWEJ instancji
 * (zlecenie Z63 część B, 20.10.2026). `dev/` nie jedzie do paczki klienta.
 *
 * ⚠ **Dlaczego własne osoby.** Baza robocza ma dziś stany „brak konta", „niezaproszone"
 * i „zaproszony"; stanów „aktywne" i „zablokowane" nie da się na niej zmierzyć bez
 * przestawienia komuś prawdziwego konta — a to nie pomiar, tylko ingerencja w dane kadrowe
 * (i w dostęp człowieka do programu). Zakładamy więc pięć własnych osób, każdą w jednym
 * stanie, plus konto administracyjne bez zatrudnienia, i kasujemy wszystko po pomiarze.
 *
 * ⚠ Hasło konta aktywnego jest **losowe przy każdym przebiegu** i nie wraca na wyjście:
 * pomiar nie loguje się tym kontem, tylko OGLĄDA jego panel okiem administratora.
 *
 * ⚠ Konto administracyjne dostaje rolę tenantową i **nie ma zatrudnienia** — taki jest ten stan
 * w produkcie (druga zakładka `/admin/konta`). Rozdział ról tego nie blokuje: zakaz dotyczy
 * nadania roli tenantowej koncie Z ETATEM.
 *
 * Tryby: `utworz`, `stan`, `sprzataj`. Wywołanie: `php dev/probka-stanow-konta.php utworz`.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\Password;

const PRZEDROSTEK = '__probka_stan_';
/** Stany do pomiaru: nazwisko → stan konta (`null` = bez konta). */
const STANY = [
    'brak' => null,
    'niezaproszone' => AccountRepository::STAN_NIEZAPROSZONE,
    'zaproszony' => AccountRepository::STAN_ZAPROSZONE,
    'aktywne' => AccountRepository::STAN_AKTYWNE,
    'zablokowane' => AccountRepository::STAN_ZABLOKOWANE,
    'administracyjne' => AccountRepository::STAN_AKTYWNE,
];

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
$konta = new AccountRepository($db);
$permissions = new PermissionRepository($db);

/** @return array<string, int> nazwa stanu → identyfikator osoby */
$osoby = static function () use ($pdo): array {
    $st = $pdo->prepare('SELECT id, last_name FROM person WHERE last_name LIKE :n ORDER BY id');
    $st->execute(['n' => PRZEDROSTEK . '%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $out[substr((string) $w['last_name'], strlen(PRZEDROSTEK))] = (int) $w['id'];
    }

    return $out;
};

$sprzataj = static function () use ($pdo, $osoby): array {
    $ile = ['zadania' => 0, 'tokeny' => 0, 'role' => 0, 'sesje' => 0, 'konta' => 0, 'etaty' => 0, 'osoby' => 0];
    foreach ($osoby() as $osoba) {
        $st = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
        $st->execute(['p' => $osoba]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
            $z = $pdo->prepare('DELETE FROM job WHERE type LIKE :typ AND payload LIKE :igla');
            $z->execute(['typ' => 'mail.%', 'igla' => '%"account_id": ' . $konto . '%']);
            $ile['zadania'] += $z->rowCount();
            // ⚠ Trzy zapytania wypisane WPROST, a nie nazwa tabeli sklejana w pętli: nazwy
            // tabeli nie da się sparametryzować, więc pętla po nazwach jest konkatenacją w SQL
            // (CLAUDE.md §4) — i słusznie wyłapał ją `bin/check-rules.php`.
            $z = $pdo->prepare('DELETE FROM invitation WHERE account_id = :k');
            $z->execute(['k' => $konto]);
            $ile['tokeny'] += $z->rowCount();
            $z = $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k');
            $z->execute(['k' => $konto]);
            $ile['role'] += $z->rowCount();
            $z = $pdo->prepare('DELETE FROM session WHERE account_id = :k');
            $z->execute(['k' => $konto]);
            $ile['sesje'] += $z->rowCount();
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

$stan = static function () use ($pdo, $osoby): array {
    $out = [];
    foreach ($osoby() as $nazwa => $osoba) {
        $st = $pdo->prepare('SELECT a.id, a.status, (SELECT COUNT(*) FROM employment e WHERE e.person_id = :p2
            AND e.is_active = 1) AS etatow FROM account a WHERE a.person_id = :p');
        $st->execute(['p' => $osoba, 'p2' => $osoba]);
        $konto = $st->fetch(PDO::FETCH_ASSOC);
        $st2 = $pdo->prepare('SELECT COUNT(*) FROM employment WHERE person_id = :p AND is_active = 1');
        $st2->execute(['p' => $osoba]);
        $out[$nazwa] = [
            'osoba' => $osoba,
            'konto' => $konto === false ? null : (int) $konto['id'],
            'stan_konta' => $konto === false ? null : (string) $konto['status'],
            'etatow' => (int) $st2->fetchColumn(),
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
$haslo = new Password(dirname(__DIR__) . '/var/cache/password-dummy.hash');
$sufiks = bin2hex(random_bytes(3));
foreach (STANY as $nazwa => $stanKonta) {
    $administracyjne = $nazwa === 'administracyjne';
    $st = $pdo->prepare(
        "INSERT INTO person (first_name, last_name, email_private, outside_hr, created_at)
         VALUES ('Pomiar', :n, :mail, 1, UTC_TIMESTAMP())"
    );
    $st->execute([
        'n' => PRZEDROSTEK . $nazwa,
        'mail' => $nazwa === 'brak' ? null : 'probka-' . $nazwa . '-' . $sufiks . '@pomiar.invalid',
    ]);
    $osoba = (int) $pdo->lastInsertId();
    // ⚠ Konto administracyjne NIE MA zatrudnienia — tym się różni i to jest cały ten stan.
    if (!$administracyjne) {
        $st = $pdo->prepare(
            'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                     contract_employment, is_active, created_at)
             VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
        );
        $st->execute(['osoba' => $osoba, 'spolka' => $spolka]);
    }
    if ($stanKonta === null) {
        continue;
    }
    $konto = $konta->createInvited($osoba, 'probka-' . $nazwa . '-' . $sufiks . '@pomiar.invalid');
    $st = $pdo->prepare('UPDATE account SET status = :s WHERE id = :k');
    $st->execute(['s' => $stanKonta, 'k' => $konto]);
    if ($stanKonta === AccountRepository::STAN_AKTYWNE) {
        // Hasło losowe i nigdzie nie zapisywane: pomiar oglda panel, nie loguje się nim.
        $konta->setPassword($konto, $haslo->hash(bin2hex(random_bytes(16))));
    }
    if ($stanKonta === AccountRepository::STAN_ZABLOKOWANE) {
        $st = $pdo->prepare("UPDATE account SET blocked_reason = 'manual', blocked_at = UTC_TIMESTAMP(),
            status_before_block = 'active' WHERE id = :k");
        $st->execute(['k' => $konto]);
    }
    $permissions->assignRole(
        $konto,
        $administracyjne ? PermissionRepository::ROLA_ADMIN : PermissionRepository::ROLA_PRACOWNIK,
        $administracyjne ? 0 : $spolka,
        null,
    );
}

echo json_encode(['spolka' => $spolka, 'stan' => $stan()], JSON_UNESCAPED_UNICODE), "\n";
