<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rozpoznanie klienta (docs/szkielet-tenanta.md rozdz. 5 i 8): skąd nastąpiło logowanie.
 * Wartość zapisywana raz, przy logowaniu — znacznik prezentacji, nie poświadczenie (CLAUDE.md §11).
 *
 * Ustawienia: mobile_access (app_only na produkcji, open na dev — seed) oraz karencja
 * subskrypcji licencji w dniach (parametr handlowy, nie techniczny).
 */
final class AddSessionClient extends AbstractMigration
{
    private const SETTINGS = [
        ['key' => 'mobile_access',      'value' => 'app_only'],
        ['key' => 'license_grace_days', 'value' => '7'],
    ];

    public function up(): void
    {
        $this->table('session')
            ->addColumn('client', 'enum', ['null' => false, 'values' => ['web', 'app'], 'default' => 'web', 'after' => 'employment_id'])
            ->addColumn('app_version', 'string', ['null' => true, 'limit' => 32, 'after' => 'client'])
            ->update();

        $this->table('tenant_setting')->insert(self::SETTINGS)->saveData();
    }

    public function down(): void
    {
        $this->table('session')->removeColumn('app_version')->removeColumn('client')->update();
        $keys = array_map(static fn (array $s): string => $s['key'], self::SETTINGS);
        $this->getQueryBuilder('delete')->delete('tenant_setting')->whereInList('key', $keys)->execute();
    }
}
