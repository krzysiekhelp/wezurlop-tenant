<?php

declare(strict_types=1);

// Zadania kolejki: nazwy po polsku i reguła usuwania (zlecenie Z43 §B, 19.10.2026).
//
// ⚠ Sedno tych sprawdzeń: **lista typów zadań żyje w czterech miejscach** — stałe `JOB_*`
// w pięciu klasach, gałąź `match` w `JobRunner::execute()`, lista cyklicznych
// (`JobRunner::CYKLICZNE`) i ten katalog. Rozjazd nie wywraca niczego od razu: nowe zadanie
// po prostu pokazuje się na ekranie jako goły kod, a przy błędnej stronie reguły — z aktywnym
// koszem, który bez ostrzeżenia usuwa alert bezpieczeństwa. Tu rozjazd widać od razu.
//
// Uruchomienie: php tests/kolejka-zadan.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Security\RateLimiter;
use Wezurlop\Service\AccountMailService;
use Wezurlop\Service\Canary;
use Wezurlop\Service\JobRunner;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Service\Sync\TaskService;
use Wezurlop\Support\ZadaniaKolejki;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ——— każdy typ zadania z kodu jest w katalogu ———
    // ⚠ Lista pisana z ręki, a nie wyliczona refleksją: refleksja po stałych `JOB_*` przegapiłaby
    // klasę, której nikt tu nie wymienił — czyli dokładnie ten przypadek, którego szukamy.
    $wKodzie = [
        AccountMailService::JOB_INVITATION,
        AccountMailService::JOB_PASSWORD_RESET,
        RateLimiter::JOB_SECURITY_ALERT,
        TaskService::JOB_HR_ALERT,
        TaskService::JOB_HR_ALERT_URGENT,
        SyncService::JOB_SPLIT_ALERT,
        JobRunner::JOB_KEY_IDLE_ALERT,
        JobRunner::JOB_KEY_UNUSED_ALERT,
        JobRunner::JOB_COMPANY_IDLE_ALERT,
        JobRunner::JOB_SESSION_CLEANUP,
        JobRunner::JOB_SYNC_CLEANUP,
        JobRunner::JOB_KEY_IDLE_CHECK,
        JobRunner::JOB_CRON_PROBE,
        Canary::JOB_CHECK,
    ];
    $brakujace = array_values(array_diff($wKodzie, ZadaniaKolejki::typy()));
    $check('każdy typ zadania z kodu ma wpis w katalogu', $brakujace === [], implode(', ', $brakujace));
    $nadmiarowe = array_values(array_diff(ZadaniaKolejki::typy(), $wKodzie));
    $check('katalog nie opisuje zadań, których nie ma', $nadmiarowe === [], implode(', ', $nadmiarowe));

    // ——— nieznany typ: nazwa to kod, kosz wyłączony ———
    // ⚠ To jest reguła ważniejsza od wszystkich nazw: zadanie dopisane w kodzie i zapomniane
    // tutaj nie może ani zniknąć z ekranu, ani dać się usunąć (CLAUDE.md §10).
    $check('nieznany typ zostaje swoim kodem', ZadaniaKolejki::nazwa('cokolwiek.nowego') === 'cokolwiek.nowego');
    $check('nieznanego typu nie wolno usunąć', ZadaniaKolejki::usuwalne('cokolwiek.nowego') === false);
    $check('nieznany typ ma powód odmowy', ZadaniaKolejki::powod('cokolwiek.nowego') === ZadaniaKolejki::POWOD_NIEZNANY);
    $check('nieznany typ nie udaje poczty', ZadaniaKolejki::wiadomosc('cokolwiek.nowego') === '');

    // ——— zadanie cykliczne wraca, więc wolno je usunąć ———
    foreach (array_keys(JobRunner::CYKLICZNE) as $typ) {
        $check("zadanie cykliczne „{$typ}” wolno usunąć (wraca co przebieg)", ZadaniaKolejki::usuwalne($typ));
        $check("zadanie cykliczne „{$typ}” ma nazwę po polsku", ZadaniaKolejki::nazwa($typ) !== $typ);
    }

    // ——— alert nie wróci sam: kosz wyłączony ———
    $alerty = [
        RateLimiter::JOB_SECURITY_ALERT, TaskService::JOB_HR_ALERT, TaskService::JOB_HR_ALERT_URGENT,
        SyncService::JOB_SPLIT_ALERT, JobRunner::JOB_KEY_IDLE_ALERT, JobRunner::JOB_KEY_UNUSED_ALERT,
        JobRunner::JOB_COMPANY_IDLE_ALERT,
    ];
    foreach ($alerty as $typ) {
        $check("alertu „{$typ}” nie wolno usunąć", ZadaniaKolejki::usuwalne($typ) === false);
        $check("alert „{$typ}” mówi, czym grozi usunięcie", ZadaniaKolejki::powod($typ) === ZadaniaKolejki::POWOD_ALERT);
    }
    $check('sondy crona nie wolno usunąć', ZadaniaKolejki::usuwalne(JobRunner::JOB_CRON_PROBE) === false);
    $check('sonda ma własny powód odmowy',
        ZadaniaKolejki::powod(JobRunner::JOB_CRON_PROBE) === ZadaniaKolejki::POWOD_SONDA);

    // ——— zadanie usuwalne nie ma powodu odmowy, i na odwrót ———
    // ⚠ Para „usuwalne + powód" rozjechałaby się po cichu: wyłączony kosz bez zdania w `title`
    // to przycisk, który nic nie robi i nie mówi dlaczego.
    foreach (ZadaniaKolejki::typy() as $typ) {
        $check("„{$typ}”: powód odmowy dokładnie wtedy, gdy kosz wyłączony",
            ZadaniaKolejki::usuwalne($typ) === (ZadaniaKolejki::powod($typ) === ''));
    }

    // ——— dwie formy rodzaju wiadomości ———
    // ⚠ Każde zadanie pocztowe musi mieć biernik I dopełniacz: zdanie o niepowodzeniu bierze
    // drugą formę, a jej brak dawał „nie wysłał link do ustawienia hasła".
    $bezFormy = [];
    foreach (ZadaniaKolejki::typy() as $typ) {
        $biernik = ZadaniaKolejki::wiadomosc($typ);
        $dopelniacz = ZadaniaKolejki::wiadomoscDopelniacz($typ);
        if (($biernik === '') !== ($dopelniacz === '')) {
            $bezFormy[] = $typ;
        }
    }
    $check('zadanie pocztowe ma obie formy rodzaju', $bezFormy === [], implode(', ', $bezFormy));
    $check('biernik i dopełniacz są różne tam, gdzie polski je różnicuje',
        ZadaniaKolejki::wiadomosc(AccountMailService::JOB_INVITATION) === 'zaproszenie'
        && ZadaniaKolejki::wiadomoscDopelniacz(AccountMailService::JOB_INVITATION) === 'zaproszenia');
    // Zadanie, które poczty nie wysyła, nie może podawać rodzaju wiadomości — dekorator dziennika
    // rozpoznaje je właśnie po pustym napisie i nie zapisuje wtedy wpisu o wysyłce.
    foreach (array_keys(JobRunner::CYKLICZNE) as $typ) {
        $check("zadanie cykliczne „{$typ}” nie wysyła poczty", ZadaniaKolejki::wiadomosc($typ) === '');
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
