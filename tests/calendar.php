<?php

declare(strict_types=1);

// Test kalendarza dni roboczych (Etap 3, E1): święta ruchome liczone algorytmicznie, lata 2026 i 2027.
// Wartości oczekiwane policzone niezależnie (daty Wielkanocy jako znane fakty, nie tym samym algorytmem).
// Uruchomienie: php tests/calendar.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\Calendar\PolishHolidays;
use Wezurlop\Service\Calendar\WorkingCalendar;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    $d = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);

    $expected = [
        2026 => ['easter' => '2026-04-05', 'monday' => '2026-04-06', 'pentecost' => '2026-05-24', 'corpus' => '2026-06-04',
                 'months' => [20, 20, 22, 21, 20, 21, 23, 21, 22, 22, 20, 21], 'total' => 253],
        2027 => ['easter' => '2027-03-28', 'monday' => '2027-03-29', 'pentecost' => '2027-05-16', 'corpus' => '2027-05-27',
                 'months' => [19, 20, 22, 22, 19, 22, 22, 22, 22, 21, 20, 22], 'total' => 253],
    ];
    $cal = new WorkingCalendar();
    foreach ($expected as $year => $e) {
        $h = PolishHolidays::forYear($year);
        $check("$year: Wielkanoc {$e['easter']}", PolishHolidays::easterSunday($year)->format('Y-m-d') === $e['easter']);
        $check("$year: Poniedziałek Wielkanocny {$e['monday']}", isset($h[$e['monday']]) && !$cal->isWorkingDay($d($e['monday'])));
        $check("$year: Zesłanie Ducha Świętego {$e['pentecost']}", isset($h[$e['pentecost']]));
        $check("$year: Boże Ciało {$e['corpus']}", isset($h[$e['corpus']]) && !$cal->isWorkingDay($d($e['corpus'])));
        $check("$year: Wigilia wolna", !$cal->isWorkingDay($d("$year-12-24")) || in_array((int) $d("$year-12-24")->format('N'), [6, 7], true));
        foreach ($e['months'] as $i => $count) {
            $m = $i + 1;
            $from = $d(sprintf('%04d-%02d-01', $year, $m));
            $got = $cal->countWorkingDays($from, $from->modify('last day of this month'));
            if ($got !== $count) {
                $check("$year-$m: $count dni roboczych (jest $got)", false);
            }
        }
        $total = $cal->countWorkingDays($d("$year-01-01"), $d("$year-12-31"));
        $check("$year: {$e['total']} dni roboczych w roku (jest $total), wszystkie miesiące zgodne", $total === $e['total']);
    }

    // Wielkanoc dla lat odległych — algorytm, nie tablica.
    foreach (['2000-04-23', '2019-04-21', '2024-03-31', '2025-04-20', '2038-04-25', '2285-03-22'] as $known) {
        $check("Wielkanoc $known", PolishHolidays::easterSunday((int) substr($known, 0, 4))->format('Y-m-d') === $known);
    }

    // Wyjątki firmowe i rozkład z R2 (nadrzędny).
    $withExceptions = new WorkingCalendar([1, 2, 3, 4, 5], ['2026-11-10' => false, '2026-11-14' => true]);
    $check('wyjątek: 10.11.2026 wolny', !$withExceptions->isWorkingDay($d('2026-11-10')));
    $check('wyjątek: sobota 14.11.2026 robocza', $withExceptions->isWorkingDay($d('2026-11-14')));
    $r2 = new WorkingCalendar([1, 2, 3, 4, 5], ['2026-10-30' => false], [2026 => ['2026-10-25', '2026-11-01']]);
    $check('R2 nadrzędny: sobota 31.10.2026 robocza w rozkładzie R2', $r2->isWorkingDay($d('2026-10-31')));
    $check('R2 nadrzędny: wyjątek firmowy nie wygrywa z R2', $r2->isWorkingDay($d('2026-10-30')));
    $check('R2 tylko dla swojego roku: 2027 z własnego kalendarza', !$r2->isWorkingDay($d('2027-01-01')));
    $check('przełom miesiąca 29.10–03.11.2026: 4 dni', $cal->countWorkingDays($d('2026-10-29'), $d('2026-11-03')) === 4);

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
