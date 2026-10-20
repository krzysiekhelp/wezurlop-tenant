<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

/**
 * **Barwy wiadomości — jedno miejsce w kodzie poczty** (zlecenie Z60).
 *
 * ⚠ **Dlaczego nie tokeny CSS.** W skrzynce odbiorcy nie ma naszego arkusza: `var(--c-accent)`
 * w wiadomości nie znaczy nic, a `<style>` w nagłówku część klientów wycina (docs/system-wizualny.md,
 * sekcja „Poczta"). Styl wiadomości musi więc stać w atrybutach `style=`, z wartościami wpisanymi
 * wprost — i właśnie dlatego te wartości potrzebują JEDNEGO miejsca. Rozsypane po szablonach
 * rozjechałyby się przy pierwszej zmianie palety, a rozjazdu nikt nie zobaczy: wiadomość oglądamy
 * raz, w dniu wysyłki.
 *
 * ⚠ **Wartości przepisane z `public/assets/css/00-tokens.css`** (stan na 20.10.2026) i tamten
 * plik zostaje źródłem palety. Przy zmianie koloru marki trzeba poprawić oba miejsca — i to jest
 * cena tego, że poczta nie czyta CSS-a. Lista jest krótka właśnie po to, żeby ta cena była mała.
 *
 * ⚠ Nazwy kluczy są **po polsku i opisują rolę**, nie wygląd („akcja", a nie „zielony"): szablon
 * ma pytać o rolę koloru, tak samo jak reszta aplikacji pyta o `--c-accent`.
 */
final class BarwyPoczty
{
    /**
     * @var array<string, string>
     */
    public const MAPA = [
        // --c-text (treść główna), --c-text-2 (treść zwykła), --c-text-3 (podpis)
        'tytul'     => '#15181D',
        'tekst'     => '#2A2F39',
        'podpis'    => '#6B7280',
        'stopka'    => '#8A909C',
        // --c-accent (AKCJA i tylko akcja) oraz --c-accent-text (napis w barwie akcji)
        'akcja'     => '#12874A',
        'akcja_tekst' => '#0E6E3C',
        'na_akcji'  => '#FFFFFF',
        // --c-bg (tło strony), --c-surface (karta), --c-line (ramka), --c-line-soft (kreska)
        'tlo'       => '#F5F6F8',
        'karta'     => '#FFFFFF',
        'ramka'     => '#E6E8EC',
        'kreska'    => '#F0F2F5',
        // --c-danger-text — wyłącznie dla alertu, który wymaga reakcji
        'uwaga'     => '#9F2115',
        // --c-warn-line / --c-warn-soft / --c-warn-text — ramka ostrzeżenia w treści wiadomości
        // („jeśli to nie Ty prosiłeś o zmianę hasła"). ⚠ Bursztyn, nie czerwień: to nie jest
        // awaria, tylko zdanie, które trzeba przeczytać.
        'ostrzezenie_ramka' => '#F0D8A8',
        'ostrzezenie_tlo'   => '#FDF3E2',
        'ostrzezenie_tekst' => '#8A5300',
    ];

    /** Krój systemowy: IBM Plex ze strony w skrzynce się nie wczyta. */
    public const KROJ = "-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif";
}
