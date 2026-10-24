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

    // „N dostęp/dostępy/dostępów do firm" w wyniku wysyłki zaproszeń. ⚠ Zdanie stało tam
    // bez odmiany („2 dostępów do spółek") i miało jeszcze zakazane słowo.
    foreach ([1 => 'dostęp', 2 => 'dostępy', 4 => 'dostępy', 5 => 'dostępów',
              12 => 'dostępów'] as $ile => $oczekiwane) {
        $check("dostepy({$ile})", Odmiana::dostepy($ile), $oczekiwane);
    }

    // ⚠ Biernik „N osobę/osoby/osób pominięto": mianownik różni się tylko w pojedynczej,
    // więc błąd wychodzi w najczęstszym zdaniu — i wyszedł („1 osoba pominięto").
    foreach ([1 => 'osobę', 2 => 'osoby', 4 => 'osoby', 5 => 'osób', 12 => 'osób',
              22 => 'osoby'] as $ile => $oczekiwane) {
        $check("osobyBiernik({$ile})", Odmiana::osobyBiernik($ile), $oczekiwane);
    }

    // ⚠ Dopełniacz „do N firm" ma JEDNĄ formę mnogą — „do 2 firmy" i „do 2 firm" różnią się
    // tak samo jak „2 osób" od „2 osoby", czyli w najczęstszym zdaniu panelu blokady.
    foreach ([1 => 'firmy', 2 => 'firm', 4 => 'firm', 5 => 'firm', 12 => 'firm'] as $ile => $oczekiwane) {
        $check("firmyDopelniacz({$ile})", Odmiana::firmyDopelniacz($ile), $oczekiwane);
    }

    // ⚠ Czasownik przy liczbie: mnogi TYLKO dla 2–4 (bez nastu). Zero i 25 biorą dopełniacz
    // mnogi rzeczownika i orzeczenie POJEDYNCZE — „0 osób dostanie", nie „0 osób dostaną".
    // Oba te zdania stały na ekranie wysyłki zaproszeń, zanim ta funkcja powstała.
    foreach ([0 => false, 1 => false, 2 => true, 4 => true, 5 => false, 13 => false,
              22 => true, 25 => false] as $ile => $oczekiwane) {
        $check("czasownikMnogi({$ile})", Odmiana::czasownikMnogi($ile) ? 'mnogi' : 'pojedynczy',
            $oczekiwane ? 'mnogi' : 'pojedynczy');
    }

    // ⚠ `reguly()` jest w BIERNIKU, bo zdanie paska zmian w Hierarchii zawsze ma czasownik
    // przechodni („ta zmiana wyłączy…"). Mianownik różni się tylko w liczbie pojedynczej
    // („1 reguła" wobec „1 regułę"), więc błąd wyszedłby w najczęstszym przypadku na ekranie.
    foreach ([1 => 'regułę', 2 => 'reguły', 4 => 'reguły', 5 => 'reguł', 12 => 'reguł',
              22 => 'reguły', 25 => 'reguł'] as $ile => $oczekiwane) {
        $check("reguly({$ile})", Odmiana::reguly($ile), $oczekiwane);
    }

    // ⚠ Licznik paska Hierarchii pisał „2 zmian" i „3 zmian" — formę poprawną wyłącznie od pięciu
    // (zgłoszone z pomiaru Z80 cz. C). Słowa stoją tu, wybór formy robi `modules/hierarchia.js`.
    foreach ([1 => 'zmiana', 2 => 'zmiany', 3 => 'zmiany', 4 => 'zmiany', 5 => 'zmian',
              12 => 'zmian', 13 => 'zmian', 22 => 'zmiany', 25 => 'zmian'] as $ile => $oczekiwane) {
        $check("zmiany({$ile})", Odmiana::zmiany($ile), $oczekiwane);
    }

    // ⚠ Przycisk wysyłki zaproszeń i zdanie o licencjach (Z84 C): oba stoją w oknie, które
    // poprzedza czynność nieodwracalną, więc błąd formy widać dokładnie tam, gdzie człowiek
    // czyta najuważniej. „Licencje" mają trzy RÓŻNE formy — biernik po 2–4 nie równa się
    // dopełniaczowi od 5.
    foreach ([1 => 'zaproszenie', 2 => 'zaproszenia', 4 => 'zaproszenia', 5 => 'zaproszeń',
              12 => 'zaproszeń', 22 => 'zaproszenia'] as $ile => $oczekiwane) {
        $check("zaproszenia({$ile})", Odmiana::zaproszenia($ile), $oczekiwane);
    }
    foreach ([1 => 'licencję', 2 => 'licencje', 4 => 'licencje', 5 => 'licencji',
              12 => 'licencji', 22 => 'licencje'] as $ile => $oczekiwane) {
        $check("licencje({$ile})", Odmiana::licencje($ile), $oczekiwane);
    }

    // Kafel kolejki na `/ustawienia/poczta` (Z43): prototyp miał „N zadania", czyli formę
    // poprawną wyłącznie dla 2–4, a kafel pokazuje liczbę prosto z bazy.
    foreach ([0 => 'zadań', 1 => 'zadanie', 2 => 'zadania', 5 => 'zadań', 13 => 'zadań',
              22 => 'zadania'] as $ile => $oczekiwane) {
        $check("zadania({$ile})", Odmiana::zadania($ile), $oczekiwane);
    }

    // ⚠ Zero to dopełniacz („0 wpisów”, „0 dni roboczych”) — w tych widokach pojawia się przy
    // nieobecności kadrowej, której kadry liczą zero dni.
    $check('wpisy(0)', Odmiana::wpisy(0), 'wpisów');
    $check('dniRobocze(0)', Odmiana::dniRobocze(0), 'dni roboczych');

    // ⚠ Liczba ujemna ma dawać formę taką, jak jej wartość bezwzględna — limit bywa ujemny
    // (nadebrany urlop), a „-2 dni roboczych” czyta się jak dwa różne błędy naraz.
    $check('dniRobocze(-2)', Odmiana::dniRobocze(-2), 'dni robocze');
    $check('wpisy(-1)', Odmiana::wpisy(-1), 'wpis');

    // Kafel „Wynik" na `/diagnostyka` (Z50): liczba przychodzi z przebiegu sprawdzeń, więc bywa
    // zarówno 1, jak i 13 — a kafel ma brzmieć jak zdanie, nie jak licznik.
    foreach ([0 => 'błędów', 1 => 'błąd', 2 => 'błędy', 4 => 'błędy', 5 => 'błędów',
              13 => 'błędów', 22 => 'błędy'] as $ile => $oczekiwane) {
        $check("bledy({$ile})", Odmiana::bledy($ile), $oczekiwane);
    }
    foreach ([0 => 'ostrzeżeń', 1 => 'ostrzeżenie', 3 => 'ostrzeżenia', 5 => 'ostrzeżeń',
              12 => 'ostrzeżeń', 23 => 'ostrzeżenia'] as $ile => $oczekiwane) {
        $check("ostrzezenia({$ile})", Odmiana::ostrzezenia($ile), $oczekiwane);
    }
    // Po przyimku `z` dopełniacz mnogi jest jeden — sprawdzamy brzeg liczby pojedynczej.
    foreach ([0 => 'sprawdzeń', 1 => 'sprawdzenia', 2 => 'sprawdzeń', 66 => 'sprawdzeń'] as $ile => $oczekiwane) {
        $check("sprawdzenDopelniacz({$ile})", Odmiana::sprawdzenDopelniacz($ile), $oczekiwane);
    }

    // ⚠ „podwładny" odmienia się jak przymiotnik: jedna forma mnoga dla 2–4 i dla 5+.
    foreach ([0 => 'podwładnych', 1 => 'podwładny', 2 => 'podwładnych', 3 => 'podwładnych',
              5 => 'podwładnych', 22 => 'podwładnych'] as $ile => $oczekiwane) {
        $check("podwladnych({$ile})", Odmiana::podwladnych($ile), $oczekiwane);
    }

    // ⚠ „inna/inne/innych" idzie za rodzajem ŻEŃSKIM (domyślny rzeczownik: osoba), więc
    // 2–4 dostaje „inne", a 5+ „innych" — tak samo jak `osoby()`, nie jak `podwladnych()`.
    foreach ([1 => 'inna', 2 => 'inne', 3 => 'inne', 4 => 'inne', 5 => 'innych',
              12 => 'innych', 22 => 'inne'] as $ile => $oczekiwane) {
        $check("inne({$ile})", Odmiana::inne($ile), $oczekiwane);
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
