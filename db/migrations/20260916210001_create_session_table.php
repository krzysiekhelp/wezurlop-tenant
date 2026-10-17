<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Sesje w bazie (docs/szkielet-tenanta.md rozdz. 5, „Sesje w bazie”) i domyślne progi alertu
 * o podejrzeniu password spraying (rozdz. 6).
 *
 * Identyfikator sesji wyłącznie jako hash SHA-256 — odczyt tabeli nie daje gotowego ciasteczka.
 * ip_prefix: adres zanonimizowany (/24, /48) wyłącznie do listy „Twoje urządzenia”.
 */
final class CreateSessionTable extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    private const SETTINGS = [
        ['key' => 'login_alert_threshold',      'value' => '50'],
        ['key' => 'login_alert_window_minutes', 'value' => '15'],
        // ostatni wysłany alert — pozwala wysłać najwyżej jeden na godzinę
        ['key' => 'login_alert_last_at',        'value' => '1970-01-01 00:00:00'],
        ['key' => 'alert_email',                'value' => ''],
    ];

    public function up(): void
    {
        $this->table('session', ['id' => false, 'primary_key' => ['id']] + self::OPTIONS)
            ->addColumn('id', 'char', ['null' => false, 'limit' => 64])
            ->addColumn('account_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('employment_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('payload', 'blob', ['null' => false, 'limit' => MysqlAdapter::BLOB_MEDIUM])
            ->addColumn('ip_hash', 'char', ['null' => true, 'limit' => 64])
            ->addColumn('ip_prefix', 'string', ['null' => true, 'limit' => 45])
            ->addColumn('user_agent', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_seen_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addIndex(['account_id', 'last_seen_at'])
            ->addIndex(['expires_at'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('tenant_setting')->insert(self::SETTINGS)->saveData();
    }

    public function down(): void
    {
        $this->table('session')->drop()->save();
        $keys = array_map(static fn (array $s): string => $s['key'], self::SETTINGS);
        $this->getQueryBuilder('delete')->delete('tenant_setting')->whereInList('key', $keys)->execute();
    }
}
