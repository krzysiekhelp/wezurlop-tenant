<?php

declare(strict_types=1);

// Etykiety i wyróżnienia kodów zdarzeń (etap 6, ekran dziennika).
// ⚠ Sedno tych sprawdzeń to zachowanie przy kodzie NIEZNANYM: ekran audytowy nie może ukryć
// zdarzenia tylko dlatego, że ktoś zapomniał dopisać etykietę.
// Uruchomienie: php tests/kody-zdarzen.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Support\KodyZdarzen;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };

    $check('znany kod ma etykietę', KodyZdarzen::etykieta('account.block_refused') === 'Odmowa blokady konta');
    // Kod składany w locie (`leave.<akcja>`) — nie ma własnego wpisu i mieć nie będzie.
    $check('nieznany kod z rodziny → etykieta rodziny', KodyZdarzen::etykieta('leave.cofniety_reczne') === 'Wniosek urlopowy');
    $check('kod spoza rodzin → sam kod', KodyZdarzen::etykieta('cokolwiek.nowego') === 'cokolwiek.nowego');
    $check('etykieta nigdy nie jest pusta', KodyZdarzen::etykieta('x') !== '');

    $check('blokada wyróżniona mocno', KodyZdarzen::waga('account.blocked_manually') === KodyZdarzen::WAGA_BLAD);
    // ⚠ Warunek z odbioru: odmowa blokady widoczna TAK SAMO jak blokada.
    $check('odmowa blokady wyróżniona jak blokada',
        KodyZdarzen::waga('account.block_refused') === KodyZdarzen::waga('account.blocked_manually'));
    $check('blokada z synchronizacji jak ręczna',
        KodyZdarzen::waga('account.blocked_by_sync') === KodyZdarzen::waga('account.blocked_manually'));
    // Cała rodzina `security.` jest mocna z prefiksu, także kody, których jeszcze nie ma.
    $check('nowy kod security.* mocny', KodyZdarzen::waga('security.czegos_nowego') === KodyZdarzen::WAGA_BLAD);
    $check('zwykłe logowanie bez wyróżnienia', KodyZdarzen::waga('auth.login_success') === KodyZdarzen::WAGA_ZWYKLA);
    $check('zakończenie sesji wyróżnione słabo', KodyZdarzen::waga('session.revoked') === KodyZdarzen::WAGA_OSTRZEZENIE);

    // Wagi muszą pasować do makra `partials/plakietka.twig` — inaczej plakietka nie dostaje koloru.
    $plakietka = file_get_contents(dirname(__DIR__) . '/templates/partials/plakietka.twig');
    $check('waga „blad" znana makru plakietki', is_string($plakietka) && str_contains($plakietka, "'blad'"));
    $check('waga „ostrzezenie" znana makru plakietki', is_string($plakietka) && str_contains($plakietka, "'ostrzezenie'"));

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Niepowodzeń: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
