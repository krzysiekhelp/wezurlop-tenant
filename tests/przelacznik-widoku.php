<?php

declare(strict_types=1);

// **Kiedy istnieje pozycja „Zespół" w przełączniku kalendarza** (zlecenie Z70, 21.10.2026).
//
// ⚠ Powód istnienia tego pliku: zgłoszenie brzmiało „u administratora oba widoki pokazują to
// samo", a pod spodem była pomylona liczba. Pozycję „Zespół" włączał warunek `galaz > 1`, gdzie
// `galaz` od Z53 liczy **gałąź RAZEM z osobami z reguł** — więc konto z regułą „cała firma"
// miało „gałąź" 39 osób, choć nie prowadzi nikogo, a przełącznik udawał wybór, którego nie ma.
//
// ⚠ Reguła jest czystą funkcją (`ZasiegPodgladu::zespolMaSens()`), więc sprawdzamy ją BEZ bazy
// i bez przeglądarki — we wszystkich kombinacjach, nie tylko w tej, którą dało się zalogować.
// Ekran sprawdza osobno kryterium bramki `przelacznik_zespolu` (konto administracyjne: brak
// pozycji „Zespół" i widok firmy pod adresem `?widok=zespol`).
//
// ⚠ **Czwarty przypadek jest tym, który pękł w produkcie**: zespół równy całej firmie. Trzy
// pozostałe przechodziły i przed poprawką.
//
// Uruchomienie: php tests/przelacznik-widoku.php

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\ZasiegPodgladu;

(static function (): void {
    $bledy = 0;
    $sprawdz = static function (string $opis, bool $ok) use (&$bledy): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . "\n";
        $bledy += $ok ? 0 : 1;
    };

    $firma = range(1, 39);

    // 1. Pusty zespół — nie ma czego pokazać.
    $sprawdz('zespół pusty → brak pozycji', !ZasiegPodgladu::zespolMaSens([], $firma, true));

    // 2. Sam właściciel kalendarza — to „Tylko ja" pod inną nazwą.
    $sprawdz('zespół = tylko ja → brak pozycji', !ZasiegPodgladu::zespolMaSens([7], $firma, true));

    // 3. Gałąź mniejsza niż firma — pozycja ma treść różną od „Firmy".
    $sprawdz('zespół 5 osób przy firmie 39 → pozycja JEST',
        ZasiegPodgladu::zespolMaSens(range(1, 5), $firma, true));

    // 4. ⚠ Zespół równy całej firmie — to jest usterka ze zgłoszenia Z70.
    $sprawdz('zespół równy całej firmie → brak pozycji (oba widoki pokazywałyby to samo)',
        !ZasiegPodgladu::zespolMaSens($firma, $firma, true));

    // 5. Bez dostępu do „Firmy" zespół jest najszerszym widokiem konta — pozycja zostaje
    //    nawet wtedy, gdy stoją w nim wyłącznie osoby z reguł (konto bez etatu, Z53 §2).
    $sprawdz('bez widoku firmy: zespół 3 osób → pozycja JEST',
        ZasiegPodgladu::zespolMaSens([11, 12, 13], [], false));
    $sprawdz('bez widoku firmy: zespół 39 osób → pozycja JEST (nie ma z czym się dublować)',
        ZasiegPodgladu::zespolMaSens($firma, [], false));

    // 6. Granica: zespół o jedną osobę mniejszy niż firma — jeszcze się różni.
    $sprawdz('zespół 38 przy firmie 39 → pozycja JEST',
        ZasiegPodgladu::zespolMaSens(range(1, 38), $firma, true));

    echo $bledy === 0 ? "Wszystko OK\n" : "Nieudane: {$bledy}\n";
    exit($bledy === 0 ? 0 : 1);
})();
