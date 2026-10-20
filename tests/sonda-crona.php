<?php

declare(strict_types=1);

// **Stan sondy crona — trzy przypadki i termin liczony z przebiegu** (zlecenie Z65, 20.10.2026).
//
// ⚠ Powód istnienia tego pliku: do Z65 wyrok „cron nie odpowiedział" padał po DWÓCH
// zadeklarowanych okresach, nie mniej niż 15 minut — czyli przy cronie co 5 minut człowiek
// czekał kwadrans na odpowiedź, którą można dać po jednym przebiegu. Teraz terminem jest
// **pierwszy spodziewany przebieg po postawieniu sondy plus 60 s zapasu**, a to jest arytmetyka
// na czasach i właśnie dlatego ma własny test bez bazy i bez przeglądarki.
//
// ⚠ Czas „teraz" podajemy z palca. Test, który pyta o `time()`, mierzy zegar, nie regułę.
//
// Uruchomienie: php tests/sonda-crona.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\CronStan;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis
            . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };
    $t = static fn (string $czas): int => (int) strtotime($czas . ' UTC');

    // ——— 1. Nikt nie pytał ———
    $s = CronStan::stanSondy('', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:01:00'));
    $check('bez sondy: stan „brak"', $s['stan'] === 'brak', $s['stan']);
    $check('bez sondy: nie ma końca odliczania', $s['koniec_utc'] === null);
    $check('bez sondy: odliczanie nie trwa', $s['trwa'] === false);

    // ——— 2. Sonda świeża: cron przebiegł 12:00, okres 5 min, sonda o 12:01 ———
    // Pierwszy przebieg PO sondzie to 12:05, czyli koniec odliczania 12:06:00.
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:01:30'));
    $check('świeża sonda: stan „czeka"', $s['stan'] === 'czeka', $s['stan']);
    $check('świeża sonda: koniec to przebieg 12:05 plus 60 s zapasu',
        $s['koniec_utc'] === '2026-10-20 12:06:00', (string) $s['koniec_utc']);
    $check('świeża sonda: odliczanie trwa', $s['trwa'] === true);
    $check('świeża sonda: nie ma jeszcze czasu oczekiwania', $s['czekalismy_s'] === null);

    // ⚠ Sonda postawiona TUŻ PRZED przebiegiem: termin ma być krótki, nie „dwa okresy".
    $s = CronStan::stanSondy('2026-10-20 12:04:50', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:04:55'));
    $check('sonda tuż przed przebiegiem: koniec 12:06:00, nie 12:10',
        $s['koniec_utc'] === '2026-10-20 12:06:00', (string) $s['koniec_utc']);

    // ⚠ Sonda postawiona PO spodziewanym przebiegu: licznik przeskakuje na następny okres.
    $s = CronStan::stanSondy('2026-10-20 12:07:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:07:10'));
    $check('sonda po spodziewanym przebiegu: koniec to 12:10 plus zapas',
        $s['koniec_utc'] === '2026-10-20 12:11:00', (string) $s['koniec_utc']);

    // ——— 3. Sonda potwierdzona ———
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '2026-10-20 12:05:12', '2026-10-20 12:05:12', 5,
        $t('2026-10-20 12:06:00'));
    $check('potwierdzona: stan „potwierdzona"', $s['stan'] === 'potwierdzona', $s['stan']);
    $check('potwierdzona: czekaliśmy 252 s', $s['czekalismy_s'] === 252, (string) $s['czekalismy_s']);
    $check('potwierdzona: odliczanie nie trwa', $s['trwa'] === false);
    $check('potwierdzona: bez końca odliczania', $s['koniec_utc'] === null);

    // ——— 4. Po terminie ———
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:06:01'));
    $check('po terminie: stan „bez_odpowiedzi"', $s['stan'] === 'bez_odpowiedzi', $s['stan']);
    $check('po terminie: odliczanie nie trwa', $s['trwa'] === false);
    // ⚠ Sekunda PRZED terminem to jeszcze „czeka" — granica jest granicą, nie przybliżeniem.
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:06:00'));
    $check('sekundę przed terminem: jeszcze „czeka"', $s['stan'] === 'czeka', $s['stan']);

    // ——— 5. Cron nigdy nie przebiegł: termin liczony od sondy ———
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', null, 5, $t('2026-10-20 12:02:00'));
    $check('cron nigdy nie przebiegł: koniec to sonda plus okres plus zapas',
        $s['koniec_utc'] === '2026-10-20 12:07:00', (string) $s['koniec_utc']);
    // Nieznana częstotliwość: przyjmujemy pięć minut, tak samo jak polecenie crontaba na ekranie.
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', null, 0, $t('2026-10-20 12:02:00'));
    $check('nieznana częstotliwość: liczona jak co 5 min',
        $s['koniec_utc'] === '2026-10-20 12:07:00', (string) $s['koniec_utc']);

    // ——— 6. Zapas jest JEDNĄ stałą, nie liczbą w dwóch miejscach ———
    $check('zapas to 60 s w jednej stałej', CronStan::ZAPAS_SONDY_S === 60,
        (string) CronStan::ZAPAS_SONDY_S);
    $s = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:01:30'));
    $check('koniec odliczania zgadza się z zapasem ze stałej',
        $t((string) $s['koniec_utc']) === $t('2026-10-20 12:05:00') + CronStan::ZAPAS_SONDY_S);

    // ——— 7. Fragment wiersza renderuje się ze `strict_variables` w każdym stanie ———
    // ⚠ Render, nie podciąg: `strict_variables` wywala brakującą zmienną, a to jest dokładnie
    // ten rodzaj usterki, który bez tego sprawdzenia wychodzi na ekranie u człowieka. Mierzymy
    // TEN plik szablonu, który wchodzi na ekran — z auto-escapingiem jak w aplikacji.
    $urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
    $twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
        'csrf_token'    => static fn (): string => 'probka',
        'czas_pelny'    => static fn (?string $u): string => Strefa::czasSekundyZeStrefa($u, Strefa::DOMYSLNA),
        'czas_prognoza' => static fn (?string $u): string => Strefa::prognoza($u, Strefa::DOMYSLNA),
        'czas_godzina'  => static fn (?string $u): string => Strefa::godzina($u, Strefa::DOMYSLNA),
    ]);
    /** Stan crona poza sondą — tylko to, o co pyta fragment. */
    $cron = static fn (array $sonda): array => [
        'nigdy' => false, 'wiek' => 1, 'awaria' => false, 'minuty' => 5,
        'ostatni_utc' => '2026-10-20 12:00:00', 'najblizszy_utc' => '2026-10-20 12:05:00',
        'profil' => 'apache', 'po_http' => false, 'sonda' => $sonda,
    ];
    $render = static function (array $sonda) use ($twig, $cron): string {
        return $twig->render('fragments/cron-sonda.twig', ['cron' => $cron($sonda)]);
    };

    $czeka = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:01:30'));
    $html = $render($czeka);
    $check('CZEKA: wiersz mówi „Sprawdzam crona…"', str_contains($html, 'Sprawdzam crona'));
    $check('CZEKA: oba znaczniki czasu w atrybutach (skrypt ich nie liczy)',
        str_contains($html, 'data-sonda-start="2026-10-20 12:01:00Z"')
        && str_contains($html, 'data-sonda-koniec="2026-10-20 12:06:00Z"'));
    $check('CZEKA: pasek postępu i licznik są na miejscu',
        str_contains($html, 'data-sonda-pasek') && str_contains($html, 'data-sonda-licznik'));
    $check('CZEKA: zapas wypisany z serwera, nie z szablonu', str_contains($html, '(+60 s zapasu)'));
    // ⚠ Odpytywanie wisi na POJEMNIKU i tylko w stanie nieostatecznym — tak gaśnie pętla.
    $check('CZEKA: pojemnik odpytuje co 5 s',
        str_contains($html, 'hx-trigger="every 5s"') && str_contains($html, '/ustawienia/cron/sonda'));
    $check('CZEKA: ścieżka bez JavaScriptu ma odnośnik „Odśwież wynik"',
        str_contains($html, 'Odśwież wynik'));

    $dziala = CronStan::stanSondy('2026-10-20 12:01:00', '2026-10-20 12:05:12', '2026-10-20 12:05:12', 5,
        $t('2026-10-20 12:06:00'));
    $html = $render($dziala);
    $check('DZIAŁA: wiersz mówi „Cron działa"', str_contains($html, 'Cron działa'));
    $check('DZIAŁA: podpis z godziną odhaczenia i czasem oczekiwania',
        str_contains($html, 'znacznik odhaczony o') && str_contains($html, 'czekaliśmy 4:12'), '4:12');
    $check('DZIAŁA: przycisk „Sprawdź ponownie"', str_contains($html, 'Sprawdź ponownie'));
    $check('DZIAŁA: odpytywanie NIE trwa (pętla gaśnie sama)', !str_contains($html, 'hx-trigger'));

    $milczy = CronStan::stanSondy('2026-10-20 12:01:00', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:07:00'));
    $html = $render($milczy);
    $check('NIE ODPOWIEDZIAŁ: wiersz mówi „Cron nie odpowiedział"', str_contains($html, 'Cron nie odpowiedział'));
    $check('NIE ODPOWIEDZIAŁ: zdanie nazywa skutek i kieruje do instrukcji',
        str_contains($html, 'poczta, przypomnienia i sprzątanie stoją')
        && str_contains($html, 'Co ustawić u hostingodawcy'));
    $check('NIE ODPOWIEDZIAŁ: przycisk „Sprawdź jeszcze raz"', str_contains($html, 'Sprawdź jeszcze raz'));
    $check('NIE ODPOWIEDZIAŁ: odpytywanie NIE trwa', !str_contains($html, 'hx-trigger'));
    $check('NIE ODPOWIEDZIAŁ: barwa z tokenów ostrzeżenia, nie z wartości',
        str_contains($html, 'sonda--milczy'));

    $html = $render(CronStan::stanSondy('', '', '2026-10-20 12:00:00', 5, $t('2026-10-20 12:01:00')));
    $check('BEZ SONDY: wiersz mówi, czym sonda jest, i ma przycisk „Sprawdź cron"',
        str_contains($html, 'Sonda sprawdza co innego') && str_contains($html, 'Sprawdź cron'));
    $check('BEZ SONDY: bez odpytywania i bez licznika',
        !str_contains($html, 'hx-trigger') && !str_contains($html, 'data-sonda-licznik'));

    // ⚠ Kafle „Ostatni przebieg" i „Najbliższy spodziewany" są W ŚRODKU fragmentu — odświeżają
    // się razem z wynikiem (§2 zlecenia). Bez tego po odhaczeniu sondy karta pokazywałaby
    // stary znacznik przebiegu obok nowego wyniku.
    $check('kafle przebiegu są częścią odświeżanego fragmentu',
        str_contains($render($dziala), 'Ostatni przebieg')
        && str_contains($render($dziala), 'Najbliższy spodziewany'));

    echo $failures === 0 ? "\nWszystko OK\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
