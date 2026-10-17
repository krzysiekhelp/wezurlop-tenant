<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Support\Maska;
use Wezurlop\Support\NazwaOsoby;

/**
 * Co zrobić, gdy link do ustawienia hasła otwiera **zalogowana** osoba
 * (zlecenie `link-hasla-przy-zalogowanej-sesji`).
 *
 * ⚠ **Jedna klasa dla obu linków** — zaproszenia i resetu hasła. Reguła jest ta sama, a dwie
 * kopie w dwóch kontrolerach to ten sam wzorzec, przez który mieliśmy dwie nazwy nagłówka strony
 * i dwa paski komunikatu.
 *
 * Trzy stany, bo dwa przypadki wyglądają podobnie i znaczą coś zupełnie innego:
 *
 *  - `STAN_NIEZALOGOWANY` — zwykła ścieżka, formularz hasła jak dotąd,
 *  - `STAN_WLASNE` — link dotyczy konta, na które ta osoba jest zalogowana. Nie ma czego
 *    ustawiać; przekierowanie do aplikacji z komunikatem,
 *  - ⚠ `STAN_CUDZE` — link dotyczy **innego** konta. Tu **nie wolno** ani pokazać formularza,
 *    ani przekierować:
 *      • formularz to dzisiejsze zachowanie i jest najgorsze z trzech — zalogowany administrator,
 *        który otworzy cudze zaproszenie, **po cichu ustawia hasło cudzemu kontu i je aktywuje**
 *        (zmierzone: dokładnie tak aktywowało się konto #14),
 *      • przekierowanie posadziłoby pracownika na **cudzym pulpicie**, z cudzymi danymi
 *        kadrowymi, w przekonaniu, że to jego konto.
 *    Zostaje ekran z wyborem — decyzję podejmuje człowiek, nie my za niego.
 *
 * ⚠ Czego ta klasa **nie robi** i to jest istotne:
 *  1. **nie zużywa i nie unieważnia tokenu** — kto trafił tu przez pomyłkę, musi móc otworzyć
 *     ten link ponownie na swoim komputerze,
 *  2. **nie wylogowuje nikogo** — gdyby otwarcie linku kończyło cudzą sesję, wystarczyłoby
 *     podesłać komuś dowolny link, żeby wyrzucić go z pracy w połowie wniosku. Wylogowanie
 *     wyłącznie po kliknięciu przycisku.
 *
 * ⚠ Rozpoznanie „to samo konto" idzie po **identyfikatorze konta z tokenu**, nigdy po adresie
 * e-mail: adres da się zmienić (i właśnie zbudowaliśmy na to drogę), konto nie.
 *
 * ⚠ Sesja **przed 2FA** nie jest tu zalogowana i nie wymaga osobnego warunku: `Authenticate`
 * ustawia atrybut `USER` tylko dla sesji pełnej, a sesja częściowa dostaje `PARTIAL_ACCOUNT`.
 * Dla linku wygląda więc jak brak logowania — czyli tak, jak ma być (§3).
 */
final class LinkHasla
{
    public const STAN_NIEZALOGOWANY = 'niezalogowany';
    public const STAN_WLASNE = 'wlasne';
    public const STAN_CUDZE = 'cudze';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * @param int $kontoZTokenu konto, do którego należy link (z tokenu, nie z adresu)
     * @param string $rodzaj `zaproszenie` albo `reset` — wyłącznie do dziennika i treści ekranu
     * @return array{stan: string, wlasciciel: array{imie_nazwisko: string, email: string}|null}
     */
    public function stan(?CurrentUser $zalogowany, int $kontoZTokenu, string $rodzaj, ?string $ipHash): array
    {
        if ($zalogowany === null) {
            return ['stan' => self::STAN_NIEZALOGOWANY, 'wlasciciel' => null];
        }
        if ($zalogowany->accountId() === $kontoZTokenu) {
            return ['stan' => self::STAN_WLASNE, 'wlasciciel' => null];
        }

        // ⚠ Wpis w dzienniku: otwarcie cudzego linku przy zalogowanej sesji to albo pomyłka,
        // albo próba — jedno i drugie chce się widzieć. ⚠ BEZ TOKENU w ładunku (CLAUDE.md §14);
        // zapisujemy, czyj link i kto go otworzył, bo to są identyfikatory kont, nie sekrety.
        $this->events->record('auth.password_link_other_session', $zalogowany->accountId(), $ipHash,
            $zalogowany->displayName, 'account', $kontoZTokenu, null, ['rodzaj' => $rodzaj]);

        $konto = $this->accounts->findById($kontoZTokenu);

        return [
            'stan' => self::STAN_CUDZE,
            // ⚠ Imię, nazwisko i ZAMASKOWANY adres — ten ekran widzi ktoś, kto nie jest
            // właścicielem tego konta i nie ma prawa dowiedzieć się z niego więcej (§2.6).
            'wlasciciel' => $konto === null ? null : [
                'imie_nazwisko' => NazwaOsoby::imieNazwisko(
                    (string) ($konto['first_name'] ?? ''),
                    (string) ($konto['last_name'] ?? ''),
                ),
                'email' => Maska::email((string) ($konto['email'] ?? '')),
            ],
        ];
    }
}
