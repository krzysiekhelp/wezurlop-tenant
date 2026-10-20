<?php

declare(strict_types=1);

/*
 * **Panel osoby w czterech stanach konta** — pomiar na szablonie (zlecenie Z58).
 *
 * ⚠ Dlaczego nie przez przeglądarkę: baza robocza ma dziś wyłącznie stany „brak konta",
 * „niezaproszone" i „zaproszony". Stanów „aktywne" i „zablokowane" nie da się na niej zmierzyć
 * bez przestawienia komuś konta — a to nie jest pomiar, tylko ingerencja w dane kadrowe.
 * Zamiast tego renderujemy TEN SAM plik szablonu z czterema zestawami danych.
 *
 * ⚠ To mierzy plik, który wchodzi na ekran: `strict_variables` i auto-escaping jak w aplikacji,
 * więc brakująca zmienna wywala render, a nie renderuje pustkę.
 *
 * ⚠ Wszystkie dane są FIKCYJNE (CLAUDE.md §18) — nazwiska zastępcze, NIP z jedynek.
 *
 * Wywołanie: `php dev/probka-panelu-osoby.php`, wynik w JSON. Nie jedzie do paczki klienta
 * (katalog `dev/`, patrz `dev/package-allowlist.txt`).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\ZakresWidocznosci as Z;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\WorkTime;

$urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
$twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
    'csrf_token'  => static fn (): string => 'probka',
    'can'         => static fn (string $p): bool => $p === 'admin.event_log',
    'nip_grupami' => static fn (string $nip): string => $nip,
    'czas_pracy'  => WorkTime::format(...),
    'czas_pelny'  => static fn (?string $t): string => '20.10.2026, 09:00',
    'czas_ze_strefa' => static fn (?string $t): string => '20.10.2026, 09:00',
    'odmiana_osob' => Odmiana::osoby(...),
    'odmiana_innych' => Odmiana::inne(...),
]);

/** Osoba w zadanym stanie konta — dane fikcyjne. */
$osoba = static function (?string $status, array $nadpisz = []): array {
    return $nadpisz + [
        'person_id' => 7,
        'name' => 'PRÓBNA Alicja',
        'account_id' => $status === null ? null : 11,
        'account_status' => $status,
        'account_email' => $status === null ? null : 'probna@przyklad.invalid',
        'email_kontaktowy' => 'probna@przyklad.invalid',
        'phone' => null,
        'z_kadr' => true,
        'spoza_kadr' => false,
        'totp' => $status === 'active',
        'sesje' => $status === 'active' ? 2 : 0,
        'last_login_at' => $status === 'active' ? '2026-10-20 07:00:00' : null,
        'powod_blokady' => $status === 'blocked' ? 'manual' : null,
        'blokada_od' => $status === 'blocked' ? '2026-10-19 12:00:00' : null,
    ];
};

$zrodla = [
    ['rodzaj' => Z::CZYJE_PODWLADNI, 'ikona' => 'drzewo', 'cel' => 'Swoi podwładni',
        'skad' => 'z hierarchii · reguła domyślna „Każdy przełożony"', 'zakres' => Z::BEZ_SZCZEGOLOW, 'osob' => 4],
    ['rodzaj' => Z::CZYJE_GRUPY, 'ikona' => 'etykieta', 'cel' => 'Grupy: Magazyn, Produkcja',
        'skad' => 'przez grupę Brygadziści', 'zakres' => Z::ZE_SZCZEGOLAMI, 'osob' => 12],
];

$przypadki = [
    'brak_konta'  => ['status' => null, 'zrodla' => [], 'caly_tenant' => false],
    'zaproszony'  => ['status' => 'invited', 'zrodla' => [], 'caly_tenant' => false],
    'aktywne'     => ['status' => 'active', 'zrodla' => $zrodla, 'caly_tenant' => false],
    'zablokowane' => ['status' => 'blocked', 'zrodla' => $zrodla, 'caly_tenant' => false],
    // Piąty przypadek, nie stan konta: administrator tenanta nie ma pola wyboru firm.
    'administrator' => ['status' => 'active', 'zrodla' => $zrodla, 'caly_tenant' => true],
];

