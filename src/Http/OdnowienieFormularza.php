<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Support\View;

/**
 * Formularz PRZED zalogowaniem wysłany z wygasłym tokenem CSRF — **siatka po stronie serwera**
 * (zlecenie Z56 §1, zgłoszenie Krzysztofa: strona logowania otwarta długo, po wpisaniu danych
 * komunikat „formularz wygasł").
 *
 * ⚠ **To nie jest poluzowanie ochrony CSRF.** Token jest dalej wymagany: żądanie z wygasłym
 * tokenem **nie loguje, nie wysyła poczty i nie zapisuje hasła**. Zmienia się wyłącznie
 * odpowiedź — zamiast strony błędu 419 wraca ten sam formularz ze świeżym tokenem i z tym,
 * co człowiek już wpisał (poza hasłem). Z punktu widzenia napastnika nic nie zyskuje:
 * dostaje stronę, którą i tak może sobie otworzyć sam.
 *
 * ⚠ **Hasła NIE przenosimy** i nie próbujemy go użyć w tym żądaniu. Przeniesione hasło byłoby
 * dokładnie tym, przed czym broni token: żądaniem wywołanym z obcej strony, które mimo braku
 * tokenu doszłoby do skutku „po poprawieniu".
 *
 * ⚠ **Nieudana próba logowania liczy się tak samo jak dziś** (`RateLimiter`, licznik adresu).
 * Inaczej wygasły token byłby drogą obejścia limitu: wystarczyłoby wysyłać hasła z byle jakim
 * `_csrf`, żeby nie zostawić śladu w licznikach.
 *
 * ⚠ **Dotyczy wyłącznie czterech formularzy sprzed zalogowania** — pozostałe 419 zostają bez
 * zmiany. Ekran wewnątrz aplikacji ma inny ciężar: tam wygasła sesja znaczy „zalogowano kogoś
 * innego albo minął czas", a cichy powrót do formularza ukryłby to, że zapis się nie wykonał.
 *
 * `null` z `odpowiedz()` znaczy „to nie jest ten przypadek" — czyli zwykła strona 419.
 */
final class OdnowienieFormularza
{
    /** ⚠ Spokojne zdanie, bez czerwieni i bez słowa „błąd" — nic się nie zepsuło (§1 zlecenia). */
    public const KOMUNIKAT_LOGOWANIE = 'Strona była otwarta długo — zaloguj się jeszcze raz.';
    public const KOMUNIKAT_RESET = 'Strona była otwarta długo — podaj adres jeszcze raz.';
    public const KOMUNIKAT_HASLO = 'Strona była otwarta długo — wpisz hasło jeszcze raz.';

    /**
     * Formularze objęte siatką: `METODA /ścieżka` → szablon, komunikat, przenoszone pola.
     *
     * ⚠ `przenosi` to **biała lista pól**, nie „wszystko oprócz hasła" (CLAUDE.md §11).
     * Czarna lista przy pierwszym nowym polu przepuściłaby je bez zastanowienia.
     *
     * ⚠ `liczy_probe` wyłącznie przy logowaniu. Prośba o reset ma własny, twardy limit, ale
     * na tej drodze **nie powstaje żadna poczta ani token**, więc nie ma czego ograniczać;
     * doliczenie jej do limitu zabierałoby człowiekowi jedną z trzech prób resetu za to,
     * że miał otwartą kartę.
     *
     * @var array<string, array{szablon: string, komunikat: string, przenosi: list<string>, liczy_probe: bool}>
     */
    private const FORMULARZE = [
        'POST /logowanie' => [
            'szablon'     => 'page/auth/login.twig',
            'komunikat'   => self::KOMUNIKAT_LOGOWANIE,
            'przenosi'    => ['email'],
            'liczy_probe' => true,
        ],
        'POST /haslo/reset' => [
            'szablon'     => 'page/auth/reset-request.twig',
            'komunikat'   => self::KOMUNIKAT_RESET,
            'przenosi'    => ['email'],
            'liczy_probe' => false,
        ],
        'POST /haslo/nowe' => [
            'szablon'     => 'page/auth/reset-password.twig',
            'komunikat'   => self::KOMUNIKAT_HASLO,
            'przenosi'    => ['token'],
            'liczy_probe' => false,
        ],
        'POST /zaproszenie' => [
            'szablon'     => 'page/auth/invitation.twig',
            'komunikat'   => self::KOMUNIKAT_HASLO,
            'przenosi'    => ['token'],
            'liczy_probe' => false,
        ],
    ];

