<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Czas nakładki przy wymianie klucza connectora (docs/api-connector.md §2).
 *
 * ⚠ Było 30 dni — czyli miesiąc, w którym DWA connectory mogą pracować na jednej firmie.
 * Przy takiej długości ktoś zapomni wyłączyć starą maszynę; to nie podejrzenie, tylko statystyka
 * wdrożeń. Nakładka ma być oknem przesiadki, nie stanem, w którym instalacja potrafi żyć miesiąc.
 * Domyślnie 24 godziny, administrator może podnieść najwyżej do 7 dni (168 godzin).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddKeyOverlapHours extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_overlap_hours', '24', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_overlap_hours'");
    }
}
