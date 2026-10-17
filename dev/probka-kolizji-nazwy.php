<?php

declare(strict_types=1);

/*
 * Kolizja nazwy wyświetlanej z nazwą z kadr — **dowód przez łamanie na dwóch POŁĄCZENIACH**
 * (zlecenie 08.10.2026 §7.4). Wejście dla bramki; nie jedzie do paczki klienta (katalog `dev/`).
 *
 * ⚠ Sprawdzenie kolizji jest w tej samej transakcji co zapis i trzyma `FOR UPDATE`. Zdanie
 * „sprawdzenie w transakcji je zatrzymuje" trzeba było zmierzyć, a nie zadeklarować — więc
 * próbka ustawia WYŚCIG: jedno połączenie zajmuje wiersz kolidujący i trzyma go, drugie w tym
 * samym czasie próbuje zapisać tę nazwę.
 *
 * Tryby:
 *   `php dev/probka-kolizji-nazwy.php blokuj <sekundy>` — trzyma `SELECT … FOR UPDATE` na wierszu
 *       spółki, której nazwa z kadr zostanie użyta jako nazwa wyświetlana innej spółki,
 *   `php dev/probka-kolizji-nazwy.php zapisz` — woła `CompanyRepository::setDisplayName()`
 *       i mierzy, ile czekał oraz co dostał,
 *   `php dev/probka-kolizji-nazwy.php stan` — wypisuje nazwy obu spółek.
 *
 * ⚠ Pomiar sprząta po sobie: `zapisz` **nie zostawia** nowej nazwy — przy powodzeniu (czyli
 * gdy ochrona nie działa) natychmiast przywraca stan sprzed próby i mówi o tym w wyniku.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\CompanyRepository;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
$polaczenie = static fn (): Connection => new Connection([
    'host' => (string) $env['DB_HOST'],
    'port' => (int) $env['DB_PORT'],
    'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'],
    'password' => (string) $env['DB_PASS'],
]);

$db = $polaczenie();
$spolki = $db->pdo()->query('SELECT id, name, display_name FROM company ORDER BY id')->fetchAll();
if (count($spolki) < 2) {
    echo json_encode(['blad' => 'tenant ma mniej niż dwie spółki — wyścigu nie da się ustawić']), "\n";
    exit(1);
}
// Ofiara: spółka, której nazwę wyświetlaną zmieniamy. Kolizja: nazwa z kadr tej drugiej.
$ofiara = (int) $spolki[0]['id'];
$kolidujaca = (string) $spolki[1]['name'];
$kolidujacaId = (int) $spolki[1]['id'];
$tryb = $argv[1] ?? 'stan';

if ($tryb === 'stan') {
    echo json_encode(['spolki' => $spolki, 'ofiara' => $ofiara, 'kolidujaca_nazwa' => $kolidujaca],
        JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

if ($tryb === 'blokuj') {
    $sekundy = max(1, min(20, (int) ($argv[2] ?? 3)));
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT id FROM company WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $kolidujacaId]);
    echo json_encode(['trzymam_wiersz' => $kolidujacaId, 'sekund' => $sekundy]), "\n";
    flush();
    sleep($sekundy);
    $pdo->rollBack();
    echo json_encode(['zwolnione' => true]), "\n";
    exit(0);
}

if ($tryb === 'zapisz') {
    $repo = new CompanyRepository($db);
    $przed = $repo->find($ofiara);
    $start = microtime(true);
    $powod = $repo->setDisplayName($ofiara, $kolidujaca);
    $czekal = round(microtime(true) - $start, 2);
    $po = $repo->find($ofiara);
    $sprzatanie = 'niepotrzebne';
    if ($powod === null) {
        // ⚠ Ochrona NIE zadziałała — cofamy natychmiast, żeby pomiar nie zostawił po sobie
        // dwóch nierozróżnialnych wierszy na liście, na której nadaje się uprawnienia.
        $repo->setDisplayName($ofiara, $przed['nazwa_wyswietlana']);
        $sprzatanie = 'cofnięto zapis, który nie powinien przejść';
    }
    echo json_encode([
        'powod' => $powod,
        'czekal_s' => $czekal,
        'nazwa_przed' => $przed['nazwa_wyswietlana'],
        'nazwa_po' => $po['nazwa_wyswietlana'],
        'sprzatanie' => $sprzatanie,
    ], JSON_UNESCAPED_UNICODE), "\n";
    exit($powod === null ? 1 : 0);
}

echo json_encode(['blad' => 'nieznany tryb: ' . $tryb]), "\n";
exit(1);
