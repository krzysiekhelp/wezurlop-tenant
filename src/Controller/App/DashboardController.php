<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use DateTimeImmutable;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Support\View;

/**
 * Pulpit pracownika (`/`) — limity, nieobecności i dni wolne, czyli dane, które **już mamy**
 * z programu kadrowego. Zero składania wniosków i zero akceptacji: to Etap 3.
 *
 * ⚠ Konto bez zatrudnienia (administrator tenanta) dostaje **zdanie**, nie pusty ekran ani podglądu
 * cudzego pulpitu. Nowa ścieżka do cudzych danych kadrowych to osobna decyzja, nie „przy okazji”;
 * administrator ma już `/dane/pracownik/{id}` pod uprawnieniem `data.view` (zlecenie §6.2).
 */
final class DashboardController
{
    /**
     * Powody przekierowania na pulpit — **biała lista** (CLAUDE.md §11).
     *
     * ⚠ Oba powstały z jednej sytuacji: zalogowany użytkownik otwiera link do hasła **własnego**
     * konta. Nie ma czego ustawiać, więc przekierowanie jest właściwe, ale bez komunikatu
     * wyglądałoby jak zignorowane kliknięcie (zlecenie `link-hasla-przy-zalogowanej-sesji` §1).
     *
     * ⚠ Żaden powód nie niesie treści zależnej od konkretnej osoby ani firmy — ta reguła
     * obowiązuje od 22.09.2026 dla wszystkich `powod=` w adresach.
     *
     * @var array<string, string>
     */
    private const POWODY = [
        'konto-juz-aktywne' => 'To zaproszenie dotyczy konta, na którym już jesteś zalogowany — '
            . 'hasło jest ustawione. Zmienisz je w ustawieniach konta.',
        'haslo-zalogowany'  => 'Jesteś już zalogowany; nowe hasło ustawisz w ustawieniach konta, '
            . 'bez linku z wiadomości.',
        'haslo-ustawione'   => 'Hasło ustawione — jesteś zalogowany na swoim koncie.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly PracownikRepository $dane,
        private readonly PulpitPracownika $pulpit,
        private readonly EmploymentRepository $employments,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        $powod = $request->query('powod');
        $notice = is_string($powod) ? (self::POWODY[$powod] ?? null) : null;

        $kontekst = $user->activeEmployment();
        // ⚠ Warunek właściciela sprawdzany zapytaniem, a nie brany na słowo z kontekstu (§10).
        $zatrudnienie = $kontekst === null
            ? null
            : $this->dane->mojeZatrudnienie($kontekst->employmentId, $user->accountId());

        if ($zatrudnienie === null) {
            return $this->view->render('dashboard.twig', [
                'notice' => $notice,
                'zatrudnienie' => null,
                'spolki' => [],
                'pulpit' => null,
            ]);
        }

        $dzis = new DateTimeImmutable('today');
        $rok = self::rok($request, $this->dane->lata($zatrudnienie['id']), (int) $dzis->format('Y'));

        return $this->view->render('dashboard.twig', [
            'notice' => $notice,
            'zatrudnienie' => $zatrudnienie,
            // ⚠ Przełącznik spółek to ISTNIEJĄCY przełącznik kontekstu (POST `/kontekst/{id}`),
            // a nie druga droga do tych samych danych. Limit i nieobecności wiszą przy zatrudnieniu,
            // więc jedno aktywne zatrudnienie = jeden zestaw liczb i nie ma czego sumować (§3.3).
            'spolki' => self::spolki($this->employments->accessibleForAccount($user->accountId()), $zatrudnienie['id']),
            'pulpit' => $this->pulpit->pulpit($zatrudnienie, $rok, $dzis->format('Y-m-d')),
        ]);
    }

    /**
     * Rok z adresu — wyłącznie z listy lat, dla których ta osoba **ma** dane (CLAUDE.md §11).
     * Wartość spoza listy wraca do roku bieżącego, a nie do błędu.
     *
     * @param list<int> $lata
     */
    private static function rok(Request $request, array $lata, int $biezacy): int
    {
        $zadany = filter_var($request->queryString('rok', 8), FILTER_VALIDATE_INT);

        return is_int($zadany) && in_array($zadany, $lata, true) ? $zadany : $biezacy;
    }

    /**
     * @param list<array{id: int, company_name: string, fte_numerator: int, fte_denominator: int}> $wiersze
     * @return list<array{id: int, nazwa: string, etat: string, biezaca: bool}>
     */
    private static function spolki(array $wiersze, int $biezace): array
    {
        if (count($wiersze) < 2) {
            // Jedna spółka — nie ma czego przełączać, a przełącznik z jedną pozycją kłamie o wyborze.
            return [];
        }

        return array_map(static fn (array $w): array => [
            'id' => $w['id'],
            'nazwa' => $w['company_name'],
            'etat' => $w['fte_numerator'] . '/' . $w['fte_denominator'],
            'biezaca' => $w['id'] === $biezace,
        ], $wiersze);
    }
}
