<?php

declare(strict_types=1);

// Nazwa spółki do pokazania człowiekowi — jedno wyrażenie, dwa warianty aliasu.
// Powód tego pliku: wyrażenie `COALESCE(NULLIF(display_name,''), name)` stoi w stałej `SQL`
// (alias `c`) i w `sqlDla()` (dowolny alias). Dopóki istnieją dwa, mogą się rozjechać — a rozjazd
// nie jest widoczny na ekranie: jedno zapytanie pokaże nazwę wyświetlaną, drugie nazwę z R2,
// i nikt nie skojarzy tego z jedną zmianą reguły. Ten test zamienia rozjazd w czerwony przebieg.
// Uruchomienie: php tests/nazwa-spolki.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Repository\NazwaSpolki;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ⚠ Sedno testu: stała i metoda muszą dawać DOKŁADNIE to samo dla aliasu `c`.
    $check(
        'SQL === sqlDla(\'c\')',
        NazwaSpolki::SQL === NazwaSpolki::sqlDla('c')
    );
    $check(
        'sqlDla(\'c2\') podstawia alias w obu członach',
        NazwaSpolki::sqlDla('c2') === "COALESCE(NULLIF(c2.display_name, ''), c2.name)"
    );

    // Alias spoza białej listy wybucha TUTAJ, nie w bazie (CLAUDE.md §4: identyfikatora
    // nie da się sparametryzować, więc jedyną ochroną jest zawężenie dopuszczalnej wartości).
    foreach (['c; DROP TABLE company', 'C', '', 'c-2', 'spolka_o_bardzo_dlugim_aliasie'] as $zly) {
        $rzucilo = false;
        try {
            NazwaSpolki::sqlDla($zly);
        } catch (InvalidArgumentException) {
            $rzucilo = true;
        }
        $check("alias odrzucony: '{$zly}'", $rzucilo);
    }

    // Reguła w PHP — ta sama kolejność pierwszeństwa co w SQL.
    $check('nazwa wyświetlana wygrywa', NazwaSpolki::wyswietlana('Beta', 'BETA SP. Z O.O.') === 'Beta');
    $check('null -> nazwa z R2', NazwaSpolki::wyswietlana(null, 'ALFA') === 'ALFA');
    $check('pusty ciąg -> nazwa z R2', NazwaSpolki::wyswietlana('', 'ALFA') === 'ALFA');
    $check('same spacje -> nazwa z R2', NazwaSpolki::wyswietlana('   ', 'ALFA') === 'ALFA');

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
