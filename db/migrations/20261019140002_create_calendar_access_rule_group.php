<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Grupy po stronie **CZYJE NIEOBECNOŚCI** — tabela łącząca regułę z wybranymi grupami
 * (zlecenie Z53 §1).
 *
 * ⚠ Osobna tabela, a nie lista w kolumnie: wybór jest wielokrotny, a lista w napisie znaczyłaby,
 * że „które reguły dotyczą grupy 7" trzeba liczyć `LIKE`-iem po tekście — czyli zapytaniem,
 * które nie użyje indeksu i przy okazji trafi grupę 70.
 *
 * ⚠ **Klucz obcy JEST na `rule_id` i nie ma go na `group_id`** — i to jest ta sama decyzja,
 * co w tabeli reguł. Usunięcie reguły ma zabrać jej grupy i tyle (kaskada jest tu poprawna),
 * ale usunięcie GRUPY jest zdarzeniem, o którym zlecenie każe zapisać w dzienniku: grupa wypada
 * z listy, a gdy była ostatnia — reguła znika. Kaskada bazy zrobiłaby jedno i drugie po cichu,
 * a administrator dowiedziałby się o utracie dostępu dopiero od pracownika.
 *
 * ⚠ `UNIQUE (rule_id, group_id)` jest po to, żeby ta sama grupa nie weszła do jednej reguły
 * dwa razy — inaczej „wybrano N · X osób" w stopce okna liczyłoby ją podwójnie, a to pierwszy
 * krok do dwóch liczb jednej rzeczy.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class CreateCalendarAccessRuleGroup extends AbstractMigration
{
    public function up(): void
    {
        $this->table('calendar_access_rule_group', ['id' => false, 'primary_key' => ['rule_id', 'group_id']])
            ->addColumn('rule_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('group_id', 'integer', ['signed' => false, 'null' => false])
            ->create();

        $this->table('calendar_access_rule_group')
            ->addIndex(['group_id'], ['name' => 'idx_carg_group'])
            ->update();

        $this->table('calendar_access_rule_group')
            ->addForeignKey('rule_id', 'calendar_access_rule', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('calendar_access_rule_group')->drop()->save();
    }
}
