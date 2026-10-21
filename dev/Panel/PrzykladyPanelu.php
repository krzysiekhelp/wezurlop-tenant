<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Panel;

use Wezurlop\Support\Telefon;
use Wezurlop\Service\ZakresWidocznosci as Z;
use Wezurlop\Support\Odmiana;

/**
 * **Przykładowe dane panelu osoby w każdym stanie konta** — jedno miejsce dla podglądu
 * (`dev/probka-panelu-osoby.php`) i dla testu (`tests/panel-osoby.php`).
 *
 * ⚠ Dwa zestawy danych rozjechałyby się przy pierwszym nowym stanie albo nowej karcie, a wtedy
 * test świeciłby zielono na przypadku, którego podgląd nie pokazuje — albo odwrotnie.
 *
 * ⚠ **Dane FIKCYJNE** (CLAUDE.md §18): nazwiska zastępcze, NIP z jedynek, adresy w domenie
 * `przyklad.invalid`. Nic nie pochodzi z bazy. Katalog `dev/` nie jedzie do paczki klienta.
 */
final class PrzykladyPanelu
{
    /** Źródła wglądu dla osoby z hierarchią i regułą grupową. */
    private const ZRODLA = [
        ['rodzaj' => Z::CZYJE_PODWLADNI, 'ikona' => 'drzewo', 'cel' => 'Swoi podwładni',
            'skad' => 'z hierarchii · reguła domyślna „Każdy przełożony"',
            'zakres' => Z::BEZ_SZCZEGOLOW, 'osob' => 4],
        ['rodzaj' => Z::CZYJE_GRUPY, 'ikona' => 'etykieta', 'cel' => 'Grupy: Magazyn, Produkcja',
            'skad' => 'przez grupę Brygadziści', 'zakres' => Z::ZE_SZCZEGOLAMI, 'osob' => 12],
    ];

    /** Źródło wglądu konta administracyjnego — reguła domyślna „Administratorzy". */
    private const ZRODLO_ADMINA = [
        'rodzaj' => Z::CZYJE_FIRMA, 'ikona' => 'firma', 'cel' => 'Cała firma',
        'skad' => 'z uprawnień administratora · reguła domyślna „Administratorzy"',
        'zakres' => Z::ZE_SZCZEGOLAMI, 'osob' => 53,
    ];

    /**
     * Pięć stanów konta plus konto administracyjne: nazwa => komplet zmiennych szablonu
     * `fragments/person-panel.twig`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function wszystkie(): array
    {
        $przypadki = [
            'brak_konta'  => ['status' => null, 'zrodla' => [], 'caly_tenant' => false],
            'zaproszony'  => ['status' => 'invited', 'zrodla' => [], 'caly_tenant' => false],
            'aktywne'     => ['status' => 'active', 'zrodla' => self::ZRODLA, 'caly_tenant' => false],
            'zablokowane' => ['status' => 'blocked', 'zrodla' => self::ZRODLA, 'caly_tenant' => false],
            // Administrator tenanta Z ETATEM: zostaje osobą z kadr, więc karta „Dane z kadr"
            // jest, a pola wyboru firm nie ma (rola obejmuje wszystkie).
            'administrator' => ['status' => 'active', 'zrodla' => self::ZRODLA, 'caly_tenant' => true],
            // ⚠ Konto administracyjne (zlecenie Z59): rola na cały tenant i ZERO etatów.
            // Karty „Dane z kadr" nie ma wcale, w miejscu dostępu stoi rola tylko do odczytu,
            // a w karcie „Konto" — odmowa blokady zdaniem.
            'konto_administracyjne' => ['status' => 'active', 'zrodla' => [self::ZRODLO_ADMINA],
                'caly_tenant' => true, 'bez_etatow' => true,
                'odmowa' => 'To ostatni aktywny administrator — nie można go zablokować.'],
        ];

        $out = [];
        foreach ($przypadki as $nazwa => $p) {
            $out[$nazwa] = self::dane($p);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function dane(array $p): array
    {
        $status = $p['status'];
        $bezEtatow = (bool) ($p['bez_etatow'] ?? false);

        return [
            'o' => [
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
            ],
            // ⚠ Wartość POLA telefonu — od Z73 panel renderuje wspólny komponent z „Moim
            // kontem", więc katalog przykładów musi ją podać; bez niej szablon wywraca się
            // na `strict_variables` (i tak właśnie ten test złapał brak klucza w kontrolerze).
            'telefon_pole' => Telefon::doPola($p['telefon'] ?? null),
            'wiersz' => [
                'etat_nazwy' => $bezEtatow ? [] : ['ALFA sp. z o.o.'],
                'caly_tenant' => (bool) $p['caly_tenant'],
                'dostepy' => 1,
                'etat_spolki' => 2,
            ],
            'zatrudnienia' => $bezEtatow ? [] : [
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
            'widocznosc' => ['spolka' => 'ALFA sp. z o.o.', 'adres' => '/admin/widocznosc',
                'zrodla' => $p['zrodla']],
            'nazwy_zakresow' => Z::nazwy(),
            'formy_innych' => implode('|', [Odmiana::inne(1), Odmiana::inne(2), Odmiana::inne(5)]),
            'odstep_ponowienia' => 0,
            'zaproszenie_w_kolejce' => false,
            'zaproszenie_wydane_utc' => $status === 'invited' ? '2026-10-19 09:00:00' : null,
            'zaproszenie_link' => $status === 'invited' ? 'czynny' : 'brak',
            'zaproszenie_zadanie' => null,
            'termin' => ['awaria' => false, 'zdanie' => 'w ciągu 5 minut'],
            'odmowa_blokady' => $p['odmowa'] ?? null,
            'rok' => 2026,
            'current_id' => 99,
            'notice' => null,
            'notice_termin' => null,
            'error' => null,
            'error_danych' => null,
            'error_dostepu' => null,
            'wpisane' => [],
            'w_szufladzie' => true,
        ];
    }
}
