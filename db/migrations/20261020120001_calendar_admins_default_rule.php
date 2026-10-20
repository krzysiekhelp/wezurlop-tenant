<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * **Druga reguła domyślna firmy: „Administratorzy"** oraz dokończenie migracji dawnych poziomów
 * (zlecenie Z55, decyzja Krzysztofa z 20.10.2026).
 *
 * ⚠ **Decyzja odwraca rozstrzygnięcie z 23.09.2026** („administrator nie widzi kalendarzy z samej
 * roli"). Administrator tenanta widzi odtąd **wszystko, ze szczegółami** — ale jako REGUŁA, nie
 * jako wyjątek w kodzie. Powód jest praktyczny: instalacją klienta opiekuje się czasem zewnętrzny
 * informatyk z kontem administratora, a klient ma móc mu ten wgląd **zabrać**. Wyjątku zaszytego
 * w kodzie nie da się zabrać ekranem; regułę zmienia się ołówkiem w jednym wierszu.
 *
 * ⚠ **Objęcie regułą rozstrzyga UPRAWNIENIE `calendar.visibility_manage`, nie nazwa roli.**
 * Dziś ma je wyłącznie `tenant_admin`, ale role dokładają się w czasie: przy porównaniu z nazwą
 * przyszła rola kadrowa albo „pomoc techniczna" wpadłaby w pełny wgląd w dane o zdrowiu bez
 * niczyjej decyzji. Wybrane uprawnienie jest najwęższe i samo-spójne — kto rozstrzyga
 * o widoczności, ten może sobie ten dostęp nadać i bez tej reguły.
 *
 * ⚠ **Naprawia też lukę po Z53** (znalezisko z testu `i`, 20.10.2026): nadanie dawnego poziomu
 * obejmujące cały tenant (`company_id = 0`) rozkładało się wyłącznie na firmy, w których konto
 * ma rolę `employee`. Konto bez takiej roli nie dostało ani jednej reguły, czyli **po cichu
 * straciło dostęp**. Konta z uprawnieniem administratora obejmuje teraz reguła „Administratorzy";
 * pozostałym zakładamy regułę „konto › cała firma" w KAŻDEJ firmie tenanta — dostęp do firmy
 * mają z nadania tenantowego, a nie z etatu.
 *
 * ⚠ **Migracji z Z53 nie zmieniamy** (jest zastosowana u nas i w paczce). Ta dokłada brakujące
 * wiersze i jest **idempotentna**: `INSERT IGNORE` na kluczu unikalnym podpisu, więc powtórzony
 * przebieg nie dubluje niczego.
 *
 * ⚠ **`down()` kasuje wyłącznie wiersze TEJ migracji.** Reguły „Administratorzy" poznaje po
 * rodzaju podmiotu (Z53 takiego nie znała), a reguły osobiste — po dokładnie tym samym warunku,
 * którym `up()` je zakłada, pomniejszonym o przypadki, które mogła utworzyć Z53 (nadanie firmowe
 * albo rola `employee` w tej firmie). Bez tej symetrii cofnięcie Z55 wycięłoby cudzą pracę.
 *
 * ⚠ Podpisy składane dosłownie, nie z kodu aplikacji: migracja nie ma prawa zależeć od klasy,
 * która jutro się zmieni (ta sama zasada, co w Z53).
 */
final class CalendarAdminsDefaultRule extends AbstractMigration
{
    private const UPRAWNIENIE = 'calendar.visibility_manage';

    public function up(): void
    {
        $firmy = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->fetchAll('SELECT id FROM company ORDER BY id'),
        );
        // ⚠ Liczymy WSTAWIONE wiersze, nie próby: `INSERT IGNORE` przy powtórzonym przebiegu
        // nie robi nic, a raport z wdrożenia ma mówić, co się naprawdę zmieniło.
        $adminow = 0;
        $osobistychWFirmie = [];
        foreach ($firmy as $firma) {
            // ⚠ Zakres domyślny `ze_szczegolami` stoi TU i w `ZakresWidocznosci::DOMYSLNY_ADMINISTRATOROW`.
            // Dwa miejsca, bo migracja nie czyta kodu aplikacji — ale obie wartości opisuje
            // ten sam akapit w `docs/etap3-logika-urlopowa.md` §8b.
            $adminow += $this->execute(sprintf(
                "INSERT IGNORE INTO calendar_access_rule
                    (company_id, subject_kind, subject_account_id, subject_employment_id,
                     subject_group_id, target_kind, scope, signature, created_at, created_by)
                 VALUES (%d, 'admins_all', 0, 0, 0, 'company', 'ze_szczegolami',
                         'admins_all:0|company', NOW(), NULL)",
                $firma,
            ));
            $osobistychWFirmie[$firma] = 0;
        }

        $objetych = 0;
        $osobistych = 0;
        foreach ($this->nadaniaTenantowe() as $nadanie) {
            $konto = (int) $nadanie['account_id'];
            if ($this->jestAdministratorem($konto)) {
                // Reguła „Administratorzy" obejmuje to konto w każdej firmie — nic więcej nie trzeba.
                $objetych++;
                continue;
            }
            $zakres = $nadanie['rola'] === 'calendar_viewer_full' ? 'ze_szczegolami' : 'bez_szczegolow';
            foreach ($firmy as $firma) {
                $dodane = $this->execute(sprintf(
                    "INSERT IGNORE INTO calendar_access_rule
                        (company_id, subject_kind, subject_account_id, subject_employment_id,
                         subject_group_id, target_kind, scope, signature, created_at, created_by)
                     VALUES (%d, 'account', %d, 0, 0, 'company', '%s', 'account:%d|company|%s', NOW(), NULL)",
                    $firma,
                    $konto,
                    $zakres,
                    $konto,
                    $zakres,
                ));
                $osobistych += $dodane;
                $osobistychWFirmie[$firma] += $dodane;
            }
        }

        // ⚠ Wpis w dzienniku jeden na firmę, bo dziennik filtruje się po firmie — a nie jeden
        // na tenant, bo wtedy administrator firmy BETA nie zobaczyłby, że coś się u niego stało.
        foreach ($firmy as $firma) {
            $regul = ($osobistychWFirmie[$firma] ?? 0)
                + (int) ($adminow > 0 ? 1 : 0);
            if ($regul === 0) {
                // Powtórzony przebieg nic nie zmienił — więc nie ma o czym pisać w dzienniku.
                continue;
            }
            $this->execute(sprintf(
                "INSERT INTO event_log (occurred_at, account_id, company_id, actor_label,
                        event_code, subject_type, subject_id, detail)
                 VALUES (NOW(), NULL, %d, 'migracja', 'calendar_access.migrated', NULL, NULL, '%s')",
                $firma,
                json_encode(['regul' => $regul], JSON_THROW_ON_ERROR),
            ));
        }

        $this->output->writeln(sprintf(
            '  widoczność kalendarza: reguł „Administratorzy" wstawionych %d, '
            . 'nadań tenantowych objętych tą regułą %d, reguł osobistych dla kont bez uprawnień '
            . 'administratora %d',
            $adminow,
            $objetych,
            $osobistych,
        ));
    }

    public function down(): void
    {
        // Reguły osobiste — ten sam warunek, co w `up()`, bez przypadków możliwych w Z53.
        $firmy = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->fetchAll('SELECT id FROM company ORDER BY id'),
        );
        foreach ($this->nadaniaTenantowe() as $nadanie) {
            $konto = (int) $nadanie['account_id'];
            if ($this->jestAdministratorem($konto)) {
                continue;
            }
            $zakres = $nadanie['rola'] === 'calendar_viewer_full' ? 'ze_szczegolami' : 'bez_szczegolow';
            foreach ($firmy as $firma) {
                if ($this->mogloPowstacWZ53($konto, $firma)) {
                    continue;
                }
                $this->execute(sprintf(
                    "DELETE FROM calendar_access_rule
                      WHERE company_id = %d AND created_by IS NULL
                        AND signature = 'account:%d|company|%s'",
                    $firma,
                    $konto,
                    $zakres,
                ));
            }
        }
        // Reguły „Administratorzy" — rodzaj podmiotu, którego Z53 nie znała.
        $this->execute(
            "DELETE FROM calendar_access_rule WHERE subject_kind = 'admins_all' AND created_by IS NULL"
        );
        $this->execute("DELETE FROM event_log WHERE event_code = 'calendar_access.migrated'");
    }

    /** @return list<array<string, mixed>> dawne nadania obejmujące cały tenant */
    private function nadaniaTenantowe(): array
    {
        return $this->fetchAll(
            "SELECT ra.account_id, r.code AS rola
               FROM role_assignment ra JOIN role r ON r.id = ra.role_id
              WHERE r.code IN ('calendar_viewer', 'calendar_viewer_full') AND ra.company_id = 0
              ORDER BY ra.account_id"
        );
    }

    private function jestAdministratorem(int $konto): bool
    {
        $wiersze = $this->fetchAll(sprintf(
            "SELECT 1 AS tak FROM role_assignment ra
               JOIN role_permission rp ON rp.role_id = ra.role_id
               JOIN permission p ON p.id = rp.permission_id AND p.code = '%s'
              WHERE ra.account_id = %d
              LIMIT 1",
            self::UPRAWNIENIE,
            $konto,
        ));

        return $wiersze !== [];
    }

    /** Czy regułę „konto › cała firma" w tej firmie mogła założyć migracja z Z53. */
    private function mogloPowstacWZ53(int $konto, int $firma): bool
    {
        $wiersze = $this->fetchAll(sprintf(
            "SELECT 1 AS tak FROM role_assignment ra JOIN role r ON r.id = ra.role_id
              WHERE ra.account_id = %d AND ra.company_id = %d
                AND r.code IN ('calendar_viewer', 'calendar_viewer_full', 'employee')
              LIMIT 1",
            $konto,
            $firma,
        ));

        return $wiersze !== [];
    }
}
