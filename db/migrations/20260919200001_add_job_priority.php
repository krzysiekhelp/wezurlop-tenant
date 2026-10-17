<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Priorytet zadań w kolejce (docs/profile-hostingu.md, „Worker, kolejka i powiadomienia”):
 * linki z terminem ważności przed powiadomieniami, te przed sprzątaniem. Niższa liczba = pilniej.
 */
final class AddJobPriority extends AbstractMigration
{
    public function up(): void
    {
        $this->table('job')
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 50, 'signed' => false, 'limit' => 255, 'after' => 'type'])
            ->addIndex(['status', 'priority', 'run_after'], ['name' => 'idx_job_claim'])
            ->update();
        // Zadania już w kolejce: linki pilne, sprzątanie na końcu.
        $this->execute("UPDATE job SET priority = 10 WHERE type IN ('mail.password_reset', 'mail.invitation')");
        $this->execute("UPDATE job SET priority = 90 WHERE type LIKE 'maintenance.%'");
    }

    public function down(): void
    {
        $this->table('job')->removeIndexByName('idx_job_claim')->removeColumn('priority')->update();
    }
}
