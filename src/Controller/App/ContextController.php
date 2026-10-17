<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Security\AuthSession;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\EkranyBelki;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\View;

/**
 * Wybór aktywnej firmy (zatrudnienia). Przełączenie wyłącznie przez POST (CLAUDE.md §3),
 * zatrudnienie weryfikowane zapytaniem z warunkiem właściciela (CLAUDE.md §10).
 *
 * ⚠ **Jedyny właściciel decyzji „w której spółce pracuję"** (zlecenie 07.10 §2). Od 07.10.2026
 * woła go także kontrolka wyboru spółki w kalendarzu — **tym samym POST-em**, nie własnym
 * endpointem. Dwa wejścia do jednej akcji są w porządku; dwa mechanizmy liczące kontekst to
 * usterka, która wychodzi po pół roku.
 */
final class ContextController
{
    /**
     * Ekrany, na które wolno wrócić po przełączeniu — **biała lista NAZW, nie adresów**.
     *
     * ⚠ Gdyby adres powrotu przychodził z żądania, mielibyśmy open redirect (CLAUDE.md §11):
     * `POST /kontekst/5` z `powrot=https://cudza.strona` wyniósłby zalogowaną osobę na obcy
     * adres zaraz po zmianie kontekstu. Dlatego z żądania przychodzi **słowo**, a adres
     * powstaje tutaj.
     *
     * @var array<string, string>
     */

    public function __construct(
        private readonly View $view,
        private readonly EmploymentRepository $employments,
        private readonly AuthSession $session,
        private readonly EventLogRepository $events,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
        private readonly KontekstSpolki $kontekst,
        private readonly AccountRepository $konta,
    ) {
    }

    /** @param array<string, string> $params */
    public function choose(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        $current = $user->activeEmployment()?->employmentId;

        $items = [];
        foreach ($this->employments->activeForAccount($user->accountId()) as $row) {
            $items[] = [
                'id'      => $row['id'],
                'company' => $row['company_name'],
                'fte'     => $row['fte_numerator'] . '/' . $row['fte_denominator'],
                'current' => $row['id'] === $current,
            ];
        }

        return $this->view->render('context.twig', ['employments' => $items]);
    }

