<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\Config;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\View;
use Wezurlop\Support\WidokKonta;

/**
 * Ustawienia 2FA własnego konta (docs/szkielet-tenanta.md rozdz. 6).
 * Kod QR osobnym endpointem jako SVG — w szablonie zwykły <img>, bez |raw.
 */
final class SecurityController
{
    public function __construct(
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly PermissionRepository $permissions,
        private readonly ClientFingerprint $fingerprint,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
        // ⚠ Od Z69 ekranem 2FA jest „Moje konto": te akcje renderują TĘ stronę z otwartym oknem,
        // zamiast własnego widoku. Logika parowania została tu, gdzie była — przeniósł się tylko
        // ekran, bo dwa miejsca wołające `confirmPairing()` to dwa liczniki prób.
        private readonly WidokKonta $widok,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, string> $params */
    /**
     * ⚠ **Stary adres `/ustawienia/2fa` tylko przekierowuje** (Z69, rozstrzygnięcie zarządcy):
     * POST-y zostają, bo są zaudytowane i wołane z nowych okien, a GET nie ma już czego pokazać.
     * Usunięcie trasy zamiast przekierowania zostawiłoby 404 pod adresem, który ludzie mają
     * w zakładkach i który stoi w naszej własnej dokumentacji.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return Response::redirectFor($request, $this->urls->to('/konto'));
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        $user = $this->user($request);
        if (!$user->twoFactorEnabled) {
            $this->twoFactor->startPairing($user->accountId());
        }

        return Response::redirectFor($request, $this->urls->to('/konto') . '?okno=2fa');
    }

    /** @param array<string, string> $params */
    public function qr(Request $request, array $params): Response
    {
        $svg = $this->twoFactor->qrSvg($this->user($request)->accountId());
        if ($svg === null) {
            return $this->errors->errorPage(404);
        }

        return (new Response($svg, 200, ['Content-Type' => 'image/svg+xml']))
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function enable(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $error = $this->twoFactor->confirmPairing(
            $user->accountId(),
            $request->postString('code', 16),
            $this->fingerprint->ipHash($request->serverParams()),
        );
        if ($error !== null) {
            return $this->stronaKonta($user, '2fa', $error, 422);
        }

        return Response::redirectFor($request, $this->urls->to('/konto') . '?okno=kody');
    }

    /** @param array<string, string> $params */
    public function codes(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $codes = $this->twoFactor->codesToShow($user->accountId());
        if ($codes === null) {
            return Response::redirectFor($request, $this->urls->to('/konto'));
        }

        return $this->stronaKonta($user, 'kody', null, 200, $codes);
    }

    /**
     * Pobranie kodów jako pliku tekstowego — tylko dopóki czekają na potwierdzenie.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $codes = $this->twoFactor->codesToShow($this->user($request)->accountId());
        if ($codes === null) {
            return $this->errors->errorPage(404);
        }
        $body = "wezurlop — kody zapasowe 2FA\nKażdy kod działa jeden raz.\n\n" . implode("\n", $codes) . "\n";

        return Response::text($body)
            ->withHeader('Content-Disposition', 'attachment; filename="wezurlop-kody-zapasowe.txt"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function acknowledge(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $codes = $this->twoFactor->codesToShow($user->accountId());
        if ($codes === null) {
            return Response::redirectFor($request, $this->urls->to('/konto'));
        }
        if ($request->postString('saved', 3) !== '1') {
            return $this->stronaKonta($user, 'kody',
                'Potwierdź, że kody są zapisane w bezpiecznym miejscu.', 422, $codes);
        }
        $this->twoFactor->acknowledgeCodes($user->accountId());

        return Response::redirectFor($request, $this->urls->to('/konto') . '?ok=2fa-wlaczone');
    }

    /**
     * Wyłączenie 2FA — hasło **i** kod: z aplikacji albo zapasowy (Z69 pkt 7).
     *
     * ⚠ **Odmowa po stronie serwera, gdy wymóg obowiązuje**, a nie samo ukrycie przycisku:
     * ekran przy `ADMIN_2FA_REQUIRED=1` pokazuje administratorowi zdanie zamiast przycisku,
     * ale żądanie da się wysłać z ręki (CLAUDE.md §10 — o dostępie rozstrzyga serwer).
     *
     * @param array<string, string> $params
     */
    public function disable(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $error = $this->twoFactor->disable(
            $user->accountId(),
            $request->postString('password', 4096),
            $request->postString('code', 32),
            $this->fingerprint->ipHash($request->serverParams()),
            $request->postString('tryb', 10) === 'zapasowy',
            // ⚠ Wymóg rozstrzyga kontroler (zna konfigurację i rolę), a ODMAWIA serwis —
            // tam, gdzie regułę da się sprawdzić testem.
            $this->wymoganeDlaKonta($user),
        );
        if ($error !== null) {
            return $this->stronaKonta($user, '2fa-wylacz', $error, 422);
        }

        return Response::redirectFor($request, $this->urls->to('/konto') . '?ok=2fa-wylaczone');
    }

    /**
     * Nowe kody zapasowe (Z69 pkt 8) — stare przestają działać, nowe widać raz.
     *
     * @param array<string, string> $params
     */
    public function regenerate(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $error = $this->twoFactor->regenerateCodes(
            $user->accountId(),
            $request->postString('password', 4096),
            $this->fingerprint->ipHash($request->serverParams()),
        );
        if ($error !== null) {
            return $this->stronaKonta($user, 'kody-nowe', $error, 422);
        }

        return Response::redirectFor($request, $this->urls->to('/konto') . '?okno=kody&ok=kody-nowe');
    }

    /** Czy TO konto musi mieć 2FA: konfiguracja ORAZ rola (punkt 10 zlecenia Z69). */
    private function wymoganeDlaKonta(CurrentUser $user): bool
    {
        return $this->config->adminTwoFactorRequired
            && $this->permissions->requiresTwoFactor($user->accountId());
    }

    /**
     * Strona „Moje konto" z otwartym oknem — przez `WidokKonta`, tą samą drogą co
     * `ProfileController`. ⚠ Odmowa wraca PEŁNĄ STRONĄ, nie fragmentem: ekran ma działać bez
     * JavaScriptu, a bez niego nie ma czego podmieniać (ta sama zasada, co w panelu osoby, Z64).
     *
     * @param list<string>|null $kody
     */
    private function stronaKonta(
        CurrentUser $user,
        ?string $okno,
        ?string $error,
        int $status = 200,
        ?array $kody = null,
    ): Response {
        return $this->widok->strona($user, $okno, $error, $status, $kody);
    }

    private function user(Request $request): CurrentUser
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);

        return $user;
    }
}
