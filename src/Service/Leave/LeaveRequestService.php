<?php

declare(strict_types=1);

namespace Wezurlop\Service\Leave;

use DateTimeImmutable;
use InvalidArgumentException;
use Wezurlop\Db\Connection;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\LeaveRequestRepository;
use Wezurlop\Repository\SyncTaskRepository;
use Wezurlop\Service\Calendar\CalendarFactory;

/**
 * Składanie i rozstrzyganie wniosków urlopowych — jedna ścieżka dla interfejsu (tura B) i dla narzędzia
 * przygotowującego dane do przebiegu integracyjnego (`dev/przygotuj-wniosek.php`). STREFA JAWNA.
 *
 * Wniosek powstaje w JEDNEJ transakcji razem z odczytem salda pod blokadą wiersza: dwa równoległe wnioski
 * na te same dni albo na ostatnie minuty limitu nie mogą się prześlizgnąć (docs/audyt.md L6).
 *
 * ⚠ Pełna reguła limitu — wypoczynkowy proporcjonalny do etatu i stażu, cztery dni na żądanie, urlop
 * zaległy, przeniesienie na następny rok — to tura B i strefa kodowana `/src/Core`. Tutaj jest tylko
 * bariera „nie więcej minut, niż zostało w saldzie z kadr” (`assertLimit()`), świadomie prosta.
 * Maszyna stanów jest jedynym źródłem prawdy o tym, co wolno zrobić; znaczniki czasu tylko opisują,
 * kto i kiedy (rozstrzygnięcie 19.09.2026).
 */
final class LeaveRequestService
{
    /**
     * ⚠ Wartość ZAPASOWA, nie reguła: wymiar dnia bierzemy z `normaUrlopowaMin` kalendarza zatrudnienia,
     * a ta stała wchodzi tylko wtedy, gdy kalendarz przyszedł bez normy — i wtedy ma to być widoczne
     * (licznik `kalendarzeBezNormy` w opisie przebiegu, pozycja na diagnostyce), a nie ciche.
     */
    public const FALLBACK_DAY_MINUTES = 480;

    public function __construct(
        private readonly Connection $db,
        private readonly LeaveRequestRepository $requests,
        private readonly CalendarFactory $calendars,
        private readonly SyncTaskRepository $tasks,
        private readonly EventLogRepository $events,
        private readonly CompanyCalendarRepository $companyCalendars,
    ) {
    }

