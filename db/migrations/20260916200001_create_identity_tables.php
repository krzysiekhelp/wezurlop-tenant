<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Model tożsamości: osoba ≠ zatrudnienie (docs/szkielet-tenanta.md rozdz. 5).
 * Konto logowania należy do osoby, zatrudnienie łączy osobę z firmą.
 */
final class CreateIdentityTables extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function change(): void
    {
        $this->table('company', self::OPTIONS)
            ->addColumn('name', 'string', ['null' => false, 'limit' => 200])
            ->addColumn('tax_id', 'string', ['limit' => 20, 'null' => true])
            // identyfikator firmy w programie kadrowym; NULL = firma niepowiązana (wiele NULL dozwolone)
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['external_id'], ['unique' => true])
            ->create();

        $this->table('person', self::OPTIONS)
            ->addColumn('first_name', 'string', ['null' => false, 'limit' => 100])
            ->addColumn('last_name', 'string', ['null' => false, 'limit' => 100])
            ->addColumn('email_private', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('employment', self::OPTIONS)
            ->addColumn('person_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            // identyfikator z programu kadrowego — na zatrudnieniu, w każdej firmie inny
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('employee_number', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('hired_at', 'date', ['null' => true])
            ->addColumn('terminated_at', 'date', ['null' => true])
            ->addColumn('fte_numerator', 'smallinteger', ['null' => false, 'signed' => false, 'default' => 1])
            ->addColumn('fte_denominator', 'smallinteger', ['null' => false, 'signed' => false, 'default' => 1])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['company_id', 'external_id'], ['unique' => true])
            ->addIndex(['person_id'])
            ->addForeignKey('person_id', 'person', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('account', self::OPTIONS)
            ->addColumn('person_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('email', 'string', ['null' => false, 'limit' => 254])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            // invited | active | blocked
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16, 'default' => 'invited'])
            // sekret TOTP zaszyfrowany kluczem APP_KEY (nonce + szyfrogram, base64)
            ->addColumn('totp_secret_enc', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('totp_enabled_at', 'datetime', ['null' => true])
            ->addColumn('totp_last_window', 'biginteger', ['signed' => false, 'null' => true])
            // mikrosekundy: sesja założona w tej samej sekundzie co zmiana hasła rozstrzygana jednoznacznie
            ->addColumn('password_changed_at', 'datetime', ['limit' => 6, 'null' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['person_id'], ['unique' => true])
            ->addForeignKey('person_id', 'person', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('account_identity', self::OPTIONS)
            ->addColumn('account_id', 'integer', ['null' => false, 'signed' => false])
            // google | apple
            ->addColumn('provider', 'string', ['null' => false, 'limit' => 16])
            // tożsamością jest `sub` od dostawcy, nie adres e-mail
            ->addColumn('subject', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('linked_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['provider', 'subject'], ['unique' => true])
            ->addIndex(['account_id'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
