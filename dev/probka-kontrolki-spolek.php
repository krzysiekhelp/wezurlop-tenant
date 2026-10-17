<?php

declare(strict_types=1);

/*
 * Kontrakt **znacznika spółki w belce** (`partials/znacznik-spolki.twig`) — **pomiar
 * na szablonie**, nie na ekranie.
 *
 * ⚠ Do 10.10.2026 mierzyła `partials/combo-spolki.twig`, czyli kontrolkę w nagłówku ekranu.
 * Ten plik **został usunięty** razem z trzema kontrolkami, które zastąpił jeden znacznik
 * w górnej belce (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1). Kryteria zostają te same,
 * bo pytania są te same: czy kontrolka istnieje tylko wtedy, gdy jest z czego wybierać, czy
 * dwa rodzaje pozycji widać PRZED kliknięciem i czy przełączenie idzie `POST`-em z tokenem. Wejście dla bramki; nie jedzie do paczki klienta (katalog `dev/`).
 *
 * ⚠ **Dlaczego nie przez przeglądarkę.** Warunek §3 zlecenia 07.10.2026 mówi: pozycje o dwóch
 * znaczeniach (spółka z etatem kontra spółka tylko do podglądu) muszą być widoczne jako dwa
 * rodzaje PRZED kliknięciem. Przypadku MIESZANEGO nie da się zmierzyć na danych tego tenanta:
 * oba konta pracownicze mają etaty w obu istniejących spółkach, a konto administracyjne nie ma
 * żadnego etatu. Zamiast zmieniać komuś dane kadrowe na czas pomiaru (to nie jest pomiar, to
 * ingerencja) renderujemy ten jeden szablon z trzema zestawami danych.
 *
 * ⚠ To mierzy **ten sam plik**, który wchodzi na ekran, z `strict_variables` i auto-escapingiem
 * jak w aplikacji — nie kopię i nie makietę.
 *
 * Wywołanie: `php dev/probka-kontrolki-spolek.php`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;

$urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
$twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
    // Token CSRF w próbce jest stały i nieistotny: mierzymy układ kontrolki, nie ochronę.
    // Sama obecność pola `_csrf` w formularzu jest jednym z mierzonych faktów.
    'csrf_token' => static fn (): string => 'probka',
]);

/** Nagłówki sekcji w kolejności wystąpienia — mierzymy KOLEJNOŚĆ, nie tylko obecność. */
$naglowki = static function (string $html): array {
    preg_match_all('/<p class="spolka__grupa">(.*?)<\/p>/s', $html, $m);

    return array_map('trim', $m[1]);
};

$spolka = static fn (int $id, string $nazwa, ?int $etat): array => [
    'id' => $id, 'nazwa' => $nazwa, 'zatrudnienie_id' => $etat,
];

$przypadki = [
    'mieszany' => [$spolka(3, 'Piaskarz', 1), $spolka(4, 'Beta', null)],
    'same_etaty' => [$spolka(3, 'Piaskarz', 1), $spolka(4, 'Beta', 2)],
    'sam_podglad' => [$spolka(3, 'Piaskarz', null), $spolka(4, 'Beta', null)],
    'jedna_spolka' => [$spolka(3, 'Piaskarz', 1)],
    // Ekran całego tenanta: znacznik ma stać, ale **nie rozwijać się** i nie kłamać nazwą spółki.
    'tenant' => [$spolka(3, 'Piaskarz', 1), $spolka(4, 'Beta', null)],
    // Zapamiętana spółka wypadła z listy dozwolonych — znacznik musi to **powiedzieć**.
    'utracona' => [$spolka(3, 'Piaskarz', 1), $spolka(4, 'Beta', null)],
];

$wynik = [];
foreach ($przypadki as $nazwa => $spolki) {
    // ⚠ Szablon czyta **funkcję globalną** `znacznik_spolki()`, nie zmienne include'a — tak samo
    // jak w aplikacji, gdzie liczy ją `ViewContext` raz na żądanie. Próbka podstawia tę funkcję,
    // a nie przepisuje jej treści: inaczej mierzyłaby własne założenia o kształcie danych.
    $dane = [
        'zakres' => $nazwa === 'tenant' ? 'tenant' : 'spolka',
        'przelaczalny' => $nazwa !== 'tenant' && count($spolki) > 1,
        'nazwa' => $nazwa === 'tenant' ? 'Wszystkie firmy' : 'Piaskarz',
        'spolki' => $nazwa === 'tenant' ? [] : $spolki,
        'biezaca_id' => $nazwa === 'tenant' ? null : 3,
        'ekran' => 'kalendarz',
        'stan' => ['widok' => 'ja', 'miesiac' => '2026-08'],
        'fallback' => $nazwa === 'utracona',
    ];
    $twigPrzypadku = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
        'csrf_token' => static fn (): string => 'probka',
        'znacznik_spolki' => static fn (): array => $dane,
    ]);
    $html = $twigPrzypadku->render('partials/znacznik-spolki.twig', []);
    $wynik[$nazwa] = [
        // ⚠ Dopasowanie BEZ zamykającego „>": znacznik niesie `data-spolka` z identyfikatorem
        // spółki, więc wzorzec z nawiasem przestałby pasować przy pierwszym nowym atrybucie
        // — i kryterium czerwieniłoby się od kształtu znacznika, nie od jego zachowania.
        'kontrolka' => str_contains($html, '<details class="spolka spolka--wybor"'),
        'napis' => str_contains($html, 'spolka--staly'),
        'naglowki' => $naglowki($html),
        'formularzy' => substr_count($html, '<form method="post"'),
        'pozycji' => substr_count($html, 'class="spolka__pozycja'),
        'csrf_w_formularzu' => substr_count($html, 'name="_csrf"'),
        'post_do_zatrudnienia' => substr_count($html, 'action="/kontekst/1'),
        'post_do_spolki' => substr_count($html, 'action="/kontekst/spolka/'),
        'stopka' => str_contains($html, 'spolka__stopka'),
        'stan_w_formularzu' => substr_count($html, 'name="miesiac"'),
        'uwaga_o_utraconej' => str_contains($html, 'spolka__uwaga'),
        // ⚠ Zero odnośników i zero `hx-*`: przełączenie zmienia stan, więc wolno mu iść
        // wyłącznie `POST`-em (CLAUDE.md §3). Pozycja-odnośnik byłaby zmianą stanu GET-em.
        'odnosnikow' => substr_count($html, '<a '),
        'hx_na_pozycji' => (bool) preg_match('/class="spolka__pozycja[^"]*"[^>]*hx-/s', $html),
    ];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
