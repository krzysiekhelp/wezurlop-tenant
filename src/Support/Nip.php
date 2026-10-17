<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * NIP firmy — klucz strumienia synchronizacji. Najpierw normalizacja (myślniki, spacje, prefiks PL —
 * program kadrowy trzyma numer tak, jak wpisał go człowiek), potem suma kontrolna: wagi 6 5 7 2 3 4 5 6 7,
 * suma mod 11 równa ostatniej cyfrze; wynik 10 oznacza numer niepoprawny.
 */
final class Nip
{
    private const WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    public static function normalize(string $raw): string
    {
        $nip = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));

        return str_starts_with($nip, 'PL') ? substr($nip, 2) : $nip;
    }

    /** Poprawny NIP po normalizacji albo null. */
    public static function parse(mixed $raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > 32) {
            return null;
        }
        $nip = self::normalize($raw);

        return self::isValid($nip) ? $nip : null;
    }

    /**
     * NIP do LOGU: `525…006`, czyli trzy pierwsze i trzy ostatnie cyfry (zlecenie Z16, 17.10.2026).
     *
     * ⚠ To nie jest anonimizacja i nie udaje nią być — cztery ukryte cyfry plus suma kontrolna
     * zawężają numer do kilkuset kandydatów. Chodzi o co innego: **log aplikacji nie ma być listą
     * NIP-ów klienta gotową do skopiowania**, a do diagnozy wystarczy odróżnić jedną firmę od
     * drugiej. Tam, gdzie firmę znamy u siebie, do logu i tak idzie jej identyfikator.
     * ⚠ Wartość z nagłówka `X-Nip` ani z `?nip=` nie trafia do logu w innej postaci niż ta.
     */
    public static function doLogu(string $raw): string
    {
        $nip = self::normalize($raw);

        return strlen($nip) === 10 ? substr($nip, 0, 3) . '…' . substr($nip, 7, 3) : 'niepoprawny';
    }

    /**
     * NIP w postaci do czytania: `525 252 50 06` (docs/system-wizualny.md §5.1).
     * ⚠ Wyłącznie do wyświetlenia — w bazie, w API i w porównaniach zostaje ciąg dziesięciu cyfr.
     */
    public static function human(string $nip): string
    {
        $clean = self::normalize($nip);
        if (strlen($clean) !== 10) {
            return $nip;
        }

        return substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6, 2) . ' ' . substr($clean, 8, 2);
    }

    public static function isValid(string $nip): bool
    {
        if (preg_match('/^\d{10}$/', $nip) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += $w * (int) $nip[$i];
        }

        return $sum % 11 !== 10 && $sum % 11 === (int) $nip[9];
    }
}
