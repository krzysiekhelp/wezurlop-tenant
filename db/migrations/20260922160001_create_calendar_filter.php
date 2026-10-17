<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zapamiętany wybór osób na kalendarzu zespołu (zlecenie `pulpit-pracownika-i-kalendarz` §5).
 *
 * ⚠ **Dlaczego baza, a nie sesja.** Zaznaczenie ma przetrwać wyjście z ekranu, a sesja ma
 * bezczynnościowy limit 30 minut (`CLAUDE.md` §8) — wybór trzymany w sesji znikałby po przerwie
 * na obiad. To jest dokładnie ten przypadek, o którym mówi zlecenie: „przy zespole na dwadzieścia
 * osób wybór klikany od nowa przy każdym wejściu przestanie być używany po tygodniu”.
 *
 * ⚠ **To zapamiętany FILTR, nie nadany dostęp.** Wiersz w tej tabeli nie daje prawa do niczego:
 * przy każdym odczycie lista jest przecinana z aktualnym zasięgiem z hierarchii
 * (`ZasiegPodgladu::przefiltruj()`). Dzięki temu zmiana w hierarchii — przeniesienie kogoś pod
 * innego przełożonego — odbiera podgląd **natychmiast**, a nie „przy następnym czyszczeniu
 * zapamiętanych wyborów”. Bez tej reguły tabela byłaby cichym rejestrem uprawnień, których
 * nikt nie nadał.
 *
 * Klucz główny obejmuje `employment_id`, bo osoba zatrudniona w dwóch spółkach ma **dwa**
 * niezależne zespoły i dwa niezależne wybory — jak wszystko inne w tym module, wybór wisi
 * przy zatrudnieniu, nie przy człowieku.
 */
final class CreateCalendarFilter extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function up(): void
    {
        $this->table('calendar_filter', ['id' => false, 'primary_key' => ['account_id', 'employment_id']] + self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('employment_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('employment_ids', 'json', [
                'null' => false,
                'comment' => 'Zaznaczone zatrudnienia — filtr widoku, nie nadany dostęp',
            ])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        $this->table('calendar_filter')->drop()->save();
    }
}
