<?php

declare(strict_types=1);

/*
 * Klucz API na czas pomiaru — wejście dla bramki ekranowej. **Nie jedzie do paczki klienta.**
 *
 * ⚠ Bramka **nie dotyka czynnego klucza instalacji**, bo dowód „czynnego nie da się skasować"
 * przy zepsutym warunku skasowałby klucz, z którego korzysta connector u klienta. Zakłada więc
 * własny klucz bez przypisanych firm, na nim próbuje, i sama go sprząta.
 *
 * Wywołanie: `php dev/probka-klucza.php wydaj|uniewaznij <id>|usun <id>|stan`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\ApiKeyRepository;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
$db = new Connection([
    'host' => (string) $env['DB_HOST'], 'port' => (int) $env['DB_PORT'], 'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'], 'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();
$keys = new ApiKeyRepository($db);

$tryb = $argv[1] ?? 'stan';
$id = isset($argv[2]) ? (int) $argv[2] : 0;
$NAZWA = 'klucz bramki: proba kasowania';

if ($tryb === 'wydaj') {
    // ⚠ Bez `ApiKeyService::issue()`, bo ono wysyła zdarzenia i pilnuje jednego czynnego klucza
    // na firmę. Tu chodzi o surowy wiersz do zepsucia, nie o ścieżkę wydawania.
    $hash = hash('sha256', 'bramka-' . bin2hex(random_bytes(8)));
    $prefix = substr($hash, 0, 8);
    $stmt = $pdo->prepare(
        "INSERT INTO api_key (name, key_hash, key_prefix, status, created_at)
         VALUES (:name, :hash, :prefix, 'active', UTC_TIMESTAMP())"
    );
    $stmt->execute(['name' => $NAZWA, 'hash' => $hash, 'prefix' => $prefix]);
    echo json_encode(['id' => (int) $pdo->lastInsertId(), 'prefiks' => $prefix]), "\n";
    exit(0);
}

if ($tryb === 'uniewaznij') {
    echo json_encode(['uniewazniony' => $keys->revoke($id)]), "\n";
    exit(0);
}

if ($tryb === 'usun') {
    // Sprzątanie po pomiarze — kasuje WYŁĄCZNIE klucz bramki, nigdy cudzy.
    $stmt = $pdo->prepare('DELETE FROM api_key WHERE id = :id AND name = :name');
    $stmt->execute(['id' => $id, 'name' => $NAZWA]);
    echo json_encode(['usuniete' => $stmt->rowCount()]), "\n";
    exit(0);
}

if ($tryb === 'repo-czynny') {
    // ⚠ Dowód na SAM warunek w zapytaniu, z pominięciem warstwy usługi. Bez tego bramka
    // sprawdzała tylko WYNIK („czynny klucz nie ginie"), a ten wynik dawała już pierwsza
    // przeszkoda w `ApiKeyService::usun()` — zdjęcie warunku z SQL-a przechodziło na zielono.
    // Dwie warstwy mają sens tylko wtedy, gdy każda jest sprawdzona osobno.
    $przed = $keys->find($id);
    $wynik = $keys->usun($id);
    $po = $keys->find($id);
    echo json_encode([
        'status_przed' => $przed === null ? null : $przed['status'],
        'repozytorium_skasowalo' => $wynik,
        'istnieje_po' => $po !== null,
    ]), "\n";
    exit(0);
}

$st = $pdo->prepare('SELECT id, status FROM api_key WHERE id = :id');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
echo json_encode(['istnieje' => $row !== false, 'status' => $row === false ? null : $row['status']]), "\n";