    /** @param array<string, string> $params */
    public function switch(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $row = $id === false ? null : $this->employments->findActiveForAccount($id, $user->accountId());
        if ($row === null) {
            // Cudze lub nieistniejące zatrudnienie — bez ujawniania, które z nich.
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        // ⚠ Stan PRZED odczytany przed zapisem — potem sesja go już nie zna (zlecenie 08.10 §1:
        // wpis opisujący zmianę niesie wartość przed i po). `subject_id` i `company_id` mówią,
        // na co przełączono; bez `z` nie da się odtworzyć, skąd.
        $poprzedni = $user->activeEmployment();
        $this->session->setEmploymentId($row['id']);
        // ⚠ …i **trwale spółka tego zatrudnienia**: kontekst ma przeżyć wylogowanie (§1
        // zlecenia z 10.10.2026). Jedna wartość w dwóch miejscach — sesja (szybka) i konto
        // (trwałe); zatrudnienie odtwarza się ze spółki przy następnym zalogowaniu.
        $this->session->setCompanyId((int) $row['company_id']);
        $this->konta->zapiszSpolke($user->accountId(), (int) $row['company_id']);
        $this->events->record('context.switched', $user->accountId(), null, null, 'employment', $row['id'], $row['company_id'], [
            'z_zatrudnienie' => $poprzedni?->employmentId,
            'z_spolka' => $poprzedni?->companyId,
            'na_zatrudnienie' => (int) $row['id'],
            'na_spolka' => (int) $row['company_id'],
        ]);

        return Response::redirectFor($request, $this->powrot($request));
    }

    /**
     * **Przełączenie SPÓŁKI** — dla konta, które nie ma w niej zatrudnienia.
     *
     * ⚠ To **drugi kształt tej samej drogi**, nie druga droga. Rozstrzygnięcie z 07.10 („jedna
     * droga zmiany kontekstu: `POST /kontekst/{employmentId}`") zakładało, że kontekst zawsze
     * da się wskazać zatrudnieniem. Zmierzone 10.10.2026: **wszystkie trzy konta z rolą
     * tenantową mają zero zatrudnień** (w tym konto Krzysztofa), a to one pracują na ekranach,
     * gdzie wybór spółki ma znaczenie. Bez tej akcji znacznik w belce nie miałby czym
     * przełączać na dziewięciu ekranach, a `?spolka=` miało zniknąć.
     *
     * ⚠ Ten sam kontroler, ten sam wpis w dzienniku (`context.switched`), ta sama walidacja
     * przeciw liście czytanej z bazy. Identyfikator z żądania **nie wskazuje spółki wprost**:
     * przechodzi przez `KontekstSpolki::zapamietaj()`, które przyjmuje go tylko wtedy, gdy stoi
     * na liście dozwolonych dla tego konta (CLAUDE.md §10).
     *
     * @param array<string, string> $params
     */
    public function switchCompany(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $poprzednia = $this->kontekst->rozstrzygnij($user->accountId(), $user->activeEmployment())['spolka'];
        $nazwa = $id === false ? null : $this->kontekst->zapamietaj($user->accountId(), $id);
        if ($nazwa === null) {
            // Cudza albo nieistniejąca spółka — jedna odpowiedź na oba przypadki (§10 reguł).
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $this->events->record('context.switched', $user->accountId(), null, null, 'company', $id, $id, [
            'z_spolka' => $poprzednia['id'] ?? null,
            'na_spolka' => $id,
            // ⚠ Jawnie: to przełączenie **widoku**, nie spółki pracy — konto nie ma tu etatu.
            'rodzaj' => 'widok',
        ]);

        return Response::redirectFor($request, $this->powrotZBelki($request));
    }

    /**
     * Adres powrotu po przełączeniu z belki — **z białej listy nazw**.
     *
     * ⚠ Nazwa ekranu przychodzi z formularza w belce, adres powstaje tutaj. Nieznana nazwa
     * wraca na pulpit, a nie do błędu: przełączenie spółki ma zadziałać także wtedy, gdy ktoś
     * doda ekran i zapomni dopisać go do listy — tylko wróci wtedy na pulpit.
     */
    private function powrotZBelki(Request $request): string
    {
        return $this->adresPowrotu($request, 'ekran');
    }

    /**
     * Adres po przełączeniu: ekran, z którego przyszło żądanie, albo pulpit.
     *
     * ⚠ Powrót na kalendarz **z tym samym miesiącem i widokiem** nie jest wygodą: bez tego
     * przełączenie spółki w kalendarzu wyrzucałoby człowieka na pulpit, czyli kontrolka
     * wyglądałaby na zepsutą. Miesiąc i widok **walidujemy tutaj** — do adresu nie wchodzi
     * nic, czego nie rozpoznaliśmy (CLAUDE.md §11).
     */
    private function powrot(Request $request): string
    {
        return $this->adresPowrotu($request, 'powrot');
    }

    /**
     * Adres powrotu — **jedna droga dla obu kształtów przełączenia** (zatrudnienie i spółka).
     *
     * ⚠ Nazwa pola różni się historycznie (`powrot` przy zatrudnieniu, `ekran` przy spółce),
     * ale rozstrzyganie musi być jedno: dwie kopie tej samej logiki rozjechałyby się przy
     * pierwszej zmianie, a objawem byłby powrót gubiący miesiąc tylko na jednym z dwóch
     * rodzajów pozycji w belce.
     */
    private function adresPowrotu(Request $request, string $pole): string
    {
        // ⚠ **Biała lista nazw** (`Support\EkranyBelki`): nazwa nieznana = pulpit, nie błąd.
        $cel = EkranyBelki::adres($request->postString($pole, 24));
        $adres = $this->urls->to($cel);
        if ($cel !== EkranyBelki::EKRANY['kalendarz']) {
            return $adres;
        }
        $widok = $request->postString('widok', 8);
        $miesiac = $request->postString('miesiac', 7);
        $ogon = [];
        // Białą listę widoków trzyma `KalendarzPracownika` — tu tylko pytamy, czy to jeden z nich.
        if (in_array($widok, [KalendarzPracownika::WIDOK_JA, KalendarzPracownika::WIDOK_ZESPOL,
            KalendarzPracownika::WIDOK_FIRMA], true)) {
            $ogon[] = 'widok=' . $widok;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $miesiac) === 1) {
            $ogon[] = 'miesiac=' . $miesiac;
        }

        return $ogon === [] ? $adres : $adres . '?' . implode('&', $ogon);
    }
}
