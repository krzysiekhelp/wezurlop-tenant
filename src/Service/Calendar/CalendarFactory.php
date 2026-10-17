<?php

declare(strict_types=1);

namespace Wezurlop\Service\Calendar;

use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\TenantSettingRepository;

/**
 * Kalendarz dla firmy i (opcjonalnie) kalendarza R2 przypiętego do zatrudnienia.
 * Ustawienia z tenant_setting: calendar_workdays („1,2,3,4,5”), calendar_exceptions
 * ({"0": {...wszystkie firmy}, "<company_id>": {...}}), calendar_source (own | r2).
 */
final class CalendarFactory
{
    public function __construct(
        private readonly TenantSettingRepository $settings,
        private readonly CompanyCalendarRepository $r2Calendars,
    ) {
    }

    public function forCompany(int $companyId, ?string $r2CalendarRef = null): WorkingCalendar
    {
        $workdays = array_values(array_filter(
            array_map('intval', explode(',', $this->settings->get('calendar_workdays', '1,2,3,4,5'))),
            static fn (int $d): bool => $d >= 1 && $d <= 7,
        ));
        $all = json_decode($this->settings->get('calendar_exceptions', '{}'), true);
        $exceptions = [];
        foreach ([(string) 0, (string) $companyId] as $scope) {
            foreach (is_array($all[$scope] ?? null) ? $all[$scope] : [] as $date => $working) {
                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && is_bool($working)) {
                    $exceptions[$date] = $working;
                }
            }
        }
        // Rozkład z R2 nadrzędny, gdy włączony i dostępny dla kalendarza zatrudnienia.
        $r2 = $this->settings->get('calendar_source', 'own') === 'r2' && $r2CalendarRef !== null
            ? $this->r2Calendars->nonWorkingDaysByYear($companyId, $r2CalendarRef)
            : [];

        return new WorkingCalendar($workdays === [] ? [1, 2, 3, 4, 5] : $workdays, $exceptions, $r2);
    }
}
