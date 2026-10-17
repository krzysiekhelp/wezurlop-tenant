<?php

declare(strict_types=1);

// Odmiana rzeczownika przez liczbę (`src/Support/Odmiana.php`). Trzy formy polskiego liczebnika
// plus wyjątek nastu — ten sam zestaw brzegów dla każdego słowa, bo każda z tych funkcji stoi
// w zdaniu składanym z liczby i słowa, i każda myli się dokładnie na 12, 13, 14.
// Uruchomienie: php tests/odmiana.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Support\Odmiana;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, string $wynik, string $oczekiwane) use (&$failures): void {
        $ok = $wynik === $oczekiwane;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . " -> '{$wynik}'" . ($ok ? '' : " (oczekiwano '{$oczekiwane}')") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ⚠ `wpisy()` dochodzi 17.10.2026, bo dwa ekrany pisały „5 wpisy w kadrach”: wykaz w Miesiącu
    // „Tylko ja” i tabela nieobecności na pulpicie. Liczba bierze się z danych kadrowych, więc
    // błędna forma czyta się jak usterka w danych, a nie w zdaniu.
    foreach ([1 => 'wpis', 2 => 'wpisy', 3 => 'wpisy', 4 => 'wpisy', 5 => 'wpisów', 11 => 'wpisów',
              12 => 'wpisów', 13 => 'wpisów', 14 => 'wpisów', 21 => 'wpisów', 22 => 'wpisy',
              25 => 'wpisów', 102 => 'wpisy', 112 => 'wpisów'] as $ile => $oczekiwane) {
        $check("wpisy({$ile})", Odmiana::wpisy($ile), $oczekiwane);
    }

    // ⚠ Wyjątek nastu jest sednem tej klasy: bez niego wychodzi „13 wpisy” i „13 dni robocze”.
    // Dlatego sąsiednie funkcje dostają te same trzy liczby — reguła jest wspólna, a błąd w niej
    // zepsułby wszystkie naraz.
    foreach ([1 => 'dzień roboczy', 3 => 'dni robocze', 5 => 'dni roboczych', 13 => 'dni roboczych',
              22 => 'dni robocze'] as $ile => $oczekiwane) {
        $check("dniRobocze({$ile})", Odmiana::dniRobocze($ile), $oczekiwane);
    }
    foreach ([1 => 'osoba', 2 => 'osoby', 5 => 'osób', 12 => 'osób'] as $ile => $oczekiwane) {
        $check("osoby({$ile})", Odmiana::osoby($ile), $oczekiwane);
    }

    // ⚠ Zero to dopełniacz („0 wpisów”, „0 dni roboczych”) — w tych widokach pojawia się przy
    // nieobecności kadrowej, której kadry liczą zero dni.
    $check('wpisy(0)', Odmiana::wpisy(0), 'wpisów');
    $check('dniRobocze(0)', Odmiana::dniRobocze(0), 'dni roboczych');

    // ⚠ Liczba ujemna ma dawać formę taką, jak jej wartość bezwzględna — limit bywa ujemny
    // (nadebrany urlop), a „-2 dni roboczych” czyta się jak dwa różne błędy naraz.
    $check('dniRobocze(-2)', Odmiana::dniRobocze(-2), 'dni robocze');
    $check('wpisy(-1)', Odmiana::wpisy(-1), 'wpis');

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
