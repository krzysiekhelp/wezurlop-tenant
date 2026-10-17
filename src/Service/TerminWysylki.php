<?php

declare(strict_types=1);

namespace Wezurlop\Service;

/**
 * Odpowiedź na pytanie **„kiedy to wyjdzie"** — jedno rozstrzygnięcie dla wszystkich miejsc,
 * które mówią „przyjęte do wysyłki" (zlecenie `kolejka-widoczna-i-kiedy-wyjdzie` §2).
 *
 * ⚠ Powód jest zmierzony minuta po minucie: kliknięcie 09:41:47, przebieg crona 09:45,
 * wysyłka 09:45:02 — **wiadomość wyszła 3 minuty i 15 sekund po kliknięciu**, a Krzysztof
 * sprawdzał skrzynkę wcześniej i uznał, że nic nie działa. Kolejka zachowała się poprawnie;
 * to interfejs nie odpowiadał na jedyne pytanie, które ma człowiek po kliknięciu: **kiedy**.
 *
 * ⚠ U klienta jest gorzej niż u nas: na hostingu klasy home.pl cron bywa co 15 minut albo
 * rzadziej (CLAUDE.md §0). Administrator wyśle, sprawdzi po minucie, kliknie pięć razy,
 * dostanie odmowę z odstępu i **będzie pewien, że system jest zepsuty**.
 *
 * Cztery stany, bo cztery różne odpowiedzi:
 *
 *  - `awaria`   — cron nie przebiegł od dawna albo nigdy. To jedyny przypadek, w którym
 *                 „nie przychodzi" naprawdę znaczy usterkę, i musi wyglądać inaczej niż czekanie,
 *  - `minuty`   — cron częsty (do 15 minut): „w ciągu około N minut",
 *  - `godzina`  — cron rzadki: **godzina** najbliższego przebiegu, nie „wkrótce",
 *  - `nieznany` — częstotliwości nie znamy; mówimy to wprost i odsyłamy do Ustawień.
 *
 * ⚠ **Ta klasa nie formatuje czasu.** Zwraca znaczniki UTC, a datę składa `czas_pelny()`
 * i `czas_godzina()` w szablonie — jedno miejsce, w którym UTC staje się czasem użytkownika
 * (docs/system-wizualny.md §10). Zdanie mieszka w `partials/termin-wysylki.twig`, bo tekst
 * dla człowieka to widok, nie logika (CLAUDE.md §5).
 *
 * ⚠ Prognoza jest **bez sekund**, pomiar z sekundami (reguła przyjęta 22.09.2026): liczba ma
 * nieść tyle pewności, ile jej naprawdę za nią stoi.
 */
final class TerminWysylki
{
    /** Powyżej tego progu podajemy godzinę najbliższego przebiegu, nie „w ciągu N minut". */
    public const PROG_CZESTEGO_CRONA = 15;

    public function __construct(private readonly CronStan $cron)
    {
    }

    /**
     * @return array{
     *     stan: string, awaria: bool, minuty: int, wiek: ?int,
     *     ostatni_utc: ?string, najblizszy_utc: ?string, zapasowy: bool
     * }
     */
    public function stan(): array
    {
        $cron = $this->cron->stan();
        $stan = match (true) {
            $cron['awaria']                                     => 'awaria',
            $cron['minuty'] > 0
                && $cron['minuty'] <= self::PROG_CZESTEGO_CRONA  => 'minuty',
            $cron['najblizszy_utc'] !== null                    => 'godzina',
            default                                             => 'nieznany',
        };

        return [
            'stan'           => $stan,
            'awaria'         => $stan === 'awaria',
            'minuty'         => $cron['minuty'],
            'wiek'           => $cron['wiek'],
            'ostatni_utc'    => $cron['ostatni_utc'],
            'najblizszy_utc' => $cron['najblizszy_utc'],
            // ⚠ Drugi wyzwalacz kolejki (CLAUDE.md §0): przy niepotwierdzonym cronie pilne
            // zadania domykają się PO ODPOWIEDZI na zwykłe żądanie. Zmierzone 22.09.2026:
            // przy cronie cofniętym o 40 minut komunikat mówił „nie wyjdzie", a wiadomość
            // wyszła sekundę później właśnie tą drogą. Zdanie musi to mówić, bo inaczej
            // straszymy awarią tam, gdzie poczta chodzi.
            'zapasowy'       => $cron['zapasowy_czynny'],
        ];
    }
}
