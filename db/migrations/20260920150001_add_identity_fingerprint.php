<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Odcisk sekretu tożsamości connectora (`odciskTozsamosci` w `start`) — bezpiecznik przed
 * kompletem duplikatów osób. ⚠ Sekret, z którego connector liczy `osobaId`, mieszka w jego
 * konfiguracji i przenosiny same z siebie go nie zachowują; gdy przepadnie, ci sami ludzie
 * przychodzą z nowymi identyfikatorami. `start` jest jedynym miejscem, w którym da się to złapać
 * ZANIM powstanie szkoda — dlatego pamiętamy odcisk per firma.
 *
 * `identity_fingerprint` — odcisk zaakceptowany, `identity_fingerprint_pending` — odcisk, który
 * przyszedł i czeka na jawną decyzję administratora (bez cichego nadpisania).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddIdentityFingerprint extends AbstractMigration
{
    public function up(): void
    {
        $this->table('company')
            ->addColumn('identity_fingerprint', 'char', ['null' => true, 'limit' => 8, 'after' => 'is_active'])
            ->update();
        $this->table('company')
            ->addColumn('identity_fingerprint_pending', 'char', ['null' => true, 'limit' => 8, 'after' => 'identity_fingerprint'])
            ->update();
        $this->table('company')
            ->addColumn('identity_fingerprint_at', 'datetime', ['null' => true, 'after' => 'identity_fingerprint_pending'])
            ->update();
    }

    public function down(): void
    {
        $this->table('company')->removeColumn('identity_fingerprint_at')->update();
        $this->table('company')->removeColumn('identity_fingerprint_pending')->update();
        $this->table('company')->removeColumn('identity_fingerprint')->update();
    }
}
