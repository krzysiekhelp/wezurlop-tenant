<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Uprawnienie `integration.manage` (ekran /admin/integracja): klucze instalacji connectora obejmują
 * wiele firm, więc uprawnienie działa WYŁĄCZNIE z przypisania na całego tenanta (company_id = 0).
 * Znacznik `permission.tenant_only` jest częścią zapytania uprawnień — menu i trasa sprawdzają to samo
 * uprawnienie tym samym wywołaniem, bez dodatkowych warunków w kontrolerze ani w szablonie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddIntegrationPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->table('permission')
            ->addColumn('tenant_only', 'boolean', ['null' => false, 'default' => false, 'after' => 'name'])
            ->update();

        $this->execute("INSERT INTO permission (code, name, tenant_only) VALUES ('integration.manage', 'Integracja z programem kadrowym', 1)");

        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r JOIN permission p ON p.code = 'integration.manage' WHERE r.code = 'tenant_admin'"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permission WHERE code = 'integration.manage'");

        $this->table('permission')->removeColumn('tenant_only')->update();
    }
}
