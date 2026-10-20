<?php

declare(strict_types=1);

/*
 * **Podgląd wszystkich rodzajów wiadomości** (zlecenie Z60 §4, wariant A).
 *
 * ⚠ Wariant A, nie trasa podglądu: trasa — nawet schowana za `APP_ENV=dev` i uprawnieniem —
 * jest **kolejnym wejściem renderującym treść z danych żądania**, czyli powierzchnią, której
 * trzeba pilnować przy każdej zmianie. Skrypt w `dev/` nie istnieje u klienta (nie wchodzi
 * do paczki, patrz `dev/package-allowlist.txt`), a daje to samo: HTML każdej wiadomości
 * do otwarcia w przeglądarce.
 *
 * ⚠ **Dane są FIKCYJNE** (CLAUDE.md §18): nazwiska zastępcze, adresy w `przyklad.invalid`,
 * nazwa firmy „ALFA sp. z o.o.". Nic nie jest czytane z bazy i nic nie jest wysyłane.
 *
 * ⚠ Pliki lądują w katalogu tymczasowym POZA repozytorium — podgląd poczty to wydruk na chwilę,
 * nie artefakt do wersjonowania.
 *
 * Wywołanie: `php dev/probka-poczty.php` (wypisuje katalog i listę plików).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Dev\Poczta\PrzykladyWiadomosci;
use Wezurlop\Service\Mail\MailRenderer;

$renderer = new MailRenderer(dirname(__DIR__) . '/templates', null, 'Europe/Warsaw');
$katalog = sys_get_temp_dir() . '/wezurlop-poczta-' . date('Ymd-His');
if (!mkdir($katalog, 0o700, true) && !is_dir($katalog)) {
    fwrite(STDERR, "Nie udało się utworzyć katalogu podglądu.\n");
    exit(1);
}

$rodzaje = PrzykladyWiadomosci::wszystkie();

$pliki = [];
foreach ($rodzaje as $nazwa => [$szablon, $temat, $dane]) {
    $wiadomosc = $renderer->wiadomosc($szablon, 'podglad@przyklad.invalid', $temat, $dane);
    file_put_contents($katalog . '/' . $nazwa . '.html', (string) $wiadomosc->html);
    file_put_contents($katalog . '/' . $nazwa . '.txt', $wiadomosc->body);
    $pliki[$nazwa] = ['temat' => $wiadomosc->subject,
        'html_bajtow' => strlen((string) $wiadomosc->html), 'tekst_bajtow' => strlen($wiadomosc->body)];
}

echo json_encode(['katalog' => $katalog, 'rodzaje' => $pliki],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
