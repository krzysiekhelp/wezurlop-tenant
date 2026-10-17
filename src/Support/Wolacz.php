<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Imię w wołaczu — **tylko wtedy, gdy umiemy odmienić** (zlecenie `szablon-poczty` §4).
 *
 * ⚠ Zasada jest wąska celowo: odmieniamy **wyłącznie przypadek pewny**, a w pozostałych
 * zwracamy `null`, żeby wiadomość napisała „Dzień dobry." bez imienia. Powód: forma
 * przekręcona jest gorsza niż brak formy — pierwsza wiadomość od pracodawcy, która wita
 * pracownika zniekształconym imieniem, wygląda na wysłaną przez automat z cudzej listy.
 *
 * ⚠ Odmieniamy imiona żeńskie na `-a` → `-o` (Anna → Anno, Ewa → Ewo, Kinga → Kingo,
 * Maria → Mario). To jedyna reguła polskiego wołacza, która nie ma praktycznych wyjątków
 * w imionach. ⚠ Imion męskich **nie odmieniamy**: wołacz ma tam co najmniej pięć wzorców
 * (Piotr → Piotrze, Marek → Marku, Tomasz → Tomaszu, Jakub → Jakubie, Antoni → Antoni),
 * a rozpoznanie wzorca po końcówce myli się na nazwach obcych i zdrobnieniach.
 * Gdyby ktoś chciał to rozszerzyć: **potrzebny jest słownik**, nie kolejny warunek.
 */
final class Wolacz
{
    /**
     * ⚠ Imiona żeńskie na `-a`, które w wołaczu mają `-u`, nie `-o` (zdrobnienia). Lista jest
     * krótka i zamknięta — jeśli imienia tu nie ma i nie kończy się na `-a`, zwracamy `null`.
     *
     * @var list<string>
     */
    private const NA_U = ['ania', 'hania', 'kasia', 'basia', 'zosia', 'marysia', 'gosia', 'jagoda'];

    /** @return string|null forma wołacza albo `null`, gdy nie umiemy odmienić */
    public static function imie(string $imie): ?string
    {
        $imie = trim($imie);
        // Dwa imiona, inicjał, imię z myślnikiem — nie odmieniamy, bo nie wiadomo, co odmieniać.
        if ($imie === '' || mb_strlen($imie) < 3 || preg_match('/^\p{Lu}\p{Ll}+$/u', $imie) !== 1) {
            return null;
        }
        $male = mb_strtolower($imie);
        if (in_array($male, self::NA_U, true)) {
            return mb_substr($imie, 0, -1) . 'u';
        }
        // ⚠ `-ia` też daje `-o` (Maria → Mario, Julia → Julio), więc nie wydzielamy tego osobno.
        if (str_ends_with($male, 'a')) {
            return mb_substr($imie, 0, -1) . 'o';
        }

        return null;
    }
}
