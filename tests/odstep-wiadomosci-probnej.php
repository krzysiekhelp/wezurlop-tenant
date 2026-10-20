<?php

declare(strict_types=1);

// **Odstęp między wiadomościami próbnymi** (zlecenie Z57 U7) — pomiar na bazie, bo to ona
// rozstrzyga.
//
// ⚠ Powód istnienia tego pliku. Zgłoszenie Krzysztofa: „po kliknięciu nie ma żadnej informacji,
// że wysłano, a przycisk zostaje aktywny, więc da się kliknąć wiele razy (i wysłać wiele maili)".
// Wyszarzenie przycisku jest wygodą, nie bramką — dwie karty przeglądarki albo wyłączony
// JavaScript wyślą tyle żądań, ile kliknięć. Bramką jest `TenantSettingRepository::claimSlot()`.
//
// ⚠ **Sedno: JEDNO polecenie, bez odczytu przed zapisem.** Dwa kliknięcia pod rząd to dwa
// żądania w tej samej sekundzie; wzorzec „sprawdź, potem zapisz" przepuściłby oba, bo oba
// przeczytałyby ten sam stan. Ten test nie umie uruchomić dwóch żądań naraz, ale pilnuje
// własności, z której atomowość wynika: pierwsze zajęcie się udaje, kolejne w tym samym oknie
// czasu **nie zmieniają wiersza** (`rowCount() === 0`), a po upływie okna znów się udaje.
//
// ⚠ Test PISZE do bazy, więc ma bezpiecznik (`APP_ENV=dev`) i przywraca stan wiersza, który
// zastał — także po wyjątku (`finally`).
//
// Uruchomienie: php tests/odstep-wiadomosci-probnej.php — 0 = przeszło, 2 = odmowa (nie dev).

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\TenantSettingRepository;

(static function (): void {
    $root = dirname(__DIR__);
    (Dotenv\Dotenv::createImmutable($root))->safeLoad();
    $cfg = (require $root . '/config/config.php')($_ENV, $_SERVER, $root);
    if (($cfg['app_env'] ?? '') !== 'dev') {
        fwrite(STDERR, "ODMOWA: ten test pisze do tabeli ustawień i uruchamia się wyłącznie przy APP_ENV=dev.\n"
            . 'Widziane: APP_ENV=' . ($cfg['app_env'] ?? '—') . "\n");
        exit(2);
    }

    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $pdo = (new Connection($cfg['db']))->pdo();
    $settings = new TenantSettingRepository(new Connection($cfg['db']));
    // ⚠ Własny klucz, nie ten z aplikacji: test nie ma prawa zużyć okienka czasu, z którego
    // korzysta ekran ustawień — inaczej przebieg testu blokowałby wiadomość próbną na minutę.
    $klucz = 'test_odstep_proby';
    $odstep = 60;

    // Stan PRZED pierwszym zapisem (pomiar nie nadpisuje cudzej wartości).
    $stmt = $pdo->prepare('SELECT value FROM tenant_setting WHERE `key` = :k');
    $stmt->execute(['k' => $klucz]);
    $przed = $stmt->fetchColumn();

    try {
        $pdo->prepare('DELETE FROM tenant_setting WHERE `key` = :k')->execute(['k' => $klucz]);

        // ⚠ Pierwsze zajęcie udaje się także wtedy, gdy wiersza jeszcze nie ma — bez migracji
        // z zasiewem (to różnica wobec `claimAlertSlot()`, które wymaga istniejącego wiersza).
        $check('pierwsza próba zajmuje okienko bez istniejącego wiersza', $settings->claimSlot($klucz, $odstep));
        $check('druga próba w tej samej chwili — odmowa', !$settings->claimSlot($klucz, $odstep));
        $check('trzecia próba — też odmowa', !$settings->claimSlot($klucz, $odstep));
        $check('wiersz jest dokładnie jeden', (int) $pdo->query(
            "SELECT COUNT(*) FROM tenant_setting WHERE `key` = 'test_odstep_proby'"
        )->fetchColumn() === 1);

        // ⚠ Odmowa jest CZASOWA, nie trwała: cofamy zapisaną chwilę o sekundę więcej niż odstęp.
        $pdo->prepare(
            "UPDATE tenant_setting
                SET value = DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL :ile SECOND, '%Y-%m-%d %H:%i:%s')
              WHERE `key` = :k"
        )->execute(['ile' => $odstep + 1, 'k' => $klucz]);
        $check('po upływie odstępu próba znów przechodzi', $settings->claimSlot($klucz, $odstep));
        $check('i znów blokuje następną', !$settings->claimSlot($klucz, $odstep));

        // ⚠ Zapisana wartość musi być **znacznikiem czasu UTC w formacie porównywalnym tekstowo**
        // (`RRRR-MM-DD GG:MM:SS`) — na tym stoi cały warunek odstępu. Inny format albo czas
        // lokalny dałby porównanie, które „działa" do pierwszej zmiany strefy serwera.
        $stmt = $pdo->prepare('SELECT value, UTC_TIMESTAMP() AS teraz FROM tenant_setting WHERE `key` = :k');
        $stmt->execute(['k' => $klucz]);
        $wiersz = $stmt->fetch();
        $zapisana = (string) ($wiersz['value'] ?? '');
        $check('zapisana chwila w formacie porównywalnym tekstowo',
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $zapisana) === 1, $zapisana);
        // ⚠ I jest to czas UTC z bazy, nie czas PHP: różnica najwyżej kilku sekund.
        $check('zapisana chwila to czas UTC bazy',
            abs(strtotime($zapisana) - strtotime((string) ($wiersz['teraz'] ?? ''))) <= 5, $zapisana);
    } finally {
        $pdo->prepare('DELETE FROM tenant_setting WHERE `key` = :k')->execute(['k' => $klucz]);
        if ($przed !== false) {
            $pdo->prepare('INSERT INTO tenant_setting (`key`, value, updated_at) VALUES (:k, :v, UTC_TIMESTAMP())')
                ->execute(['k' => $klucz, 'v' => $przed]);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tenant_setting WHERE `key` = :k');
        $stmt->execute(['k' => $klucz]);
        $check('sprzątanie: po przebiegu nie został żaden wiersz testowy',
            (int) $stmt->fetchColumn() === ($przed === false ? 0 : 1));
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
