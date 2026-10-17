<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * **Ekrany, na które wraca przełączenie spółki z belki — jedna biała lista** (zlecenie
 * `2026-10-10-globalny-wybor-spolki.md` §1). Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ Powstało, bo ta sama informacja była potrzebna z dwóch stron: szablon belki musi wiedzieć,
 * **jaką nazwę wysłać**, a `ContextController` — **na jaki adres przekierować**. Dwie listy
 * rozjechałyby się przy pierwszym nowym ekranie: znacznik wysyłałby nazwę, której kontroler
 * nie zna, a człowiek po przełączeniu spółki lądowałby na pulpicie bez wyjaśnienia.
 *
 * ⚠ **Nazwa, nie adres.** Z żądania przychodzi wyłącznie nazwa z tej listy; adres powstaje
 * po stronie serwera (`adres()`), więc `powrot=https://cudza.strona` nie ma jak zadziałać —
 * to ochrona przed open redirectem (CLAUDE.md §11).
 *
 * ⚠ Nazwa dla **wariantu mobilnego** jest ta sama: `/m/kalendarz` to ten sam ekran co
 * `/kalendarz`, a o wariancie odpowiedzi decyduje `Response::redirectFor()`. Bez tego
 * przełączenie spółki na telefonie wyrzucałoby na wersję webową.
 */
final class EkranyBelki
{
    /** @var array<string, string> */
    public const EKRANY = [
        'pulpit' => '/',
        'kalendarz' => '/kalendarz',
        'zespol' => '/zespol',
        'wnioski' => '/wnioski',
        'grupy' => '/admin/grupy',
        'hierarchia' => '/admin/hierarchia',
        'konta' => '/admin/konta',
        'dane' => '/dane',
        'dziennik' => '/admin/dziennik',
    ];

    /**
     * Nazwa ekranu dla bieżącej ścieżki — albo `''`, gdy ten ekran nie jest na liście.
     *
     * ⚠ Dopasowanie po **prefiksie ścieżki**, żeby podstrony wracały na swój ekran nadrzędny
     * (`/admin/grupy/12` → `grupy`). Pusty wynik znaczy „wróć na pulpit" i jest poprawnym
     * stanem, nie błędem: lepiej pulpit niż przekierowanie zbudowane z czegoś, czego
     * nie rozpoznaliśmy.
     */
    public static function nazwa(string $path): string
    {
        $sciezka = preg_replace('#^/m(?=/|$)#', '', $path) ?? $path;
        $sciezka = $sciezka === '' ? '/' : $sciezka;
        $najdluzsza = '';
        $nazwa = '';
        foreach (self::EKRANY as $klucz => $adres) {
            if ($adres === '/') {
                continue;
            }
            if (($sciezka === $adres || str_starts_with($sciezka, $adres . '/')) && strlen($adres) > strlen($najdluzsza)) {
                $najdluzsza = $adres;
                $nazwa = $klucz;
            }
        }
        if ($nazwa === '' && $sciezka === '/') {
            return 'pulpit';
        }

        return $nazwa;
    }

    /** Adres ekranu o tej nazwie — albo pulpit, gdy nazwy nie znamy. */
    public static function adres(string $nazwa): string
    {
        return self::EKRANY[$nazwa] ?? '/';
    }
}
