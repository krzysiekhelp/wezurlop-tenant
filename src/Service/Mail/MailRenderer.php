<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Wezurlop\Support\Strefa;

/**
 * Treść wiadomości: **jeden zestaw zmiennych, dwa wyjścia** (HTML i tekst).
 *
 * ⚠ To jest cała racja istnienia tej klasy. `AltBody` pisany drugi raz z ręki rozjeżdża się
 * z HTML-em przy pierwszej poprawce, a rozjazdu nikt nie zauważy, bo wersję tekstową czyta
 * ułamek odbiorców. Tutaj obie wersje biorą **te same dane** i renderują się w jednym wywołaniu,
 * więc pominięcie jednej z nich wymagałoby usunięcia szablonu, a nie przeoczenia.
 *
 * ⚠ **Własny, minimalny Twig, osobny od widoków strony.** Dwa powody, oba praktyczne:
 * wiadomości renderuje **kolejka zadań** (CLI, `bin/run-jobs.php`), gdzie nie ma ani
 * `UrlGenerator`, ani funkcji globalnych widoku; a szablon poczty nie ma prawa wołać `url()`
 * czy `can()` — linki dostaje gotowe w zmiennych, bo buduje je `Config::absoluteUrl()`
 * z `APP_URL`, nigdy z nagłówka `Host` (CLAUDE.md §11).
 *
 * ⚠ **Wersja tekstowa renderuje się z `autoescape` WYŁĄCZONYM** i to jest jedyne takie miejsce
 * w projekcie. Powód: w treści tekstowej nie istnieje kontekst HTML, więc nie ma czego
 * wstrzyknąć — a escapowanie psułoby treść (nazwisko „Jan & Anna" przyszłoby do odbiorcy jako
 * „Jan &amp; Anna"). Wersja HTML ma `autoescape` włączony jak każdy inny szablon.
 */
final class MailRenderer
{
    /**
     * **Jeden wzór tematu dla całej poczty** (zlecenie Z60): „wezurlop · Zaproszenie do
     * aplikacji", „wezurlop · Wiadomość próbna", „wezurlop · Alert: …".
     *
     * ⚠ To **odwołuje** wcześniejsze rozstrzygnięcie, które zabraniało stawiać nazwę produktu
     * na początku tematu zaproszenia („pracownik ma poznać wiadomość od pracodawcy, nie od
     * dostawcy"). Zarządca wymienił zaproszenie wprost w nowym wzorze, a nazwa pracodawcy
     * stoi w treści i w stopce każdej wiadomości. Rozstrzygnięcie zapisane tutaj, żeby nikt
     * nie „naprawiał" tego z powrotem bez decyzji.
     *
     * ⚠ Prefiks dokłada ta klasa, nie nadawcy: osiem miejsc składających temat z ręki
     * rozjechałoby się przy pierwszym nowym rodzaju wiadomości — i rozjechało się
     * („wezurlop: …" z dwukropkiem w siedmiu alertach).
     */
    public const PREFIKS = 'wezurlop · ';

    private ?Environment $html = null;

    private ?Environment $tekst = null;

    /**
     * @param string $templatesPath katalog `templates/` aplikacji
     * @param string|null $cachePath cache Twiga (poza webrootem) albo null
     * @param string $strefa strefa instalacji — do znacznika czasu w stopce
     */
    public function __construct(
        private readonly string $templatesPath,
        private readonly ?string $cachePath = null,
        private readonly string $strefa = Strefa::DOMYSLNA,
    ) {
    }

    /**
     * Wiadomość z szablonu.
     *
     * ⚠ **Dane wspólne dokłada ta metoda**, a nie każdy nadawca: barwy, krój, znacznik czasu,
     * a także wartości domyślne (`akcja`, `firma`, `podglad`). Przy `strict_variables` brak
     * jednej z nich wywala render — a to jest ta klasa wyjątków, które nadawca alertu zauważy
     * dopiero wtedy, gdy alert naprawdę będzie potrzebny.
     *
     * @param string $szablon nazwa bez rozszerzenia, np. `zaproszenie`
     * @param array<string, mixed> $dane te same zmienne dla obu wersji
     */
    public function wiadomosc(string $szablon, string $do, string $temat, array $dane): MailMessage
    {
        $dane += [
            'temat'   => $temat,
            'podglad' => '',
            'firma'   => null,
            'akcja'   => null,
            'barwy'   => BarwyPoczty::MAPA,
            'kroj'    => BarwyPoczty::KROJ,
            // ⚠ Znacznik czasu z nazwą strefy: wiadomość bywa czytana po dwóch dniach, a klient
            // poczty pokazuje swoją strefę — bez nazwy nie wiadomo, czyją godzinę się czyta.
            'teraz'   => Strefa::czas(gmdate('Y-m-d H:i:s'), $this->strefa) . ' (' . $this->strefa . ')',
        ];

        return new MailMessage(
            $do,
            self::PREFIKS . $temat,
            // ⚠ Kolejność wywołań nieprzypadkowa: tekst jest obowiązkowy, więc gdyby jego
            // szablonu brakowało, wiadomość nie powstanie wcale — zamiast wyjść bez wersji
            // tekstowej i wpaść odbiorcy do spamu.
            $this->tekst()->render($szablon . '.twig', $dane),
            $this->html()->render($szablon . '.twig', $dane),
        );
    }

    private function html(): Environment
    {
        return $this->html ??= new Environment(
            new FilesystemLoader($this->templatesPath . '/mail'),
            [
                'autoescape'       => 'html',
                'strict_variables' => true,
                'cache'            => $this->cachePath === null ? false : $this->cachePath . '/mail-html',
            ],
        );
    }

    private function tekst(): Environment
    {
        return $this->tekst ??= new Environment(
            new FilesystemLoader($this->templatesPath . '/mail/text'),
            [
                // ⚠ Patrz komentarz klasy: brak kontekstu HTML, a escapowanie psuje treść.
                'autoescape'       => false,
                'strict_variables' => true,
                'cache'            => $this->cachePath === null ? false : $this->cachePath . '/mail-text',
            ],
        );
    }
}
