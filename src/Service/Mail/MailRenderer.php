<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
    private ?Environment $html = null;

    private ?Environment $tekst = null;

    /**
     * @param string $templatesPath katalog `templates/` aplikacji
     * @param string|null $cachePath cache Twiga (poza webrootem) albo null
     */
    public function __construct(
        private readonly string $templatesPath,
        private readonly ?string $cachePath = null,
    ) {
    }

    /**
     * Wiadomość z szablonu.
     *
     * @param string $szablon nazwa bez rozszerzenia, np. `zaproszenie`
     * @param array<string, mixed> $dane te same zmienne dla obu wersji
     */
    public function wiadomosc(string $szablon, string $do, string $temat, array $dane): MailMessage
    {
        return new MailMessage(
            $do,
            $temat,
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
