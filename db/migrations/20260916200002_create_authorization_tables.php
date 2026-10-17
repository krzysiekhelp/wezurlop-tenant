<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Role i uprawnienia (docs/szkielet-tenanta.md rozdz. 5). Uprawnienia należą do pary (konto, firma);
 * company_id = 0 oznacza całego tenanta — celowo NOT NULL DEFAULT 0, bo UNIQUE nie działa
 * na kolumnie z NULL (CLAUDE.md §4). Z tego powodu company_id nie ma klucza obcego.
 */
final class CreateAuthorizationTables extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    private const PERMISSIONS = [
        ['code' => 'app.use',            'name' => 'Korzystanie z aplikacji'],
        ['code' => 'account.manage_own', 'name' => 'Zarządzanie własnym kontem'],
        ['code' => 'admin.users',        'name' => 'Administracja użytkownikami'],
        ['code' => 'admin.settings',     'name' => 'Ustawienia tenanta'],
        ['code' => 'admin.event_log',    'name' => 'Dziennik zdarzeń'],
        ['code' => 'admin.license',      'name' => 'Licencja'],
    ];

    private const ROLES = [
        ['code' => 'tenant_admin', 'name' => 'Administrator tenanta', 'is_system' => 1, 'requires_2fa' => 1],
        ['code' => 'employee',     'name' => 'Pracownik',             'is_system' => 1, 'requires_2fa' => 0],
    ];

    private const ROLE_PERMISSIONS = [
        'tenant_admin' => ['app.use', 'account.manage_own', 'admin.users', 'admin.settings', 'admin.event_log', 'admin.license'],
        'employee'     => ['app.use', 'account.manage_own'],
    ];

    public function up(): void
    {
        $this->table('role', self::OPTIONS)
            ->addColumn('code', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 100])
            ->addColumn('is_system', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('requires_2fa', 'boolean', ['null' => false, 'default' => false])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('permission', self::OPTIONS)
            ->addColumn('code', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 150])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('role_permission', self::OPTIONS + ['id' => false, 'primary_key' => ['role_id', 'permission_id']])
            ->addColumn('role_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('permission_id', 'integer', ['null' => false, 'signed' => false])
            ->addIndex(['permission_id'])
            ->addForeignKey('role_id', 'role', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permission', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('role_assignment', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('role_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('company_id', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('granted_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('granted_by', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['account_id', 'role_id', 'company_id'], ['unique' => true])
            ->addIndex(['role_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('role_id', 'role', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('granted_by', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        // Słowniki wypełniane migracją, nie seedem — muszą istnieć także na produkcji.
        $this->table('permission')->insert(self::PERMISSIONS)->saveData();
        $this->table('role')->insert(self::ROLES)->saveData();

        $roleIds = [];
        foreach ($this->fetchAll('SELECT id, code FROM role') as $row) {
            $roleIds[$row['code']] = (int) $row['id'];
        }
        $permissionIds = [];
        foreach ($this->fetchAll('SELECT id, code FROM permission') as $row) {
            $permissionIds[$row['code']] = (int) $row['id'];
        }
        $rows = [];
        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                $rows[] = ['role_id' => $roleIds[$role], 'permission_id' => $permissionIds[$permission]];
            }
        }
        $this->table('role_permission')->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('role_assignment')->drop()->save();
        $this->table('role_permission')->drop()->save();
        $this->table('permission')->drop()->save();
        $this->table('role')->drop()->save();
    }
}
