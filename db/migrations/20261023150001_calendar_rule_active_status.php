<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * **Status „aktywna" dla reguł widoczności kalendarza** (zlecenie Z80 cz. B).
 *
 * ⚠ **Powód jest ten sam, co przy regule, która „po prostu nic nie daje".** Do dziś reguła, która
 * straciła skutek (zwolniony pracownik w celu, usunięta grupa, przełożony bez podwładnych),
 * zostawała na liście jako czynna i nie dawała nic — a administrator nie miał jak odróżnić
 * „nadałem i działa" od „nadałem i wyparowało". Jedyny ślad był LICZONY W LOCIE przy jednym
 * rodzaju celu (plakietka „nieaktywna — brak czynnych osób"), więc istniał tylko na ekranie
 * listy i nigdzie więcej. Od Z80 stan jest ZAPISANY: widzi go lista, dziennik i każdy odczyt
 * dostępu, a wyłączenie ma powód, datę i sprawcę.
 *
 * ⚠ **Reguły stałe firmy (`supervisor_all`, `admins_all`) są aktywne ZAWSZE** i nie mają
 * przełącznika (§B1 zlecenia). Dlatego domyślną wartością kolumny jest `1`, a nie `NULL`:
 * migracja nie musi niczego dla nich wpisywać, a nowy wiersz reguły stałej rodzi się aktywny
 * bez pamiętania o tym w kodzie.
 *
 * ⚠ **Pięć kolumn, nie jedna „nieaktywna od".** Każda odpowiada na inne pytanie, które pada
 * przy audycie dostępu do danych kadrowych: CZY działa (`is_active`), DLACZEGO nie
 * (`inactive_reason` — kod, nie zdanie: tekst dla człowieka składa ekran), KTO ją wyłączył
 * (`inactive_kind` = `auto`/`manual` plus `inactive_by_account_id`) i KIEDY (`inactive_at`).
 * Sklejenie tego w jedno pole znaczyłoby parsowanie napisu przy każdym odczycie.
 *
 * ⚠ **`inactive_by_account_id` bez klucza obcego i to jest decyzja.** Konto bywa usuwane razem
 * z osobą (reset instalacji, czyszczenie danych), a wpis o tym, kto odebrał komuś wgląd w dane
 * o zdrowiu, ma przeżyć usunięcie sprawcy — tak samo jak `event_log.account_id`. Kaskada
 * wyczyściłaby ślad po cichu, a `RESTRICT` zablokowałby usunięcie konta.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie `update()`** (CLAUDE.md §4: Phinx potrafi przestawić
 * kolejność operacji zebranych w jednym wywołaniu). Kolumny są od siebie niezależne, więc
 * kolejność niczego by tu nie wywróciła — ale reguła obowiązuje bez wyjątków, bo wyjątek
 * trzeba pamiętać przy każdej następnej migracji.
 *
 * ⚠ **`down()` zdejmuje kolumny i NIE udaje, że odtwarza stan.** Przy cofnięciu ginie informacja
 * o tym, które reguły były wyłączone — ale nie ginie ani jeden dostęp: po cofnięciu wszystkie
 * reguły znów działają tak, jak działały przed Z80 (czyli „nic nie dają po cichu"). Zapisywanie
 * tego gdzieś na boku byłoby trzecim miejscem z tym samym stanem.
 */
final class CalendarRuleActiveStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->table('calendar_access_rule')
            ->addColumn('is_active', 'boolean', [
                'default' => true,
                'null' => false,
                'comment' => 'Czy reguła daje wgląd; reguły stałe firmy są aktywne zawsze (Z80)',
                'after' => 'scope',
            ])
            ->update();

        // Kod powodu, nie zdanie: `brak_podwladnych`, `grupa_usunieta`, `konto_zablokowane`,
        // `bez_czynnych_osob`, `reczne`. Tekst dla człowieka składa ekran listy.
        $this->table('calendar_access_rule')
            ->addColumn('inactive_reason', 'string', [
                'limit' => 32,
                'null' => true,
                'default' => null,
                'comment' => 'Kod powodu wyłączenia; NULL przy regule aktywnej',
                'after' => 'is_active',
            ])
            ->update();

        // `auto` — wyłączył system, bo reguła straciła skutek; `manual` — wyłączył administrator.
        // ⚠ Rozdział jest potrzebny na ekranie (dwie różne plakietki) i w rozmowie z klientem:
        // „system to zdjął" i „ktoś to zdjął" to dwie zupełnie inne odpowiedzi.
        $this->table('calendar_access_rule')
            ->addColumn('inactive_kind', 'string', [
                'limit' => 8,
                'null' => true,
                'default' => null,
                'comment' => 'auto (system) albo manual (administrator); NULL przy regule aktywnej',
                'after' => 'inactive_reason',
            ])
            ->update();

        $this->table('calendar_access_rule')
            ->addColumn('inactive_at', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => 'Kiedy reguła została wyłączona (UTC)',
                'after' => 'inactive_kind',
            ])
            ->update();

        $this->table('calendar_access_rule')
            ->addColumn('inactive_by_account_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'Konto administratora przy wyłączeniu ręcznym; NULL gdy wyłączył system',
                'after' => 'inactive_at',
            ])
            ->update();
    }

    public function down(): void
    {
        foreach (['inactive_by_account_id', 'inactive_at', 'inactive_kind', 'inactive_reason', 'is_active'] as $kolumna) {
            $this->table('calendar_access_rule')->removeColumn($kolumna)->update();
        }
    }
}
