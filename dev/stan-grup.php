<?php

declare(strict_types=1);

/*
 * **Osłona dowodów przez łamanie: migawka i odtworzenie stanu** (aneks `2026-10-10-grupy-okno-i-symetria.md` §6.2).
 * Wejście dla `dev/dowody-grup.sh`; nie jedzie do paczki klienta (katalog `dev/`), nieosiągalne po HTTP.
 *
 * ⚠ Powód jest zmierzony **trzy razy w jeden dzień**, za każdym razem inaczej:
 *   1. wstrzyknięta usterka „zapis czyści pozostałe przynależności" zdjęła osobę próbną
 *      z Produkcji — sprzątanie pomiaru nie miało skąd wiedzieć, że ma to odtworzyć,
 *   2. usterka „żądanie wkłada osobę do wszystkich grup spółki" dorzuciła **12** przynależności
 *      do grup roboczych — tego nie da się posprzątać „po nazwie próbnej", bo wiersze dotyczą
 *      prawdziwych grup,
 *   3. **doraźny skrypt pomiarowy nadał koncie administratora rolę `calendar_viewer_full`
 *      i jej nie zdjął** — a migawka tabeli przynależności tego nie widziała. Objawiło się
 *      czerwoną bramką na zupełnie innym ekranie (`/m/kalendarz`: administrator zaczął widzieć
 *      siatkę 53 osób × 31 dni, więc karta przewijała się w poziomie).
 *
 * Stąd migawka obejmuje **dwie rzeczy, nie jedną**: przynależności do grup **i przypisania ról**.
 * Odtworzenie idzie w obie strony — zdejmuje to, co doszło, i wpisuje to, co zniknęło.
 *
 * ⚠ Przynależności odtwarzamy przez `GrupyService`, więc każdy ruch zostaje w **dzienniku
 * zdarzeń** ze sprawcą „pomiar bramki — przywrócenie". Pomiar, który po cichu poprawia dane,
 * jest gorszy od pomiaru, który ich nie rusza. Role wracają zapytaniem (dziennik ról prowadzi
 * ekran dostępu, a nie ten plik) i **wypisujemy je w wyniku**, żeby nie znikły z raportu.
 *
 * Wywołanie: `php dev/stan-grup.php zapisz <plik>` przed wstrzyknięciem usterki,
 *            `php dev/stan-grup.php odtworz <plik>` po przebiegu. Wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Service\GrupyService;

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
$service = new GrupyService($repo, new EventLogRepository($db));

$polecenie = $argv[1] ?? '';
$plik = $argv[2] ?? '';
if (!in_array($polecenie, ['zapisz', 'odtworz'], true) || $plik === '') {
    echo json_encode(['blad' => 'użycie: stan-grup.php zapisz|odtworz <plik>']), "\n";
    exit(1);
}

/** @return array{przynaleznosci: array<string, array{e: int, g: int, c: int}>, role: list<string>} */
$teraz = static function () use ($pdo): array {
    $przynaleznosci = [];
    $sql = 'SELECT eg.employment_id e, eg.group_id g, cg.company_id c
              FROM employment_group eg JOIN company_group cg ON cg.id = eg.group_id';
    foreach ($pdo->query($sql) as $r) {
        $przynaleznosci[$r['e'] . '-' . $r['g']] = [
            'e' => (int) $r['e'], 'g' => (int) $r['g'], 'c' => (int) $r['c'],
        ];
    }
    ksort($przynaleznosci);
    // ⚠ WSZYSTKIE przypisania ról, nie tylko konta pomiarowego: pomiar potrafi nadać rolę
    // komu innemu (np. koncie administratora, którego używa do klikania), a filtr po koncie
    // byłby założeniem o tym, czego jeszcze nie wiemy.
    $role = $pdo->query('SELECT CONCAT(account_id, "-", role_id, "-", COALESCE(company_id, 0)) k
                           FROM role_assignment ORDER BY k')->fetchAll(PDO::FETCH_COLUMN);

    return ['przynaleznosci' => $przynaleznosci, 'role' => $role];
};

if ($polecenie === 'zapisz') {
    $stan = $teraz();
    file_put_contents($plik, json_encode($stan));
    echo json_encode([
        'zapisano' => ['przynaleznosci' => count($stan['przynaleznosci']), 'role' => count($stan['role'])],
    ]), "\n";
    exit(0);
}

$wzor = json_decode((string) file_get_contents($plik), true);
if (!is_array($wzor) || !isset($wzor['przynaleznosci'], $wzor['role'])) {
    echo json_encode(['blad' => 'migawka nieczytelna: ' . $plik]), "\n";
    exit(1);
}
$stan = $teraz();
$dodane = array_diff_key($stan['przynaleznosci'], $wzor['przynaleznosci']);
$brakujace = array_diff_key($wzor['przynaleznosci'], $stan['przynaleznosci']);
foreach ($dodane as $w) {
    $service->wypisz($w['g'], $w['c'], [$w['e']], null, 'pomiar bramki — przywrócenie');
}
foreach ($brakujace as $w) {
    $service->przypisz($w['g'], $w['c'], [$w['e']], null, 'pomiar bramki — przywrócenie');
}
// ⚠ Role tylko **zdejmujemy nadane** i **wpisujemy zdjęte** — tym samym zapytaniem, którym
// nadaje je panel osoby. Rozbieżność wypisujemy w wyniku ZAWSZE, także pustą: cisza o rolach
// jest dokładnie tym, co kosztowało nas dzisiejszy fałszywy alarm.
$roleDodane = array_values(array_diff($stan['role'], $wzor['role']));
$roleZdjete = array_values(array_diff($wzor['role'], $stan['role']));
foreach ($roleDodane as $k) {
    [$konto, $rola, $spolka] = array_map('intval', explode('-', $k));
    $st = $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k AND role_id = :r
                           AND COALESCE(company_id, 0) = :c');
    $st->execute(['k' => $konto, 'r' => $rola, 'c' => $spolka]);
}
foreach ($roleZdjete as $k) {
    [$konto, $rola, $spolka] = array_map('intval', explode('-', $k));
    $st = $pdo->prepare('INSERT INTO role_assignment (account_id, role_id, company_id)
                         VALUES (:k, :r, :c)');
    $st->execute(['k' => $konto, 'r' => $rola, 'c' => $spolka === 0 ? null : $spolka]);
}
$po = $teraz();
echo json_encode([
    'przynaleznosci' => ['zdjete' => array_keys($dodane), 'odtworzone' => array_keys($brakujace)],
    'role' => ['zdjete' => $roleDodane, 'odtworzone' => $roleZdjete],
    'zgadza_sie' => array_keys($po['przynaleznosci']) === array_keys($wzor['przynaleznosci'])
        && $po['role'] === $wzor['role'],
], JSON_UNESCAPED_UNICODE), "\n";
