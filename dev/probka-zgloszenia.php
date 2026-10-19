<?php

declare(strict_types=1);

/*
 * Stan zgłoszenia firmy na czas pomiaru — wejście dla pomiarów ekranu `/dane` i bramki.
 * **Nie jedzie do paczki klienta** (katalog `dev/`).
 *
 * ⚠ Powód istnienia: wiersz firmy na `/dane` ma trzy stany zgłoszenia (zatwierdzona, czeka,
 * odrzucona), a na bazie roboczej wszystkie firmy są zatwierdzone — dwóch pozostałych plakietek
 * nie dało się zobaczyć na żywo ani razu. Stan „nie do pokazania" to stan niesprawdzony.
 *
 * ⚠ **Próbka NIE przechodzi ścieżką zatwierdzania** (`POST /admin/integracja/zgloszenia/…`):
 * tamta zmienia także firmy, klucze i dziennik, a tu chodzi o jedną kolumnę do obejrzenia.
 * Dlatego zapamiętuje poprzednią wartość i przywraca ją co do znaku.
 *
 * Wywołanie:
 *   php dev/probka-zgloszenia.php stan
 *   php dev/probka-zgloszenia.php ustaw <id_firmy> pending|rejected|approved
 *   php dev/probka-zgloszenia.php przywroc
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
/** Stan sprzed pomiaru leży obok próbki, nie w bazie — baza ma wrócić do stanu, w jakim była. */
$plik = dirname(__DIR__) . '/var/probka-zgloszenia.json';
$STANY = ['pending', 'rejected', 'approved'];

$tryb = $argv[1] ?? 'stan';

/** Zgłoszenie firmy: po NIP-ie, bo tak wiąże je ekran (`DataViewRepository::companies`). */
$zgloszenie = static function (PDO $pdo, int $firmaId): ?array {
    $st = $pdo->prepare(
        'SELECT s.id, s.status, c.id AS company_id FROM company c
          JOIN company_signup s ON s.nip = c.nip WHERE c.id = :id ORDER BY s.id DESC LIMIT 1'
    );
    $st->execute(['id' => $firmaId]);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
};

if ($tryb === 'stan') {
    $out = [];
    foreach ($pdo->query('SELECT id FROM company ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $z = $zgloszenie($pdo, (int) $id);
        $out[] = ['firma' => (int) $id, 'zgloszenie' => $z['id'] ?? null, 'status' => $z['status'] ?? null];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

    exit(0);
}

if ($tryb === 'ustaw') {
    $firmaId = (int) ($argv[2] ?? 0);
    $status = (string) ($argv[3] ?? '');
    if (!in_array($status, $STANY, true)) {
        fwrite(STDERR, "Stan musi być jednym z: " . implode(', ', $STANY) . "\n");

        exit(2);
    }
    $z = $zgloszenie($pdo, $firmaId);
    if ($z === null) {
        fwrite(STDERR, "Firma {$firmaId} nie ma zgłoszenia.\n");

        exit(2);
    }
    // ⚠ Zapamiętujemy PIERWSZĄ zastaną wartość i nigdy jej nie nadpisujemy drugim wywołaniem:
    // inaczej „przywróć" oddałoby stan z połowy pomiaru, a nie ten sprzed niego.
    $przed = is_file($plik) ? (array) json_decode((string) file_get_contents($plik), true) : [];
    $klucz = (string) $z['id'];
    if (!isset($przed[$klucz])) {
        $przed[$klucz] = $z['status'];
        file_put_contents($plik, json_encode($przed, JSON_UNESCAPED_UNICODE));
    }
    $pdo->prepare('UPDATE company_signup SET status = :s WHERE id = :id')
        ->execute(['s' => $status, 'id' => $z['id']]);
    echo "zgłoszenie #{$z['id']} (firma {$firmaId}): {$z['status']} → {$status}\n";

    exit(0);
}

if ($tryb === 'przywroc') {
    if (!is_file($plik)) {
        echo "nie ma czego przywracać\n";

        exit(0);
    }
    $przed = (array) json_decode((string) file_get_contents($plik), true);
    foreach ($przed as $id => $status) {
        $pdo->prepare('UPDATE company_signup SET status = :s WHERE id = :id')
            ->execute(['s' => (string) $status, 'id' => (int) $id]);
        echo "zgłoszenie #{$id} → {$status}\n";
    }
    unlink($plik);

    exit(0);
}

fwrite(STDERR, "Nieznany tryb. Użycie: stan | ustaw <id_firmy> <status> | przywroc\n");

exit(2);
