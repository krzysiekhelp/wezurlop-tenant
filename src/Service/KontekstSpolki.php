<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Entity\EmploymentContext;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\AuthSession;

/**
 * **W jakiej spółce jestem — jedno miejsce z odpowiedzią** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1).
 * Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ Powstało, bo tę samą odpowiedź budowały cztery ekrany na trzy różne sposoby: kalendarz
 * z `activeForAccount` + poziomów widoczności, grupy i hierarchia z `hierarchia->spolki()`
 * i `hasTenantWideRole`, a konta ze zwykłego `<select>`. Przy znaczniku w belce, który stoi
 * na **każdym** ekranie, piąty sposób byłby pewny.
 *
 * ⚠ **To NIE jest nowy stan.** Kontekst sesji zostaje tam, gdzie był (`AuthSession`), a ta klasa
 * tylko go rozstrzyga i waliduje. Zmiana wobec 07.10 jest jedna i wymuszona pomiarem:
 * **kontekst ma dwa kształty**, bo konto bez zatrudnienia nie ma czym przełączać.
 *   - konto z zatrudnieniem → kontekstem jest **zatrudnienie** (`POST /kontekst/{employmentId}`),
 *     dokładnie jak dotąd: przełączenie zmienia pulpit, limity i wnioski,
 *   - konto **bez ani jednego zatrudnienia**, ale z rolą tenantową albo nadanym poziomem
 *     widoczności → kontekstem jest **spółka** (`POST /kontekst/spolka/{companyId}`), bo nie ma
 *     zatrudnienia, którym można by ją wskazać.
 * ⚠ Zmierzone 10.10.2026: **wszystkie trzy konta z rolą `tenant_admin` mają zero zatrudnień**
 * (`accessibleForAccount: []`) — w tym konto Krzysztofa. Bez drugiego kształtu znacznik na
 * dziewięciu ekranach administracyjnych nie miałby czym przełączać, a `?spolka=` miało zniknąć.
 * To jeden stan w dwóch postaciach, nie trzeci stan obok kontekstu (§1 zabrania trzeciego).
 *
 * ⚠ **Przy każdym żądaniu sprawdzamy od nowa** (§10 reguł): lista spółek pochodzi z uprawnień
 * czytanych z bazy w tym żądaniu, nigdy z sesji. Zapamiętana spółka, do której konto straciło
 * dostęp, **nie jest pokazywana** — wracamy do pierwszej dozwolonej i mówimy o tym zdaniem
 * (`fallback`), zamiast po cichu.
 */
final class KontekstSpolki
{
    /**
     * Lista spółek policzona w TYM żądaniu, kluczowana identyfikatorem konta.
     *
     * @var array<int, list<array{id: int, nazwa: string, zatrudnienie_id: ?int}>>
     */
    private array $zapamietane = [];

    /**
     * Odpowiedź kolumny `account.context_company_id` w tym żądaniu (także `null`).
     *
     * @var array<int, ?int>
     */
    private array $zKonta = [];

    public function __construct(
        private readonly EmploymentRepository $employments,
        private readonly PermissionRepository $permissions,
        private readonly CalendarVisibility $widocznosc,
        private readonly AuthSession $session,
        private readonly AccountRepository $konta,
    ) {
    }

    /**
     * Spółki, które to konto może zobaczyć — **z identyfikatorem zatrudnienia, gdy je tam ma**.
     *
     * ⚠ Identyfikator zatrudnienia rozstrzyga o znaczeniu pozycji (rozstrzygnięcie 07.10, §3):
     * spółka z etatem przełącza **kontekst pracy**, spółka bez etatu — sam **widok**. Dwa
     * rodzaje muszą być widoczne jako dwa rodzaje, więc ta informacja jedzie do szablonu.
     *
     * ⚠ **Dwa zapytania na żądanie, nie więcej** (warunek z §1): jedno o zatrudnienia konta,
     * jedno o spółki z nadanym poziomem widoczności. Trzecie — o wszystkie spółki — leci
     * **wyłącznie** dla konta z rolą tenantową, bo tylko ono widzi spółki, w których nie ma
     * ani etatu, ani nadanego poziomu.
     *
     * @return list<array{id: int, nazwa: string, zatrudnienie_id: ?int}>
     */
    public function spolki(int $accountId): array
    {
        // ⚠ **Pamiętane w obrębie żądania.** Jedna instancja obsługuje znacznik w belce
        // **i** kontroler ekranu, a oba pytają o tę samą listę — bez tego konto z rolą
        // tenantową płaciło za nią dwa razy (zmierzone licznikiem `Questions`: 8 zapytań
        // zamiast 4 na ekranie spółkowym). Lista nie może się zmienić w trakcie jednego
        // żądania: uprawnienia nadaje osobny `POST`, który kończy się przekierowaniem.
        if (isset($this->zapamietane[$accountId])) {
            return $this->zapamietane[$accountId];
        }
        $nazwy = [];
        $etatWSpolce = [];
        foreach ($this->employments->activeForAccount($accountId) as $etat) {
            $id = (int) $etat['company_id'];
            $nazwy[$id] = (string) $etat['company_name'];
            $etatWSpolce[$id] ??= (int) $etat['id'];
        }
        foreach ($this->widocznosc->spolkiZPoziomem($accountId) as $s) {
            $nazwy[(int) $s['id']] ??= (string) $s['nazwa'];
        }
        if ($this->permissions->hasTenantWideRole($accountId)) {
            foreach ($this->permissions->wszystkieSpolki() as $s) {
                $nazwy[(int) $s['id']] ??= (string) $s['nazwa'];
            }
        }
        // Kolejność po nazwie wyświetlanej — ta sama reguła co w pozostałych wykazach spółek.
        uasort($nazwy, static fn (string $a, string $b): int => strcoll($a, $b));
        $lista = [];
        foreach ($nazwy as $id => $nazwa) {
            $lista[] = ['id' => $id, 'nazwa' => $nazwa, 'zatrudnienie_id' => $etatWSpolce[$id] ?? null];
        }

        return $this->zapamietane[$accountId] = $lista;
    }

