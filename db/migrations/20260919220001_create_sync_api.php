<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Etap 3, E5–E6 — API dla connectora (docs/api-connector.md).
 *
 * - api_key: klucz instalacji connectora, w bazie WYŁĄCZNIE jako SHA-256 (64 losowe znaki hex — entropia
 *   klucza robi to, co przy hasłach robi koszt funkcji), z listą dozwolonych NIP-ów (api_key_company),
 * - company_signup: zgłoszenia firm spoza zakresu — firmy nie zakładamy automatycznie,
 * - sync_stream / sync_cursor: kursor = nieprzezroczysty token potwierdzenia, per firma i zbiór,
 * - sync_record: właściciel jednoznaczności (company, zbiór, id) → suma, stan, rekord lokalny;
 *   rekordy oczekujące (wskazują na coś, czego jeszcze nie ma) z licznikiem i regułą wygaszania,
 * - sync_review_seen: identyfikatory z przeglądu kontrolnego w obrębie jednego przebiegu,
 * - sync_idempotency: odpowiedź zapamiętana per X-Idempotency-Key,
 * - sync_task: zadania zapisu/usunięcia w R2 (requestId, rezerwacja 15 min),
 * - api_rate_counter: osobny limit częstotliwości dla klucza, niezależny od limitów logowania.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4 — phinx potrafi przestawić kolejność w update()).
 */
final class CreateSyncApi extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function up(): void
    {
        $this->table('api_key', self::OPTIONS)
            ->addColumn('name', 'string', ['null' => false, 'limit' => 100])
            ->addColumn('key_hash', 'char', ['null' => false, 'limit' => 64])
            // pierwsze znaki klucza — wyłącznie do rozpoznania na liście, nie do uwierzytelnienia
            ->addColumn('key_prefix', 'char', ['null' => false, 'limit' => 8])
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16])          // active | revoked
            // wymieniony klucz działa do expires_at (nakładka 30 dni), potem 401 KLUCZ_WYGASL
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addColumn('replaced_by_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_by_account_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addIndex(['key_hash'], ['unique' => true])
            ->addForeignKey('created_by_account_id', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('api_key_company', ['id' => false, 'primary_key' => ['api_key_id', 'nip']] + self::OPTIONS)
            ->addColumn('api_key_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('nip', 'string', ['null' => false, 'limit' => 10])
            ->addColumn('added_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('api_key_id', 'api_key', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('company_signup', self::OPTIONS)
            ->addColumn('nip', 'string', ['null' => false, 'limit' => 10])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 200])
            ->addColumn('api_key_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16])          // pending | approved | rejected
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('decided_at', 'datetime', ['null' => true])
            ->addColumn('decided_by_account_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['nip'], ['unique' => true])
            ->addForeignKey('api_key_id', 'api_key', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_stream', ['id' => false, 'primary_key' => ['company_id']] + self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('cursor_seq', 'integer', ['null' => false, 'signed' => false, 'default' => 0])
            // przegląd kontrolny wymagany w następnym przebiegu (po pełnym ładowaniu, odtworzeniu bazy, na żądanie)
            ->addColumn('review_required', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('last_review_at', 'datetime', ['null' => true])
            // stan connectora z ostatniego `start` — do strony diagnostycznej
            ->addColumn('connector', 'json', ['null' => true])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_cursor', ['id' => false, 'primary_key' => ['company_id', 'zbior']] + self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('zbior', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('cursor_token', 'string', ['null' => false, 'limit' => 32])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_record', ['id' => false, 'primary_key' => ['company_id', 'zbior', 'record_id']] + self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('zbior', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('record_id', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('checksum', 'string', ['null' => true, 'limit' => 71])           // sha256:<64 hex>
            // active | deleted | out_of_window | pending | orphaned
            ->addColumn('state', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('local_id', 'integer', ['null' => true, 'signed' => false])
            // rekord nadrzędny w R2 (pracownikId dla zatrudnienia, zatrudnienieId dla limitu i nieobecności)
            ->addColumn('parent_ref', 'string', ['null' => true, 'limit' => 64])
            // rekord oczekujący: przefiltrowana treść (tylko pola z config/sync-schema.php), licznik prób
            ->addColumn('payload', 'json', ['null' => true])
            ->addColumn('pending_reason', 'string', ['null' => true, 'limit' => 200])
            ->addColumn('pending_attempts', 'integer', ['null' => false, 'signed' => false, 'default' => 0])
            ->addColumn('pending_since', 'datetime', ['null' => true])
            ->addColumn('updated_run_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['company_id', 'zbior', 'parent_ref'])
            ->addIndex(['company_id', 'state'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_review_seen', ['id' => false, 'primary_key' => ['sync_run_id', 'zbior', 'record_id']] + self::OPTIONS)
            ->addColumn('sync_run_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('zbior', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('record_id', 'string', ['null' => false, 'limit' => 64])
            ->addForeignKey('sync_run_id', 'sync_run', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_idempotency', ['id' => false, 'primary_key' => ['api_key_id', 'idem_key']] + self::OPTIONS)
            ->addColumn('api_key_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('idem_key', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('operation', 'string', ['null' => false, 'limit' => 24])
            ->addColumn('response_status', 'smallinteger', ['null' => false, 'signed' => false])
            ->addColumn('response_body', 'text', ['null' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['created_at'])
            ->addForeignKey('api_key_id', 'api_key', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_task', self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('leave_request_id', 'integer', ['null' => false, 'signed' => false])
            // requestId: maks. 32 znaki ASCII [A-Za-z0-9-] (ograniczenie programu kadrowego)
            ->addColumn('request_id', 'string', ['null' => false, 'limit' => 32])
            ->addColumn('operation', 'string', ['null' => false, 'limit' => 16])       // zapis | usuniecie
            // pending | issued | done | withdrawn | failed | cancelled
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('attempts', 'integer', ['null' => false, 'signed' => false, 'default' => 0])
            ->addColumn('reserved_until', 'datetime', ['null' => true])
            ->addColumn('issued_run_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('result_status', 'string', ['null' => true, 'limit' => 24])
            ->addColumn('result_code', 'string', ['null' => true, 'limit' => 64])
            ->addColumn('result_message', 'string', ['null' => true, 'limit' => 500])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addIndex(['request_id'], ['unique' => true])
            ->addIndex(['company_id', 'status'])
            ->addIndex(['leave_request_id'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('api_rate_counter', ['id' => false, 'primary_key' => ['api_key_id', 'bucket', 'window_start']] + self::OPTIONS)
            ->addColumn('api_key_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('bucket', 'string', ['null' => false, 'limit' => 16])         // api | signup
            ->addColumn('window_start', 'datetime', ['null' => false])
            ->addColumn('hits', 'integer', ['null' => false, 'signed' => false, 'default' => 0])
            ->addForeignKey('api_key_id', 'api_key', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_run')->addForeignKey('api_key_id', 'api_key', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])->update();

        $this->table('tenant_setting')->insert([
            // przegląd kontrolny, gdy ostatni kompletny jest starszy niż tyle godzin
            ['key' => 'sync_review_hours', 'value' => '1'],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'sync_review_hours'");
        $this->table('sync_run')->dropForeignKey('api_key_id')->update();
        foreach (['api_rate_counter', 'sync_task', 'sync_idempotency', 'sync_review_seen', 'sync_record', 'sync_cursor',
                  'sync_stream', 'company_signup', 'api_key_company', 'api_key'] as $table) {
            $this->table($table)->drop()->save();
        }
    }
}
