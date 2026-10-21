<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Session;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\DeviceService;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\View;

/**
 * „Twoje urządzenia” — aktywne sesje konta (docs/szkielet-tenanta.md rozdz. 6).
 * Zmiany wyłącznie przez POST; właściciel sesji sprawdzany w zapytaniu (IDOR).
 */
final class DeviceController
{
    public function __construct(
        private readonly View $view,
        private readonly DeviceService $devices,
        private readonly Session $session,
        private readonly ClientFingerprint $fingerprint,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /**
     * Lista urządzeń.
     *
     * ⚠ **Od Z69 ekranem urządzeń jest „Moje konto"**, więc zwykłe wejście tu przekierowuje —
     * tak samo jak `/ustawienia/2fa`. Trasa zostaje, bo htmx odświeża nią samą listę
     * (`hx-get` z karty „Bezpieczeństwo"), a 404 pod adresem z zakładek nikomu nie pomaga.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $user = $this->user($request);
        if (!$request->isHtmx()) {
            return Response::redirectFor($request, $this->urls->to('/konto'));
        }

        return $this->view->render('fragments/konto-urzadzenia.twig', [
            'urzadzenia' => $this->devices->listForAccount($user->accountId(), $this->session->id()),
            'zakonczono' => $request->query('zakonczono') === '1',
        ]);
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $ok = $this->devices->revoke(
            $user->accountId(),
            $request->postString('session', 64),
            $this->session->id(),
            $this->fingerprint->ipHash($request->serverParams()),
        );
        if (!$ok) {
            // Cudza, bieżąca lub nieistniejąca sesja — bez ujawniania, która z nich.
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->afterChange($request, $user);
    }

    /** @param array<string, string> $params */
    public function revokeOthers(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $this->devices->revokeOthers($user->accountId(), $this->session->id(), $this->fingerprint->ipHash($request->serverParams()));

        return $this->afterChange($request, $user);
    }

    /** Po zmianie: htmx dostaje fragment ze STANEM Z BAZY, zwykły formularz — przekierowanie (PRG). */
    private function afterChange(Request $request, CurrentUser $user): Response
    {
        if ($request->isHtmx()) {
            return $this->view->render('fragments/konto-urzadzenia.twig', [
                'urzadzenia' => $this->devices->listForAccount($user->accountId(), $this->session->id()),
                'zakonczono' => true,
            ]);
        }

        // ⚠ Potwierdzenie przez `?ok=`, a nie `?zakonczono=1`: „Moje konto" ma jeden słownik
        // zdań potwierdzeń (`ProfileController::POTWIERDZENIA`), więc liczba komunikatów nie
        // rośnie razem z liczbą akcji.
        return Response::redirectFor($request, $this->urls->to('/konto') . '?ok=' . $this->ok($request));
    }

    /** Które zdanie potwierdzenia: jedno urządzenie czy pozostałe. */
    private function ok(Request $request): string
    {
        return $request->postString('session', 64) === '' ? 'sesje-zakonczone' : 'sesja-zakonczona';
    }

    private function user(Request $request): CurrentUser
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);

        return $user;
    }
}