    /**
     * Spółka bieżącego żądania, lista do wyboru i informacja, czy trzeba było wrócić do innej.
     *
     * ⚠ Kolejność rozstrzygania jest jednoznaczna i **nie zależy od adresu**:
     *   1. **zapamiętana spółka** (sesja, a gdy milczy — kolumna na koncie), jeśli nadal jest
     *      na liście dozwolonych,
     *   2. spółka kontekstu zatrudnienia (sesja, zwalidowana przez middleware),
     *   3. pierwsza dozwolona spółka — i wtedy `fallback` mówi, że pokazaliśmy co innego,
     *      niż konto ostatnio wybrało.
     *
     * ⚠ **Zapamiętana spółka idzie PRZED kontekstem zatrudnienia** — i to jest poprawka
     * zmierzona 10.10.2026, nie kwestia gustu. Przy odwrotnej kolejności konto z etatem
     * w spółce A i **nadanym podglądem** w spółce B nie było w stanie przestawić znacznika
     * na B: wybór zapisywał się w sesji i na koncie, a rozstrzygnięcie i tak wracało do spółki
     * etatu. Zmierzone na koncie próbnym (`dev/probka-spolki.php`): po `POST /kontekst/spolka/4`
     * znacznik dalej pokazywał „Piaskarz". A to jest dokładnie ten scenariusz, który od 23.09
     * obsługiwał `?spolka=` na kalendarzu — czyli ten, który ta zmiana miała zastąpić.
     * ⚠ Nie rozjeżdża to kontekstu pracy: `POST /kontekst/{employmentId}` zapisuje spółkę
     * tego zatrudnienia **w tym samym żądaniu** (sesja + konto), więc po przełączeniu
     * zatrudnienia obie wartości mówią to samo.
     *
     * @return array{spolka: array{id: int, nazwa: string, zatrudnienie_id: ?int}|null,
     *     spolki: list<array{id: int, nazwa: string, zatrudnienie_id: ?int}>, fallback: bool}
     */
    public function rozstrzygnij(int $accountId, ?EmploymentContext $etat): array
    {
        $spolki = $this->spolki($accountId);
        if ($spolki === []) {
            return ['spolka' => null, 'spolki' => [], 'fallback' => false];
        }
        $poId = [];
        foreach ($spolki as $s) {
            $poId[$s['id']] = $s;
        }
        // ⚠ Sesja, a gdy jej nie ma — **kolumna na koncie**: kontekst ma przeżyć wylogowanie,
        // a sesja ginie razem z nim. Jedna wartość, dwa miejsca przechowywania: szybkie
        // (sesja) i trwałe (konto). Odczyt z konta tylko wtedy, gdy sesja milczy — czyli raz,
        // po zalogowaniu.
        $zapamietana = $this->session->companyId() ?? $this->zapamietanaZKonta($accountId);
        if ($zapamietana !== null && isset($poId[$zapamietana])) {
            // Przepisanie do sesji, żeby następne żądania nie pytały o to konta.
            $this->session->setCompanyId($zapamietana);

            return ['spolka' => $poId[$zapamietana], 'spolki' => $spolki, 'fallback' => false];
        }
        if ($etat !== null && isset($poId[$etat->companyId])) {
            // ⚠ `fallback` **tak**, gdy coś było zapamiętane i wypadło z listy: człowiek wybrał
            // spółkę B, widzi spółkę etatu A i musi wiedzieć, dlaczego.
            return ['spolka' => $poId[$etat->companyId], 'spolki' => $spolki,
                'fallback' => $zapamietana !== null];
        }

        return [
            'spolka' => $spolki[0],
            'spolki' => $spolki,
            // ⚠ `fallback` **tylko wtedy, gdy coś było zapamiętane**: pierwsze wejście konta
            // bez wyboru to nie powrót z zakazanej spółki i nie ma o czym mówić zdaniem.
            'fallback' => $zapamietana !== null,
        ];
    }

