<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Rozkłady czasu pracy z R2 (zbiór `kalendarz`), per firma, kalendarz i rok.
 */
final class CompanyCalendarRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, list<string>> rok => dni nierobocze */
    public function nonWorkingDaysByYear(int $companyId, string $calendarRef): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT year, non_working_days FROM company_calendar WHERE company_id = :company AND calendar_ref = :ref'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $calendarRef]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $days = json_decode((string) $row['non_working_days'], true);
            $result[(int) $row['year']] = is_array($days) ? array_values(array_filter($days, 'is_string')) : [];
        }

        return $result;
    }

    /**
     * Norma urlopowa kalendarza przypisanego do zatrudnienia — dzielnik minut na dni.
     * `null` znaczy „kalendarz nie przyszedł z normą”; wartość zapasową wybiera wołający,
     * żeby fakt jej użycia dało się policzyć i pokazać, a nie tylko po cichu zastosować.
     */
    public function leaveNormMinutes(int $companyId, ?string $calendarRef, int $year): ?int
    {
        if ($calendarRef === null || $calendarRef === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT leave_norm_min FROM company_calendar
             WHERE company_id = :company AND calendar_ref = :ref AND leave_norm_min IS NOT NULL
             ORDER BY (year = :year) DESC, year DESC LIMIT 1'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $calendarRef, 'year' => $year]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /** Kalendarze, które przyszły bez normy urlopowej — pozycja na diagnostyce, nie cicha wartość zapasowa. */
    public function countWithoutNorm(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM company_calendar WHERE leave_norm_min IS NULL')->fetchColumn();
    }

    /** @param list<string> $nonWorkingDays */
    public function upsert(int $companyId, string $calendarRef, int $year, string $name, bool $individual, array $nonWorkingDays,
        ?int $leaveNormMin = null, ?int $dailyNormMin = null): void
    {
        $params = [
            'company' => $companyId, 'ref' => $calendarRef, 'year' => $year, 'name' => mb_substr($name, 0, 100),
            'individual' => $individual ? 1 : 0, 'days' => json_encode(array_values($nonWorkingDays), JSON_THROW_ON_ERROR),
            'leave_norm' => $leaveNormMin, 'daily_norm' => $dailyNormMin,
        ];
        $update = $this->db->pdo()->prepare(
            'UPDATE company_calendar SET name = :name, individual = :individual, non_working_days = :days,
                    leave_norm_min = :leave_norm, daily_norm_min = :daily_norm, fetched_at = UTC_TIMESTAMP()
             WHERE company_id = :company AND calendar_ref = :ref AND year = :year'
        );
        $update->execute($params);
        if ($update->rowCount() === 0) {
            $insert = $this->db->pdo()->prepare(
                'INSERT IGNORE INTO company_calendar (company_id, calendar_ref, year, name, individual, non_working_days,
                        leave_norm_min, daily_norm_min, fetched_at)
                 VALUES (:company, :ref, :year, :name, :individual, :days, :leave_norm, :daily_norm, UTC_TIMESTAMP())'
            );
            $insert->execute($params);
        }
    }

    public function delete(int $companyId, string $calendarRef, int $year): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM company_calendar WHERE company_id = :company AND calendar_ref = :ref AND year = :year');
        $stmt->execute(['company' => $companyId, 'ref' => $calendarRef, 'year' => $year]);
    }
}
