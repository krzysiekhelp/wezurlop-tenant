<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bezpieczeństwo kont (docs/szkielet-tenanta.md rozdz. 5).
 * Tokeny wyłącznie jako hash SHA-256; adresy IP wyłącznie jako HMAC (ip_hash).
 */
final class CreateSecurityTables extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function change(): void
    {
        $this->table('backup_code', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('code_hash', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addIndex(['account_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('password_reset_token', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token_hash', 'char', ['null' => false, 'limit' => 64])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('requested_ip_hash', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['account_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('invitation', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token_hash', 'char', ['null' => false, 'limit' => 64])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['account_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('created_by', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('login_attempt', ['id' => false, 'primary_key' => ['id']] + self::OPTIONS)
            ->addColumn('id', 'biginteger', ['null' => false, 'signed' => false, 'identity' => true])
            ->addColumn('account_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('ip_hash', 'char', ['null' => false, 'limit' => 64])
            // password | totp | backup_code | reset
            ->addColumn('kind', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('succeeded', 'boolean', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['account_id', 'created_at'])
            ->addIndex(['ip_hash', 'created_at'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // Dziennik zdarzeń bez kluczy obcych — wpis ma przetrwać usunięcie konta i firmy.
        $this->table('event_log', ['id' => false, 'primary_key' => ['id']] + self::OPTIONS)
            ->addColumn('id', 'biginteger', ['null' => false, 'signed' => false, 'identity' => true])
            ->addColumn('occurred_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('account_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('company_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('actor_label', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('event_code', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('subject_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('ip_hash', 'char', ['limit' => 64, 'null' => true])
            // nigdy hasło, token, sekret TOTP ani kod zapasowy
            ->addColumn('detail', 'json', ['null' => true])
            ->addIndex(['occurred_at'])
            ->addIndex(['account_id', 'occurred_at'])
            ->addIndex(['event_code', 'occurred_at'])
            ->create();

        // Tabela powstaje teraz; funkcja „zapamiętaj to urządzenie” nie jest w tym etapie implementowana.
        $this->table('remembered_device', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('selector', 'char', ['null' => false, 'limit' => 24])
            ->addColumn('validator_hash', 'char', ['null' => false, 'limit' => 64])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['selector'], ['unique' => true])
            ->addIndex(['account_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
