<?php

declare(strict_types=1);

// Rodzaje limitów z kadr po polsku (zlecenie Z46, 19.10.2026).
//
// ⚠ Sedno tego sprawdzenia: zachowanie przy kodzie NIEZNANYM. Ekran danych kadrowych nie może
// ukryć pozycji dlatego, że R2 przysłało rodzaj, którego nie ma w naszym słowniku — do 19.10.2026
// karta pokazywała surowy kod (`wypoczynkowy`), więc regres w drugą stronę (pozycja znika albo
// udaje inny rodzaj) byłby niewidoczny bez tego testu.
//
// Uruchomienie: php tests/rodzaje-limitow.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Support\RodzajeLimitow;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $check('rodzaj urlopowy ma nazwę po polsku',
        RodzajeLimitow::nazwa('wypoczynkowy') === 'Urlop wypoczynkowy', RodzajeLimitow::nazwa('wypoczynkowy'));
    $check('opieka nad dzieckiem ma nazwę z podstawą prawną',
        str_contains(RodzajeLimitow::nazwa('opieka_art188'), 'art. 188'), RodzajeLimitow::nazwa('opieka_art188'));
    // ⚠ Ta sama zasada, co w `KodyZdarzen::etykieta()` i `ZadaniaKolejki::nazwa()`.
    $check('nieznany rodzaj zostaje swoim kodem',
        RodzajeLimitow::nazwa('cos_nowego_z_r2') === 'cos_nowego_z_r2');
    $check('nieznany rodzaj nie udaje znanego', RodzajeLimitow::znany('cos_nowego_z_r2') === false);
    $check('nazwa nigdy nie jest pusta', RodzajeLimitow::nazwa('') === '');

    // ⚠ Kafel „Pozostało urlopu" filtruje limity po TEJ stałej (`DataController::kafleKarty`),
    // a nie po literale — gdyby stała zmieniła wartość, kafel pokazywałby „brak limitu"
    // przy limicie, który stoi w karcie niżej. To jest ten rozjazd, który test ma złapać.
    $check('rodzaj urlopowy z kafla ma nazwę w słowniku',
        RodzajeLimitow::znany(PulpitPracownika::LIMIT_URLOPOWY), PulpitPracownika::LIMIT_URLOPOWY);

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
