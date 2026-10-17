<?php

declare(strict_types=1);

namespace Wezurlop\Service\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Kalendarz dni roboczych firmy (docs/etap3-logika-urlopowa.md rozdz. 5). STREFA JAWNA.
 *
 * Kolejność rozstrzygania dnia:
 * 1. rozkład z R2 dla danego roku (jeśli przyszedł) — NADRZĘDNY,
 * 2. wyjątek firmowy z tenant_setting (dzień wolny za święto w sobotę, przerwa świąteczna, sobota robocza),
 * 3. święto państwowe (PolishHolidays),
 * 4. dni tygodnia z ustawień (domyślnie poniedziałek–piątek).
 *
 * ⚠ To prognoza. Wiążąca liczba dni to ta z potwierdzenia zapisu w R2.
 */
final class WorkingCalendar
{
    /** @var array<int, array<string, string>> */
    private array $holidays = [];

    /**
     * @param list<int> $workdays dni robocze ISO-8601 (1 = poniedziałek … 7 = niedziela)
     * @param array<string, bool> $exceptions data => czy roboczy
     * @param array<int, list<string>> $r2NonWorkingDays rok => dni nierobocze z R2 (niedziele, święta, wolne)
     */
    public function __construct(
        private readonly array $workdays = [1, 2, 3, 4, 5],
        private readonly array $exceptions = [],
        private readonly array $r2NonWorkingDays = [],
    ) {
        foreach ($workdays as $d) {
            if (!is_int($d) || $d < 1 || $d > 7) {
                throw new InvalidArgumentException('Dzień tygodnia musi być liczbą 1–7');
            }
        }
    }

    public function isWorkingDay(DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');
        $year = (int) $day->format('Y');
        if (isset($this->r2NonWorkingDays[$year])) {
            return !in_array($date, $this->r2NonWorkingDays[$year], true);
        }
        if (array_key_exists($date, $this->exceptions)) {
            return $this->exceptions[$date];
        }
        if (isset($this->holidaysFor($year)[$date])) {
            return false;
        }

        return in_array((int) $day->format('N'), $this->workdays, true);
    }

    /** @return list<string> dni robocze w zakresie (włącznie), Y-m-d */
    public function workingDays(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            if ($this->isWorkingDay($d)) {
                $days[] = $d->format('Y-m-d');
            }
        }

        return $days;
    }

    public function countWorkingDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return count($this->workingDays($from, $to));
    }

    /** @return array<string, string> */
    private function holidaysFor(int $year): array
    {
        return $this->holidays[$year] ??= PolishHolidays::forYear($year);
    }
}
