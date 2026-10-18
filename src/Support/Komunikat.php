<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Komunikat o wyniku operacji wysyłany przy odpowiedzi na żądanie htmx (zlecenie Z47).
 *
 * ⚠ **Po co nagłówek, skoro jest `?ok=…`**: po zapisie przez htmx nie ma przeładowania strony,
 * więc nie ma gdzie wyrenderować zdania o powodzeniu — a wymienienie fragmentu nie mówi samo
 * z siebie, że zapis się udał. Nagłówek `HX-Trigger` wywołuje w przeglądarce zdarzenie
 * `wezurlop:komunikat`, czyli dokładnie ten sam kanał, z którego korzystają moduły JS —
 * `modules/flash.js` zostaje jedynym miejscem, które wie, jak komunikat wygląda.
 *
 * ⚠ Bez JavaScriptu nagłówek nie robi nic i nic nie psuje: ścieżka bez skryptów nie używa htmx,
 * tylko zwykłego formularza, po którym serwer przekierowuje z `?ok=…` i renderuje zdanie
 * w treści ekranu.
 */
final class Komunikat
{
    public const SUKCES = 'sukces';
    public const BLAD = 'blad';
    public const UWAGA = 'uwaga';
    public const INFO = 'info';

    /**
     * Wartość nagłówka `HX-Trigger`.
     *
     * ⚠ **Polskie znaki idą jako `\uXXXX`, czyli czystym ASCII** — i to nie jest ostrożność
     * na zapas: nagłówki HTTP nie mają zadeklarowanego kodowania, więc „ą" wysłane surowym
     * UTF-8 przeglądarka potrafi przeczytać jako dwa znaki z Latin-1. Dlatego **nie ma tu**
     * `JSON_UNESCAPED_UNICODE`; `JSON.parse` po stronie przeglądarki odtwarza litery z escape'ów.
     */
    public static function naglowek(string $tekst, string $rodzaj = self::SUKCES): string
    {
        return json_encode(
            ['wezurlop:komunikat' => ['tekst' => $tekst, 'rodzaj' => $rodzaj]],
            JSON_THROW_ON_ERROR,
        );
    }
}
