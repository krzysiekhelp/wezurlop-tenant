<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use LogicException;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\SyncTaskRepository;

/**
 * Faza 2–4 cyklu (docs/api-connector.md 3.4–3.5, docs/etap3-logika-urlopowa.md rozdz. 2). STREFA JAWNA.
 *
 * - weryfikacja przed wydaniem na świeżych danych: aktywne zatrudnienie, data zwolnienia, kolizja
 *   z nieobecnością zewnętrzną OSOBY (we wszystkich firmach),
 * - wydanie z rezerwacją 15 minut, najwyżej jedno zadanie na wniosek,
 * - wyniki: ⚠ decyduje `status`, `kod` jest do wyświetlenia; `zapisy` to faktyczny stan w kadrach,
 *   dni i minuty z WSZYSTKICH pozycji; powtórzony wynik nie zmienia stanu.
 */
final class TaskService
{
    public const RESERVATION_MINUTES = 15;
    /** Po tylu błędach przejściowych zadanie nie wraca już samo — czeka na człowieka. */
    public const MAX_TRANSIENT_ATTEMPTS = 5;

    public const JOB_HR_ALERT = 'mail.hr_alert';
    public const JOB_HR_ALERT_URGENT = 'mail.hr_alert_urgent';

    private const STATUSES = ['zapisano', 'zapisanoZOstrzezeniem', 'blad', 'bladPrzejsciowy', 'wycofano'];

