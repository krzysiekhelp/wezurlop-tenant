<?php

declare(strict_types=1);

// Kody wyników zapisu (docs/ksztalt-danych-r2.md) — WYŁĄCZNIE SŁOWNIK DO WYŚWIETLANIA.
// ⚠ O zachowaniu aplikacji decyduje `status`, nigdy `kod` (docs/api-connector.md 3.5): nieznany kod
// przy znanym statusie jest poprawny, pokazujemy go kadrom i zapisujemy. Nie wolno na tej liście
// budować warunków sterujących przepływem.

return [
    'OSTRZ_PRZELOM_MIESIACA'        => 'Urlop rozbity na części per miesiąc',
    'OSTRZ_LIMIT_PRZEKROCZONY'      => 'Przekroczony limit urlopu wypoczynkowego (program kadrowy go nie blokuje)',
    'OSTRZ_NA_ZADANIE_WYKORZYSTANY' => 'Wykorzystany czwarty dzień urlopu na żądanie',
    'OSTRZ_INNE'                    => 'Inne ostrzeżenie programu kadrowego',
    'R2_KOLIZJA'                    => 'Daty pokrywają się z istniejącą nieobecnością',
    'R2_NA_ZADANIE_PRZEKROCZONY'    => 'Ponad 4 dni urlopu na żądanie w roku — odrzucone',
    'R2_PRACOWNIK_NIEZNALEZIONY'    => 'Pracownik nie istnieje albo nie jest aktualny',
    'R2_ZATRUDNIENIE_NIEZNALEZIONE' => 'Zatrudnienie nie istnieje albo nie jest aktualne',
    'R2_WPIS_NIE_DO_USUNIECIA'      => 'Wskazany wpis nie jest okresem urlopu',
    'R2_NIEDOSTEPNE'                => 'Zapis chwilowo niemożliwy',
    'R2_KONFIGURACJA'               => 'Problem instalacji u klienta — wymaga administratora',
    'R2_NIEZNANY'                   => 'Inny komunikat programu kadrowego',
    'ZADANIE_NIEPOPRAWNE'           => 'Zadanie niezgodne z protokołem',
    'CONNECTOR_TIMEOUT'             => 'Zapis przerwany po limicie czasu',
    'CONNECTOR_AWARIA'              => 'Awaria connectora w trakcie zadania',
    'OPERACJA_NIEOBSLUGIWANA'       => 'Operacja, której connector nie wykonuje',
    'ZMIANA_CZESCIOWA'              => 'Zmiana nieudana i przywrócenie nieudane — wniosek bez wpisu w kadrach',
];