    /**
     * Wniosek pracownika: walidacja, dni robocze z kalendarza firmy, saldo pod blokadą, stan `submitted`.
     *
     * @param string $rodzaj `zrodlo:kod`, np. `urlop:0`
     * @return array{id: int, dni: int, minuty: int, typ: string, zatrudnienie: int}
     */
    public function submit(string $employmentRef, string $rodzaj, string $from, string $to, ?int $accountId = null): array
    {
        [$source, $code] = self::parseType($rodzaj);
        $dateFrom = self::parseDate($from, 'dataOd');
        $dateTo = self::parseDate($to, 'dataDo');
        if ($dateTo < $dateFrom) {
            throw new InvalidArgumentException('Data „do” jest wcześniejsza niż „od”.');
        }
        if ($dateFrom->format('Y') !== $dateTo->format('Y')) {
            // Limit jest roczny, a kadry rozliczają rok osobno — wniosek przez sylwestra dzielimy na dwa.
            throw new InvalidArgumentException('Wniosek nie może przechodzić przez granicę roku — złóż dwa.');
        }
        $employment = $this->requests->employmentByRef($employmentRef);
        if ($employment === null) {
            throw new InvalidArgumentException('Nie znam zatrudnienia ' . $employmentRef . ' — najpierw musi je przysłać connector.');
        }
        if (!$employment['is_active']) {
            throw new InvalidArgumentException('Zatrudnienie ' . $employmentRef . ' nie jest aktywne.');
        }
        if ($employment['terminated_at'] !== null && $to > $employment['terminated_at']) {
            throw new InvalidArgumentException('Termin wykracza poza ostatni dzień pracy (' . $employment['terminated_at'] . ').');
        }
        $type = $this->requests->leaveType($source, $code);
        if ($type === null) {
            throw new InvalidArgumentException('Nie znam rodzaju ' . $rodzaj . ' — słownik przysyła connector.');
        }
        if (!$type['requestable'] || $type['read_only']) {
            throw new InvalidArgumentException('Rodzaj „' . $type['name'] . '” nie jest do wniosku (słownik kadr).');
        }

        $calendar = $this->calendars->forCompany($employment['company_id'], $employment['r2_calendar_ref']);
        $days = array_map(
            static fn (mixed $day): string => $day instanceof DateTimeImmutable ? $day->format('Y-m-d') : (string) $day,
            $calendar->workingDays($dateFrom, $dateTo),
        );
        if ($days === []) {
            throw new InvalidArgumentException('W tym terminie nie ma ani jednego dnia roboczego.');
        }
        $norm = $this->companyCalendars->leaveNormMinutes(
            (int) $employment['company_id'],
            is_string($employment['r2_calendar_ref'] ?? null) ? (string) $employment['r2_calendar_ref'] : null,
            (int) $dateFrom->format('Y'),
        );
        $minutesPerDay = (int) round(($norm ?? self::FALLBACK_DAY_MINUTES) * (float) $employment['fte']);
        $minutes = $minutesPerDay * count($days);
        $warnings = [];
        if ($norm === null) {
            $warnings[] = 'Kalendarz nie podał normy urlopowej — liczba dni policzona wartością zapasową '
                . self::FALLBACK_DAY_MINUTES . ' min na dzień.';
        }
        // ⚠ „domyslne” znaczy: rodzaj nieużywany u tego klienta, powiązanie z limitem jest zachowaniem
        // programu kadrowego, a nie faktem z jego danych. Wniosek przechodzi — nie blokujemy pracownika
        // na podstawie domysłu o konfiguracji programu (rozstrzygnięcie 20.09.2026).
        if ($type['counts_to_limit'] && $type['limit_kind_source'] === 'domyslne') {
            $warnings[] = 'Powiązanie rodzaju „' . $type['name'] . '” z limitem „' . (string) $type['limit_kind']
                . '” nie jest potwierdzone danymi tej firmy — sprawdź saldo w kadrach.';
        }

        return $this->db->transaction(function () use ($employment, $employmentRef, $type, $from, $to, $days, $minutes, $minutesPerDay, $accountId, $dateFrom, $warnings): array {
            $busy = $this->requests->busyDays($employment['id'], $days);
            if ($busy !== []) {
                throw new InvalidArgumentException('Termin zajęty innym wnioskiem: ' . implode(', ', $busy));
            }
            $absence = $this->requests->externalAbsenceKolizja($employment['person_id'], $from, $to);
            if ($absence !== null) {
                throw new InvalidArgumentException('Kolizja z nieobecnością wpisaną w kadrach: ' . $absence);
            }
            if ($type['counts_to_limit']) {
                $this->assertLimit($employment['id'], (int) $dateFrom->format('Y'), $minutes, $minutesPerDay);
            }
            $id = $this->requests->create($employment['id'], $type['id'], $from, $to, $days, $accountId);
            $this->events->record('leave.request_submitted', $accountId, null, $accountId === null ? 'narzedzie' : 'pracownik',
                'leave_request', $id, $employment['company_id'],
                ['zatrudnienieId' => $employmentRef, 'dni' => count($days)] + ($warnings === [] ? [] : ['ostrzezenia' => $warnings]));

            return ['id' => $id, 'dni' => count($days), 'minuty' => $minutes, 'typ' => $type['name'],
                'zatrudnienie' => $employment['id'], 'ostrzezenia' => $warnings];
        });
    }

    /** Decyzja przełożonego: `submitted` → `approved`. Od tego momentu wniosek czeka na zadanie dla kadr. */
    public function approve(int $requestId, ?int $accountId = null, ?string $reason = null): void
    {
        $this->changeState($requestId, 'submitted', 'approved', 'approve', $accountId, $reason, ['decided_at' => 'now']);
    }

    public function reject(int $requestId, ?int $accountId = null, ?string $reason = null): void
    {
        $this->changeState($requestId, 'submitted', 'rejected', 'reject', $accountId, $reason, ['decided_at' => 'now']);
    }

    /**
     * Wniosek już zaakceptowany (być może zapisany w kadrach) — pracownik prosi o anulowanie.
     * ⚠ Samo przestawienie znacznika nie wycofuje urlopu z kadr: dopiero zatwierdzone anulowanie
     * daje zadanie `usuniecie` dla connectora (docs/api-connector.md 3.4).
     */
    public function requestCancel(int $requestId, ?int $accountId = null, ?string $reason = null): void
    {
        $request = $this->requests->find($requestId);
        $from = $request['status'] ?? '';
        if (!in_array($from, ['approved', 'confirmed', 'sync_failed'], true)) {
            throw new InvalidArgumentException('Wniosku w stanie „' . $from . '” nie anuluje się tą drogą.');
        }
        $this->changeState($requestId, $from, 'cancel_requested', 'cancel_request', $accountId, $reason, ['cancel_requested_at' => 'now']);
    }

