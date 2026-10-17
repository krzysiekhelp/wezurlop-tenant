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
