<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Etap 3, E1 — model danych logiki urlopowej (docs/etap3-logika-urlopowa.md rozdz. 11).
 *
 * Zmiany w tabelach z Etapu 2 (rozstrzygnięcia z 19.09):
 * - company.tax_id → nip (klucz mapowania firm; identyfikator firmy z R2 nie jest unikalny),
 *   company.external_id usunięte — przy okazji znika UNIQUE na kolumnie z NULL (CLAUDE.md §4),
 * - employment.external_id → r2_employment_ref (urlopy w R2 wiszą pod zatrudnieniem), ZWYKŁY indeks;
 *   dawny UNIQUE(company_id, external_id) stał na kolumnie z NULL (to samo naruszenie §4),
 *   + supervisor_employment_id, fte DECIMAL(6,4), r2_calendar_ref,
 * - person.external_person_id (osobaId = HMAC z PESEL-u liczony przez connector), zwykły indeks;
 *   jednoznaczność pilnuje person_external_ref (bez NULL-i).
 *
 * Migracja jest odwracalna: rollback wraca do stanu sprzed zmiany nazw.
 */
final class CreateLeaveDomain extends AbstractMigration
{
    private const OPTIONS = ['engine' => 'InnoDB', 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => false];

    public function up(): void
    {
        // --- tabele z Etapu 2 -----------------------------------------------------------------
        // Osobne kroki: phinx nie gwarantuje kolejności operacji w jednym update().
        $this->table('company')->removeIndexByName('external_id')->update();
        $this->table('company')->removeColumn('external_id')->update();
        $this->table('company')->renameColumn('tax_id', 'nip')->update();
        $this->table('company')
            // NIP firmy: 10 cyfr, klucz mapowania — NOT NULL, więc UNIQUE działa (CLAUDE.md §4)
            ->changeColumn('nip', 'string', ['limit' => 10, 'null' => false])
            ->addIndex(['nip'], ['unique' => true, 'name' => 'uq_company_nip'])
            ->update();

        $this->table('employment')->dropForeignKey('company_id')->update();
        $this->table('employment')->removeIndexByName('company_id')->update();
        $this->table('employment')->renameColumn('external_id', 'r2_employment_ref')->update();
        $this->table('employment')
            // identyfikator zatrudnienia w R2 (zmienia się przy ponownym zatrudnieniu); NULL = spoza R2
            ->changeColumn('r2_employment_ref', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('supervisor_employment_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'company_id'])
            // wymiar etatu z R2 (0.6667); NULL gdy R2 nie ma umowy przypiętej do zatrudnienia
            ->addColumn('fte', 'decimal', ['null' => true, 'precision' => 6, 'scale' => 4, 'after' => 'fte_denominator'])
            ->addColumn('r2_calendar_ref', 'string', ['null' => true, 'limit' => 32, 'after' => 'fte'])
            // zatrudnienie wypadło z zakresu synchronizacji (koniec umowy o pracę) — nie usunięte
            ->addColumn('deactivated_at', 'datetime', ['null' => true, 'after' => 'is_active'])
            ->addIndex(['company_id', 'r2_employment_ref'], ['name' => 'idx_employment_r2_ref'])
            ->addIndex(['supervisor_employment_id'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('supervisor_employment_id', 'employment', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->update();

        $this->table('person')
            // osobaId z connectora (HMAC liczony u klienta; PESEL nie dociera do nas w żadnej postaci)
            ->addColumn('external_person_id', 'string', ['null' => true, 'limit' => 128, 'after' => 'phone'])
            ->addIndex(['external_person_id'], ['name' => 'idx_person_external_id'])
            ->update();

        // Jeden osobaId wskazuje dokładnie jedną osobę. Osoby bez osobaId nie mają tu wiersza.
        $this->table('person_external_ref', ['id' => false, 'primary_key' => ['external_person_id']] + self::OPTIONS)
            ->addColumn('external_person_id', 'string', ['null' => false, 'limit' => 128])
            ->addColumn('person_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['person_id'], ['unique' => true])
            ->addForeignKey('person_id', 'person', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // --- typy nieobecności ------------------------------------------------------------------
        // ⚠ klucz złożony source + code: kody powtarzają się między trzema tabelami R2.
        $this->table('leave_type', self::OPTIONS)
            ->addColumn('source', 'string', ['null' => false, 'limit' => 16])          // urlop | kadrowe | zasilek
            ->addColumn('code', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 200])            // ze słownika klienta
            ->addColumn('requestable', 'boolean', ['null' => false, 'default' => false]) // doWniosku
            ->addColumn('read_only', 'boolean', ['null' => false, 'default' => true])    // tylkoOdczyt
            ->addColumn('is_absence', 'boolean', ['null' => false, 'default' => true])   // nieobecnosc (ekwiwalenty: false)
            ->addColumn('counts_to_limit', 'boolean', ['null' => false, 'default' => false])
            // próg eskalacji w godzinach; NULL = domyślny z tenant_setting
            ->addColumn('escalation_hours', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['source', 'code'], ['unique' => true])
            ->create();

        // --- wnioski ------------------------------------------------------------------------------
        $this->table('leave_request', self::OPTIONS)
            ->addColumn('employment_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('leave_type_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('date_from', 'date', ['null' => false])
            ->addColumn('date_to', 'date', ['null' => false])
            // prognoza z naszego kalendarza; wiążące są dni i minuty z potwierdzenia R2
            ->addColumn('days_requested', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('days_confirmed', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('minutes_confirmed', 'integer', ['null' => true, 'signed' => false])
            // draft | submitted | escalated | approved | confirmed | rejected | cancelled |
            // cancel_requested | cancelled_confirmed | conflict | sync_failed
            ->addColumn('status', 'string', ['null' => false, 'limit' => 24])
            ->addColumn('created_by_account_id', 'integer', ['null' => true, 'signed' => false])
            // ostrzeżenie R2 przy zapisie („zapisano z ostrzeżeniem”) — do wyświetlenia kadrom
            ->addColumn('r2_warning_code', 'string', ['null' => true, 'limit' => 64])
            ->addColumn('r2_warning_text', 'string', ['null' => true, 'limit' => 500])
            // ostatni błąd zapisu / powód konfliktu (kod z connectora tylko do wyświetlenia)
            ->addColumn('r2_error_code', 'string', ['null' => true, 'limit' => 64])
            ->addColumn('r2_error_text', 'string', ['null' => true, 'limit' => 500])
            // kadry zmieniły nasz wpis w R2 (daty/dni) — pracownik ma to zobaczyć
            ->addColumn('changed_in_r2', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('submitted_at', 'datetime', ['null' => true])
            ->addColumn('decided_at', 'datetime', ['null' => true])
            ->addColumn('confirmed_at', 'datetime', ['null' => true])
            ->addColumn('cancel_requested_at', 'datetime', ['null' => true])
            // anulowanie zatwierdzone przez przełożonego — czeka na wycofanie wpisu z R2
            ->addColumn('cancel_approved_at', 'datetime', ['null' => true])
            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['employment_id', 'status'])
            ->addIndex(['status'])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('leave_type_id', 'leave_type', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_account_id', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        // Rozbicie na dni — kolizje i kalendarz bez liczenia zakresów w locie.
        $this->table('leave_request_day', ['id' => false, 'primary_key' => ['leave_request_id', 'day']] + self::OPTIONS)
            ->addColumn('leave_request_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('employment_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('day', 'date', ['null' => false])
            ->addIndex(['employment_id', 'day'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        // ⚠ LISTA zapisów w R2 dla jednego wniosku — przełom miesiąca dzieli urlop na kilka wpisów.
        $this->table('leave_request_r2_record', self::OPTIONS)
            ->addColumn('leave_request_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('r2_record_id', 'string', ['null' => false, 'limit' => 64])   // np. urlop:12330
            ->addColumn('date_from', 'date', ['null' => false])
            ->addColumn('date_to', 'date', ['null' => false])
            ->addColumn('days', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('minutes', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('removed_at', 'datetime', ['null' => true])                   // zniknął z R2
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['leave_request_id', 'r2_record_id'], ['unique' => true])
            ->addIndex(['r2_record_id'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // Pełna historia decyzji, nie ostatni stan.
        $this->table('leave_decision', self::OPTIONS)
            ->addColumn('leave_request_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('decided_by_account_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('decision', 'string', ['null' => false, 'limit' => 24])
            ->addColumn('from_status', 'string', ['null' => false, 'limit' => 24])
            ->addColumn('to_status', 'string', ['null' => false, 'limit' => 24])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('decided_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['leave_request_id'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('decided_by_account_id', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('leave_comment', self::OPTIONS)
            ->addColumn('leave_request_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('account_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['leave_request_id'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('account_id', 'account', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        // --- saldo z R2 ---------------------------------------------------------------------------
        // ⚠ w MINUTACH jako wartości wiążącej; dni = minuty ÷ 480 wyłącznie do wyświetlenia (także przy
        // niepełnym etacie — tak liczy program kadrowy). Wartości mogą być ujemne (korekty, przekroczenie).
        $this->table('leave_balance', self::OPTIONS)
            ->addColumn('employment_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('year', 'smallinteger', ['null' => false, 'signed' => false])
            ->addColumn('kind', 'string', ['null' => false, 'limit' => 24])          // wypoczynkowy | opieka_art188
            ->addColumn('accrued', 'boolean', ['null' => false, 'default' => false]) // naliczony
            ->addColumn('entitlement_min', 'integer', ['null' => true, 'signed' => true])      // wymiarMin
            ->addColumn('carried_over_min', 'integer', ['null' => true, 'signed' => true])     // zaleglyMin
            ->addColumn('available_start_min', 'integer', ['null' => true, 'signed' => true])  // dostepneNaStartMin
            ->addColumn('used_min', 'integer', ['null' => true, 'signed' => true])             // wykorzystaneMin
            ->addColumn('adjustments_min', 'integer', ['null' => true, 'signed' => true])      // korektyMin
            ->addColumn('remaining_min', 'integer', ['null' => true, 'signed' => true])        // pozostaloMin
            ->addColumn('on_demand_used_days', 'integer', ['null' => true, 'signed' => false]) // naZadanieWykorzystaneDni
            // limit z roku, który wypadł z okna synchronizacji — historia, nie konflikt
            ->addColumn('out_of_window', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['employment_id', 'year', 'kind'], ['unique' => true])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        // --- nieobecności z R2, których nie wnioskujemy -------------------------------------------
        // ⚠ tylko do odczytu: jedyną drogą zmiany jest R2. Bez kodów chorobowych i danych medycznych.
        $this->table('external_absence', self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('employment_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('person_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('leave_type_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('r2_record_id', 'string', ['null' => false, 'limit' => 64])   // urlop:… / kadrowe:… / zasilek:…
            ->addColumn('date_from', 'date', ['null' => false])
            ->addColumn('date_to', 'date', ['null' => false])
            ->addColumn('days', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('minutes', 'integer', ['null' => true, 'signed' => false])     // zawsze NULL przy zasilek
            ->addColumn('continuation', 'boolean', ['null' => true])                  // zasiłek: kolejny rekord tego samego L4
            ->addColumn('entry_source', 'string', ['null' => false, 'limit' => 16])   // connector | kadry
            ->addColumn('r2_created_at', 'datetime', ['null' => true])
            ->addColumn('r2_modified_at', 'datetime', ['null' => true])
            // rok wypadł z okna synchronizacji — zostaje w historii, bez konfliktu
            ->addColumn('out_of_window', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('removed_at', 'datetime', ['null' => true])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['company_id', 'r2_record_id'], ['unique' => true])
            ->addIndex(['person_id', 'date_from'])
            ->addIndex(['employment_id', 'date_from'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('person_id', 'person', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('leave_type_id', 'leave_type', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        // --- kalendarz z R2 -----------------------------------------------------------------------
        // Rozkład z R2 per kalendarz i rok (nadrzędny wobec naszego). Dni tygodnia i wyjątki firmowe —
        // tenant_setting; święta — liczone algorytmicznie (Service\Calendar), nie tablicą dat.
        $this->table('company_calendar', self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('calendar_ref', 'string', ['null' => false, 'limit' => 32])   // kalendarzId z R2
            ->addColumn('year', 'smallinteger', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 100])
            ->addColumn('individual', 'boolean', ['null' => false, 'default' => false]) // harmonogram indywidualny możliwy
            ->addColumn('non_working_days', 'json', ['null' => false])
            ->addColumn('fetched_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['company_id', 'calendar_ref', 'year'], ['unique' => true])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // --- synchronizacja -----------------------------------------------------------------------
        $this->table('sync_run', self::OPTIONS)
            ->addColumn('run_uuid', 'char', ['null' => false, 'limit' => 36])          // przebiegId
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('api_key_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'string', ['null' => false, 'limit' => 16])          // open | closed | abandoned
            ->addColumn('phase', 'string', ['null' => false, 'limit' => 16])           // start | dane | przeglad | zadania | wyniki | koniec
            ->addColumn('connector_version', 'string', ['null' => true, 'limit' => 32])
            ->addColumn('r2_version', 'string', ['null' => true, 'limit' => 32])
            ->addColumn('connector_state', 'string', ['null' => true, 'limit' => 24])
            ->addColumn('connector_state_note', 'string', ['null' => true, 'limit' => 200])
            ->addColumn('last_success_cycle', 'string', ['null' => true, 'limit' => 40])
            // liczniki: przyjęte/usunięte/poza oknem/oczekujące per zbiór, pominięte pola, zadania, wyniki, konflikty
            ->addColumn('stats', 'json', ['null' => false])
            ->addColumn('connector_summary', 'json', ['null' => true])                  // treść `koniec` od connectora
            ->addColumn('started_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addIndex(['run_uuid'], ['unique' => true])
            ->addIndex(['company_id', 'started_at'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('sync_conflict', self::OPTIONS)
            ->addColumn('company_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('sync_run_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('leave_request_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('kind', 'string', ['null' => false, 'limit' => 40])
            ->addColumn('reason', 'string', ['null' => false, 'limit' => 500])
            ->addColumn('detail', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by_account_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['company_id', 'resolved_at'])
            ->addIndex(['leave_request_id'])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('sync_run_id', 'sync_run', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->addForeignKey('leave_request_id', 'leave_request', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        // Ustawienia kalendarza: dni robocze (ISO: 1 = poniedziałek) i wyjątki firmowe
        // ({"0": {"2026-11-10": false}, "<company_id>": {...}}; false = dzień wolny, true = roboczy).
        $this->table('tenant_setting')->insert([
            ['key' => 'calendar_workdays',   'value' => '1,2,3,4,5'],
            ['key' => 'calendar_exceptions', 'value' => '{}'],
            ['key' => 'calendar_source',     'value' => 'own'],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` IN ('calendar_workdays', 'calendar_exceptions', 'calendar_source')");
        foreach (['sync_conflict', 'sync_run', 'company_calendar', 'external_absence', 'leave_balance', 'leave_comment',
                  'leave_decision', 'leave_request_r2_record', 'leave_request_day', 'leave_request', 'leave_type',
                  'person_external_ref'] as $table) {
            $this->table($table)->drop()->save();
        }

        $this->table('person')
            ->removeIndexByName('idx_person_external_id')
            ->removeColumn('external_person_id')
            ->update();

        $this->table('employment')->dropForeignKey('supervisor_employment_id')->update();
        $this->table('employment')->dropForeignKey('company_id')->update();
        $this->table('employment')->removeIndexByName('idx_employment_r2_ref')->update();
        $this->table('employment')->removeIndexByName('supervisor_employment_id')->update();
        $this->table('employment')
            ->removeColumn('supervisor_employment_id')
            ->removeColumn('fte')
            ->removeColumn('r2_calendar_ref')
            ->removeColumn('deactivated_at')
            ->update();
        $this->table('employment')->renameColumn('r2_employment_ref', 'external_id')->update();
        $this->table('employment')
            ->addIndex(['company_id', 'external_id'], ['unique' => true])
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->update();

        $this->table('company')->removeIndexByName('uq_company_nip')->update();
        $this->table('company')->renameColumn('nip', 'tax_id')->update();
        $this->table('company')
            ->changeColumn('tax_id', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => true, 'after' => 'tax_id'])
            ->addIndex(['external_id'], ['unique' => true])
            ->update();
    }
}
