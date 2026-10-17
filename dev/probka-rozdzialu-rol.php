<?php

declare(strict_types=1);

/*
 * Dowód przez łamanie dla rozdziału „administrator tenanta ≠ pracownik”
 * (`docs/etap3-logika-urlopowa.md` §8c, zlecenie 23.09 §4.4). Wejście dla bramki ekranowej;
 * **nie jedzie do paczki klienta** (katalog `dev/`), nie jest osiągalny po HTTP.
 *
 * Trzy próby, trzy różne drogi:
 *   a) nadanie `tenant_admin` koncie, które MA aktywne zatrudnienie  → musi być odrzucone,
 *   b) nadanie dostępu pracowniczego koncie administracyjnemu        → musi być odrzucone,
 *   c) wstrzyknięcie ZATRUDNIENIA osobie z konta administracyjnego   → musi dać pozycję
 *      na `/diagnostyka` (tę część sprawdza bramka po HTTP; tutaj zakładamy i sprzątamy stan).
 *
 * ⚠ **Pomiar sprząta po sobie.** Każda próba, która mimo reguły COKOLWIEK zapisze, jest tu
 * wycofywana — inaczej nieudany dowód zostawiłby w bazie dokładnie ten stan, którego pilnuje.
 * Wstrzyknięte zatrudnienie jest usuwane w tym samym przebiegu.
 *
 * Wywołanie: `php dev/probka-rozdzialu-rol.php [wstaw-etat|usun-etat]`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\RozdzialRolException;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
// ⚠ Połączenie przez `Connection`, nie przez własne `new PDO`: ta próbka ma mierzyć kod aplikacji
// w jego własnych warunkach (profil, strefa czasu, prawdziwe zapytania przygotowane).
$db = new Connection([
    'host' => (string) $env['DB_HOST'],
    'port' => (int) $env['DB_PORT'],
    'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'],
    'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();
$permissions = new PermissionRepository($db);

$jeden = static function (string $sql, array $args = []) use ($pdo): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();

    return $v === false ? null : $v;
};

// Konto z aktywnym zatrudnieniem i konto administracyjne — CZYTANE z bazy, nie wpisane.
// ⚠ Konto z etatem, które jeszcze NIE MA roli administratora. Bez tego warunku próbka przy
// WYŁĄCZONEJ regule próbowała nadać rolę, którą konto już miało, i padała na kluczu unikalnym
// zamiast powiedzieć „reguła nie działa" — czyli dowód przez łamanie dawał błąd narzędzia
// w miejscu, w którym miał dać czytelną diagnozę.
$zEtatem = $jeden('SELECT a.id FROM account a JOIN employment e ON e.person_id = a.person_id
                    WHERE e.is_active = 1 AND NOT EXISTS (
                          SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                           WHERE ra.account_id = a.id AND r.code = :rola)
                    ORDER BY a.id LIMIT 1', ['rola' => PermissionRepository::ROLA_ADMIN]);
$admin = $jeden('SELECT a.id FROM account a JOIN role_assignment ra ON ra.account_id = a.id
                  JOIN role r ON r.id = ra.role_id
                 WHERE r.code = :rola AND NOT EXISTS (
                       SELECT 1 FROM employment e WHERE e.person_id = a.person_id AND e.is_active = 1)
                 ORDER BY a.id LIMIT 1', ['rola' => PermissionRepository::ROLA_ADMIN]);
$spolka = $jeden('SELECT id FROM company WHERE is_active = 1 ORDER BY id LIMIT 1');

$tryb = $argv[1] ?? 'proby';

if ($tryb === 'usun-etat') {
    $st = $pdo->prepare("DELETE FROM employment WHERE r2_employment_ref = 'BRAMKA-ROZDZIAL'");
    $st->execute();
    echo json_encode(['usuniete' => $st->rowCount()]), "\n";
    exit(0);
}

if ($tryb === 'wstaw-etat') {
    // ⚠ Trzecia droga: zatrudnienie powstaje PO STRONIE KADR, bez żadnego nadania roli.
    $osoba = $jeden('SELECT person_id FROM account WHERE id = :k', ['k' => $admin]);
    $st = $pdo->prepare(
        "INSERT INTO employment (person_id, company_id, r2_employment_ref, hired_at, is_active, created_at)
         VALUES (:osoba, :spolka, 'BRAMKA-ROZDZIAL', CURDATE(), 1, UTC_TIMESTAMP())"
    );
    $st->execute(['osoba' => $osoba, 'spolka' => $spolka]);
    echo json_encode(['konto' => (int) $admin, 'osoba' => (int) $osoba,
        'zatrudnienie' => (int) $pdo->lastInsertId()]), "\n";
    exit(0);
}

$wynik = ['konto_z_etatem' => $zEtatem === null ? null : (int) $zEtatem,
    'konto_admina' => $admin === null ? null : (int) $admin,
    'spolka' => $spolka === null ? null : (int) $spolka];

// a) tenant_admin dla konta z etatem
try {
    $permissions->assignRole((int) $zEtatem, PermissionRepository::ROLA_ADMIN, 0, null);
    $wynik['a_admin_dla_etatu'] = 'PRZESZŁO — REGUŁA NIE DZIAŁA';
    $pdo->prepare('DELETE ra FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                   WHERE ra.account_id = :k AND r.code = :rola')
        ->execute(['k' => $zEtatem, 'rola' => PermissionRepository::ROLA_ADMIN]);
} catch (RozdzialRolException $e) {
    $wynik['a_admin_dla_etatu'] = 'odrzucone: ' . $e->powod;
}

// b) dostęp pracowniczy dla konta administracyjnego
try {
    $permissions->assignRole((int) $admin, PermissionRepository::ROLA_PRACOWNIK, (int) $spolka, null);
    $wynik['b_pracownik_dla_admina'] = 'PRZESZŁO — REGUŁA NIE DZIAŁA';
    $pdo->prepare('DELETE ra FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                   WHERE ra.account_id = :k AND ra.company_id = :c AND r.code = :rola')
        ->execute(['k' => $admin, 'c' => $spolka, 'rola' => PermissionRepository::ROLA_PRACOWNIK]);
} catch (RozdzialRolException $e) {
    $wynik['b_pracownik_dla_admina'] = 'odrzucone: ' . $e->powod;
}

$wynik['naruszenia_teraz'] = count($permissions->administratorzyZEtatem());
echo json_encode($wynik, JSON_UNESCAPED_UNICODE), "\n";
