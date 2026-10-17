<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\HierarchyRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Service\Hierarchia;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Hierarchia przełożonych — kto komu zatwierdza urlop (zlecenie `hierarchia` §4–§8).
 *
 * ⚠ **Jedno drzewo na ekranie, jedna spółka.** Struktura wisi na zatrudnieniu, a zatrudnienie
 * jest osobne dla każdej spółki — więc przełącznik spółek u góry, a nie filtr obok listy.
 * Upuszczenie w poprzek spółek nie istnieje: wypuściłoby dane kadrowe jednej spółki ścieżką
 * akceptacji drugiej.
 *
 * ⚠ **Zakres spółek wołającego sprawdzamy po stronie serwera** (§6.3). Administrator tenanta
 * widzi wszystkie spółki; konto bez roli ogólnotenantowej — wyłącznie swoją. Parametr
 * `?spolka=` spoza zakresu znaczy „pierwsza dozwolona", nie „błąd": adres wklejony po zmianie
 * uprawnień ma pokazać ekran, nie awarię (biała lista, `CLAUDE.md` §11).
 *
 * ⚠ **Przeciąganie niczego nie zapisuje.** Zapis to jeden `POST` całej partii, w transakcji
 * (§6.4), po którym następuje przekierowanie (POST/Redirect/GET, `CLAUDE.md` §3).
 */
final class HierarchyController
{
    public function __construct(
        private readonly View $view,
        private readonly Hierarchia $hierarchia,
        private readonly HierarchyRepository $repo,
        private readonly PermissionRepository $permissions,
        private readonly KontekstSpolki $kontekstSpolki,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $spolki = $this->dozwoloneSpolki($admin);
        if ($spolki === []) {
            // Brak choćby jednej spółki w zakresie to nie awaria — to konto, które nie ma
            // czego ustawiać. Pokazujemy ekran z tym zdaniem, nie 403 bez wyjaśnienia.
            return $this->view->render('page/web/hierarchy.twig', [
                'spolki' => [], 'spolka' => null, 'struktura' => null,
                'z_adresu' => false, 'fallback' => false,
                'notice' => null, 'error' => null, 'caly_tenant' => false,
            ]);
        }
        $wybor = $this->wybranaSpolka($admin, $request, $spolki);
        $wybrana = $wybor['spolka'];

        return $this->view->render('page/web/hierarchy.twig', [
            'spolki'    => $spolki,
            'spolka'    => $wybrana,
            'z_adresu'  => $wybor['z_adresu'],
            'fallback'  => $wybor['fallback'],
            'struktura' => $this->hierarchia->struktura($wybrana['id']),
            'notice'    => $request->query('ok') === null ? null : 'Zmiany zapisane.',
            'error'     => null,
            // ⚠ Zdanie o zasięgu, nie nowe uprawnienie (zlecenie 08.10 §4, wariant a).
            // Kontrolka spółek na tym ekranie pokazuje WSZYSTKIE spółki tenanta, bo
            // `admin.hierarchy` jest uprawnieniem tenantowym — a w kalendarzu ta sama kontrolka
            // pokazuje tylko spółki z nadanym podglądem, bo tam uprawnienie jest per spółka.
            // Oba zachowania są poprawne dla swojego ekranu; bez zdania wyglądają na sprzeczność.
            'caly_tenant' => count($spolki) > 1 && $this->permissions->hasTenantWideRole($admin->accountId()),
        ]);
    }

