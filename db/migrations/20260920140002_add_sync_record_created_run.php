<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `sync_record.created_run_id` — przebieg, w którym rekord pojawił się u nas po raz pierwszy.
 * ⚠ Bez tej kolumny ekran przebiegu umie pokazać tylko „dotknięte w tym cyklu” (`updated_run_id`),
 * a etapy A3–A7 polegają na rozróżnieniu **dodane / zmienione / usunięte**: „kadry zmieniły trzy
 * rzeczy, przyszły dokładnie trzy rekordy”. Kolumna jest ustawiana przy wstawieniu i nigdy potem.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddSyncRecordCreatedRun extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sync_record')
            ->addColumn('created_run_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'pending_since'])
            ->update();
        // Rekordy sprzed tej zmiany: przebieg pierwszego pojawienia się nie jest znany — zostaje NULL
        // i ekran pokazuje je jako „zmienione”, zamiast zgadywać.
        $this->table('sync_record')
            ->addIndex(['created_run_id'])
            ->update();
    }

    public function down(): void
    {
        $this->table('sync_record')->removeIndex(['created_run_id'])->update();
        $this->table('sync_record')->removeColumn('created_run_id')->update();
    }
}
