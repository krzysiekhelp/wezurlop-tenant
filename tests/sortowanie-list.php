<?php

declare(strict_types=1);

// Kierunek sortowania musi trafić do KAŻDEJ kolumny wyrażenia sortującego.
//
// Powód tego pliku jest zmierzony, nie teoretyczny (zlecenie Z30, 18.10.2026). Obie listy osób
// trzymały wyrażenie sortujące jako jeden napis (`'p.last_name, p.first_name'`) i doklejały
// kierunek na jego końcu. Wychodziło `ORDER BY p.last_name, p.first_name DESC`: nazwisko ZAWSZE
// rosnąco, odwracało się wyłącznie imię. Na `/admin/konta` `kierunek=asc` i `kierunek=desc`
// zwracały tę samą pierwszą trójkę osób.
//
// ⚠ **Usterki nie dało się zobaczyć na danych roboczych**: różnica między „nazwisko rosnąco,
// imię malejąco" a „oba malejąco" jest widoczna dopiero przy DWÓCH OSOBACH O TYM SAMYM NAZWISKU,
// a w bazie roboczej nie ma ani jednej takiej pary (sprawdzone: kolejność malejąca jest dokładnie
// odwróconą rosnącą, 37 osób, zero miejsc różnicy). Dlatego ten przypadek pilnuje test
// na WYRAŻENIU, a nie pomiar na ekranie — ekran go nie pokaże, dopóki ktoś nie zostanie
// zatrudniony pod tym samym nazwiskiem.
//
// ⚠ Drugi powód: ten sam wzorzec był przepisany do dwóch repozytoriów, więc jedna poprawka
// zostawiłaby drugą usterkę (`PersonRepository` i `DataViewRepository`).
//
// Uruchomienie: php tests/sortowanie-list.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Repository\DataViewRepository;
use Wezurlop\Repository\PersonRepository;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ⚠ Metody są prywatne i statyczne, bo budują fragment SQL-a i nie mają żadnego stanu —
    // test sięga po nie refleksją zamiast otwierać je światu tylko dla sprawdzenia.
    $sql = static function (string $klasa, string $metoda, string $sort, bool $asc): string {
        $m = new ReflectionMethod($klasa, $metoda);
        $m->setAccessible(true);

        return (string) $m->invoke(null, $sort, $asc);
    };

    $osoby = static fn (string $sort, bool $asc): string
        => $sql(PersonRepository::class, 'orderSql', $sort, $asc);
    $prac = static fn (string $sort, bool $asc): string
        => $sql(DataViewRepository::class, 'employeeOrderSql', $sort, $asc);

    // ——— lista osób i kont ———
    $check(
        'osoby: nazwisko malejąco odwraca OBIE kolumny',
        $osoby('nazwisko', false) === 'p.last_name DESC, p.first_name DESC',
        $osoby('nazwisko', false),
    );
    $check(
        'osoby: nazwisko rosnąco to obie kolumny rosnąco',
        $osoby('nazwisko', true) === 'p.last_name ASC, p.first_name ASC',
        $osoby('nazwisko', true),
    );
    // ⚠ `email IS NULL` NIE dostaje kierunku: pcha osoby bez adresu na koniec listy niezależnie
    // od tego, w którą stronę sortujemy. Z kierunkiem sortowanie malejąco zaczynałoby się od
    // pustych wierszy w kolumnie, po której się sortuje.
    $check(
        'osoby: puste adresy zostają na końcu także przy sortowaniu malejąco',
        $osoby('email', false) === 'email IS NULL, email DESC',
        $osoby('email', false),
    );
    $check(
        'osoby: nieznany klucz spada na nazwisko (biała lista, §4)',
        $osoby('DROP TABLE person', true) === $osoby('nazwisko', true),
        $osoby('DROP TABLE person', true),
    );

    // ——— lista pracowników firmy na /dane ———
    $check(
        'pracownicy: nazwisko malejąco odwraca OBIE kolumny',
        $prac('nazwisko', false) === 'p.last_name DESC, p.first_name DESC, e.id',
        $prac('nazwisko', false),
    );
    $check(
        'pracownicy: jedna kolumna dostaje kierunek tak samo',
        $prac('etat', false) === 'e.fte DESC, e.id',
        $prac('etat', false),
    );
    $check(
        'pracownicy: nieznany klucz spada na nazwisko',
        $prac('; DELETE FROM employment', true) === $prac('nazwisko', true),
        $prac('; DELETE FROM employment', true),
    );

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