    /**
     * Spółka dla **ekranu spółkowego**, z honorowaniem starego `?spolka=` przez jedno żądanie.
     *
     * ⚠ `?spolka=` **znika z adresów generowanych przez aplikację** (rozstrzygnięcie 10.10.2026
     * §1: dwa źródła jednej prawdy to ta sama rodzina, którą wyplewiliśmy z liczników). Ale
     * adresy już istnieją — w zakładkach i w mailach — więc przychodzące `?spolka=`
     * **honorujemy jako WIDOK, na jedno żądanie, i nie zapisujemy**. Kontekst pracy zmienia
     * wyłącznie `POST` (§3 reguł): otwarcie cudzego odnośnika nie ma prawa przestawić komuś
     * kontekstu.
     *
     * ⚠ Wartość spoza listy dozwolonych jest **pomijana**, nie błędem: stary odnośnik do
     * spółki, do której konto straciło dostęp, ma pokazać dozwoloną spółkę ze zdaniem,
     * a nie 404 (ta sama zasada co przy `fallback`).
     *
     * @return array{spolka: array{id: int, nazwa: string, zatrudnienie_id: ?int}|null,
     *     spolki: list<array{id: int, nazwa: string, zatrudnienie_id: ?int}>, fallback: bool,
     *     z_adresu: bool}
     */
    public function dlaEkranu(int $accountId, ?EmploymentContext $etat, ?string $zAdresu): array
    {
        $wynik = $this->rozstrzygnij($accountId, $etat) + ['z_adresu' => false];
        $zadana = filter_var((string) $zAdresu, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($zadana === false) {
            return $wynik;
        }
        foreach ($wynik['spolki'] as $s) {
            if ($s['id'] === $zadana) {
                // ⚠ Zmiana dotyczy TEGO żądania: ani sesja, ani konto nie są zapisywane.
                return ['spolka' => $s, 'spolki' => $wynik['spolki'], 'fallback' => false,
                    'z_adresu' => $s['id'] !== ($wynik['spolka']['id'] ?? null)];
            }
        }

        return $wynik;
    }

    /**
     * Zapamiętana spółka z **kolumny na koncie** — pytana raz na żądanie.
     *
     * ⚠ Sesja odpowiada na to pytanie taniej, ale milczy przy pierwszym żądaniu po zalogowaniu.
     * Wtedy pyta się konta — i wtedy też tylko RAZ: znacznik w belce i kontroler ekranu pytają
     * o to samo, a `null` (konto bez zapamiętanego wyboru) nie trafiał do sesji, więc bez tego
     * zapamiętania leciało drugie zapytanie o tę samą odpowiedź (zmierzone licznikiem
     * `Questions`: „kolejne żądanie: 1 zapytanie" na kontach bez zapisanego wyboru).
     */
    private function zapamietanaZKonta(int $accountId): ?int
    {
        if (array_key_exists($accountId, $this->zKonta)) {
            return $this->zKonta[$accountId];
        }

        return $this->zKonta[$accountId] = $this->konta->zapamietanaSpolka($accountId);
    }

    /**
     * Zapamiętanie wyboru spółki — **dla konta, które nie ma tam zatrudnienia**.
     *
     * ⚠ Waliduje przeciw liście dozwolonych w TYM żądaniu, więc podstawiony identyfikator
     * nie przechodzi (CLAUDE.md §10). Zwraca nazwę spółki albo `null`, gdy wybór odrzucono.
     */
    public function zapamietaj(int $accountId, int $companyId): ?string
    {
        foreach ($this->spolki($accountId) as $s) {
            if ($s['id'] === $companyId) {
                $this->session->setCompanyId($companyId);
                // …i trwale, bo kontekst ma przeżyć wylogowanie (§1 zlecenia).
                $this->konta->zapiszSpolke($accountId, $companyId);
                // ⚠ Zapamiętana odpowiedź z konta przestała być prawdą — w tym samym żądaniu
                // może o nią zapytać jeszcze znacznik w belce.
                $this->zKonta[$accountId] = $companyId;

                return $s['nazwa'];
            }
        }

        return null;
    }
}