    /** Twarde ograniczenie programu kadrowego (docs/api-connector.md): długość i alfabet requestId. */
    public const REQUEST_ID_MAX = 32;
    public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9-]{1,32}$/';

    public function __construct(
        private readonly SyncTaskRepository $tasks,
        private readonly SyncRepository $sync,
        private readonly EventLogRepository $events,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * Weryfikacja i założenie zadań dla wniosków czekających na zapis albo wycofanie z kadr.
     *
     * @return array{utworzone: int, konflikty: int}
     */
    public function prepare(int $companyId, int $runId): array
    {
        $created = 0;
        $conflicts = 0;
        foreach ($this->tasks->candidates($companyId) as $c) {
            $problem = $c['operation'] === 'zapis' ? $this->verify($c) : null;
            if ($problem !== null) {
                $this->tasks->setRequestStatus($c['id'], 'approved', 'conflict', $problem, ['r2_error_code' => null, 'r2_error_text' => $problem]);
                $this->sync->conflict($companyId, $runId, $c['id'], 'verification', $problem);
                $this->alert(false, 'weryfikacja', $c['id']);
                $conflicts++;
                continue;
            }
            $this->tasks->createTask($companyId, $c['id'], self::newRequestId(), $c['operation']);
            $created++;
        }

        return ['utworzone' => $created, 'konflikty' => $conflicts];
    }

    /**
     * @param array{id: int, operation: string, employment_id: int, person_id: int, date_from: string, date_to: string, is_active: bool, terminated_at: ?string} $c
     */
    private function verify(array $c): ?string
    {
        if (!$c['is_active']) {
            return 'Zatrudnienie nie jest już aktywne w kadrach';
        }
        if ($c['terminated_at'] !== null && $c['date_to'] > $c['terminated_at']) {
            return 'Termin wniosku wykracza poza ostatni dzień pracy (' . $c['terminated_at'] . ')';
        }
        $kolizja = $this->tasks->externalKolizja($c['person_id'], $c['date_from'], $c['date_to']);
        if ($kolizja !== null) {
            return 'Kolizja z nieobecnością wpisaną w kadrach: ' . $kolizja;
        }

        return $this->verifyLimit($c);
    }

    /**
     * ⚠ MIEJSCE NA SPRAWDZENIE LIMITU — tura B (E2), reguła w strefie kodowanej /src/Core.
     * W tej turze świadomie puste: program kadrowy limitu wypoczynkowego NIE blokuje (zapisuje
     * z ostrzeżeniem), a barierą przed wysłaniem jest już rezerwacja przy składaniu wniosku
     * (`LeaveRequestService::assertLimit()` z `reservedMinutes()`) — sprawdzana w chwili składania,
     * nie przy wydawaniu zadania, więc zmiana limitu w kadrach po synchronizacji tutaj nie zadziała.
     *
     * @param array<string, mixed> $c
     */
    private function verifyLimit(array $c): ?string
    {
        return null;
    }

    /**
     * @return list<array{requestId: string, operacja: string, zatrudnienieId: string, rodzaj: array{zrodlo: string, kod: int}, dataOd: string, dataDo: string, zapisy: list<array{id: string}>}>
     */
    public function issue(int $companyId, int $runId, int $limit): array
    {
        $out = [];
        $issuedFor = [];
        foreach ($this->tasks->issuable($companyId, $limit * 2) as $t) {
            if (count($out) >= $limit || isset($issuedFor[$t['leave_request_id']]) || $t['employment_ref'] === null) {
                continue;
            }
            // Wniosek wycofany w międzyczasie — zadania zapisu już nie wydajemy.
            if ($t['operation'] === 'zapis' && $t['request_status'] !== 'approved' && $t['request_status'] !== 'sync_failed') {
                $this->tasks->finishTask($t['id'], 'withdrawn', 'wycofano', null, 'wniosek wycofany przed wydaniem');
                continue;
            }
            $this->tasks->reserve($t['id'], $runId, self::RESERVATION_MINUTES);
            $issuedFor[$t['leave_request_id']] = true;
            $out[] = [
                'requestId'      => $t['request_id'],
                'operacja'       => $t['operation'],
                'zatrudnienieId' => $t['employment_ref'],
                'rodzaj'         => ['zrodlo' => $t['source'], 'kod' => $t['code']],
                'dataOd'         => $t['date_from'],
                'dataDo'         => $t['date_to'],
                'zapisy'         => $t['operation'] === 'usuniecie' ? $this->tasks->activeR2Records($t['leave_request_id']) : [],
            ];
        }

        return $out;
    }

    /**
     * @param array{requestId: string, status: string, kod: ?string, komunikat: ?string, zapisy: list<array{id: string, dataOd: string, dataDo: string, dni: ?int, minuty: ?int}>} $w
     * @return string|null wynik do statystyk; null = nieznany requestId (pominięty)
     */
    public function applyResult(int $companyId, int $runId, array $w): ?string
    {
        $task = $this->tasks->byRequestId($companyId, $w['requestId']);
        if ($task === null) {
            return null;
        }
        // Powtórzony wynik (connector ponawia, aż dostanie odpowiedź) — bez zmiany stanu.
        if (in_array($task['status'], ['done', 'withdrawn', 'failed', 'cancelled'], true)) {
            return 'powtorzony';
        }
        $requestId = $task['leave_request_id'];
        $from = $task['request_status'];
        $code = $w['kod'];
        $message = $w['komunikat'];

        switch ($w['status']) {
            case 'zapisano':
            case 'zapisanoZOstrzezeniem':
                $this->tasks->replaceR2Records($requestId, $w['zapisy']);
                $totals = $this->tasks->confirmedTotals($requestId);
                if ($task['operation'] === 'usuniecie') {
                    $this->tasks->setRequestStatus($requestId, $from, 'cancelled_confirmed', 'wpis wycofany z kadr');
                } elseif (in_array($from, ['cancelled', 'rejected'], true)) {
                    // Wniosek wycofany, a zapis już się odbył — dokładamy zadanie usunięcia.
                    $this->tasks->setRequestStatus($requestId, $from, 'cancel_requested', 'zapis w kadrach po wycofaniu wniosku');
                    $this->tasks->createTask($companyId, $requestId, self::newRequestId(), 'usuniecie');
                } else {
                    $warning = $w['status'] === 'zapisanoZOstrzezeniem';
                    $this->tasks->setRequestStatus($requestId, $from, 'confirmed', null, [
                        'days_confirmed' => $totals['days'], 'minutes_confirmed' => $totals['minutes'],
                        'r2_warning_code' => $warning ? $code : null, 'r2_warning_text' => $warning ? $message : null,
                    ]);
                    if ($warning) {
                        $this->events->record('leave.r2_warning', null, null, 'sync', 'leave_request', $requestId, $companyId, ['kod' => $code]);
                        $this->alert(false, 'ostrzezenie', $requestId, $code);
                    }
                }
                $this->tasks->finishTask($task['id'], 'done', $w['status'], $code, $message);
                break;

            case 'blad':
                // ⚠ `zapisy` przy błędzie NIE są realizacją wniosku (rozstrzygnięcie 20.09.2026): nie wiążemy
                // ich z wnioskiem i nie liczymy z nich dni ani minut. To stan kadr, który blokuje termin —
                // idzie wyłącznie do treści konfliktu, dla człowieka, który będzie to rozplątywał.
                $blocking = array_map(static fn (array $z): array => [
                    'id' => $z['id'], 'dataOd' => $z['dataOd'], 'dataDo' => $z['dataDo'],
                ], array_slice($w['zapisy'], 0, 50));
                $this->tasks->setRequestStatus($requestId, $from, 'conflict', $message, ['r2_error_code' => $code, 'r2_error_text' => $message]);
                // ⚠ ZMIANA_CZESCIOWA: wniosek bez żadnego wpisu w kadrach — alert o najwyższym priorytecie.
                // To jedyny kod rozpoznawany z nazwy, i tylko po to, żeby podnieść priorytet powiadomienia;
                // zachowanie (conflict) wynika ze statusu `blad`.
                $urgent = $code === 'ZMIANA_CZESCIOWA';
                $this->sync->conflict($companyId, $runId, $requestId, $urgent ? 'zmiana_czesciowa' : 'r2_error', (string) ($message ?? 'Błąd zapisu w kadrach'),
                    ['kod' => $code, 'blokujaceZapisy' => $blocking]);
                $this->alert($urgent, $urgent ? 'zmiana_czesciowa' : 'blad', $requestId, $code);
                $this->tasks->finishTask($task['id'], 'failed', 'blad', $code, $message);
                break;

            case 'bladPrzejsciowy':
                $attempts = $this->tasks->retryTask($task['id'], $code, $message);
                $this->tasks->setRequestStatus($requestId, $from, 'sync_failed', $message, ['r2_error_code' => $code, 'r2_error_text' => $message]);
                if ($attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
                    // Automat ponawia tylko ograniczoną liczbę razy — dalej decyduje człowiek.
                    $this->tasks->finishTask($task['id'], 'failed', 'bladPrzejsciowy', $code, $message);
                    $this->sync->conflict($companyId, $runId, $requestId, 'sync_failed', 'Zapis w kadrach nieudany po ' . $attempts . ' próbach');
                    $this->alert(false, 'blad', $requestId, $code);
                }
                break;

            case 'wycofano':
                $this->tasks->finishTask($task['id'], 'withdrawn', 'wycofano', $code, $message);
                // Zadanie zapisu nie zostało wykonane, a anulowanie jest zatwierdzone i nie ma nic w kadrach
                // → wniosek domykamy lokalnie (rozstrzygnięcie 20.09.2026), bez zadania `usuniecie`.
                if ($from === 'cancel_requested' && !$this->tasks->existsInR2($requestId)) {
                    $this->tasks->setRequestStatus($requestId, $from, 'cancelled', 'anulowanie bez wpisu w kadrach', ['cancelled_at' => 'now']);
                    $this->events->record('leave.cancel_closed_locally', null, null, 'sync', 'leave_request', $requestId, $companyId);
                }
                break;
        }

        return $w['status'];
    }

    public static function isKnownStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * requestId: 26 losowych znaków base32 (130 bitów) + prefiks `wu-`, razem 29 znaków.
     * ⚠ Limit 32 znaków i alfabet [A-Za-z0-9-] to twarde ograniczenie programu kadrowego, więc jest
     * wymuszony w generatorze, nie tylko opisany: wynik niezgodny z REQUEST_ID_PATTERN jest błędem
     * programistycznym i nie może wyjść na zewnątrz.
     */
    public static function newRequestId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = 'wu-';
        for ($i = 0; $i < 26; $i++) {
            $id .= $alphabet[random_int(0, 31)];
        }
        if (strlen($id) > self::REQUEST_ID_MAX || preg_match(self::REQUEST_ID_PATTERN, $id) !== 1) {
            throw new LogicException('Wygenerowany requestId nie mieści się w ograniczeniach programu kadrowego');
        }

        return $id;
    }

    public static function isValidRequestId(string $requestId): bool
    {
        return strlen($requestId) <= self::REQUEST_ID_MAX && preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }

    /** Alert do kadr przez kolejkę — bez danych osobowych w treści (numer wniosku i kod). */
    private function alert(bool $urgent, string $kind, int $leaveRequestId, ?string $code = null): void
    {
        $this->jobs->enqueue($urgent ? self::JOB_HR_ALERT_URGENT : self::JOB_HR_ALERT, [
            'kind' => $kind, 'leave_request_id' => $leaveRequestId, 'kod' => $code,
        ]);
    }
}
