<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Response;
use Wezurlop\Http\Session;
use Wezurlop\Security\Totp;
use Wezurlop\Service\MojeKonto;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Service\ZakresWidocznosci;

/**
 * **Jedno miejsce, w którym powstaje odpowiedź ze stroną „Moje konto"** (zlecenie Z69).
 *
 * ⚠ Po co osobna klasa: tę stronę renderują DWA kontrolery — `ProfileController` (widok, telefon,
 * hasło) i `SecurityController` (parowanie 2FA, kody, wyłączenie), bo odmowa musi wracać pełną
 * stroną z otwartym oknem, a nie fragmentem (ekran działa bez JavaScriptu). Przy dwóch kopiach
 * listy zmiennych pierwsza nowa zmienna w szablonie wywróciłaby jedną z dróg na
 * `strict_variables` — i to tę rzadszą, czyli odmowę, którą widzi się najpóźniej.
 *
 * ⚠ Klasa nie podejmuje żadnej decyzji: nie sprawdza uprawnień, nie waliduje, nie zapisuje.
 * Składa model i zmienne widoku — stan rozstrzygają serwisy, a dostęp middleware i trasa.
 */
final class WidokKonta
{
    public function __construct(
        private readonly View $view,
        private readonly MojeKonto $konto,
        private readonly TwoFactorService $twoFactor,
        private readonly Session $session,
    ) {
    }

    /**
     * @param string|null $okno które okno ma być otwarte (`telefon`, `haslo`, `2fa`, `2fa-wylacz`, `kody`)
     * @param list<string>|null $kody kody zapasowe pokazywane JEDEN raz; `null` = czytaj z sesji
     */
    public function strona(
        CurrentUser $user,
        ?string $okno = null,
        ?string $error = null,
        int $status = 200,
        ?array $kody = null,
        ?string $potwierdzenie = null,
        string $wpisanyTelefon = '',
        string $trybKodu = '',
    ): Response {
        $model = $this->konto->model($user, $this->session->id());
        // ⚠ Kody czytamy z sesji także wtedy, gdy kontroler ich nie podał: po włączeniu 2FA
        // przekierowanie trafia na `/konto?okno=kody`, czyli do zwykłego widoku strony — gdyby
        // ten odczyt był tylko w akcji 2FA, człowiek zobaczyłby okno kodów BEZ kodów.
        $kody ??= $this->twoFactor->codesToShow($user->accountId());
        $sekret = $this->twoFactor->pendingSecret($user->accountId());

        return $this->view->render('profile.twig', $model + [
            'okno'            => $okno,
            'error'           => $error,
            'potwierdzenie'   => $potwierdzenie,
            'wpisany_telefon' => $wpisanyTelefon,
            // Którą drogą potwierdzamy wyłączenie 2FA: kodem z aplikacji czy zapasowym (Z69 pkt 7).
            'tryb_kodu'       => $trybKodu,
            'nazwy_zakresow'  => ZakresWidocznosci::nazwy(),
            'kody'            => $kody,
            // Sekret do przepisania ręcznego — tylko w trakcie parowania, nigdy po włączeniu.
            'sekret'          => $sekret === null ? null : Totp::formatSecret($sekret),
            'parowanie'       => $sekret !== null,
            // ⚠ Moduł wygód wczytuje się TYLKO na tym ekranie (ta sama zasada, co przy htmx
            // i pozostałych modułach): plik na każdej stronie to koszt bez pożytku.
            'konto_js'        => true,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
