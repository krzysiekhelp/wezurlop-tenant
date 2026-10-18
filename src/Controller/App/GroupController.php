<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Service\GrupyService;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Grupy — `/admin/grupy` (zlecenie `2026-10-08-grupy-dzialy.md`, zmiana modelu 10.10.2026,
 * przebudowa ekranu `2026-10-10-grupy-ekran-dwie-kolumny.md`).
 *
 * ⚠ **Grupa jest etykietą**: bez kierownika (decyzja Krzysztofa), bez zagnieżdżania, bez wpływu
 * na ścieżkę zatwierdzania urlopu. Sąsiad hierarchii, nie jej część — dlatego osobny ekran
 * między „Konta" a „Hierarchia".
 *
 * ⚠ **Jedna osoba należy do wielu grup** (decyzja Krzysztofa 10.10.2026). Stąd znika krok
 * potwierdzenia przeniesienia z §2b zlecenia: skoro przypisanie nic nie odbiera, nie ma czego
 * potwierdzać, a ekran potwierdzenia bez treści uczy ludzi klikać bez czytania.
 *
 * ⚠ **ZAPIS NATYCHMIASTOWY, bez przycisku „Zapisz"** (rozstrzygnięcie Krzysztofa 10.10.2026,
 * §3 zlecenia). Jedno kliknięcie = jedna zmiana w bazie. Co z tego wynika dla tego pliku:
 *   - nie istnieje stan „zaznaczone, ale niezapisane", więc nie ma czego gubić przy szukaniu
 *     ani licznika, który mógłby skłamać,
 *   - **nie ma żądania zastępującego cały stan członkostwa** — każde żądanie wymienia osoby,
 *     których dotyczy, i tylko one się ruszają. Dlatego zniknął mechanizm `widoczne[]`
 *     (lista pokazana na ekranie): był potrzebny wyłącznie temu zapisowi, który mógł wypisać
 *     kogoś niewidocznego,
 *   - **dwa punkty zapisu zamiast jednego**, każdy przyjmujący listę `osoby[]`: `dodaj`
 *     i `usun`. Lista, a nie identyfikator w ścieżce, bo „Dodaj wszystkich pokazanych" i jego
 *     „Cofnij" muszą mieć ten sam kształt — inaczej trzeba by czterech punktów zamiast dwóch,
 *   - **nie ma paska „Cofnij" i nie ma potwierdzeń** (aneks 10.10.2026 §3a). Pasek dublował
 *     akcję, która stoi dwa centymetry dalej: osoba właśnie dodana jest w drugiej kolumnie
 *     i ma przy sobie akcję odwrotną. Drogą powrotu dla **akcji zbiorczej** jest druga akcja
 *     zbiorcza w przeciwnej kolumnie — i ona musiała istnieć, **zanim** pasek zniknął.
 *
 * ⚠ **Sprawdzenia nie wolno rozluźnić, bo żądanie jest małe** (§3 zlecenia). Każde żądanie
 * sprawdza od nowa zalogowanie (middleware), uprawnienie (trasa), istnienie grupy w TEJ spółce
 * i — w zapytaniu, nie w `if`-ie — zgodność spółki zatrudnienia z grupą. To jest teraz
 * trzydzieści siedem małych drzwi zamiast jednych dużych i każde mają ten sam zamek.
 *
 * ⚠ **Uprawnienie istniejące: `admin.hierarchy`.** Nie dokładamy `admin.groups` — to samo
 * rozstrzygnięcie co przy hierarchii: uprawnienie bez roli, której można by je nadać, jest
 * uprawnieniem „na zapas" (§1 reguł). Wraca razem z rolą kadrowej.
 *
 * ⚠ **Zakres spółek jak na hierarchii**, tą samą drogą (`PermissionRepository::hasTenantWideRole`),
 * bo to ten sam rodzaj ekranu i ta sama granica: grupa należy do spółki, nie do tenanta.
 */
final class GroupController
{
    /** Limit długości `osoby[]` z jednego żądania — grupa, nie import całej firmy. */
    private const OSOB_MAX = 300;

