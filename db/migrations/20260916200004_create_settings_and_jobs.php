<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ustawienia tenanta i kolejka zadań (docs/szkielet-tenanta.md rozdz. 5).
 * Kolejka: brak procesów w tle i limit 30 s — wysyłki idą przez cron (bin/run-jobs.php).
 */
final class CreateSettingsAndJobs extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function change(): void
    {
        $this->table('tenant_setting', ['id' => false, 'primary_key' => ['key']] + self::OPTIONS)
            ->addColumn('key', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('updated_by', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('job', ['id' => false, 'primary_key' => ['id']] + self::OPTIONS)
            ->addColumn('id', 'biginteger', ['null' => false, 'signed' => false, 'identity' => true])
            ->addColumn('type', 'string', ['null' => false, 'limit' => 64])
            // bez sekretów i bez tokenów — tokeny powstają dopiero przy wykonaniu zadania
            ->addColumn('payload', 'json', ['null' => false])
            // pending | done | failed
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16, 'default' => 'pending'])
            ->addColumn('attempts', 'smallinteger', ['null' => false, 'signed' => false, 'default' => 0])
            ->addColumn('run_after', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addIndex(['status', 'run_after'])
            ->create();
    }
}
