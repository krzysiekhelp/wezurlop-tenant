<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Rodzaj limitu z kadr po polsku (zlecenie Z46, 19.10.2026).
 *
 * Czysty helper bez stanu i bez bazy (CLAUDE.md §1), strefa jawna: zamienia `wypoczynkowy`
 * na „Urlop wypoczynkowy”.
 *
 * ⚠ Do 19.10.2026 karta pracownika pokazywała **surowy kod z R2** w kolumnie „Rodzaj”
 * (`wypoczynkowy`, `opieka_art188`) — czyli nazwę z cudzej bazy danych w miejscu, w którym
 * kadrowa czyta nazwę świadczenia.
 *
 * ⚠ **Kod nieznany zostaje sobą**, tak samo jak w `KodyZdarzen` i `ZadaniaKolejki`: nowy rodzaj
 * limitu z R2 ma się pokazać taki, jaki przyszedł, a nie zniknąć ani udać innego. Ekran danych
 * kadrowych nie ma prawa ukryć pozycji dlatego, że ktoś zapomniał dopisać tłumaczenie.
 */
final class RodzajeLimitow
{
    /** @var array<string, string> */
    private const NAZWY = [
        'wypoczynkowy'  => 'Urlop wypoczynkowy',
        'opieka_art188' => 'Opieka nad dzieckiem (art. 188 k.p.)',
    ];

    public static function nazwa(string $kod): string
    {
        return self::NAZWY[$kod] ?? $kod;
    }

    /** Czy ten rodzaj ma własną nazwę — do sprawdzenia w teście, nie do decyzji w szablonie. */
    public static function znany(string $kod): bool
    {
        return isset(self::NAZWY[$kod]);
    }
}
