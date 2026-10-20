<?php

declare(strict_types=1);

/*
 * **Panel osoby w każdym stanie konta** — pomiar na szablonie (zlecenia Z58, Z59, Z61).
 *
 * ⚠ Dlaczego nie przez przeglądarkę: baza robocza ma dziś wyłącznie stany „brak konta",
 * „niezaproszone" i „zaproszony". Stanów „aktywne" i „zablokowane" nie da się na niej zmierzyć
 * bez przestawienia komuś konta — a to nie jest pomiar, tylko ingerencja w dane kadrowe.
 * Zamiast tego renderujemy TEN SAM plik szablonu z sześcioma zestawami danych.
 *
 * ⚠ To mierzy plik, który wchodzi na ekran: `strict_variables` i auto-escaping jak w aplikacji,
 * więc brakująca zmienna wywala render, a nie renderuje pustkę.
 *
 * ⚠ Dane fikcyjne (CLAUDE.md §18) i wspólne z testem (`dev/Panel/PrzykladyPanelu`).
 *
 * Wywołanie: `php dev/probka-panelu-osoby.php`, wynik w JSON. Nie jedzie do paczki klienta.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Dev\Panel\PrzykladyPanelu;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\WorkTime;

$urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
$twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
    'csrf_token'  => static fn (): string => 'probka',
    'can'         => static fn (string $p): bool => $p === 'admin.event_log',
    'nip_grupami' => static fn (string $nip): string => $nip,
    'czas_pracy'  => WorkTime::format(...),
    'czas_pelny'  => static fn (?string $t): string => '20.10.2026, 09:00',
    'czas_ze_strefa' => static fn (?string $t): string => '20.10.2026, 09:00',
    'odmiana_osob' => Odmiana::osoby(...),
    'odmiana_innych' => Odmiana::inne(...),
]);

$wynik = [];
foreach (PrzykladyPanelu::wszystkie() as $nazwa => $dane) {
    $html = $twig->render('fragments/person-panel.twig', $dane);

    preg_match_all('/class="op-karta__tytul">([^<]+)</', $html, $tytuly);
    preg_match_all('/class="op-akcja__tytul">([^<]+)</', $html, $akcje);
    $wynik[$nazwa] = [
        'karty' => $tytuly[1],
        'akcje' => $akcje[1],
        // ⚠ Każda akcja ma dokładnie JEDEN przycisk tej samej klasy — na tym stoi równa szerokość.
        'wierszy_akcji' => substr_count($html, '<li class="op-akcja">'),
        'przyciskow_akcji' => substr_count($html, 'class="btn btn--primary btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn op-akcja__btn--niebezpieczna"'),
        'pole_firm' => str_contains($html, 'class="pf"'),
        'rola_tylko_do_odczytu' => str_contains($html, 'op-rola'),
        'zrodel' => substr_count($html, 'class="op-zrodlo"'),
        'zdanie_o_pierwszenstwie' => str_contains($html, 'wygrywa szerszy zakres'),
        'odmowa_zdaniem' => str_contains($html, 'op-akcja__powod'),
        'odmowa_wylaczonym_przyciskiem' => (bool) preg_match('/op-akcja__btn" disabled[^>]*>Zablokuj/', $html),
        'widzi_tylko_siebie' => str_contains($html, 'Widzi tylko siebie.'),
        'widocznosc_bez_konta' => str_contains($html, 'Widoczność dotyczy konta'),
        // Z61: JEDEN zapis — pola firm należą do formularza panelu, karta nie ma swojego przycisku.
        'pol_firm_w_formularzu' => substr_count($html, 'name="spolki[]" value'),
        'pol_firm_z_atrybutem_form' => substr_count($html, 'form="osoba-dane"'),
        'znacznik_firm' => str_contains($html, 'name="firmy_w_formularzu"'),
        'przyciskow_zapisu' => substr_count($html, 'type="submit" form="osoba-dane"'),
        'przycisk_zapisz_dostep' => str_contains($html, 'Zapisz dostęp'),
        'stan_konta' => (preg_match('/chip[^"]*"><span class="chip__kropka"><\/span>([^<]+)</', $html, $m) === 1) ? trim($m[1]) : null,
        // Stare elementy, których tu już nie ma (Z58, Z61).
        'stary_przelacznik' => str_contains($html, 'access--btn') || str_contains($html, 'data-dostep='),
        'stara_sekcja_uprawnien' => str_contains($html, 'Uprawnienia'),
        'dlugi_akapit_o_kadrach' => str_contains($html, 'Te dane przychodzą z programu kadrowego'),
    ];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
