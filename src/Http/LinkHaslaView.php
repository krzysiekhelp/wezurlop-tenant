<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Security\AuthSession;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Service\LinkHasla;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Odpowiedź na link do hasła otwarty przy zalogowanej sesji — **jedna dla obu ścieżek**
 * (zaproszenie i reset hasła).
 *
 * ⚠ Decyzję podejmuje `Service\LinkHasla` (co się dzieje), tutaj jest wyłącznie **czym na to
 * odpowiadamy** (przekierowanie albo ekran wyboru). Rozdzielenie jest po to, żeby reguła dała
 * się sprawdzić bez HTTP, a kontrolery nie miały dwóch kopii tego samego `if`-a.
 *
 * `null` z `odpowiedz()` znaczy „idź dalej zwykłą ścieżką" — czyli pokaż formularz hasła.
 */
final class LinkHaslaView
{
    public function __construct(
        private readonly LinkHasla $decyzja,
        private readonly View $view,
        private readonly ClientFingerprint $fingerprint,
        private readonly AuthSession $session,
        private readonly AccountRepository $accounts,
        private readonly UrlGenerator $urls,
    ) {
    }

    /**
     * Po ustawieniu hasła z linku — **świeża sesja właściciela tego konta** (§2.3 zlecenia).
     *
     * ⚠ Poprzednia sesja nie może się przenieść: `AuthSession::complete()` robi
     * `session->reset()`, czyli nowy identyfikator (CLAUDE.md §8, ochrona przed utrwaleniem
     * sesji). Do tego trafia się tu **wyłącznie** po udanym zapisie hasła, więc token był
     * dowodem dostępu do skrzynki.
     *
     * ⚠ Konto z włączonym 2FA dostaje sesję **częściową**, nie pełną: hasło jest dopiero
     * pierwszym składnikiem, a ustawienie go z linku nie ma prawa omijać drugiego (§9 reguł).
     * Dlatego ta metoda istnieje w jednym miejscu — w dwóch kontrolerach różnica zniknęłaby
     * przy pierwszej poprawce.
     */
    public function poUstawieniuHasla(Request $request, int $accountId): Response
    {
        $konto = $this->accounts->findById($accountId);
        $klient = ClientKind::fromRequest($request);
        if ($konto !== null && ($konto['totp_enabled_at'] ?? null) !== null) {
            $this->session->beginPartial($accountId, $klient);

            return Response::redirectFor($request, $this->urls->to('/logowanie/kod'));
        }
        $this->session->complete($accountId, $klient);

        return Response::redirectFor($request, $this->urls->to('/') . '?powod=haslo-ustawione');
    }

    /**
     * @param string $rodzaj `zaproszenie` albo `reset`
     */
    public function odpowiedz(Request $request, int $kontoZTokenu, string $rodzaj, string $token): ?Response
    {
        $user = $request->attribute(Authenticate::USER);
        $zalogowany = $user instanceof CurrentUser ? $user : null;
        $stan = $this->decyzja->stan(
            $zalogowany,
            $kontoZTokenu,
            $rodzaj,
            $this->fingerprint->ipHash($request->serverParams()),
        );

        if ($stan['stan'] === LinkHasla::STAN_NIEZALOGOWANY) {
            return null;
        }
        if ($stan['stan'] === LinkHasla::STAN_WLASNE) {
            // ⚠ Przekierowanie do aplikacji, bo nie ma czego ustawiać — a nie formularz, który
            // sugerowałby, że trzeba. Komunikat mówi, GDZIE zmienia się hasło zalogowanemu.
            return Response::redirectFor($request, $this->urls->to('/') . '?powod=' . ($rodzaj === 'reset'
                ? 'haslo-zalogowany'
                : 'konto-juz-aktywne'));
        }

        // ⚠ Ekran wyboru. Token wraca do szablonu, bo osoba, która tu trafiła, ma go w pasku
        // adresu — nie jest to dla niej nowa informacja. Do dziennika i do logów nie trafia.
        return $this->view->render('page/auth/link-inna-sesja.twig', [
            'zalogowany'  => $zalogowany?->displayName ?? '',
            'wlasciciel'  => $stan['wlasciciel'],
            'rodzaj'      => $rodzaj,
            'token'       => $token,
        ], 200)->withHeader('Cache-Control', 'no-store');
    }
}
