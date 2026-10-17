<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Uprawnienie `data.view` (ekrany podglądu danych z kadr: firmy, pracownicy, karta, kalendarz,
 * stan synchronizacji). ⚠ **Nie** jest `tenant_only`: konto z przypisaniem do jednej firmy ma widzieć
 * wyłącznie jej dane, więc uprawnienie musi działać także z przypisania per firma (`role_assignment`
 * z `company_id` firmy), a filtr po firmie idzie do zapytania, nie do `if` po pobraniu (CLAUDE.md §10).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddDataViewPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("INSERT INTO permission (code, name, tenant_only) VALUES ('data.view', 'Podgląd danych z kadr', 0)");

        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r JOIN permission p ON p.code = 'data.view' WHERE r.code = 'tenant_admin'"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permission WHERE code = 'data.view'");
    }
}
