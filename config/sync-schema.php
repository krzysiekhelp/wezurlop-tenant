<?php

declare(strict_types=1);

// Pola rekordów wymiany connector → wezurlop (docs/ksztalt-danych-r2.md, wersja 0.7).
// Dopisanie pola to JEDNA LINIA tutaj — nie warunek w klasie (docs/api-connector.md).
//
// Format pola: [typ, wymagane, parametr]
//   typ: string (parametr = maks. długość), int, decimal, bool, date (YYYY-MM-DD),
//        timestamp (ISO 8601 z przesunięciem), date_list (tablica dat), object (parametr = pola)
//   wymagane: true  = klucz obecny i wartość różna od null (inaczej 422),
//             false = brak klucza traktujemy jak null (rozstrzygnięcie z 19.09).
// Pola spoza listy są ignorowane i liczone (pominietePola) — nie są nigdzie zapisywane.
// Wspólne dla każdego rekordu: id (string, wymagane), suma (sha256:…, wymagana poza usunięciem).
//
// ⚠ Karta zasiłkowa: przyjmujemy wyłącznie rodzaj i daty (+ dni, kontynuacja) — żadnych kodów
// chorobowych ani kwot. Gdyby przyszły, nie ma ich na tej liście, więc nie zostaną zapisane.

return [
    'firma' => [
        'nazwa' => ['string', true, 200],
    ],
    'pracownik' => [
        'osobaId'        => ['string', false, 128],
        'nazwisko'       => ['string', true, 100],
        'imie'           => ['string', true, 100],
        'identyfikator'  => ['string', false, 32],
        'zwolniony'      => ['bool', true],
        'dataZwolnienia' => ['date', false],
        'skasowany'      => ['bool', true],
        'umowy'          => ['object', true, [
            'oPrace'   => ['bool', true],
            'zlecenie' => ['bool', true],
            'oDzielo'  => ['bool', true],
        ]],
    ],
    'zatrudnienie' => [
        'pracownikId'     => ['string', true, 64],
        'aktualne'        => ['bool', true],
        'domyslne'        => ['bool', true],
        'etat'            => ['decimal', false],
        'dataRozpoczecia' => ['date', false],
        'dataZakonczenia' => ['date', false],
        'kalendarzId'     => ['string', false, 32],
    ],
    'limit' => [
        'zatrudnienieId'           => ['string', true, 64],
        'rok'                      => ['int', true],
        'rodzaj'                   => ['string', true, 24],
        'naliczony'                => ['bool', true],
        'wymiarMin'                => ['int', false],
        'zaleglyMin'               => ['int', false],
        'dostepneNaStartMin'       => ['int', false],
        'wykorzystaneMin'          => ['int', false],
        'korektyMin'               => ['int', false],   // może być ujemne
        'pozostaloMin'             => ['int', false],   // może być ujemne
        'naZadanieWykorzystaneDni' => ['int', false],
    ],
    'nieobecnosc' => [
        'zatrudnienieId' => ['string', true, 64],
        'rodzaj'         => ['object', true, [
            'zrodlo' => ['string', true, 16],
            'kod'    => ['int', true],
        ]],
        'dataOd'         => ['date', true],
        'dataDo'         => ['date', true],
        'dni'            => ['int', false],
        'minuty'         => ['int', false],   // zawsze null przy zasilek
        'requestId'      => ['string', false, 32],
        'zrodloWpisu'    => ['string', true, 16],
        'kontynuacja'    => ['bool', false],
        'utworzono'      => ['timestamp', false],
        'zmieniono'      => ['timestamp', false],
    ],
    'slownik' => [
        'zrodlo'             => ['string', true, 16],
        'kod'                => ['int', true],
        'nazwa'              => ['string', true, 200],
        'tylkoOdczyt'        => ['bool', true],
        'doWniosku'          => ['bool', true],
        'nieobecnosc'        => ['bool', true],
        // Który limit obciąża ten rodzaj: wartość jak `rodzaj` rekordu `limit` albo null.
        // ⚠ Przy `kadrowe` i `zasilek` zawsze null — program kadrowy nie wiąże ich z pulą.
        'obciazaLimit'       => ['string', false, 24],
        // `dane` = policzone z sald tej firmy, `domyslne` = zachowanie programu, niepotwierdzone u klienta.
        'obciazaLimitZrodlo' => ['string', false, 16],
    ],
    'kalendarz' => [
        'kalendarzId'     => ['string', true, 32],
        'nazwa'           => ['string', true, 100],
        'rok'             => ['int', true],
        'indywidualne'    => ['bool', true],
        'dniNierobocze'   => ['date_list', true],
        // ⚠ Dzielnik minut na dni. Brak pola = wartość zapasowa 480, widoczna w opisie przebiegu.
        'normaUrlopowaMin' => ['int', false],
        'normaDobowaMin'   => ['int', false],
    ],
];
