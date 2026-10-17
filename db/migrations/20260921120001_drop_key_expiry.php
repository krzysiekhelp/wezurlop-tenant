<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Termin ważności klucza instalacji znika (rozstrzygnięcie 20.09.2026, `docs/api-connector.md`).
 *
 * ⚠ Licznik miał dokładnie jeden efekt praktyczny: gdy nowy klucz nie został wklejony do connectora
 * (literówka, nikt nie wszedł na maszynę, serwer padł), po dobie zabijał **działający** stary klucz
 * i zatrzymywał synchronizację kadr. Gdy nowy klucz działał, licznik i tak nie miał nic do roboty,
 * bo stary ginie sam przy pierwszym zakończonym przebiegu nowego.
 *
 * Zostaje `replaced_by_id` — wskazanie następcy, po którym rozpoznajemy przejęcie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class DropKeyExpiry extends AbstractMigration
{
    public function up(): void
    {
        $this->table('api_key')->removeColumn('expires_at')->update();
    }

    public function down(): void
    {
        $this->table('api_key')
            ->addColumn('expires_at', 'datetime', ['null' => true, 'after' => 'status'])
            ->update();
    }
}
