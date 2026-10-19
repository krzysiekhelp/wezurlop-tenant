<?php

declare(strict_types=1);

/*
 * Limit urlopowy na czas pomiaru — wejście dla pomiarów karty pracownika. **Nie jedzie
 * do paczki klienta** (katalog `dev/`).
 *
 * ⚠ Powód istnienia: wiersz limitu ma stan „przekroczony" (ujemne „pozostało": pasek pełny,
 * saldo w barwie błędu), a na bazie roboczej nie ma ani jednej osoby, której NAJNOWSZY rok
 * limitu byłby ujemny — więc tego stanu nie dało się zobaczyć ani razu. Stan „nie do pokazania"
 * to stan niesprawdzony.
 *
 * ⚠ Próbka rusza WYŁĄCZNIE `used_min` i `remaining_min` jednego wiersza i zapamiętuje obie
 * wartości sprzed zmiany. Nie przechodzi ścieżką synchronizacji, bo tamta zmienia także
 * nieobecności i dziennik, a tu chodzi o dwie liczby do obejrzenia.
 *
 * Wywołanie:
 *   php dev/probka-limitu.php stan <id_osoby>
 *   php dev/probka-limitu.php przekrocz <id_osoby>
 *   php dev/probka-limitu.php przywroc
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
$db = new Connection([
    'host' => (string) $env['DB_HOST'], 'port' => (int) $env['DB_PORT'], 'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'], 'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();
$plik = dirname(__DIR__) . '/var/probka-limitu.json';

$tryb = $argv[1] ?? 'stan';
$osoba = (int) ($argv[2] ?? 0);

/** Najnowszy wiersz limitu urlopowego tej osoby — ten, który karta pokazuje. */
$wiersz = static function (PDO $pdo, int $osoba): ?array {
    $st = $pdo->prepare(
        "SELECT b.id, b.year, b.kind, b.available_start_min, b.used_min, b.remaining_min
         FROM leave_balance b JOIN employment e ON e.id = b.employment_id
         WHERE e.person_id = :osoba AND b.kind = 'wypoczynkowy'
         ORDER BY b.year DESC, b.id DESC LIMIT 1"
    );
    $st->execute(['osoba' => $osoba]);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
};

if ($tryb === 'stan') {
    echo json_encode($wiersz($pdo, $osoba), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

    exit(0);
}

if ($tryb === 'przekrocz') {
    $w = $wiersz($pdo, $osoba);
    if ($w === null) {
        fwrite(STDERR, "Osoba {$osoba} nie ma limitu urlopowego.\n");

        exit(2);
    }
    $przed = is_file($plik) ? (array) json_decode((string) file_get_contents($plik), true) : [];
    if (!isset($przed[(string) $w['id']])) {
        $przed[(string) $w['id']] = ['used_min' => $w['used_min'], 'remaining_min' => $w['remaining_min']];
        file_put_contents($plik, json_encode($przed, JSON_UNESCAPED_UNICODE));
    }
    // Wykorzystane o dwa dni ponad limit — tyle wystarczy, żeby pasek był pełny, a saldo ujemne.
    $uzyte = (int) $w['available_start_min'] + 960;
    $pdo->prepare('UPDATE leave_balance SET used_min = :u, remaining_min = :r WHERE id = :id')
        ->execute(['u' => $uzyte, 'r' => -960, 'id' => $w['id']]);
    echo "limit #{$w['id']} (osoba {$osoba}, rok {$w['year']}): wykorzystane {$w['used_min']} → {$uzyte} min, "
        . "pozostało {$w['remaining_min']} → -960 min\n";

    exit(0);
}

if ($tryb === 'przywroc') {
    if (!is_file($plik)) {
        echo "nie ma czego przywracać\n";

        exit(0);
    }
    foreach ((array) json_decode((string) file_get_contents($plik), true) as $id => $wartosci) {
        $pdo->prepare('UPDATE leave_balance SET used_min = :u, remaining_min = :r WHERE id = :id')
            ->execute(['u' => $wartosci['used_min'], 'r' => $wartosci['remaining_min'], 'id' => (int) $id]);
        echo "limit #{$id} → wykorzystane {$wartosci['used_min']} min, pozostało {$wartosci['remaining_min']} min\n";
    }
    unlink($plik);

    exit(0);
}

fwrite(STDERR, "Nieznany tryb. Użycie: stan <id_osoby> | przekrocz <id_osoby> | przywroc\n");

exit(2);
