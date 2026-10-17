<?php

declare(strict_types=1);

/*
 * **Wygaszone zatrudnienie W GRUPIE — stan wytworzony przez pomiar, na własnych obiektach.**
 * Wejście dla `dev/bramka-ekranowa.py`; nie jedzie do paczki klienta (katalog `dev/`).
 *
 * ⚠ Powód powstania (zlecenie 10.10.2026, druga tura §3): ekranowa połowa kryterium
 * „(nieaktywnych: N)" czytała **dane robocze** — jedno wygaszone zatrudnienie, które ktoś
 * wstawił do Magazynu ręcznie. Krzysztof zdjął je przy sprawdzaniu i kryterium przestało mieć
 * co mierzyć; gorzej, **po słusznej poprawce zapis odmawia dodania wygaszonego**, więc tego
 * stanu nie da się odtworzyć z interfejsu. Kryterium zależne od zawartości cudzych danych jest
 * kryterium „z braku okazji".
 *
 * ⚠ **Jedyna kolejność, w jakiej ten stan powstaje**, i dokładnie ją tu odtwarzamy:
 * zatrudnienie wchodzi do grupy jako **czynne**, a wygaszone jest **potem**. Tak to się dzieje
 * w kadrach (synchronizacja wygasza zatrudnienie, którego R2 przestaje przysyłać) i tylko tak
 * aplikacja potrafi ten stan wytworzyć.
 *
 * ⚠ **Pomiar zapisuje u siebie, nigdy u nas**: osoba ma nazwisko `__probka_ekran`
 * i `outside_hr = 1`, a `sprzataj` usuwa ją razem z przynależnościami. Nazwisko jest inne niż
 * w `probka-grup.php` (`__probka`), żeby dwa pomiary nie sprzątały sobie wzajemnie obiektów.
 *
 * Wywołanie:
 *   php dev/probka-wygaszony.php utworz <companyId> <groupId>   → JSON z identyfikatorami
 *   php dev/probka-wygaszony.php sprzataj                        → JSON z liczbą usuniętych
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\GroupRepository;

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
$repo = new GroupRepository($db);
const NAZWISKO = '__probka_ekran';

$sprzataj = static function () use ($pdo): array {
    $pdo->prepare('DELETE eg FROM employment_group eg JOIN employment e ON e.id = eg.employment_id
                    JOIN person p ON p.id = e.person_id WHERE p.last_name = :n')->execute(['n' => NAZWISKO]);
    $etaty = $pdo->prepare('DELETE e FROM employment e JOIN person p ON p.id = e.person_id
                             WHERE p.last_name = :n');
    $etaty->execute(['n' => NAZWISKO]);
    $osoby = $pdo->prepare('DELETE FROM person WHERE last_name = :n');
    $osoby->execute(['n' => NAZWISKO]);

    return ['etaty' => $etaty->rowCount(), 'osoby' => $osoby->rowCount()];
};

$polecenie = $argv[1] ?? '';
if ($polecenie === 'sprzataj') {
    echo json_encode($sprzataj()), "\n";
    exit(0);
}
if ($polecenie !== 'utworz' || !isset($argv[2], $argv[3])) {
    echo json_encode(['blad' => 'użycie: probka-wygaszony.php utworz <companyId> <groupId> | sprzataj']), "\n";
    exit(1);
}
$companyId = (int) $argv[2];
$groupId = (int) $argv[3];

// ⚠ Sprzątamy PRZED utworzeniem, nie tylko po: poprzedni przebieg mógł zostać zabity w połowie,
// a druga osoba `__probka_ekran` zawyżyłaby liczniki mierzone chwilę później.
$sprzataj();

$osoba = $pdo->prepare('INSERT INTO person (first_name, last_name, outside_hr, created_at)
                        VALUES (:imie, :nazwisko, 1, UTC_TIMESTAMP())');
$osoba->execute(['imie' => 'Pomiar Ekran', 'nazwisko' => NAZWISKO]);
$personId = (int) $pdo->lastInsertId();
$etat = $pdo->prepare(
    'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                             contract_employment, is_active, created_at)
     VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
);
$etat->execute(['osoba' => $personId, 'spolka' => $companyId]);
$employmentId = (int) $pdo->lastInsertId();

// 1. Wchodzi do grupy jako CZYNNE — tą samą drogą co człowiek (repozytorium, nie `INSERT` z ręki).
$wynik = $repo->przypisz($groupId, $companyId, [$employmentId], null);
// 2. …i dopiero teraz je wygaszamy.
$pdo->prepare('UPDATE employment SET is_active = 0, deactivated_at = UTC_TIMESTAMP() WHERE id = :e')
    ->execute(['e' => $employmentId]);

echo json_encode([
    'osoba' => $personId,
    'zatrudnienie' => $employmentId,
    'w_grupie' => in_array($employmentId, array_column($repo->czlonkowie($groupId, $companyId), 'id'), true),
    'dodani' => $wynik['dodani'],
], JSON_UNESCAPED_UNICODE), "\n";
