<?php

declare(strict_types=1);

// **Widoczność kalendarza — reguły dostępu** (zlecenie Z53, przypadki a–s z odbioru).
//
// ⚠ Sedno tego pliku: o tym, ile widać z cudzej nieobecności, rozstrzygają DWIE czyste funkcje —
// `ZakresWidocznosci::dlaOsoby()` (jaki zakres dla tej pary) i `KalendarzPracownika::pasek()`
// (co z tego zakresu wychodzi na ekran). Testujemy je, a nie render HTML-a, z jednego powodu:
// render dowiódłby JEDNEGO szablonu, a te funkcje obsługują wszystkie widoki naraz — siatkę
// zespołu, miesiąc osoby i wariant `/m/`. Gdyby decyzja stała w szablonie, pierwszy nowy widok
// pokazałby nazwy rodzajów bez pytania („ukrycie CSS-em się nie liczy", §2 zlecenia).
//
// ⚠ Czego TU nie ma i dlaczego: przypadki zależne od stanu bazy (grupa usunięta po obu stronach,
// konto zablokowane, migracja starych poziomów, odmowy zapisu, zwolnienie wskazanej osoby)
// sprawdzamy pomiarem na żywo i opisujemy w raporcie liczbami. Wstawienie tu atrap repozytoriów
// znaczyłoby interfejsy „na zapas" (CLAUDE.md §1) i test, który dowodzi zgodności z atrapą.
//
// Uruchomienie: php tests/widocznosc.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\WidocznoscService as W;
use Wezurlop\Service\ZakresWidocznosci as Z;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name
            . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // Reguła w takiej postaci, w jakiej czyta ją `dlaOsoby()` — tylko te cztery pola.
    $regula = static fn (string $kto, string $czyje, string $zakres, array $grupy = []): array => [
        'subject_kind' => $kto,
        'target_kind' => $czyje,
        'scope' => $zakres,
        'grupy' => $grupy,
    ];
    $domyslna = static fn (string $zakres): array => $regula(Z::KTO_PRZELOZENI, Z::CZYJE_PODWLADNI, $zakres);

    // ——— maksimum ze źródeł (przypadek e) ———————————————————————————————————————
    // ⚠ Przy kilku regułach dotyczących tej samej osoby wygrywa SZERSZA. Odwrotna reguła
    // („wygrywa węższa") znaczyłaby, że dołożenie dostępu komuś odbiera dostęp komuś innemu.
    $check('e: brak < bez szczegółów', Z::wyzszy(Z::BRAK, Z::BEZ_SZCZEGOLOW) === Z::BEZ_SZCZEGOLOW);
    $check('e: bez szczegółów < ze szczegółami',
        Z::wyzszy(Z::BEZ_SZCZEGOLOW, Z::ZE_SZCZEGOLAMI) === Z::ZE_SZCZEGOLAMI);
    $check('e: kolejność argumentów nie zmienia wyniku',
        Z::wyzszy(Z::ZE_SZCZEGOLAMI, Z::BEZ_SZCZEGOLOW) === Z::ZE_SZCZEGOLAMI);
    $check('e: wartość spoza słownika nie wygrywa z niczym',
        Z::wyzszy(Z::BEZ_SZCZEGOLOW, 'cokolwiek') === Z::BEZ_SZCZEGOLOW);

    // ——— a, k: przełożony i jego podwładny ————————————————————————————————————
    $check('a: przełożony widzi podwładnego w zakresie reguły domyślnej (bez szczegółów)',
        Z::dlaOsoby([$domyslna(Z::BEZ_SZCZEGOLOW)], false, true, true, []) === Z::BEZ_SZCZEGOLOW);
    $check('k: po zmianie reguły domyślnej na „ze szczegółami" ten sam podwładny jest pełny',
        Z::dlaOsoby([$domyslna(Z::ZE_SZCZEGOLAMI)], false, true, true, []) === Z::ZE_SZCZEGOLAMI);
    $check('k: powrót na „bez szczegółów" zabiera nazwę rodzaju tą samą drogą',
        Z::dlaOsoby([$domyslna(Z::BEZ_SZCZEGOLOW)], false, true, true, []) === Z::BEZ_SZCZEGOLOW);
    // ⚠ Reguła domyślna obejmuje widza tylko wtedy, gdy ktoś pod nim DZIŚ stoi — inaczej
    // „każdy przełożony" znaczyłoby „każdy".
    $check('a: ktoś, kto nie jest dziś przełożonym, nie korzysta z reguły domyślnej',
        Z::dlaOsoby([$domyslna(Z::ZE_SZCZEGOLAMI)], false, true, false, []) === Z::BRAK);
    $check('a: osoba spoza gałęzi nie jest objęta regułą domyślną',
        Z::dlaOsoby([$domyslna(Z::ZE_SZCZEGOLAMI)], false, false, true, []) === Z::BRAK);

    // ——— własne dane ———————————————————————————————————————————————————————————
    // ⚠ Siebie widzi się w pełni NIEZALEŻNIE od reguł — także wtedy, gdy nie ma żadnej.
    $check('własne dane zawsze ze szczegółami', Z::dlaOsoby([], true, false, false, []) === Z::ZE_SZCZEGOLAMI);

    // ——— f: inna firma ————————————————————————————————————————————————————————
    // ⚠ Reguły innej firmy nie trafiają do tej listy (filtruje je zapytanie), więc brak reguł
    // musi znaczyć BRAK, a nie „coś domyślnego".
    $check('f: bez reguł nie widać nic', Z::dlaOsoby([], false, false, false, []) === Z::BRAK);
    $check('f: bez reguł nie pomaga nawet gałąź',
        Z::dlaOsoby([], false, true, true, []) === Z::BRAK);

    // ——— l: konto bez zatrudnienia w tej firmie jako „kto widzi" ————————————————
    // Taki widz nie ma gałęzi i nie jest przełożonym, więc działa u niego wyłącznie reguła
    // o celu niezależnym od hierarchii.
    $check('l: konto bez etatu korzysta z reguły „cała firma"',
        Z::dlaOsoby([$regula(Z::KTO_KONTO, Z::CZYJE_FIRMA, Z::BEZ_SZCZEGOLOW)], false, false, false, [])
            === Z::BEZ_SZCZEGOLOW);
    $check('l: konto bez etatu nic nie zyskuje z reguły „podwładni"',
        Z::dlaOsoby([$regula(Z::KTO_KONTO, Z::CZYJE_PODWLADNI, Z::ZE_SZCZEGOLAMI)], false, false, false, [])
            === Z::BRAK);

    // ——— c: reguła grupy czyta skład W CHWILI ODCZYTU ————————————————————————
    $grupowa = $regula(Z::KTO_KONTO, Z::CZYJE_GRUPY, Z::BEZ_SZCZEGOLOW, [7, 9]);
    $check('c: osoba spoza wskazanych grup nie jest objęta',
        Z::dlaOsoby([$grupowa], false, false, false, [3]) === Z::BRAK);
    $check('c: osoba dopisana do grupy PO nadaniu reguły jest objęta bez dotykania reguły',
        Z::dlaOsoby([$grupowa], false, false, false, [3, 9]) === Z::BEZ_SZCZEGOLOW);

    // ——— q: reguła wskazanych osób ————————————————————————————————————————————
    $osobowa = $regula(Z::KTO_KONTO, Z::CZYJE_OSOBY, Z::ZE_SZCZEGOLAMI, [41, 42]);
    $check('q: wskazana osoba jest widoczna ze szczegółami',
        Z::dlaOsoby([$osobowa], false, false, false, [], 42) === Z::ZE_SZCZEGOLAMI);
    $check('q: osoba spoza listy nie jest widoczna wcale',
        Z::dlaOsoby([$osobowa], false, false, false, [], 43) === Z::BRAK);
    // ⚠ Porównanie idzie po ZATRUDNIENIU, nie po osobie: po `person_id` reguła z jednej firmy
    // dotykałaby tej samej osoby w drugiej, czyli przeciekłaby przez granicę firmy.
    $check('q: brak numeru zatrudnienia nie trafia w regułę osób',
        Z::dlaOsoby([$osobowa], false, false, false, [], 0) === Z::BRAK);
    $check('q: reguła osób nie obejmuje gałęzi widza sama z siebie',
        Z::dlaOsoby([$osobowa], false, true, true, [], 43) === Z::BRAK);

    // ——— m: reguła domyślna „bez" + reguła konta „cała firma / ze" ————————————
    $mieszane = [$domyslna(Z::BEZ_SZCZEGOLOW), $regula(Z::KTO_KONTO, Z::CZYJE_FIRMA, Z::ZE_SZCZEGOLAMI)];
    $check('m: podwładny tego przełożonego jest ze szczegółami (wygrywa szersza reguła)',
        Z::dlaOsoby($mieszane, false, true, true, []) === Z::ZE_SZCZEGOLAMI);
    $check('m: pracownik spoza gałęzi też jest ze szczegółami, bo reguła obejmuje całą firmę',
        Z::dlaOsoby($mieszane, false, false, true, []) === Z::ZE_SZCZEGOLAMI);

    // ——— n: reguła domyślna „ze" + węższa reguła grupy „bez" ——————————————————
    $check('n: węższa reguła nie obniża zakresu danego regułą domyślną',
        Z::dlaOsoby(
            [$domyslna(Z::ZE_SZCZEGOLAMI), $regula(Z::KTO_GRUPA, Z::CZYJE_GRUPY, Z::BEZ_SZCZEGOLOW, [7])],
            false, true, true, [7]
        ) === Z::ZE_SZCZEGOLAMI);

    // ——— o: reguła „Przełożony X › Podwładni › ze" przy domyślnej „bez" ——————
    // ⚠ Dla innego przełożonego ta reguła w ogóle nie wchodzi do listy (nie jest jej podmiotem),
    // więc jego lista to sama reguła domyślna — i to jest cała różnica między X a resztą.
    $dlaX = [$domyslna(Z::BEZ_SZCZEGOLOW), $regula(Z::KTO_PRZELOZONY, Z::CZYJE_PODWLADNI, Z::ZE_SZCZEGOLAMI)];
    $check('o: X widzi swoich podwładnych ze szczegółami',
        Z::dlaOsoby($dlaX, false, true, true, []) === Z::ZE_SZCZEGOLAMI);
    $check('o: inny przełożony zostaje przy „bez szczegółów"',
        Z::dlaOsoby([$domyslna(Z::BEZ_SZCZEGOLOW)], false, true, true, []) === Z::BEZ_SZCZEGOLOW);

    // ——— p: „Podwładni" dla osoby, która dziś nie ma podwładnych ———————————————
    $podwladni = [$regula(Z::KTO_KONTO, Z::CZYJE_PODWLADNI, Z::ZE_SZCZEGOLAMI)];
    $check('p: bez podwładnych reguła nie daje nic poza własnymi danymi',
        Z::dlaOsoby($podwladni, false, false, false, []) === Z::BRAK);
    $check('p: po dopisaniu podwładnego w hierarchii widać go bez zmiany reguły',
        Z::dlaOsoby($podwladni, false, true, false, []) === Z::ZE_SZCZEGOLAMI);

    // ——— t, u, v: REGUŁA „ADMINISTRATORZY" ————————————————————————————————————
    // ⚠ Druga reguła stała firmy (zlecenie Z55). Jej domyślny zakres jest ODWROTNY niż
    // u przełożonych — `ze_szczegolami` — i to jest decyzja Krzysztofa z 20.10.2026:
    // administrator i tak może sobie każdy dostęp nadać tym samym ekranem, więc reguła tylko
    // nazywa stan osiągalny. Nazwany daje się ograniczyć, zaszyty w kodzie nie.
    $adminowa = static fn (string $zakres): array => $regula(Z::KTO_ADMINI, Z::CZYJE_FIRMA, $zakres);
    $check('t: administrator bez żadnej reguły osobistej widzi pełną nazwę u każdego w firmie',
        Z::dlaOsoby([$adminowa(Z::DOMYSLNY_ADMINISTRATOROW)], false, false, false, [], 0, true)
            === Z::ZE_SZCZEGOLAMI);
    $check('t: domyślny zakres administratorów to „ze szczegółami"',
        Z::DOMYSLNY_ADMINISTRATOROW === Z::ZE_SZCZEGOLAMI);
    $check('u: po zmianie reguły na „bez szczegółów" administrator widzi tylko fakt nieobecności',
        Z::dlaOsoby([$adminowa(Z::BEZ_SZCZEGOLOW)], false, false, false, [], 0, true)
            === Z::BEZ_SZCZEGOLOW);
    // ⚠ Ograniczenie reguły administratorów NIE zabiera dostępu nadanego osobno — bo wygrywa
    // zbiór szerszy. Inaczej ograniczenie jednej reguły odbierałoby dostęp nadany inną decyzją.
    $check('u: osobna reguła „ze szczegółami" wygrywa z ograniczoną regułą administratorów',
        Z::dlaOsoby(
            [$adminowa(Z::BEZ_SZCZEGOLOW), $regula(Z::KTO_KONTO, Z::CZYJE_FIRMA, Z::ZE_SZCZEGOLAMI)],
            false, false, false, [], 0, true
        ) === Z::ZE_SZCZEGOLAMI);
    $check('v: konto BEZ uprawnienia administratora nie łapie się na tę regułę',
        Z::dlaOsoby([$adminowa(Z::ZE_SZCZEGOLAMI)], false, false, false, [], 0, false) === Z::BRAK);
    // ⚠ Ten sam warunek co u przełożonych: reguła stała obejmuje widza po CESZE widza, a nie
    // po tym, że stoi w tabeli — więc odebranie uprawnienia odbiera wgląd przy następnym odczycie.
    $check('v: odebranie uprawnienia odbiera wgląd bez dotykania reguły',
        Z::dlaOsoby([$adminowa(Z::ZE_SZCZEGOLAMI)], false, true, true, [], 0, false) === Z::BRAK);
    $check('v: administrator i przełożony naraz dostają maksimum z obu reguł',
        Z::dlaOsoby(
            [$adminowa(Z::ZE_SZCZEGOLAMI), $domyslna(Z::BEZ_SZCZEGOLOW)],
            false, true, true, [], 0, true
        ) === Z::ZE_SZCZEGOLAMI);
    $check('podpis reguły administratorów też jest bez zakresu',
        Z::podpis(Z::KTO_ADMINI, 0, Z::CZYJE_FIRMA, [], Z::ZE_SZCZEGOLAMI)
        === Z::podpis(Z::KTO_ADMINI, 0, Z::CZYJE_FIRMA, [], Z::BEZ_SZCZEGOLOW));
    $check('podpisy obu reguł stałych są RÓŻNE (to dwie reguły, nie jedna)',
        Z::PODPIS_ADMINOW !== Z::PODPIS_DOMYSLNEJ);

    // ——— b, d: CO WYCHODZI NA EKRAN ————————————————————————————————————————————
    // ⚠ To jest ten test, bez którego cały mechanizm nie znaczy nic: zakres może być policzony
    // dobrze, a rodzaj nieobecności i tak wyjść z serwera w klasie, w `title` albo w polu obok.
    $wpis = ['rodzaj' => 'zwolnienie lekarskie (L4)', 'zrodlo' => 'zwolnienie', 'id' => 5];
    $bez = KalendarzPracownika::pasek($wpis, Z::BEZ_SZCZEGOLOW);
    $check('b: bez szczegółów pasek mówi tylko „nieobecny"',
        $bez['nazwa'] === KalendarzPracownika::NIEOBECNY, (string) $bez['nazwa']);
    $check('b: bez szczegółów źródło jest jedno dla wszystkich rodzajów',
        $bez['zrodlo'] === KalendarzPracownika::ZRODLO_BEZ, (string) $bez['zrodlo']);
    // ⚠ Sprawdzamy CAŁĄ zawartość paska, a nie dwa pola: rodzaj przemycony w trzecim polu
    // wyszedłby na ekran tak samo, a to jest dana o zdrowiu.
    $przeciek = static function (array $pasek) use ($wpis): bool {
        foreach ($pasek as $wartosc) {
            if (is_string($wartosc)
                && (str_contains($wartosc, $wpis['rodzaj']) || $wartosc === $wpis['zrodlo'])) {
                return true;
            }
        }

        return false;
    };
    $check('b: w całym pasku nie ma ani nazwy rodzaju, ani kodu źródła', !$przeciek($bez));
    $check('b: dołożone pola (zakres dat, numer dnia) niczego nie przemycają',
        !$przeciek(KalendarzPracownika::pasek($wpis, Z::BEZ_SZCZEGOLOW, [
            'zakres' => '12–16 października', 'numer_dnia' => 12, 'wiele' => false,
        ])));
    // ⚠ „brak" to nie jest słabsza wersja „bez szczegółów": taki wiersz nie ma prawa się
    // narysować w ogóle, ale gdyby się narysował, rodzaju i tak nie pokaże.
    $check('b: zakres „brak" też nie pokazuje rodzaju',
        !$przeciek(KalendarzPracownika::pasek($wpis, Z::BRAK)));
    $pelny = KalendarzPracownika::pasek($wpis, Z::ZE_SZCZEGOLAMI);
    $check('d: ze szczegółami pasek niesie pełną nazwę ze słownika kadr',
        $pelny['nazwa'] === $wpis['rodzaj'], (string) $pelny['nazwa']);
    $check('d: ze szczegółami źródło jest prawdziwe (kolor rodzaju)',
        $pelny['zrodlo'] === $wpis['zrodlo'], (string) $pelny['zrodlo']);

    // ——— podpis reguły: jedna reguła, jeden podpis ————————————————————————————
    // ⚠ Podpis pilnuje, żeby tej samej reguły nie dało się nadać dwa razy. Kolejność zaznaczania
    // osób w oknie jest przypadkowa, więc nie ma prawa zmieniać podpisu.
    $check('podpis osób nie zależy od kolejności zaznaczania',
        Z::podpis(Z::KTO_KONTO, 4, Z::CZYJE_OSOBY, [42, 41], Z::BEZ_SZCZEGOLOW)
        === Z::podpis(Z::KTO_KONTO, 4, Z::CZYJE_OSOBY, [41, 42], Z::BEZ_SZCZEGOLOW));
    $check('podpis osób różni się od podpisu grup o tych samych numerach',
        Z::podpis(Z::KTO_KONTO, 4, Z::CZYJE_OSOBY, [41], Z::BEZ_SZCZEGOLOW)
        !== Z::podpis(Z::KTO_KONTO, 4, Z::CZYJE_GRUPY, [41], Z::BEZ_SZCZEGOLOW));
    $check('reguła domyślna ma podpis bez zakresu (jest jedna, zmienia się jej zakres)',
        Z::podpis(Z::KTO_PRZELOZENI, 0, Z::CZYJE_PODWLADNI, [], Z::ZE_SZCZEGOLAMI)
        === Z::podpis(Z::KTO_PRZELOZENI, 0, Z::CZYJE_PODWLADNI, [], Z::BEZ_SZCZEGOLOW));

    // ——— Z80 A1, A6: STANY OKNA, KTÓRYCH NIE WOLNO ZAPISAĆ ————————————————————
    // ⚠ Od Z80 sekcja bez wskazanej pozycji jest w oknie stanem DOZWOLONYM („Osoby, nic nie
    // wybrano" — bez tego „Całej firmy" nie da się odznaczyć, czyli nie da się przejść
    // do pojedynczych osób). Skoro tak, musi istnieć miejsce, które nie pozwala takiego stanu
    // ZAPISAć — i to jest ta funkcja. Sprawdzamy ją tu, bo jest czysta: bez bazy, bez HTTP.
    $stanOkna = static fn (array $zmiany = []): array => array_merge([
        'stala' => false,
        'wybor_kto' => true,
        'wybor_czyje' => true,
        // Od Z81 krok 3 też ma wybór — i też wolno go nie mieć (patrz blok niżej).
        'wybor_zakres' => true,
        'cala_firma_zadana' => false,
        'czyje' => Z::CZYJE_FIRMA,
    ], $zmiany);
    $check('Z80: stan z wskazanym podmiotem i celem wolno zapisać',
        W::powodStanuOkna($stanOkna()) === null,
        (string) W::powodStanuOkna($stanOkna()));
    $check('Z80: bez wskazanego podmiotu (krok 1 pusty) → odmowa „podmiot"',
        W::powodStanuOkna($stanOkna(['wybor_kto' => false])) === W::POWOD_PODMIOT);
    $check('Z80: bez wskazanego celu (krok 2 pusty) → odmowa „cel"',
        W::powodStanuOkna($stanOkna(['wybor_czyje' => false])) === W::POWOD_CEL);
    $check('Z80: „cała firma" razem ze wskazanymi grupami → odmowa „cel"',
        W::powodStanuOkna($stanOkna(['cala_firma_zadana' => true, 'czyje' => Z::CZYJE_GRUPY]))
            === W::POWOD_CEL);
    // ⚠ Druga strona tego samego twierdzenia: sam znacznik całej firmy przy celu „cała firma"
    // jest stanem NORMALNYM, nie dwoma rodzajami naraz — inaczej odmowa zabrałaby zapis
    // najczęściej nadawanej regule.
    $check('Z80: „cała firma" przy celu „cała firma" wolno zapisać',
        W::powodStanuOkna($stanOkna(['cala_firma_zadana' => true])) === null);
    // ⚠ Reguła stała przechodzi zawsze: podmiot i cel ma z definicji, a zapisać w niej można
    // wyłącznie zakres. Bez tego wyjątku zmiana zakresu reguły „Każdy przełożony" (gdzie żadne
    // pole wyboru nie jedzie w żądaniu) byłaby odrzucana.
    $check('Z80: reguła stała przechodzi bez żadnego wskazania',
        W::powodStanuOkna($stanOkna(['stala' => true, 'wybor_kto' => false, 'wybor_czyje' => false]))
            === null);

    // ——— Z81: KROK 3 STARTUJE PUSTY, WIĘC JEGO BRAK TEŻ JEST ODMOWĄ ————————————
    // ⚠ Do 24.10.2026 ten stan nie istniał: serwer podstawiał „bez szczegółów" za każdą wartość
    // spoza białej listy, w tym za brak wartości. Nowa reguła miała więc zakres, zanim ktokolwiek
    // go wskazał — i to NAJNIŻSZY z dwóch, czyli domyślność rozstrzygała o wglądzie w cudze
    // nieobecności bez niczyjej decyzji.
    $check('Z81: bez wskazanego zakresu (krok 3 pusty) → odmowa „zakres"',
        W::powodStanuOkna($stanOkna(['wybor_zakres' => false])) === W::POWOD_ZAKRES,
        (string) W::powodStanuOkna($stanOkna(['wybor_zakres' => false])));
    // ⚠ Powód MUSI być inny niż `POWOD_CEL`, bo zdanie na ekranie odsyła do konkretnego kroku:
    // „wybierz, czyje nieobecności" przy wybranym celu kazałoby szukać w kroku 2 czegoś,
    // co tam jest.
    $check('Z81: brak zakresu to NIE jest odmowa celu',
        W::POWOD_ZAKRES !== W::POWOD_CEL);
    // ⚠ Reguła stała ma dokładnie jeden krok — zakres — więc tu wyjątku dla niej NIE MA.
    // To jedyna odmowa, która jej dotyczy.
    $check('Z81: reguła stała bez zakresu też jest odrzucana',
        W::powodStanuOkna($stanOkna(['stala' => true, 'wybor_kto' => false,
            'wybor_czyje' => false, 'wybor_zakres' => false])) === W::POWOD_ZAKRES);
    // ⚠ Kolejność odmów idzie po NUMERACH KROKÓW: przy dwóch brakach ekran ma odesłać do
    // pierwszego z nich, a nie do ostatniego sprawdzonego warunku.
    $check('Z81: przy pustym kroku 1 i pustym kroku 3 odmowa mówi o podmiocie',
        W::powodStanuOkna($stanOkna(['wybor_kto' => false, 'wybor_zakres' => false]))
            === W::POWOD_PODMIOT);
    $check('Z81: przy pustym kroku 2 i pustym kroku 3 odmowa mówi o celu',
        W::powodStanuOkna($stanOkna(['wybor_czyje' => false, 'wybor_zakres' => false]))
            === W::POWOD_CEL);
    $check('Z81: pełny stan z wszystkimi trzema wyborami wolno zapisać',
        W::powodStanuOkna($stanOkna()) === null);

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