    /**
     * Zatwierdzenie anulowania przez przełożonego. Dwie ścieżki, rozstrzygane **w tej samej transakcji**,
     * w której zmienia się stan wniosku (rozstrzygnięcie 20.09.2026):
     *
     * - wniosek jest (albo może być) w kadrach → zostaje `cancel_requested` ze znacznikiem zatwierdzenia,
     *   a connector dostanie zadanie `usuniecie` (dopiero po potwierdzonym zapisie — niezmiennik
     *   „zapis i usuniecie nigdy w jednym cyklu”, docs/api-connector.md 3.5),
     * - wniosek nie ma żadnego wpisu R2 i żadnego wydanego zapisu → zamykamy go lokalnie (`cancelled`),
     *   bez zadania. Polecenie skasowania czegoś, czego nigdy nie było, wygląda u klienta jak błąd.
     *
     * @return string stan końcowy wniosku
     */
    public function approveCancel(int $requestId, ?int $accountId = null, ?string $reason = null): string
    {
        return $this->db->transaction(function () use ($requestId, $accountId, $reason): string {
            $inR2 = $this->tasks->existsInR2($requestId);
            $to = $inR2 ? 'cancel_requested' : 'cancelled';
            $timestamps = $inR2 ? ['cancel_approved_at' => 'now'] : ['cancel_approved_at' => 'now', 'cancelled_at' => 'now'];
            if (!$this->requests->changeStatus($requestId, 'cancel_requested', $to, 'cancel_approve', $accountId, $reason, $timestamps)) {
                throw new InvalidArgumentException('Wniosek #' . $requestId . ' nie czeka na zatwierdzenie anulowania.');
            }
            // Którą ścieżką poszło anulowanie — w logu zdarzeń, bo to pierwsze pytanie przy diagnozie.
            $this->events->record('leave.cancel_approved', $accountId, null, $accountId === null ? 'narzedzie' : 'przelozony',
                'leave_request', $requestId, null, ['sciezka' => $inR2 ? 'usuniecie_w_kadrach' : 'zamkniete_lokalnie']);

            return $to;
        });
    }

    /**
     * ⚠ MIEJSCE NA REGUŁĘ LIMITU — tura B, strefa kodowana `/src/Core` (to samo miejsce co
     * `Service\Sync\TaskService::verifyLimit()`). Tu jest wyłącznie bariera arytmetyczna na saldzie z kadr:
     * pozostało minus to, co już zajęte wnioskami bez potwierdzenia. Bez niej narzędzie do przebiegu
     * integracyjnego produkowałoby wnioski ponad limit, których aplikacja nigdy by nie wypuściła.
     */
    private function assertLimit(int $employmentId, int $year, int $minutes, int $dayMinutes): void
    {
        $balance = $this->requests->balanceForUpdate($employmentId, $year);
        if ($balance === null || !$balance['accrued'] || $balance['remaining_min'] === null) {
            throw new InvalidArgumentException('Limit na rok ' . $year . ' nie jest naliczony w kadrach — wniosku nie przyjmujemy.');
        }
        $reserved = $this->requests->reservedMinutes($employmentId, $year, $dayMinutes);
        $available = $balance['remaining_min'] - $reserved;
        if ($minutes > $available) {
            throw new InvalidArgumentException(sprintf(
                'Limit nie wystarcza: wniosek %d min, dostępne %d min (pozostało %d, zajęte wnioskami %d).',
                $minutes, max(0, $available), $balance['remaining_min'], $reserved,
            ));
        }
    }

    /** @param array<string, string> $timestamps */
    private function changeState(int $requestId, string $from, string $to, string $decision, ?int $accountId, ?string $reason, array $timestamps): void
    {
        $this->db->transaction(function () use ($requestId, $from, $to, $decision, $accountId, $reason, $timestamps): void {
            if (!$this->requests->changeStatus($requestId, $from, $to, $decision, $accountId, $reason, $timestamps)) {
                throw new InvalidArgumentException('Wniosek #' . $requestId . ' nie jest w stanie „' . $from . '” — stan się zmienił.');
            }
            $this->events->record('leave.' . $decision, $accountId, null, $accountId === null ? 'narzedzie' : 'przelozony',
                'leave_request', $requestId, null, ['z' => $from, 'na' => $to]);
        });
    }

    /** @return array{0: string, 1: int} */
    private static function parseType(string $rodzaj): array
    {
        if (preg_match('/^([a-z]{1,16}):(-?\d{1,6})$/', $rodzaj, $m) !== 1) {
            throw new InvalidArgumentException('Rodzaj podaje się jako „zrodlo:kod”, np. urlop:0.');
        }

        return [$m[1], (int) $m[2]];
    }

    private static function parseDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Pole ' . $field . ': data w formacie RRRR-MM-DD.');
        }

        return $date;
    }
}