    /**
     * Lista kandydatów na przełożonego jednej osoby — **ścieżka bez myszki** (§8).
     *
     * ⚠ Zwykły `GET` z adresem do wklejenia, bez htmx i bez okna modalnego: ta droga musi
     * działać także tam, gdzie nie działa przeciąganie — z klawiatury, palcem i przy czytniku
     * ekranu. Wybór pozycji wysyła **jedną zmianę tym samym `POST`-em** co pasek zmian.
     *
     * @param array<string, string> $params
     */
    public function person(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $employmentId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $spolki = $this->dozwoloneSpolki($admin);
        if ($employmentId === false || $spolki === []) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $wiersz = $this->repo->doZapisu($employmentId);
        // ⚠ IDOR: zatrudnienie musi leżeć w spółce, do której wołający MA dostęp — nie
        // wystarczy, że zna identyfikator (`CLAUDE.md` §10). Brak dostępu to 404, nie 403:
        // nie potwierdzamy, że takie zatrudnienie istnieje.
        $wybrana = null;
        foreach ($spolki as $s) {
            if ($wiersz !== null && $s['id'] === $wiersz['company_id']) {
                $wybrana = $s;
            }
        }
        if ($wiersz === null || !$wiersz['aktywne'] || $wybrana === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        // ⚠ Węzeł bierzemy ze `struktura()`, nie z surowego wiersza repozytorium: stany
        // policzone („nieustawiony", „szczyt", odchylenia) mają JEDNO miejsce powstawania.
        // Zmierzone: przy surowym wierszu ekran wywracał się na `strict_variables`, bo szablon
        // pytał o pole, którego tam nie ma — i to jest właśnie ta reguła robiąca swoje.
        $osoba = null;
        foreach ($this->hierarchia->struktura($wybrana['id'])['wezly'] as $z) {
            if ($z['id'] === $employmentId) {
                $osoba = $z;
            }
        }

        // ⚠ Wyszukiwanie w PARAMETRZE zapytania: idzie do bazy, daje adres do wklejenia
        // i działa bez JavaScriptu (biała lista — obcinamy długość, nie filtrujemy znaków,
        // bo wzorzec leci placeholderem).
        $szukaj = $request->queryString('szukaj', 100);

        // ⚠ Ten sam endpoint, dwie formy odpowiedzi: pełna strona (ścieżka bez JavaScriptu,
        // adres do wklejenia) i fragment do okna modalnego. Uprawnienie, zakres spółek i IDOR
        // sprawdzone POWYŻEJ, czyli tak samo dla obu — fragment htmx jest pełnoprawnym
        // endpointem, nie „wewnętrznym" (CLAUDE.md §5).
        $szablon = $request->isHtmx()
            ? 'fragments/hierarchy-person.twig'
            : 'page/web/hierarchy-person.twig';

        return $this->view->render($szablon, [
            'spolka'     => $wybrana,
            'osoba'      => $osoba,
            'kandydaci'  => $this->hierarchia->kandydaci($wybrana['id'], $employmentId, $szukaj),
            'limit'      => Hierarchia::KANDYDATOW_NA_RAZ,
            'notice'     => null,
            'error'      => null,
        ]);
    }

    /**
     * Zapis partii zmian — **ta sama trasa dla przeciągania i dla menu `⋯`** (§8).
     *
     * ⚠ Bez JavaScriptu menu `⋯` wysyła jedną zmianę na raz, z JavaScriptem pasek zmian
     * wysyła całą partię. To jest **jedna ścieżka zapisu z dwoma wejściami**, a nie dwa
     * zapisy — inaczej drugi zestaw reguł zawsze zostaje w tyle.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $spolki = $this->dozwoloneSpolki($admin);
        if ($spolki === []) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $wybrana = $this->wybranaSpolka($admin, $request, $spolki)['spolka'];
        $zmiany = $this->zmiany($request);
        if ($zmiany === []) {
            return Response::redirect($this->adres($wybrana['id']));
        }
        $wynik = $this->hierarchia->zapisz($wybrana['id'], $zmiany, $admin->accountId(), $admin->displayName);
        if ($wynik['powod'] !== null) {
            // ⚠ Odmowa dotyczy CAŁEJ partii i ekran mówi to wprost — pół zapisanej struktury
            // jest gorsze od niezapisanej, bo administrator widzi, że „coś się stało",
            // i nie wie co.
            return $this->view->render('page/web/hierarchy.twig', [
                'spolki'    => $spolki,
                'spolka'    => $wybrana,
                'z_adresu'  => false,
                'fallback'  => false,
                'struktura' => $this->hierarchia->struktura($wybrana['id']),
                'notice'    => null,
                'error'     => 'Nie zapisano żadnej zmiany: ' . $wynik['powod'] . '.',
                'caly_tenant' => count($spolki) > 1 && $this->permissions->hasTenantWideRole($admin->accountId()),
            ], 422);
        }

        return Response::redirect($this->adres($wybrana['id'], true));
    }

    /**
     * ⚠ **Bez `?spolka=`** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1): spółkę ekranu
     * trzyma znacznik w belce, a adres generowany przez aplikację nie może być drugim źródłem
     * tej samej prawdy. Identyfikator zostaje w podpisie, bo wywołania go podają — i czyta się
     * z niego, dla której spółki zapis się udał.
     */
    private function adres(int $companyId, bool $ok = false): string
    {
        unset($companyId);

        return $this->urls->to('/admin/hierarchia') . ($ok ? '?ok=1' : '');
    }

    /**
     * Zmiany z żądania — **białą listą** (`CLAUDE.md` §11).
     *
     * Kształt: `zmiany[N][employment]`, `zmiany[N][cel]`, `zmiany[N][supervisor]`.
     * ⚠ Bez JSON-a w polu formularza: zwykłe pola tablicowe idą przez `filter_var` i dalej
     * kod pracuje na otypowanych wartościach, a nie na tym, co przysłała przeglądarka.
     *
     * @return list<array{employment: int, cel: string, supervisor?: int|null}>
     */
    private function zmiany(Request $request): array
    {
        $surowe = $request->post('zmiany');
        if (!is_array($surowe)) {
            return [];
        }
        $cele = [Hierarchia::CEL_PRZELOZONY, Hierarchia::CEL_SZCZYT, Hierarchia::CEL_ZDEJMIJ];
        $zmiany = [];
        // Limit partii: tyle, ile zmieści się w jednym ekranie pracy. Przy większej liczbie
        // i tak decyduje `max_input_vars` hostingu, więc lepiej odrzucić świadomie.
        foreach (array_slice($surowe, 0, 200, true) as $wpis) {
            if (!is_array($wpis)) {
                continue;
            }
            $employment = filter_var($wpis['employment'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cel = is_string($wpis['cel'] ?? null) ? $wpis['cel'] : '';
            if ($employment === false || !in_array($cel, $cele, true)) {
                continue;
            }
            $zmiana = ['employment' => $employment, 'cel' => $cel];
            if ($cel === Hierarchia::CEL_PRZELOZONY) {
                $supervisor = filter_var($wpis['supervisor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $zmiana['supervisor'] = $supervisor === false ? null : $supervisor;
            }
            $zmiany[] = $zmiana;
        }

        return $zmiany;
    }

    /**
     * Spółki w zakresie wołającego.
     *
     * @return list<array{id: int, nazwa: string, zatrudnionych: int}>
     */
    private function dozwoloneSpolki(CurrentUser $admin): array
    {
        $wszystkie = $this->repo->spolki();
        if ($this->permissions->hasTenantWideRole($admin->accountId())) {
            return $wszystkie;
        }
        $moja = $admin->activeEmployment()?->companyId;

        return array_values(array_filter(
            $wszystkie,
            static fn (array $s): bool => $moja !== null && $s['id'] === $moja,
        ));
    }

    /**
     * Spółka ekranu — **z jednego znacznika w belce** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1).
     *
     * ⚠ Wynik znacznika jest dalej **przecinany z zakresem tego ekranu**: `KontekstSpolki` zna
     * spółki, do których konto ma nadany podgląd kalendarza, a `admin.hierarchy` to inne
     * uprawnienie. Spółka wskazana znacznikiem, ale leżąca poza zakresem hierarchii, znaczy
     * „pokaż pierwszą dozwoloną i powiedz o tym" — nie 403 (ta sama zasada co przy `?spolka=`
     * spoza zakresu).
     *
     * @param list<array{id: int, nazwa: string, zatrudnionych: int}> $spolki
     * @return array{spolka: array{id: int, nazwa: string, zatrudnionych: int}, z_adresu: bool, fallback: bool}
     */
    private function wybranaSpolka(CurrentUser $admin, Request $request, array $spolki): array
    {
        $kontekst = $this->kontekstSpolki->dlaEkranu(
            $admin->accountId(),
            $admin->activeEmployment(),
            $request->query('spolka'),
        );
        $zadana = $kontekst['spolka']['id'] ?? null;
        // ⚠ Ukryte pole `spolka` w formularzu zapisu **zostaje** i ma pierwszeństwo: partia
        // zmian dotyczy spółki, dla której ekran był wyrenderowany, a nie tej, którą znacznik
        // pokazuje w chwili wysłania (przełączenie w drugiej karcie nie ma prawa przestawić
        // celu zapisu). To nie jest druga kontrolka ani odnośnik — to identyfikator zasobu,
        // dalej sprawdzany przeciw zakresowi poniżej. `?spolka=` z adresu honoruje
        // `KontekstSpolki::dlaEkranu()` jako widok na jedno żądanie.
        $zPostu = filter_var($request->post('spolka'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($zPostu !== false) {
            $zadana = $zPostu;
        }
        foreach ($spolki as $s) {
            if ($s['id'] === $zadana) {
                return ['spolka' => $s, 'z_adresu' => $kontekst['z_adresu'], 'fallback' => $kontekst['fallback']];
            }
        }

        return ['spolka' => $spolki[0], 'z_adresu' => false, 'fallback' => $zadana !== null];
    }
}
