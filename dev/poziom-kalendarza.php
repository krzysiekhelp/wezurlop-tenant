<?php

declare(strict_types=1);

/*
 * **Poziom wglądu w kalendarz firmy dla jednego konta** — odczyt albo ustawienie, dzisiejszym
 * modelem (zlecenie Z63 cz. A, 20.10.2026). Wejście dla bramki ekranowej; `dev/` nie jedzie
 * do paczki klienta.
 *
 * ⚠ **Dlaczego ten plik powstał.** Bramka nadawała poziom przez `role_assignment`
 * (`calendar_viewer`, `calendar_viewer_full`) — i od Z53 (19.10.2026) to nie nadaje NICZEGO:
 * widoczność liczą reguły z `calendar_access_rule`, a jedyne uprawnienie, o które jeszcze pyta
 * `CalendarVisibility`, to uprawnienie administratora. Kryterium `poziomy` mierzyło więc trzy
 * poziomy, z których żaden nie wchodził w życie — i świeciło czerwono na poprawnym kodzie,
 * bo przy „poziomie 1" ekran mówił „Nie masz nadanego podglądu kalendarza". Zmierzone
 * w przebiegu z 20.10.2026.
 *
 * ⚠ **Zapis idzie drogą aplikacji** (`CalendarAccessRepository`), nie własnym `INSERT`-em:
 * próbka, która zapisuje własnym SQL-em, mierzy siebie. Odczyt też czyta reguły, a nie tabelę.
 *
 * Trzy poziomy mają w tym modelu dokładne odpowiedniki:
 *   0 — brak reguły dla tego konta w tej firmie (widzi tylko swoje i swoją gałąź),
 *   1 — reguła „cała firma" z zakresem `bez_szczegolow` (widzi obecności, nie widzi rodzajów),
 *   2 — reguła „cała firma" z zakresem `ze_szczegolami`.
 *
 * ⚠ Ustawienie poziomu **usuwa wcześniejsze reguły tego konta w tej firmie** i tylko je —
 * reguł „administratorzy" i „przełożeni" nie rusza, bo nie o nich mówi poziom.
 *
 * Wywołanie: `php dev/poziom-kalendarza.php <email> <firma> [0|1|2|-]`, wynik w JSON
 * (`-` albo brak argumentu = tylko odczyt).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Service\ZakresWidocznosci;

$email = (string) ($argv[1] ?? '');
$spolka = (int) ($argv[2] ?? 0);
$zadany = $argv[3] ?? '-';
if ($email === '' || $spolka <= 0) {
    fwrite(STDERR, "Użycie: poziom-kalendarza.php <email> <firma> [0|1|2|-]\n");
    exit(1);
}

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
$reguly = new CalendarAccessRepository($db);

$st = $pdo->prepare('SELECT id FROM account WHERE email = :mail');
$st->execute(['mail' => $email]);
$konto = (int) $st->fetchColumn();
if ($konto === 0) {
    fwrite(STDERR, "Nie ma konta o tym adresie\n");
    exit(1);
}

/** Reguły „na to konto" w tej firmie — tylko one znaczą poziom. */
$wlasne = static function () use ($reguly, $spolka, $konto): array {
    return array_values(array_filter(
        $reguly->reguly($spolka),
        static fn (array $r): bool => $r['subject_kind'] === ZakresWidocznosci::KTO_KONTO
            && (int) $r['subject_account_id'] === $konto
    ));
};
/** Poziom wyliczony z reguł: 0 brak, 1 bez szczegółów, 2 ze szczegółami. */
$czytaj = static function () use ($wlasne): int {
    $poziom = 0;
    foreach ($wlasne() as $r) {
        if ($r['target_kind'] !== ZakresWidocznosci::CZYJE_FIRMA) {
            continue;
        }
        $poziom = max($poziom, $r['scope'] === ZakresWidocznosci::ZE_SZCZEGOLAMI ? 2 : 1);
    }

    return $poziom;
};

$przed = $czytaj();
if ($zadany !== '-') {
    $poziom = (int) $zadany;
    foreach ($wlasne() as $r) {
        $reguly->usun((int) $r['id'], $spolka);
    }
    if ($poziom > 0) {
        $reguly->dodaj(
            $spolka,
            ZakresWidocznosci::KTO_KONTO,
            $konto,
            ZakresWidocznosci::CZYJE_FIRMA,
            [],
            $poziom >= 2 ? ZakresWidocznosci::ZE_SZCZEGOLAMI : ZakresWidocznosci::BEZ_SZCZEGOLOW,
            null,
        );
    }
}

// ⚠ Nazwy rodzajów nieobecności: kryterium „poziom 1 nie przecieka szczegółem" porównuje
// z nimi BAJTY odpowiedzi, więc musi mieć ich pełną listę z bazy, nie z pamięci.
$slownik = $pdo->query('SELECT DISTINCT name FROM leave_type WHERE is_absence = 1 ORDER BY name')
    ->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'konto' => $konto,
    'przed' => $przed,
    'teraz' => $czytaj(),
    'slownik' => $slownik,
], JSON_UNESCAPED_UNICODE), "\n";