$wynik = [];
foreach ($przypadki as $nazwa => $p) {
    $dane = [
        'o' => $osoba($p['status']),
        'wiersz' => [
            'etat_nazwy' => ['ALFA sp. z o.o.'],
            'caly_tenant' => $p['caly_tenant'],
            'dostepy' => 1,
            'etat_spolki' => 2,
        ],
        'zatrudnienia' => [
            ['id' => 31, 'company_id' => 3, 'company' => 'ALFA sp. z o.o.', 'nip' => '1111111111',
                'fte_numerator' => 1, 'fte_denominator' => 1, 'hired_at' => '2020-01-02',
                'terminated_at' => null, 'aktywne' => true, 'limit_min' => 10400, 'limit_rok' => 2026],
            ['id' => 32, 'company_id' => 4, 'company' => 'BETA sp. z o.o.', 'nip' => '2222222222',
                'fte_numerator' => 1, 'fte_denominator' => 2, 'hired_at' => '2024-03-01',
                'terminated_at' => '2026-06-30', 'aktywne' => false, 'limit_min' => null, 'limit_rok' => 2026],
        ],
        'firmy' => [
            ['company_id' => 3, 'name' => 'ALFA sp. z o.o.', 'ma_etat' => true, 'ma_dostep' => true],
            ['company_id' => 4, 'name' => 'BETA sp. z o.o.', 'ma_etat' => false, 'ma_dostep' => false],
            ['company_id' => 5, 'name' => 'GAMMA sp. z o.o.', 'ma_etat' => true, 'ma_dostep' => false],
        ],
        'licencja' => ['uzyte' => 4, 'limit' => 10],
        'widocznosc' => ['spolka' => 'ALFA sp. z o.o.', 'adres' => '/admin/widocznosc', 'zrodla' => $p['zrodla']],
        'nazwy_zakresow' => Z::nazwy(),
        'formy_innych' => implode('|', [Odmiana::inne(1), Odmiana::inne(2), Odmiana::inne(5)]),
        'odstep_ponowienia' => 0,
        'zaproszenie_w_kolejce' => false,
        'zaproszenie_wydane_utc' => $p['status'] === 'invited' ? '2026-10-19 09:00:00' : null,
        'zaproszenie_link' => $p['status'] === 'invited' ? 'czynny' : 'brak',
        'zaproszenie_zadanie' => null,
        'termin' => ['awaria' => false, 'zdanie' => 'w ciągu 5 minut'],
        'odmowa_blokady' => null,
        'rok' => 2026,
        'current_id' => 99,
        'notice' => null,
        'notice_termin' => null,
        'error' => null,
        'wpisane' => [],
        'w_szufladzie' => true,
    ];
    $html = $twig->render('fragments/person-panel.twig', $dane);

    preg_match_all('/class="op-karta__tytul">([^<]+)</', $html, $tytuly);
    preg_match_all('/class="op-akcja__tytul">([^<]+)</', $html, $akcje);
    $wynik[$nazwa] = [
        'karty' => $tytuly[1],
        'akcje' => $akcje[1],
        // ⚠ Przyciski akcji muszą być równej szerokości — w szablonie znaczy to JEDNĄ klasę
        // na każdym z nich; szerokość mierzy przeglądarka, tu pilnujemy, że klasa jest wszędzie.
        // ⚠ Każda akcja ma dokładnie JEDEN przycisk tej samej klasy — na tym stoi równa
        // szerokość. Liczymy wiersze i przyciski osobno: rozjazd znaczy akcję bez przycisku
        // albo przycisk bez akcji.
        'wierszy_akcji' => substr_count($html, '<li class="op-akcja">'),
        'przyciskow_akcji' => substr_count($html, 'class="btn btn--primary btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn op-akcja__btn--niebezpieczna"'),
        'pole_firm' => str_contains($html, 'class="pf"'),
        'rola_tylko_do_odczytu' => str_contains($html, 'op-rola'),
        'zrodel' => substr_count($html, 'class="op-zrodlo"'),
        'zdanie_o_pierwszenstwie' => str_contains($html, 'wygrywa szerszy zakres'),
        'widzi_tylko_siebie' => str_contains($html, 'Widzi tylko siebie.'),
        'widocznosc_bez_konta' => str_contains($html, 'Widoczność dotyczy konta'),
        'stopka_przyciskow' => substr_count($html, 'form="osoba-dane"'),
        'zamknij_w_stopce' => str_contains($html, 'data-modal-close="osoba-panel">Zamknij'),
        'stan_konta' => (preg_match('/chip[^"]*"><span class="chip__kropka"><\/span>([^<]+)</', $html, $m) === 1) ? trim($m[1]) : null,
        // Stare elementy, których tu już nie ma (zlecenie Z58): przełącznik i sekcja uprawnień.
        'stary_przelacznik' => str_contains($html, 'access--btn') || str_contains($html, 'data-dostep='),
        'stara_sekcja_uprawnien' => str_contains($html, 'Uprawnienia'),
        'dlugi_akapit_o_kadrach' => str_contains($html, 'Te dane przychodzą z programu kadrowego'),
    ];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
