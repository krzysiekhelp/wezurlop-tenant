<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Przeniesienie dawnych **poziomów widoczności** do reguł dostępu (zlecenie Z53 §3,
 * z rozstrzygnięciami Krzysztofa z 19.10.2026).
 *
 * ```
 * pełny szczegół (calendar_viewer_full) → reguła „konto › cała firma · ze_szczegolami"
 * rodzaj ogólny  (calendar_viewer)      → reguła „konto › cała firma · bez_szczegolow"
 * ```
 *
 * ⚠ **Poziom „rodzaj ogólny" przestaje istnieć** i to jest zaokrąglenie W DÓŁ w jedynym miejscu,
 * w którym zmienia ono cokolwiek widocznego: osoba, która widziała słowo „urlop" przy cudzym
 * nazwisku, zobaczy teraz szare „nieobecny". Krzysztof to przyjął. Zaokrąglenie w górę byłoby
 * cichym poszerzeniem dostępu do danych o zdrowiu.
 *
 * ⚠ **Nadanie na CAŁEGO TENANTA (`company_id = 0`) rozkłada się na po jednej regule w każdej
 * firmie, do której konto ma dostęp** (decyzja z 19.10). Reguła jest bytem firmy i nie ma
 * postaci „wszystkie firmy" — gdyby miała, pierwsza nowa firma klienta dostawałaby cudzy wgląd
 * samym faktem powstania. „Dostęp do firmy" to przypisanie roli `employee` w tej firmie, czyli
 * dokładnie ta sama droga, którą liczy kontrolka dostępu na ekranie Konta.
 * ⚠ Konto z nadaniem tenantowym i **bez dostępu do ani jednej firmy** nie dostaje żadnej reguły
 * — nie ma firmy, w której dałoby się ją zapisać. Migracja wypisuje takie przypadki na wyjście,
 * bo przemilczana utrata dostępu jest gorsza niż utrata zapowiedziana.
 *
 * ⚠ **Nie usuwamy ról ani uprawnień** (`calendar_viewer`, `calendar_viewer_full`,
 * `calendar.view_all`, `calendar.view_all_details`) — to pytanie czeka na decyzję. Od tej
 * migracji **nic ich nie czyta**: zakres liczy się wyłącznie z reguł i z ustawienia dla
 * przełożonych. Zostają więc jako martwy zapis, a nie jako druga droga do tych samych danych.
 *
 * ⚠ **`requires_2fa` schodzi z roli `calendar_viewer_full`** (decyzja Krzysztofa 19.10: reguła
 * „ze szczegółami" nie wymaga drugiego składnika). Mówię wprost, co to znaczy: od tej chwili
 * dostęp do nazw rodzajów zwolnień całej firmy **nie wymusza już logowania dwuskładnikowego**
 * na nikim. Kolumna zostaje i dalej działa dla pozostałych ról.
 *
 * ⚠ **Podpis reguły składany jest TAK SAMO jak w `ZakresWidocznosci::podpis()`** — tu dosłownie,
 * bo migracja nie ma prawa zależeć od kodu aplikacji, który jutro się zmieni. Format pilnuje
 * test z dosłownymi napisami (`tests/widocznosc-kalendarza.php`).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class MigrateCalendarLevelsToRules extends AbstractMigration
{
    public function up(): void
    {
        $nadania = $this->fetchAll(
            "SELECT ra.account_id, ra.company_id, r.code AS rola
               FROM role_assignment ra JOIN role r ON r.id = ra.role_id
              WHERE r.code IN ('calendar_viewer', 'calendar_viewer_full')
              ORDER BY ra.account_id, ra.company_id"
        );
        $bezFirmy = [];
        $zapisanych = 0;
        foreach ($nadania as $n) {
            $konto = (int) $n['account_id'];
            $zakres = $n['rola'] === 'calendar_viewer_full' ? 'ze_szczegolami' : 'bez_szczegolow';
            $firmy = (int) $n['company_id'] >= 1
                ? [(int) $n['company_id']]
                : array_map(
                    static fn (array $r): int => (int) $r['company_id'],
                    $this->fetchAll(
                        "SELECT ra.company_id FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                          WHERE ra.account_id = " . $konto . " AND ra.company_id >= 1 AND r.code = 'employee'
                          ORDER BY ra.company_id"
                    ),
                );
            if ($firmy === []) {
                $bezFirmy[] = $konto;
                continue;
            }
            foreach ($firmy as $firma) {
                // ⚠ `INSERT IGNORE` zamiast sprawdzenia „czy już jest": konto z nadaniem
                // tenantowym I firmowym naraz daje tę samą regułę dwa razy, a klucz unikalny
                // na podpisie jest właśnie po to, żeby nie trzeba było tego pilnować w kodzie.
                $this->execute(sprintf(
                    "INSERT IGNORE INTO calendar_access_rule
                        (company_id, subject_kind, subject_account_id, subject_group_id,
                         target_kind, scope, signature, created_at, created_by)
                     VALUES (%d, 'account', %d, 0, 'company', '%s', 'account:%d|company|%s', NOW(), NULL)",
                    $firma,
                    $konto,
                    $zakres,
                    $konto,
                    $zakres,
                ));
                $zapisanych++;
            }
        }

        // ⚠ Reguła „ze szczegółami" nie wymaga 2FA (decyzja Krzysztofa 19.10.2026), więc
        // wymaganie schodzi też z roli, z której te reguły powstały.
        $this->execute("UPDATE role SET requires_2fa = 0 WHERE code = 'calendar_viewer_full'");

        // ⚠ Wyjście migracji jest **częścią raportu z wdrożenia**, a nie logiem technicznym:
        // bez tych liczb nikt nie zauważy, że komuś przepadł dostęp.
        $this->output->writeln(sprintf(
            '  widoczność kalendarza: nadań %d, reguł zapisanych %d, kont bez firmy do zapisania %d',
            count($nadania),
            $zapisanych,
            count($bezFirmy),
        ));
        if ($bezFirmy !== []) {
            // Same identyfikatory kont — żadnych adresów ani nazwisk (CLAUDE.md §14, §18).
            $this->output->writeln(
                '  ⚠ nadanie na całego tenanta bez dostępu do żadnej firmy, reguły NIE powstały '
                . 'dla kont o id: ' . implode(', ', array_unique($bezFirmy))
            );
        }
    }

    public function down(): void
    {
        // ⚠ Cofamy wyłącznie to, co ta migracja dołożyła: reguły „konto › cała firma" bez
        // autora. Reguł utworzonych później przez człowieka (`created_by IS NOT NULL`) nie
        // ruszamy — `down()` ma odwrócić migrację, a nie wyczyścić ekran.
        $this->execute(
            "DELETE FROM calendar_access_rule
              WHERE subject_kind = 'account' AND target_kind = 'company' AND created_by IS NULL"
        );
        $this->execute("UPDATE role SET requires_2fa = 1 WHERE code = 'calendar_viewer_full'");
    }
}
