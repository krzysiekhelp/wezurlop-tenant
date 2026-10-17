<?php

declare(strict_types=1);

namespace Wezurlop\Security;

/**
 * Kody zapasowe 2FA: 8 sztuk, jednorazowe, hashowane jak hasła (CLAUDE.md §9).
 */
final class BackupCodes
{
    public const COUNT = 8;
    /** Bez znaków mylących się przy przepisywaniu (0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return list<string> kody w postaci XXXX-XXXX */
    public static function generate(): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 4) . '-' . substr($raw, 4);
        }

        return array_values($codes);
    }

    /** Postać porównywana: wielkie litery, bez spacji i myślników. */
    public static function normalize(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    public static function isWellFormed(string $normalized): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{8}$/', $normalized) === 1;
    }
}
