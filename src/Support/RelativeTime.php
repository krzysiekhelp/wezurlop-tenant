<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Czas względny („3 minuty temu”) — czysty, bezstanowy helper (CLAUDE.md §1).
 *
 * ⚠ Na ekranie integracji liczy się „czy to było przed chwilą”, a nie która dokładnie godzina;
 * dokładny znacznik zostaje obok, w drugiej linii (docs/system-wizualny.md §5.3).
 * ⚠ Wartość wejściowa to czas UTC z bazy (`RRRR-MM-DD HH:MM:SS`) — tak trzymamy wszystkie znaczniki.
 */
final class RelativeTime
{
    public static function format(?string $utc, ?DateTimeImmutable $now = null): string
    {
        if ($utc === null || $utc === '') {
            return 'jeszcze nie było';
        }
        $when = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if ($when === false) {
            return $utc;
        }
        $seconds = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $when->getTimestamp();
        if ($seconds < 0) {
            return 'przed chwilą';
        }
        if ($seconds < 60) {
            return 'przed chwilą';
        }
        if ($seconds < 3600) {
            return self::plural(intdiv($seconds, 60), 'minutę', 'minuty', 'minut') . ' temu';
        }
        if ($seconds < 86400) {
            return self::plural(intdiv($seconds, 3600), 'godzinę', 'godziny', 'godzin') . ' temu';
        }

        return self::plural(intdiv($seconds, 86400), 'dzień', 'dni', 'dni') . ' temu';
    }

    /** Polska odmiana: 1 minutę, 2–4 minuty, 5+ minut (z wyjątkiem nastek). */
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return '1 ' . $one;
        }
        $last = $n % 10;
        $twoLast = $n % 100;

        return $n . ' ' . ($last >= 2 && $last <= 4 && ($twoLast < 12 || $twoLast > 14) ? $few : $many);
    }
}
