<?php

declare(strict_types=1);

// Domyślne wartości ustawień tenanta (`tenant_setting`) — JEDNO miejsce, z którego przywraca je
// reset instalacji (`Wezurlop\Service\Maintenance\TenantReset`). Migracje zakładają te wiersze przy
// instalacji; tutaj jest ich wartość domyślna, żeby reset nie musiał uruchamiać migracji od nowa.
//
// ⚠ Klucz dopisany w migracji musi trafić TUTAJ — inaczej reset skasuje go jako pozostałość.
// Pilnuje tego sprawdzenie w symulatorze: po `migrate` na czystej bazie każdy klucz z `tenant_setting`
// musi być na tej liście albo mieć przedrostek z `PRESERVED_PREFIXES`.
//
// ⚠ `canary_*` NIE są ustawieniami tenanta, tylko stanem sondy pliku kanarka w `storage/`.
// Plik leży na dysku i reset go nie kasuje, więc kasowanie jego opisu w bazie zostawiłoby
// diagnostykę bez punktu odniesienia.

return [
    'defaults' => [
        'alert_email'                => '',
        'calendar_exceptions'        => '{}',
        'calendar_source'            => 'own',
        'calendar_workdays'          => '1,2,3,4,5',
        'key_idle_alert_hours'       => '24',
        'key_idle_alert_last_at'     => '1970-01-01 00:00:00',
        'key_unused_alert_hours'     => '72',
        'key_unused_alert_last_at'   => '1970-01-01 00:00:00',
        'license_grace_days'         => '7',
        'login_alert_last_at'        => '1970-01-01 00:00:00',
        'login_alert_threshold'      => '50',
        'login_alert_window_minutes' => '15',
        'mobile_access'              => 'app_only',
        'sync_review_hours'          => '1',
        'sync_run_ttl_minutes'       => '30',
        'timezone'                   => 'Europe/Warsaw',
    ],
    'preserved_prefixes' => ['canary_'],
];
