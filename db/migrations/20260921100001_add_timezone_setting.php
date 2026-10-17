<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Strefa czasowa prezentacji (docs/system-wizualny.md §10 punkt 2).
 *
 * Czas w bazie zostaje w UTC — to ustawienie mówi wyłącznie, w jakiej strefie pokazujemy go
 * użytkownikowi. ⚠ Zapisujemy NAZWĘ strefy, nigdy przesunięcia: przesunięcie zmienia się dwa razy
 * w roku, więc wartość „+02:00” byłaby błędna od ostatniej niedzieli października.
 *
 * Domyślnie `Europe/Warsaw`; instalator podpowiada wartość z PHP, jeśli to prawdziwa nazwa strefy.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddTimezoneSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('timezone', 'Europe/Warsaw', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'timezone'");
    }
}
