<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;
use Wezurlop\Db\Connection;

/**
 * Zapamiętane **wyłączenia** na kalendarzu — para (konto, SPÓŁKA). Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ Tabela trzyma to, co użytkownik **odznaczył**, a nie to, co wybrał — i to jest różnica
 * funkcjonalna, nie nazewnicza: domyślnie widać wszystkich w zasięgu, więc pracownik przyjęty
 * jutro pojawia się na kalendarzu **sam**, bez niczyjej pamięci o nim. Lista włączeń starzałaby
 * się po cichu i nikt by tego nie zauważył (zlecenie `kalendarz-zespolu-domyslny-wybor` §1.3).
 *
 * ⚠ Zapisane wyłączenia **nie są poświadczeniem w żadną stronę**: o tym, kogo wolno pokazać,
 * decyduje `ZasiegPodgladu` przy każdym żądaniu. Wyłączenie osoby, która wypadła z gałęzi,
 * zostaje w tabeli i po prostu nic nie znaczy.
 */
final class CalendarFilterRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<int> odznaczone zatrudnienia; `[]` gdy nic nie zapisano (czyli „widać wszystkich”)
     *
     * ⚠ Klucz to **spółka**, nie zatrudnienie: kalendarz spółki oglądają też konta bez zatrudnienia
     * (administrator z nadanym poziomem), a filtr jest odpowiedzią na „co chcę widzieć, patrząc
     * na tę spółkę".
     */
    public function wykluczenia(int $accountId, int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT excluded_ids FROM calendar_filter WHERE account_id = :account AND company_id = :company'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId]);
        $value = $stmt->fetchColumn();
        $ids = is_string($value) ? json_decode($value, true) : null;

        return is_array($ids)
            ? array_values(array_map('intval', array_filter($ids, static fn (mixed $v): bool => is_int($v) || is_string($v))))
            : [];
    }

    /**
     * Zapis wyłączeń. Pusta lista znaczy „nic nie ukrywam” i zapisujemy ją tak samo jak każdą
     * inną — wiersz z pustą tablicą to jawne „ten człowiek widział pełną listę i jej nie zawężał”,
     * a brak wiersza to „nigdy tu nie był”. Dla widoku oba znaczą to samo, ale przy diagnozie
     * u klienta różnica bywa całą odpowiedzią.
     *
     * @param list<int> $excludedIds
     */
    public function zapisz(int $accountId, int $companyId, array $excludedIds): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO calendar_filter (account_id, company_id, excluded_ids, updated_at)
             VALUES (:account, :company, :ids, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE excluded_ids = :ids2, updated_at = UTC_TIMESTAMP()'
        );
        // ⚠ Wartość podana drugi raz, a nie `VALUES(excluded_ids)`: ta funkcja jest w MySQL 8.0.20
        // przestarzała, a zamiennik (alias wiersza `AS new`) nie działa na MariaDB. Ten zapis
        // działa na obu silnikach (CLAUDE.md §0: bez składni specyficznej dla jednego).
        $json = json_encode(array_values($excludedIds), JSON_THROW_ON_ERROR);
        $stmt->bindValue('account', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('company', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('ids', $json);
        $stmt->bindValue('ids2', $json);
        $stmt->execute();
    }
}
