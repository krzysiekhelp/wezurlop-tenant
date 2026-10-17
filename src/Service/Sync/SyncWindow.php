<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use DateTimeImmutable;

/**
 * Okno dat synchronizacji (docs/ksztalt-danych-r2.md 0.7): nieobecności od 1 stycznia poprzedniego
 * roku, limity od poprzedniego roku. Liczone z daty serwera tenanta.
 *
 * ⚠ Margines na granicy roku (strefy, zegary): okno liczymy od daty sprzed dwóch dni, więc 1–2 stycznia
 * okno jest SZERSZE o rok. Lepiej uznać rekord za „w oknie” i nic nie oznaczać, niż uznać za wypadnięty.
 */
final class SyncWindow
{
    private const MARGIN_DAYS = 2;

    public function __construct(private readonly ?DateTimeImmutable $today = null)
    {
    }

    /** Najstarszy rok w oknie (np. 2025 przez cały 2026 rok, a także 1–2 stycznia 2027). */
    public function oldestYear(): int
    {
        $today = $this->today ?? new DateTimeImmutable('now');

        return (int) $today->modify('-' . self::MARGIN_DAYS . ' days')->format('Y') - 1;
    }

    /** Nieobecność poza oknem: kończy się przed 1 stycznia najstarszego roku okna. */
    public function isOutside(DateTimeImmutable $dateTo): bool
    {
        return $dateTo < new DateTimeImmutable(sprintf('%04d-01-01', $this->oldestYear()));
    }
}
