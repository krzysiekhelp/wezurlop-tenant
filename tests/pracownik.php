<?php

declare(strict_types=1);

// Widok pracownika: przeliczenie minut na dni i godziny, sklejenie ciągu po polu `kontynuacja`,
// zasięg podglądu (gałąź w dół) i rodzaj ogólny cudzej nieobecności.
//
// ⚠ Trzy rzeczy z tego pliku są sprawdzalne WYŁĄCZNIE testem, bo dane robocze ich nie zawierają:
//   1. `kontynuacja` — w bazie jest wszędzie `NULL`, więc na ekranie nie ma czego sklejać,
//   2. gałąź głębsza niż jeden poziom — hierarchia w bazie jest dziś płaska (szczyt + pięć osób),
//   3. limit ujemny i `naliczony: false` — takich wierszy R2 nie przysłał.
// Mówimy to wprost w raporcie, zamiast raportować sam zielony przebieg bramki ekranowej.
//
// Uruchomienie: php tests/pracownik.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Service\ZasiegPodgladu;
use Wezurlop\Support\DataDnia;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\WorkTime;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    $zapis = PulpitPracownika::zapis(...);

    // ——— §2: minuty na dni I GODZINY, dzielnik zawsze 480 ————————————————————————
    // ⚠ Sedno: „21 dni" zamiast „21 dni 4 godz." to pół dnia urlopu zgubione u każdego pracownika.
    $check('6660 min to 13 dni i 7 godzin (nie 13 dni)',
        $zapis(6660) === ['glowna' => '13 dni', 'reszta' => '7 godz.', 'minuty' => 6660]);
    $check('godziny milczą, gdy reszta jest zerowa',
        $zapis(960) === ['glowna' => '2 dni', 'reszta' => null, 'minuty' => 960]);
    $check('jeden dzień odmieniony w liczbie pojedynczej', $zapis(480)['glowna'] === '1 dzień');
    $check('poniżej dnia godziny stają się wartością główną', $zapis(120)['glowna'] === '2 godz.');
    $check('reszta minutowa nie ginie', $zapis(90) === ['glowna' => '1 godz.', 'reszta' => null, 'minuty' => 90]);
    $check('zero to „0 dni", nie „0 min"', $zapis(0)['glowna'] === '0 dni');
    $check('brak wartości to kreska, nie zero', $zapis(null)['glowna'] === '—');

    // ⚠ Minusa nie kasujemy i nie zaokrąglamy (§3.2). Znak to U+2212, nie łącznik.
    $check('wartość ujemna zachowuje minus', $zapis(-960)['glowna'] === "\u{2212}2 dni");
    $check('ujemna z resztą podaje i dni, i godziny',
        $zapis(-6660) === ['glowna' => "\u{2212}13 dni", 'reszta' => '7 godz.', 'minuty' => -6660]);

    // Dzielnik 480 obowiązuje także przy niepełnym etacie — 1/2 etatu nie zmienia przelicznika.
    $check('dzielnik to 480 niezależnie od etatu',
        WorkTime::parts(720) === ['ujemne' => false, 'dni' => 1, 'godziny' => 4, 'minuty' => 0]);
    $check('składniki i napis pochodzą z tej samej arytmetyki',
        WorkTime::format(6660) === '13 dni 7 godz.' && $zapis(6660)['glowna'] === '13 dni');

    // ——— §3.4: zwolnienie rozbite w kadrach na kilka wpisów ————————————————————————
    $w = static fn (int $id, string $od, string $do, ?bool $kont, string $zrodlo, int $dni, int $min): array => [
        'id' => $id, 'od' => $od, 'do' => $do, 'kontynuacja' => $kont, 'zrodlo' => $zrodlo,
        'dni' => $dni, 'minuty' => $min, 'rodzaj' => 'chorobowe', 'wpis' => 'kadry',
    ];
    // Najnowsze pierwsze — tak przychodzą z repozytorium.
    $ciag = [
        $w(3, '2026-09-17', '2026-09-18', true, 'zasilek', 2, 960),
        $w(2, '2026-09-16', '2026-09-16', true, 'zasilek', 1, 480),
        $w(1, '2026-09-14', '2026-09-15', null, 'zasilek', 2, 960),
    ];
    $sklejone = PulpitPracownika::sklej($ciag);
    $check('trzy wpisy jednego ciągu dają jedną pozycję', count($sklejone) === 1);
    $check('zakres dat obejmuje cały ciąg',
        $sklejone[0]['od'] === '2026-09-14' && $sklejone[0]['do'] === '2026-09-18');
    $check('dni i minuty ciągu to suma wpisów z kadr',
        $sklejone[0]['dni'] === 5 && $sklejone[0]['minuty'] === 2400);
    $check('pozycja mówi, z ilu wpisów kadrowych powstała', $sklejone[0]['wpisow'] === 3);

    // ⚠ Bez pola `kontynuacja` NIE sklejamy, choćby wpisy sąsiadowały datami: domyślanie się
    // ciągu z samych dat byłoby liczeniem za kadry (§2 zlecenia: nie liczymy nic sami).
    $sasiednie = PulpitPracownika::sklej([
        $w(2, '2026-02-01', '2026-02-06', null, 'urlop', 5, 1800),
        $w(1, '2026-01-26', '2026-01-31', null, 'urlop', 5, 1800),
    ]);
    $check('sąsiadujące wpisy bez pola kontynuacji zostają osobno', count($sasiednie) === 2);

    // Kontynuacja innego rodzaju to nie ten sam ciąg — urlop nie przedłuża zwolnienia.
    $mieszane = PulpitPracownika::sklej([
        $w(2, '2026-09-16', '2026-09-17', true, 'urlop', 2, 960),
        $w(1, '2026-09-14', '2026-09-15', null, 'zasilek', 2, 960),
    ]);
    $check('kontynuacja z innego źródła nie scala się', count($mieszane) === 2);

    $check('kolejność zostaje malejąca po dacie',
        PulpitPracownika::sklej($sasiednie)[0]['od'] === '2026-02-01');

    // ——— §5: zasięg podglądu — cała gałąź w dół —————————————————————————————————
    // Struktura (mapa podwładny => przełożony), spółka 3:
    //   82 ── 85 ── 90        druga gałąź: 93 ── 97
    //      └─ 86
    $k = [85 => 82, 86 => 82, 90 => 85, 97 => 93];
    $galaz = ZasiegPodgladu::galaz($k, 82);
    $check('gałąź zawiera siebie na pierwszym miejscu', $galaz[0] === 82);
    $check('gałąź sięga WNUKA, nie tylko bezpośrednich podwładnych', in_array(90, $galaz, true));
    $check('gałąź ma dokładnie cztery osoby', count($galaz) === 4);
    $check('druga gałąź nie wchodzi do wyniku',
        !in_array(93, $galaz, true) && !in_array(97, $galaz, true));
    $check('liść widzi wyłącznie siebie', ZasiegPodgladu::galaz($k, 90) === [90]);
    $check('osoba spoza struktury widzi wyłącznie siebie', ZasiegPodgladu::galaz($k, 999) === [999]);

    // ⚠ Cykl w danych zastanych nie może zawiesić przejścia (przy zapisie go odrzucamy,
    // ale w bazie może już siedzieć).
    $cykl = ZasiegPodgladu::galaz([1 => 2, 2 => 3, 3 => 1], 1);
    $check('cykl nie zawiesza przejścia i nie powtarza osób',
        count($cykl) === count(array_unique($cykl)) && count($cykl) === 3);

    // ——— §5: filtr, nie uprawnienie ————————————————————————————————————————————
    $check('identyfikator spoza gałęzi zostaje odrzucony',
        ZasiegPodgladu::przefiltruj([90, 93, 97], $galaz) === [90]);
    $check('identyfikator z innej spółki nie ma jak przejść (nie ma go w gałęzi)',
        ZasiegPodgladu::przefiltruj([146], $galaz) === []);
    $check('brak wskazania znaczy „tylko ja", nie „wszyscy"',
        ZasiegPodgladu::przefiltruj([], $galaz) === [82]);
    $check('kolejność wyniku jest kolejnością gałęzi, nie żądania',
        ZasiegPodgladu::przefiltruj([90, 82], $galaz) === [82, 90]);
    $check('pusty zasięg nie przepuszcza niczego', ZasiegPodgladu::przefiltruj([82], []) === []);

    // ⚠ Pusta lista ma DWA znaczenia i muszą stać w dwóch funkcjach: w adresie „nic nie wskazano"
    // (pokaż właściciela), w formularzu „odznaczyłem wszystkich" (nie pokazuj nikogo).
    // Zmierzone na ekranie przed rozdzieleniem: po odznaczeniu wszystkich pięciu osób zostawał
    // jeden wiersz, którego nikt nie zaznaczył.
    $check('pusty ADRES pokazuje właściciela kalendarza',
        ZasiegPodgladu::przefiltruj([], $galaz) === [82]);
    $check('pusty FORMULARZ nie pokazuje nikogo',
        ZasiegPodgladu::wZasiegu([], $galaz) === []);
    $check('przecięcie bez domyślności działa jak przecięcie',
        ZasiegPodgladu::wZasiegu([90, 999], $galaz) === [90]);

    // ——— domyślny wybór: WSZYSCY w zasięgu, a zapis trzyma WYŁĄCZENIA ————————————
    // ⚠ Sedno: gdyby tabela trzymała włączenia, pracownik przyjęty jutro nie pojawiłby się
    // nikomu na kalendarzu i nikt by tego nie zauważył. Ten test pilnuje właśnie tego.
    $bez = ZasiegPodgladu::bezWykluczonych(...);
    $check('brak wyłączeń znaczy „widać wszystkich", nie „widać tylko mnie"',
        $bez($galaz, []) === $galaz);
    $check('wyłączenie odejmuje jedną osobę', $bez($galaz, [90]) === [82, 85, 86]);
    $check('można wyłączyć także siebie', $bez($galaz, [82]) === [85, 86, 90]);
    $check('wyłączenie wszystkich daje pustą listę, a nie pełną',
        $bez($galaz, $galaz) === []);
    // ⚠ NOWA OSOBA W GAŁĘZI: zapisane wyłączenia jej nie wymieniają, więc jest widoczna.
    $zNowym = ZasiegPodgladu::galaz($k + [99 => 85], 82);
    $check('osoba dodana do gałęzi po zapisaniu filtru jest widoczna SAMA',
        in_array(99, $bez($zNowym, [90]), true));
    $check('wyłączenie osoby spoza zasięgu niczego nie psuje',
        $bez($galaz, [12345]) === $galaz);

    // Przeliczenie zaznaczeń z formularza na wyłączenia — jedno miejsce, na zasięgu z chwili zapisu.
    $wykl = ZasiegPodgladu::wykluczeniaZZaznaczonych(...);
    $check('odznaczone w formularzu stają się wyłączeniami',
        $wykl($galaz, [82, 85]) === [86, 90]);
    $check('zaznaczenie wszystkich daje PUSTE wyłączenia (a nie brak zapisu)',
        $wykl($galaz, $galaz) === []);
    $check('pusty formularz wyłącza wszystkich z zasięgu', $wykl($galaz, []) === $galaz);
    // ⚠ Cudzy identyfikator przesłany w formularzu nie ma jak nic zmienić: wyłączenia powstają
    // z ZASIĘGU, nie z wejścia, więc nieznana wartość jest po prostu nieobecna w wyniku.
    $check('cudzy identyfikator w formularzu nie trafia do wyłączeń',
        !in_array(999, $wykl($galaz, [82, 999]), true));

    // ——— §5: cudza nieobecność to rodzaj OGÓLNY ————————————————————————————————
    // ⚠ Kod ze słownika („chorobowe szpitalne") to dana o zdrowiu i nie wychodzi z widoku „Tylko ja".
    $check('zasiłek to „zwolnienie", nie nazwa ze słownika', KalendarzPracownika::ogolny('zasilek') === 'zwolnienie');
    $check('urlop zostaje „urlopem"', KalendarzPracownika::ogolny('urlop') === 'urlop');
    $check('źródło kadrowe to „inna nieobecność"', KalendarzPracownika::ogolny('kadrowe') === 'inna nieobecność');
    $check('nieznane źródło nie przecieka kodem', KalendarzPracownika::ogolny('cokolwiek') === 'nieobecność');

    // ——— odmiana: „1 dni roboczych" w tabeli z danymi kadrowymi czyta się jak błąd danych ———
    $check('jeden dzień roboczy', Odmiana::dniRobocze(1) === 'dzień roboczy');
    $check('trzy dni robocze', Odmiana::dniRobocze(3) === 'dni robocze');
    $check('pięć dni roboczych', Odmiana::dniRobocze(5) === 'dni roboczych');
    $check('trzynaście dni roboczych (wyjątek nastu)', Odmiana::dniRobocze(13) === 'dni roboczych');
    $check('dwadzieścia dwa dni robocze', Odmiana::dniRobocze(22) === 'dni robocze');
    $check('zero dni roboczych', Odmiana::dniRobocze(0) === 'dni roboczych');

    // ——— §8b: POZIOM WIDOCZNOŚCI — przeniesione do `tests/widocznosc.php` ——————————
    // ⚠ Tu stało dwanaście sprawdzeń `CalendarVisibility::poziom()`: trójstanowy poziom liczony
    // z kodów uprawnień (`calendar.view_all`, `..._details`). Z53 (19.10.2026) zastąpił ten
    // mechanizm regułami dostępu per firma, więc funkcji i stałych POZIOM_* nie ma — a test,
    // który woła nieistniejącą metodę, przewraca cały plik zamiast zgłosić jedną usterkę.
    // Przypadki żyją dalej w `tests/widocznosc.php` (a–s), już na nowej funkcji
    // `ZakresWidocznosci::dlaOsoby()` i na `KalendarzPracownika::pasek()`.

    // ——— §5.5: LIMIT WYŚWIETLANIA NIE MOŻE ZAMIENIĆ SIĘ W FILTR ————————————————
    // ⚠ To jest ten test, którego brak kosztowałby najwięcej: kontrolka przy 36 osobach pokazuje
    // 30, więc licząc wyłączenia z całego zasięgu jedno kliknięcie ukryłoby 6 osób, których nikt
    // nie widział — a pracownik przyjęty jutro trafiłby na listę zakazanych.
    $poZapisie = ZasiegPodgladu::wykluczeniaPoZapisie(...);
    $zasieg53 = range(1, 53);
    $widoczne30 = range(1, 30);
    $check('zapis z widocznych 30 przy zasięgu 53 NIE wyłącza pozostałych 23',
        $poZapisie([], $widoczne30, $widoczne30) === []);
    $check('odznaczenie jednej osoby daje DOKŁADNIE jedno wyłączenie',
        $poZapisie([], $widoczne30, array_values(array_diff($widoczne30, [7]))) === [7]);
    $check('osoby poza widoczną listą zachowują poprzedni stan',
        $poZapisie([40], $widoczne30, $widoczne30) === [40]);
    $check('zaznaczenie kogoś widocznego zdejmuje jego wyłączenie',
        $poZapisie([7, 40], $widoczne30, $widoczne30) === [40]);
    $check('pusta widoczna lista nie zmienia niczego (nie ma o czym decydować)',
        $poZapisie([7], [], []) === [7]);
    // Zawężone szukanie: widoczna jest jedna osoba, decyzja dotyczy tylko jej.
    $check('przy zawężonym szukaniu decyzja dotyczy wyłącznie wyszukanej osoby',
        $poZapisie([40], [7], []) === [40, 7]);
    $check('nowa osoba w zasięgu nie jest wyłączona, więc będzie widoczna',
        !in_array(99, $poZapisie([40], $widoczne30, array_values(array_diff($widoczne30, [7]))), true));

    // ——— „przełożony: Ty" — TRZY STANY, nie dwa (zlecenie 23.09 §1) —————————————
    // ⚠ Test liczy PODPISY, nie sprawdza obecności jednego. Warunek zawsze-prawdziwy
    // i warunek zawsze-fałszywy wyglądają na ekranie tak samo, dopóki się nie policzy —
    // i właśnie dlatego usterka („przełożony: Ty" przy 25 osobach bez przełożonego)
    // przeżyła dwa tygodnie.
    $opisz = KalendarzPracownika::opisz(...);
    $osoba = static fn (int $id, ?int $szef, bool $ja = false): array => [
        'id' => $id, 'osoba' => 'Osoba ' . $id, 'przelozony' => $szef, 'ja' => $ja,
    ];
    // Mapa nazw dla CAŁEGO zasięgu — osoba 1 jest w zasięgu, osoba 9 nie.
    $nazwyZasiegu = [1 => 'Osoba 1', 2 => 'Osoba 2', 3 => 'Osoba 3'];
    // Siwiec jest KORZENIEM hierarchii (przełożony NULL), dziecko podlega jemu,
    // sierota nie ma przełożonego — i nikt z nich nie podlega oglądającemu.
    $zbior = [$osoba(1, null), $osoba(2, 1), $osoba(3, null)];
    $podpisy = static function (array $osoby, ?int $jaId) use ($opisz, $nazwyZasiegu): array {
        $licznik = [];
        foreach ($opisz($osoby, $jaId, $nazwyZasiegu) as $o) {
            $licznik[$o['rola']] = ($licznik[$o['rola']] ?? 0) + 1;
        }

        return $licznik;
    };
    $bezZatrudnienia = $podpisy($zbior, null);
    $check('konto BEZ zatrudnienia nie podpisuje nikogo „przełożony: Ty"',
        ($bezZatrudnienia['przełożony: Ty'] ?? 0) === 0);
    $check('brak przełożonego ma własny podpis, ten sam co na hierarchii',
        ($bezZatrudnienia['bez przełożonego'] ?? 0) === 2);
    $check('osoba z przełożonym spoza „ja" dostaje jego nazwisko',
        ($bezZatrudnienia['przełożony: Osoba 1'] ?? 0) === 1);
    // To samo dane, ale oglądający MA zatrudnienie i jest przełożonym osoby 2.
    $zMoim = $podpisy([$osoba(1, null, true), $osoba(2, 1), $osoba(3, null)], 1);
    $check('przy zatrudnieniu „przełożony: Ty" jest DOKŁADNIE u podwładnych',
        ($zMoim['przełożony: Ty'] ?? 0) === 1);
    $check('własny wiersz ma podpis „to Ty", nie „przełożony: Ty"',
        ($zMoim['to Ty'] ?? 0) === 1);
    $check('korzeń hierarchii nigdy nie ma podpisu „przełożony: Ty"',
        $opisz([$osoba(1, null)], 1, $nazwyZasiegu)[0]['rola'] === 'bez przełożonego');
    // ⚠ Podpis NIE MOŻE zależeć od tego, czy mój własny wiersz wpadł w limit wyświetlania.
    $check('podpis podwładnego jest ten sam, gdy mojego wiersza nie ma na liście',
        $opisz([$osoba(2, 1)], 1, $nazwyZasiegu)[0]['rola'] === 'przełożony: Ty');

    // ⚠ **Nazwisko przełożonego bierze się z ZASIĘGU, nie z pokazanej listy.** Bez tego
    // odznaczenie przełożonego w kontrolce (albo zawężenie szukaniem) zamieniało jego nazwisko
    // na zastępnik — zmierzone: 5 wierszy z nazwiskiem przed odznaczeniem, 5 z zastępnikiem po.
    $check('nazwisko przełożonego działa, gdy JEGO WIERSZA nie ma na liście',
        $opisz([$osoba(2, 1)], null, $nazwyZasiegu)[0]['rola'] === 'przełożony: Osoba 1');
    // ⚠ Granica prywatności zostaje: przełożony SPOZA zasięgu nie dostaje nazwiska.
    $check('przełożony spoza zasięgu nie jest nazwany',
        $opisz([$osoba(2, 9)], null, $nazwyZasiegu)[0]['rola'] === 'przełożony: spoza Twojego zasięgu');
    $check('zastępnik nie udaje, że chodzi o kogoś z gałęzi',
        !str_contains($opisz([$osoba(2, 9)], null, $nazwyZasiegu)[0]['rola'], 'gałęzi'));

    // ——— odmiana po przyimku i zakres na pasku (zlecenie 23.09 §3, 06.10 §3) ————
    $check('„z 53 osób", nie „z 53 osoby"', Odmiana::osobyDopelniacz(53) === 'osób');
    $check('„z 36 osób" — przy 36 stary helper trafiał przypadkiem', Odmiana::osobyDopelniacz(36) === 'osób');
    $check('„z 4 osób" — tu stary helper dawał „osoby"', Odmiana::osobyDopelniacz(4) === 'osób');
    $check('„u 1 osoby" w dopełniaczu', Odmiana::osobyDopelniacz(1) === 'osoby');
    $check('mianownik zostaje bez zmian: „4 osoby"', Odmiana::osoby(4) === 'osoby');
    $check('1 nieobecność', Odmiana::nieobecnosci(1) === 'nieobecność');
    $check('17 nieobecności', Odmiana::nieobecnosci(17) === 'nieobecności');
    $check('zakres w jednym miesiącu bez powtarzania miesiąca',
        DataDnia::zakresKrotki('2026-08-10', '2026-08-18') === '10–18.08');
    $check('zakres przez granicę miesiąca ma oba miesiące',
        DataDnia::zakresKrotki('2026-07-28', '2026-08-03') === '28.07–3.08');
    $check('jeden dzień to jedna data, nie „7.08–7.08"',
        DataDnia::zakresKrotki('2026-08-07', '2026-08-07') === '7.08');
    $check('zakres przez przełom roku nadal czytelny',
        DataDnia::zakresKrotki('2025-12-28', '2026-01-03') === '28.12–3.01');
    $check('data w nieznanym formacie NIE jest udawana',
        DataDnia::zakresKrotki('bzdura', 'bzdura') === 'bzdura');

    echo $failures === 0 ? "\nWSZYSTKO PRZESZŁO\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
