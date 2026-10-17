<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\GroupRepository;

/**
 * Grupy — operacje i **dziennik zdarzeń** (zlecenie `2026-10-08-grupy-dzialy.md` §3,
 * zmiana modelu 10.10.2026: jedno zatrudnienie należy do wielu grup).
 * Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ Wpisy zgodne z regułą z tury porządkowej (`EventLogRepository::record()`): wpis opisujący
 * **zmianę** niesie wartość przed i po. Dlatego `group.renamed` ma `{z, na}`,
 * a `group.members_changed` — `{dodani, usunieci}`, czyli kto doszedł i kto wyszedł.
 *
 * ⚠ **`przeniesieni` zniknęło z treści wpisu i to jest skutek zmiany modelu, nie uproszczenie.**
 * Przeniesienie istniało tylko dlatego, że jedna grupa na zatrudnienie znaczyła „dodanie tutaj
 * = usunięcie tam". Przy wielu grupach dodanie nic nie odbiera, więc pole, które zawsze byłoby
 * puste, kazałoby czytającemu szukać zdarzenia, którego nie ma. ⚠ **Stare wpisy zostają
 * nietknięte** — `przeniesieni` w zapisach z 08–10.10.2026 dalej opisuje to, co się wtedy
 * stało, i nadal czyta się bez zmian (`/admin/dziennik` renderuje treść wpisu, nie schemat).
 *
 * ⚠ **Jedna akcja człowieka = jeden wpis** (zlecenie 10.10.2026 §3). Przy zapisie
 * natychmiastowym jedno kliknięcie `+` to jeden wpis z jednym nazwiskiem, a „Dodaj wszystkich
 * pokazanych" — jeden wpis z całą listą. Trzydzieści kliknięć daje trzydzieści wpisów i tak ma
 * być: dziennik mówi, co się stało, a nie co ktoś zamierzał. ⚠ Po „Cofnij" stoją **dwa** wpisy
 * — dodanie i wypisanie — bo cofnięcie jest żądaniem w drugą stronę, nie wymazaniem historii.
 *
 * ⚠ **Nazwiska wolno tu zapisać** — rozstrzygnięcie z 08.10.2026: dziennik może nieść to, co
 * czytający go widzi i tak na ekranie pod tym samym uprawnieniem. Nazwiska pracowników stoją
 * na `/admin/konta`, czyli za tym samym uprawnieniem administratora tenanta.
 */
final class GrupyService
{
    public function __construct(
        private readonly GroupRepository $grupy,
        private readonly EventLogRepository $events,
    ) {
    }

    /** @return array{id: int}|string */
    public function utworz(int $companyId, string $nazwa, ?int $accountId, ?string $aktor): array|string
    {
        $wynik = $this->grupy->utworz($companyId, $nazwa, $accountId);
        if (is_string($wynik)) {
            return $wynik;
        }
        $this->events->record('group.created', $accountId, null, $aktor, 'company_group',
            $wynik['id'], $companyId, ['nazwa' => $nazwa]);

        return $wynik;
    }

    public function zmienNazwe(int $groupId, int $companyId, string $nazwa, ?int $accountId, ?string $aktor): ?string
    {
        // ⚠ Stara nazwa odczytana PRZED zapisem — inaczej wpis nie ma „z czego".
        $przed = $this->grupy->find($groupId, $companyId);
        if ($przed === null) {
            return GroupRepository::POWOD_BRAK;
        }
        $powod = $this->grupy->zmienNazwe($groupId, $companyId, $nazwa);
        if ($powod !== null) {
            return $powod;
        }
        $this->events->record('group.renamed', $accountId, null, $aktor, 'company_group',
            $groupId, $companyId, ['z' => $przed['nazwa'], 'na' => $nazwa]);

        return null;
    }

    public function usun(int $groupId, int $companyId, ?int $accountId, ?string $aktor): ?string
    {
        $przed = $this->grupy->find($groupId, $companyId);
        $powod = $this->grupy->usun($groupId, $companyId);
        if ($powod !== null) {
            return $powod;
        }
        // Nazwa w treści, bo po usunięciu nie ma jej skąd wziąć — wiersz już nie istnieje.
        $this->events->record('group.deleted', $accountId, null, $aktor, 'company_group',
            $groupId, $companyId, ['nazwa' => $przed['nazwa'] ?? '—']);

        return null;
    }

    /**
     * Przypisanie osób do grupy; zwraca to, co się faktycznie stało.
     *
     * @param list<int> $employmentIds
     * @return array{dodani: list<int>, pominieci: list<int>}
     */
    public function przypisz(int $groupId, int $companyId, array $employmentIds, ?int $accountId, ?string $aktor): array
    {
        $nazwy = $this->grupy->przynaleznosci($companyId, $employmentIds);
        $wynik = $this->grupy->przypisz($groupId, $companyId, $employmentIds, $accountId);
        if ($wynik['dodani'] === []) {
            return $wynik;
        }
        $this->events->record('group.members_changed', $accountId, null, $aktor, 'company_group',
            $groupId, $companyId, [
                'dodani' => array_map(
                    static fn (int $id): string => $nazwy[$id]['osoba'] ?? ('zatrudnienie #' . $id),
                    $wynik['dodani'],
                ),
                'usunieci' => [],
            ]);

        return $wynik;
    }

    /**
     * Wypisanie osób **z tej jednej grupy**; zwraca to, co się faktycznie stało.
     *
     * ⚠ Nazwiska odczytane PRZED zapisem — po nim wypisanego nie ma już w grupie, więc wpis
     * w dzienniku musiałby mówić „zatrudnienie #130" zamiast nazwiska.
     *
     * @param list<int> $employmentIds
     * @return array{usunieci: list<int>}
     */
    public function wypisz(int $groupId, int $companyId, array $employmentIds, ?int $accountId, ?string $aktor): array
    {
        $nazwy = $this->grupy->przynaleznosci($companyId, $employmentIds);
        $usunieci = $this->grupy->usunZGrupy($groupId, $companyId, $employmentIds);
        if ($usunieci === []) {
            return ['usunieci' => []];
        }
        $this->events->record('group.members_changed', $accountId, null, $aktor, 'company_group',
            $groupId, $companyId, [
                'dodani' => [],
                'usunieci' => array_map(
                    static fn (int $id): string => $nazwy[$id]['osoba'] ?? ('zatrudnienie #' . $id),
                    $usunieci,
                ),
            ]);

        return ['usunieci' => $usunieci];
    }
}