    public function __construct(
        private readonly View $view,
        private readonly GroupRepository $grupy,
        private readonly GrupyService $service,
        private readonly PermissionRepository $permissions,
        private readonly KontekstSpolki $kontekstSpolki,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }

        return $this->view->render('groups.twig', $this->model($request, $kontekst));
    }

    /**
     * Ekran jednej grupy: **dwie kolumny** — w grupie i pozostali w spółce.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        // ⚠ Identyfikator grupy jedzie do rozstrzygania spółki: zasób niesie własną spółkę (§1).
        $kontekst = $this->kontekst($request, self::idGrupy($params));
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $grupa = $this->grupaZTrasy($params, $kontekst['spolka']['id']);
        if ($grupa === null) {
            // ⚠ Cudza albo nieistniejąca grupa — jedna odpowiedź na oba przypadki, bez
            // ujawniania, który zachodzi (CLAUDE.md §10).
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->view->render(
            'group.twig',
            $this->modelGrupy($request, $kontekst, $grupa),
        );
    }

    /**
     * Obie kolumny jako fragment — do szukania i po każdym zapisie.
     *
     * ⚠ **Pełnoprawny endpoint** (CLAUDE.md §5): uprawnienie, zakres spółek i granica grupy
     * sprawdzają się tu tak samo jak dla pełnej strony. „Używa go tylko htmx" nie istnieje.
     *
     * @param array<string, string> $params
     */
    public function fragment(Request $request, array $params): Response
    {
        // ⚠ Identyfikator grupy jedzie do rozstrzygania spółki: zasób niesie własną spółkę (§1).
        $kontekst = $this->kontekst($request, self::idGrupy($params));
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $grupa = $this->grupaZTrasy($params, $kontekst['spolka']['id']);
        if ($grupa === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        // ⚠ **Ten sam fragment w dwóch oprawach** (aneks §1): `?okno=1` dokłada mu nagłówek
        // okna modalnego, `?okno=0` (albo brak) oddaje same kolumny do podstrony. Drugiego
        // szablonu nie ma i nie będzie — dwa szablony na jedną treść to dwa miejsca, które
        // rozjadą się przy pierwszej zmianie kolumny.
        return $this->view->render(
            'fragments/group-assign.twig',
            $this->modelGrupy($request, $kontekst, $grupa)
                + ['htmx' => true, 'w_oknie' => $request->query('okno') === '1'],
        );
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $nazwa = trim($request->postString('nazwa', 100));
        if ($nazwa === '') {
            return $this->listaZBledem($request, $kontekst, 'Podaj nazwę grupy.', 'grupa-nowa');
        }
        $wynik = $this->service->utworz(
            $kontekst['spolka']['id'],
            $nazwa,
            $kontekst['konto'],
            $kontekst['aktor'],
        );
        if (is_string($wynik)) {
            return $this->listaZBledem($request, $kontekst, self::komunikat($wynik, $nazwa), 'grupa-nowa', $nazwa);
        }

        // ⚠ Po utworzeniu trafiasz NA EKRAN GRUPY, nie na listę — rozstrzygnięcie Krzysztofa
        // z 10.10.2026: pusta grupa ma dokładnie jedną sensowną czynność, czyli dodanie ludzi.
        return Response::redirect($this->adresGrupy($wynik['id'], $kontekst['spolka']['id']));
    }

    /** @param array<string, string> $params */
    public function rename(Request $request, array $params): Response
    {
        // ⚠ Identyfikator grupy jedzie do rozstrzygania spółki: zasób niesie własną spółkę (§1).
        $kontekst = $this->kontekst($request, self::idGrupy($params));
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $grupa = $this->grupaZTrasy($params, $kontekst['spolka']['id']);
        if ($grupa === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $nazwa = trim($request->postString('nazwa', 100));
        if ($nazwa === '') {
            return $this->listaZBledem($request, $kontekst, 'Podaj nazwę grupy.', 'grupa-nazwa-' . $grupa['id']);
        }
        $powod = $this->service->zmienNazwe(
            $grupa['id'],
            $kontekst['spolka']['id'],
            $nazwa,
            $kontekst['konto'],
            $kontekst['aktor'],
        );
        if ($powod !== null) {
            return $this->listaZBledem(
                $request,
                $kontekst,
                self::komunikat($powod, $nazwa),
                'grupa-nazwa-' . $grupa['id'],
                $nazwa,
            );
        }

        // ⚠ Wracamy TAM, SKĄD PRZYSZŁO ŻĄDANIE — nazwę zmienia się z listy („⋯ → Zmień nazwę")
        // i z ekranu grupy. Biała lista dwóch adresów, nie `Referer` (CLAUDE.md §11).
        return Response::redirect($request->postString('wroc', 16) === 'grupa'
            ? $this->adresGrupy($grupa['id'], $kontekst['spolka']['id'], 'nazwa')
            : $this->adresListy($kontekst['spolka']['id'], 'nazwa'));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        // ⚠ Identyfikator grupy jedzie do rozstrzygania spółki: zasób niesie własną spółkę (§1).
        $kontekst = $this->kontekst($request, self::idGrupy($params));
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $grupa = $this->grupaZTrasy($params, $kontekst['spolka']['id']);
        if ($grupa === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $powod = $this->service->usun(
            $grupa['id'],
            $kontekst['spolka']['id'],
            $kontekst['konto'],
            $kontekst['aktor'],
        );
        if ($powod !== null) {
            return $this->listaZBledem($request, $kontekst, self::komunikat($powod, $grupa['nazwa']));
        }

        return Response::redirect($this->adresListy($kontekst['spolka']['id'], 'usuniety'));
    }

    /**
     * Dodanie wskazanych osób do grupy — **przyrostowo, nic nie odbierając**.
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        return $this->zapis($request, $params, dodaje: true);
    }

    /**
     * Wypisanie wskazanych osób **z tej jednej grupy** — pozostałych grup osoby nie rusza.
     *
     * @param array<string, string> $params
     */
    public function remove(Request $request, array $params): Response
    {
        return $this->zapis($request, $params, dodaje: false);
    }

    /**
     * Wspólne ciało obu zapisów — **jedno miejsce decyduje, co odpowiedzieć**.
     *
     * ⚠ Odpowiedź htmx to **fragment renderujący obie kolumny z aktualnego stanu bazy**
     * (CLAUDE.md §5), a nie to, co przyszło w żądaniu: liczniki w nagłówkach pochodzą z tego
     * samego odczytu co listy, więc nie mają jak się rozjechać. Bez htmx — przekierowanie
     * (POST/Redirect/GET, §3 reguł), a pasek „Cofnij" dostaje swoją treść z adresu.
     *
     * @param array<string, string> $params
     */
    private function zapis(Request $request, array $params, bool $dodaje): Response
    {
        // ⚠ Identyfikator grupy jedzie do rozstrzygania spółki: zasób niesie własną spółkę (§1).
        $kontekst = $this->kontekst($request, self::idGrupy($params));
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $grupa = $this->grupaZTrasy($params, $kontekst['spolka']['id']);
        if ($grupa === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $osoby = self::identyfikatory($request, 'osoby');
        $szukaj = trim($request->postString('szukaj', 100));
        $wGrupie = trim($request->postString('szukajg', 100));
        if ($osoby === []) {
            return $this->odpowiedz($request, $kontekst, $grupa, $szukaj, null, null, $wGrupie);
        }
        $companyId = $kontekst['spolka']['id'];
        if ($dodaje) {
            $wynik = $this->service->przypisz($grupa['id'], $companyId, $osoby, $kontekst['konto'], $kontekst['aktor']);
            $zmienieni = $wynik['dodani'];
        } else {
            $wynik = $this->service->wypisz($grupa['id'], $companyId, $osoby, $kontekst['konto'], $kontekst['aktor']);
            $zmienieni = $wynik['usunieci'];
        }
        // ⚠ „Nikogo nie dodano, a część pominięto" to jedyny stan, o którym ekran musi
        // powiedzieć wprost — reszta widać na listach.
        $nic_nie_zmieniono = $zmienieni === [];
        // ⚠ **Zapis niepełny ma być widoczny.** Identyfikator z innej spółki (albo nieistniejący)
        // nie przechodzi przez warunek w zapytaniu — i wtedy ekran nie może pokazać ciszy ani
        // sukcesu. Przez interfejs to się nie zdarza (lista pokazuje wyłącznie tę spółkę),
        // ale żądanie da się wysłać z ręki, a milczenie nad pominiętym zapisem jest tą samą
        // chorobą co licznik, którego nikt nie aktualizuje.
        $pominieci = $dodaje && isset($wynik['pominieci']) && $wynik['pominieci'] !== [];
        if ($nic_nie_zmieniono && $pominieci) {
            return $this->odpowiedz($request, $kontekst, $grupa, $szukaj, null, 'pominieci', $wGrupie);
        }
        // ⚠ **Nie ma paska „Cofnij" i nic go nie zastępuje w interfejsie** (aneks §3a):
        // potwierdzeniem jest sam ekran — osoba przeskakuje do drugiej kolumny i stoi tam
        // z akcją odwrotną przy nazwisku. Wpis w dzienniku robi serwis, a o pominiętych mówi
        // `$pominieci` niżej.
        return $this->odpowiedz($request, $kontekst, $grupa, $szukaj,
            $pominieci ? 'pominieci' : null, $wGrupie);
    }

    /**
     * @param array<string, mixed> $kontekst
     * @param array{id: int, nazwa: string} $grupa
     */
    private function odpowiedz(
        Request $request,
        array $kontekst,
        array $grupa,
        string $szukaj,
        ?string $uwaga = null,
        string $wGrupie = ''
    ): Response {
        if ($request->isHtmx()) {
            return $this->view->render(
                'fragments/group-assign.twig',
                $this->modelGrupy($request, $kontekst, $grupa, $szukaj, $uwaga, $wGrupie)
                    + ['htmx' => true, 'w_oknie' => false],
            );
        }
        $adres = $this->adresGrupy($grupa['id'], $kontekst['spolka']['id']);
        $pytanie = [];
        if ($szukaj !== '') {
            $pytanie[] = 'szukaj=' . rawurlencode($szukaj);
        }
        if ($wGrupie !== '') {
            $pytanie[] = 'szukajg=' . rawurlencode($wGrupie);
        }
        if ($uwaga !== null) {
            $pytanie[] = 'uwaga=' . $uwaga;
        }

        return Response::redirect($adres . ($pytanie === [] ? '' : '&' . implode('&', $pytanie)));
    }

    /**
     * Spółka, zakres spółek i dane sprawcy — **jedno miejsce**, jak na hierarchii.
     *
     * @return array{spolki: list<array{id: int, nazwa: string}>, spolka: array{id: int, nazwa: string},
     *     konto: ?int, aktor: ?string, caly_tenant: bool}|null
     */
    private function kontekst(Request $request, ?int $groupId = null): ?array
    {
        $user = $request->attribute(Authenticate::USER);
        if (!$user instanceof CurrentUser) {
            return null;
        }
        // ⚠ **Spółka bierze się z KONTEKSTU, nie z adresu** (zlecenie 10.10.2026 §1). Dotąd ten
        // kontroler budował własną listę spółek i czytał `?spolka=` — czyli ta sama informacja
        // jechała dwiema drogami, a wybór znikał przy przejściu na inny ekran. Teraz pyta
        // jedno miejsce (`KontekstSpolki`), to samo, z którego żyje znacznik w belce.
        // ⚠ Przychodzące `?spolka=` (stare zakładki) honorujemy **na jedno żądanie jako widok**
        // i mówimy o tym zdaniem — bez zapisu, bo kontekst zmienia wyłącznie `POST`.
        $wynik = $this->kontekstSpolki->dlaEkranu(
            $user->accountId(),
            $user->activeEmployment(),
            $request->queryString('spolka', 12) !== '' ? $request->queryString('spolka', 12)
                : $request->postString('spolka', 12),
        );
        if ($wynik['spolka'] === null) {
            return null;
        }
        // ⚠ **Spółka ZASOBU wygrywa z kontekstem** (§1 zlecenia: „serwer wyprowadza spółkę
        // z samego zasobu"). Bez tego `/admin/grupy/{id}` grupy z innej spółki dawało 404 —
        // a adresy grup przestały nosić `?spolka=`, więc odnośnik z maila albo z zakładki
        // musi być samowystarczalny. Zawężenie do spółek konta siedzi w zapytaniu (IDOR, §10).
        // ⚠ Kontekstu to **nie przestawia**: otwarcie cudzego odnośnika nie ma prawa zmienić
        // komuś spółki pracy, a `GET` nie zmienia stanu (§3 reguł). Ekran mówi o tym zdaniem.
        $zZasobu = false;
        if ($groupId !== null) {
            $spolkaGrupy = $this->grupy->spolkaGrupy($groupId, array_column($wynik['spolki'], 'id'));
            if ($spolkaGrupy !== null && $spolkaGrupy !== $wynik['spolka']['id']) {
                foreach ($wynik['spolki'] as $s) {
                    if ($s['id'] === $spolkaGrupy) {
                        $wynik['spolka'] = $s;
                        $zZasobu = true;
                    }
                }
            }
        }

        return [
            'spolki' => $wynik['spolki'],
            'spolka' => ['id' => $wynik['spolka']['id'], 'nazwa' => $wynik['spolka']['nazwa']],
            'konto' => $user->accountId(),
            'aktor' => $user->displayName,
            'caly_tenant' => count($wynik['spolki']) > 1
                && $this->permissions->hasTenantWideRole($user->accountId()),
            // Zdania dla ekranu: skąd wzięła się ta spółka, jeśli nie z kontekstu.
            'z_adresu' => $wynik['z_adresu'],
            'fallback' => $wynik['fallback'],
            'z_zasobu' => $zZasobu,
        ];
    }

    /**
     * Identyfikator grupy z trasy — **otypowany**, zanim cokolwiek na nim oprzemy (§11 reguł).
     * `null` znaczy „trasa bez grupy albo bezsensowna wartość", nie błąd: dalej rozstrzyga
     * `grupaZTrasy()`, które i tak pyta bazę z zawężeniem do spółki.
     *
     * @param array<string, string> $params
     */
    private static function idGrupy(array $params): ?int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * @param array<string, string> $params
     * @return array{id: int, nazwa: string}|null
     */
    private function grupaZTrasy(array $params, int $companyId): ?array
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $this->grupy->find($id, $companyId);
    }

    /**
     * @param array<string, mixed> $kontekst
     * @return array<string, mixed>
     */
    private function model(Request $request, array $kontekst, ?string $blad = null): array
    {
        $companyId = $kontekst['spolka']['id'];
        $stan = $request->queryString('ok', 12);
        $sklad = $this->grupy->skladGrup($companyId);

        return [
            'spolki' => $kontekst['spolki'],
            'spolka' => $kontekst['spolka'],
            'caly_tenant' => $kontekst['caly_tenant'],
            // ⚠ Skład doklejony do grup **w kontrolerze**, nie w szablonie: szablon dostaje
            // gotowe do wyświetlenia (§3 reguł), a liczba zapytań jest jedna na cały ekran.
            'grupy' => array_map(static function (array $g) use ($sklad): array {
                $osoby = $sklad[$g['id']] ?? [];

                return $g + [
                    // ⚠ PIĘĆ, nie cztery (Z31 §4): wiersz pokazuje do pięciu nakładających
                    // się kółek z inicjałami, więc tyle samo nazwisk musi dojść do szablonu.
                    'sklad' => array_slice($osoby, 0, 5),
                    'wiecej' => max(0, count($osoby) - 5),
                ];
            }, $this->grupy->lista($companyId)),
            'bez_grupy' => $this->grupy->bezGrupy($companyId),
            // Domyślnie żadne okno nie jest otwarte; ustawia je dopiero odmowa zapisu.
            'okno_bledu' => null,
            'wpisana_nazwa' => '',
            // ⚠ Trzy liczby na pasek kafli jednym zapytaniem (Z31). Czwarta — „bez grupy" —
            // jest już wyżej i liczy ten sam zbiór: czynne zatrudnienia w tej firmie.
            'statystyki' => $this->grupy->statystyki($companyId),
            // ⚠ Zdania o pochodzeniu spółki: ekran ma powiedzieć, dlaczego pokazuje TĘ spółkę,
            // gdy nie wzięła się z kontekstu (stary odnośnik albo utrata dostępu).
            'spolka_z_adresu' => $kontekst['z_adresu'] ?? false,
            'spolka_fallback' => $kontekst['fallback'] ?? false,
            'spolka_z_zasobu' => $kontekst['z_zasobu'] ?? false,
            'notice' => match ($stan) {
                'nazwa' => 'Nazwa grupy zapisana.',
                'usuniety' => 'Grupa usunięta.',
                default => null,
            },
            'error' => $blad,
        ];
    }

    /**
     * Model obu kolumn — **lewa jest wykazem członków, prawa listą do dodania**.
     *
     * ⚠ Wykazu członków pod spodem NIE MA i to jest część poprawki: dotąd ta sama lista stała
     * w dwóch miejscach (w kontrolce i w tabeli), więc panel zasłaniał tabelę, a tabela
     * pokazywała stan sprzed kliknięcia.
     *
     * @param array<string, mixed> $kontekst
     * @param array{id: int, nazwa: string} $grupa
     * @return array<string, mixed>
     */
    private function modelGrupy(
        Request $request,
        array $kontekst,
        array $grupa,
        ?string $szukaj = null,
        ?string $uwaga = null,
        ?string $wGrupie = null
    ): array {
        $companyId = $kontekst['spolka']['id'];
        $szukaj ??= trim($request->queryString('szukaj', 100));
        // ⚠ **Dwa osobne filtry, dwa parametry** (aneks §3): „Szukaj w grupie" zawęża lewą
        // kolumnę, „Szukaj osoby" prawą. Jeden wspólny parametr znaczyłby, że zawężenie jednej
        // strony przestawia drugą — a obie kolumny opisują inne zbiory.
        $wGrupie ??= trim($request->queryString('szukajg', 100));
        $czlonkowie = $this->grupy->czlonkowie($grupa['id'], $companyId, $wGrupie);
        $poza = $this->grupy->osobyPozaGrupa($grupa['id'], $companyId, $szukaj);
        $stan = $request->queryString('ok', 12);

        return [
            'spolki' => $kontekst['spolki'],
            'spolka' => $kontekst['spolka'],
            'grupa' => $grupa,
            'czlonkowie' => $czlonkowie,
            // ⚠ Licznik nieaktywnych liczy **pokazanych**, bo stoi w nagłówku nad tą listą.
            'nieaktywnych' => count(array_filter($czlonkowie, static fn (array $o): bool => !$o['czynne'])),
            // Dwie liczby po każdej stronie: nagłówek mówi „N z M" przy filtrze, „M" bez niego.
            'czlonkow_pasujacych' => $this->grupy->ileWGrupie($grupa['id'], $companyId, $wGrupie),
            'czlonkow_razem' => $this->grupy->ileWGrupie($grupa['id'], $companyId),
            'szukaj_w_grupie' => $wGrupie,
            'poza' => $poza,
            // Dwie liczby, bo nagłówek prawej kolumny mówi „7 z 32" przy zawężeniu, a „32" bez.
            'poza_pasujacych' => $this->grupy->ilePozaGrupa($grupa['id'], $companyId, $szukaj),
            'poza_razem' => $this->grupy->ilePozaGrupa($grupa['id'], $companyId),
            'szukaj' => $szukaj,
            // Te same trzy zdania co na liście — jednym partialem, nie czwartą kopią.
            'spolka_z_adresu' => $kontekst['z_adresu'] ?? false,
            'spolka_fallback' => $kontekst['fallback'] ?? false,
            'spolka_z_zasobu' => $kontekst['z_zasobu'] ?? false,
            'notice' => match ($stan) {
                'nazwa' => 'Nazwa grupy zapisana.',
                default => null,
            },
            'error' => ($uwaga ?? $request->queryString('uwaga', 12)) === 'pominieci'
                ? 'Część wskazanych zatrudnień pominięto — nie należą do tej firmy.'
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $kontekst
     */
    /**
     * Lista grup z komunikatem odmowy.
     *
     * ⚠ **Komunikat wraca DO OKNA, z którego przyszło żądanie** (zlecenie Z31, dopisek): nazwę
     * grupy podaje się w oknie, więc odmowa pokazana tylko na pasku strony zostawiała otwarte
     * pytanie „to co mam poprawić". `$oknoBledu` mówi szablonowi, które okno otworzyć, a wpisana
     * nazwa wraca do pola — żeby poprawić literę, a nie wpisywać wszystko od nowa.
     * ⚠ Bez JavaScriptu działa tak samo: serwer renderuje `<dialog open>`, a `ui.js` tylko
     * podnosi je do warstwy wierzchniej (ta sama droga, co w oknie nazw firm z Z29).
     */
    private function listaZBledem(
        Request $request,
        array $kontekst,
        string $blad,
        ?string $oknoBledu = null,
        string $wpisanaNazwa = '',
    ): Response {
        return $this->view->render(
            'groups.twig',
            // ⚠ Nadpisania STOJĄ PO LEWEJ: operator `+` zachowuje klucze z lewej strony, więc
            // przy odwrotnej kolejności domyślne `null` z modelu wygrałoby z przekazanym oknem.
            ['okno_bledu' => $oknoBledu, 'wpisana_nazwa' => $wpisanaNazwa]
                + $this->model($request, $kontekst, $blad),
            422,
        );
    }

    private static function komunikat(string $powod, string $nazwa): string
    {
        return match ($powod) {
            GroupRepository::POWOD_NAZWA_ZAJETA => 'Grupa „' . $nazwa . '" już istnieje w tej firmie — '
                . 'nazwy nie rozróżniamy wielkością liter, bo dwa takie wiersze na liście wyglądają jak jeden.',
            GroupRepository::POWOD_NIEPUSTA => 'Grupa ma jeszcze osoby — wypisz je z grupy, zanim '
                . 'usuniesz samą grupę. Pusta grupa znika bez pytania.',
            default => 'Nie znalazłem takiej grupy.',
        };
    }

    /**
     * ⚠ **Bez `?spolka=`** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1): spółkę trzyma
     * znacznik w belce, więc adres generowany po zapisie nie dopisuje jej drugi raz.
     * Identyfikator zostaje w podpisie, bo wywołania go podają i czyta się z nich, której
     * spółki zapis dotyczył.
     */
    private function adresListy(int $companyId, ?string $ok = null): string
    {
        unset($companyId);

        return $this->urls->to('/admin/grupy') . ($ok === null ? '' : '?ok=' . $ok);
    }

    private function adresGrupy(int $groupId, int $companyId, ?string $ok = null): string
    {
        unset($companyId);

        return $this->urls->to('/admin/grupy/' . $groupId) . ($ok === null ? '' : '?ok=' . $ok);
    }

    /**
     * Identyfikatory z żądania — **otypowane i ograniczone liczbą**, nigdy surowe (§11 reguł).
     *
     * ⚠ Czego tu NIE MA: sprawdzenia spółki. Ono jest w zapytaniu (`GroupRepository::przypisz`
     * i `::usunZGrupy`), bo tylko tam jest nierozdzielne od zapisu. Filtr tutaj byłby drugim
     * miejscem decydującym o tym samym — i tym, które przy pierwszej zmianie rozjedzie się
     * z bazą.
     *
     * @return list<int>
     */
    private static function identyfikatory(Request $request, string $pole): array
    {
        $surowe = $request->post($pole);
        if (is_string($surowe)) {
            // Jedna osoba z formularza `+`/`✕` przychodzi jako wartość, nie tablica.
            $surowe = [$surowe];
        }
        if (!is_array($surowe)) {
            return [];
        }
        $out = [];
        foreach (array_slice($surowe, 0, self::OSOB_MAX) as $wartosc) {
            $id = filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