    /** Najdłuższe przenoszone wartości — tyle samo, ile przyjmuje kontroler tego formularza. */
    private const DLUGOSCI = ['email' => 254, 'token' => 64];

    public function __construct(
        private readonly View $view,
        private readonly RateLimiter $limiter,
        private readonly ClientFingerprint $fingerprint,
    ) {
    }

    /**
     * @return array{szablon: string, komunikat: string, przenosi: list<string>, liczy_probe: bool}|null
     */
    public static function formularz(string $method, string $path): ?array
    {
        return self::FORMULARZE[$method . ' ' . self::bezWariantu($path)] ?? null;
    }

    /**
     * Ścieżka bez przedrostka wariantu mobilnego — `/m/logowanie` to ten sam formularz
     * co `/logowanie` (config/routes.php: trasy wspólne powstają dwa razy).
     */
    public static function bezWariantu(string $path): string
    {
        $bez = (string) preg_replace('#^/m(?=/|$)#', '', $path);

        return $bez === '' ? '/' : $bez;
    }

    /**
     * Próg odświeżenia tokenu w przeglądarce: **połowa czasu bezczynności sesji**, nie liczba
     * wpisana w kod (§2 zlecenia). Dolne ograniczenie jest po to, żeby krótki czas sesji
     * na środowisku testowym nie zamienił odświeżenia w ruch przy każdym przełączeniu karty.
     */
    public static function progSekund(int $idleTimeout): int
    {
        return max(60, intdiv($idleTimeout, 2));
    }

    /**
     * Zmienne szablonu: komunikat, przeniesione pola i stan „nic się nie wydarzyło".
     *
     * ⚠ Czysta funkcja — żeby dało się sprawdzić bez HTTP i bez bazy, co trafia do formularza
     * (a przede wszystkim: że hasła tam nie ma).
     *
     * @param array{szablon: string, komunikat: string, przenosi: list<string>, liczy_probe: bool} $formularz
     * @return array<string, mixed>
     */
    public static function dane(array $formularz, Request $request): array
    {
        $dane = ['notice' => $formularz['komunikat'], 'notice_rodzaj' => 'info', 'error' => null];
        foreach ($formularz['przenosi'] as $pole) {
            $dane[$pole] = $request->postString($pole, self::DLUGOSCI[$pole]);
        }
        if ($formularz['szablon'] === 'page/auth/reset-request.twig') {
            $dane['sent'] = false;
        }
        if (in_array('token', $formularz['przenosi'], true)) {
            $dane['min_length'] = Password::MIN_LENGTH;
        }

        return $dane;
    }

    public function odpowiedz(Request $request, Route $route): ?Response
    {
        $formularz = self::formularz($route->method, $route->path);
        if ($formularz === null) {
            return null;
        }
        if ($formularz['liczy_probe']) {
            // Hasła nie sprawdzamy, więc konta nie znamy — i nie szukamy go po adresie
            // z żądania bez tokenu: licznik konta dałby się wtedy podkręcać komukolwiek
            // bez jednej próby hasła. Licznik adresu zamyka drogę obejścia i tyle wystarcza.
            $this->limiter->record(
                null,
                $this->fingerprint->ipHash($request->serverParams()),
                RateLimiter::KIND_PASSWORD,
                false,
            );
        }

        // Świeży token jedzie w treści, więc odpowiedź nie może zostać w pamięci przeglądarki.
        return $this->view->render($formularz['szablon'], self::dane($formularz, $request))
            ->withHeader('Cache-Control', 'no-store');
    }
}
