<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Cztery pola z danych klienta (zlecenie 2026-09-20-pola-limit-normy.md):
 *
 * - `leave_type.limit_kind` / `limit_kind_source` — który limit obciąża dany rodzaj (`obciazaLimit`)
 *   i skąd to wiemy (`dane` = policzone z sald klienta, `domyslne` = zachowanie programu, niepotwierdzone).
 *   ⚠ `counts_to_limit` przestaje być ustawiane ręcznie: obciąża limit dokładnie to, co ma `limit_kind`.
 * - `company_calendar.leave_norm_min` / `daily_norm_min` — normy kalendarza (`normaUrlopowaMin`,
 *   `normaDobowaMin`). ⚠ Bez nich zamiana minut na dni szła przez stałą 480 wpisaną w kod, co u firmy
 *   z dniówką 12 h dawałoby złą liczbę dni w KAŻDYM wniosku.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddLimitKindAndNorms extends AbstractMigration
{
    public function up(): void
    {
        $this->table('leave_type')
            ->addColumn('limit_kind', 'string', ['null' => true, 'limit' => 24, 'after' => 'counts_to_limit'])
            ->update();
        $this->table('leave_type')
            ->addColumn('limit_kind_source', 'string', ['null' => true, 'limit' => 16, 'after' => 'limit_kind'])
            ->update();
        $this->table('company_calendar')
            ->addColumn('leave_norm_min', 'integer', ['null' => true, 'signed' => false, 'after' => 'individual'])
            ->update();
        $this->table('company_calendar')
            ->addColumn('daily_norm_min', 'integer', ['null' => true, 'signed' => false, 'after' => 'leave_norm_min'])
            ->update();
    }

    public function down(): void
    {
        $this->table('company_calendar')->removeColumn('daily_norm_min')->update();
        $this->table('company_calendar')->removeColumn('leave_norm_min')->update();
        $this->table('leave_type')->removeColumn('limit_kind_source')->update();
        $this->table('leave_type')->removeColumn('limit_kind')->update();
    }
}
