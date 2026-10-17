<?php

declare(strict_types=1);

// Formatowanie imion i nazwisk do wyświetlenia (docs/system-wizualny.md §9).
// Przypadki minimalne pochodzą wprost ze specyfikacji; doszły trzy własne (apostrof typograficzny,
// człon Mc w środku, wartość pusta) — nie zmieniają reguł, tylko pilnują brzegów.
// Uruchomienie: php tests/nazwa-osoby.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Support\NazwaOsoby;

(static function (): void {
    $failures = 0;
    $check = static function (string $wejscie, string $oczekiwane) use (&$failures): void {
        $wynik = NazwaOsoby::doWyswietlenia($wejscie);
        $ok = $wynik === $oczekiwane;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . "'{$wejscie}' -> '{$wynik}'" . ($ok ? '' : " (oczekiwano '{$oczekiwane}')") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // Lista ze specyfikacji, §9.
    $check('PRZYKŁADOWSKI', 'Przykładowski');
    $check('ANNA MARIA', 'Anna Maria');
    $check('TESTOWA-PRÓBNICKA', 'Testowa-Próbnicka');
    $check("O'BRIEN", "O'Brien");
    $check('DE VRIES', 'de Vries');
    $check('VAN DER BERG', 'van der Berg');
    $check('MCDONALD', 'McDonald');
    $check('MACIEJ', 'Maciej');
    $check('ŁUKASZ', 'Łukasz');
    $check('von Neumann', 'von Neumann');
    $check('Przykładowski', 'Przykładowski');

    // Brzegi: nie wolno zepsuć tego, co już działa.
    $check('O’BRIEN', 'O’Brien');                      // apostrof typograficzny
    $check('TESTOWSKI', 'Testowski');
    $check('ŻÓŁTOWSKA-ŚWIĘCICKA', 'Żółtowska-Święcicka');
    $check('DE', 'De');                                 // sam przedrostek jako całe nazwisko
    $check('JAN MCDONALD', 'Jan McDonald');
    $check('', '');
    $check('  PRZYKŁADOWSKI  ', 'Przykładowski');

    // Dwie formy podaje PHP, nie szablon.
    $formy = [
        ['nazwiskoImie', NazwaOsoby::nazwiskoImie('TESTOWSKI', 'JAN'), 'Testowski Jan'],
        ['imieNazwisko', NazwaOsoby::imieNazwisko('JAN', 'TESTOWSKI'), 'Jan Testowski'],
        ['nazwiskoImie bez imienia', NazwaOsoby::nazwiskoImie('TESTOWSKI', ''), 'Testowski'],
    ];
    foreach ($formy as [$nazwa, $wynik, $oczekiwane]) {
        $ok = $wynik === $oczekiwane;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . "{$nazwa}: '{$wynik}'" . ($ok ? '' : " (oczekiwano '{$oczekiwane}')") . "\n";
        $failures += $ok ? 0 : 1;
    }

    // ⚠ Wartość źródłowa musi zostać nietknięta — formatujemy kopię do wyświetlenia.
    $zrodlo = 'TESTOWSKI';
    NazwaOsoby::doWyswietlenia($zrodlo);
    $ok = $zrodlo === 'TESTOWSKI';
    echo ($ok ? 'OK    ' : 'BŁĄD  ') . "wartość źródłowa nietknięta\n";
    $failures += $ok ? 0 : 1;

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
