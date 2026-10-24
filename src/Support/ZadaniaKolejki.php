<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Wezurlop\Security\RateLimiter;
use Wezurlop\Service\AccountMailService;
use Wezurlop\Service\Canary;
use Wezurlop\Service\JobRunner;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Service\Sync\TaskService;

/**
 * Zadania kolejki po polsku i reguła ich usuwania (zlecenie Z43, 19.10.2026).
 *
 * Czysty helper bez stanu i bez bazy (CLAUDE.md §1), strefa jawna: zamienia `mail.invitation`
 * na „Zaproszenie do systemu" i odpowiada na jedno pytanie — **czy wolno to zadanie usunąć
 * z ekranu**.
 *
 * ⚠ **Kod typu zostaje widoczny obok nazwy** (tak samo jak w `KodyZdarzen`): ekran kolejki jest
 * ekranem diagnostycznym, a nazwa po polsku jest tłumaczeniem, nie źródłem prawdy. Przy
 * zgłoszeniu „nic nie wychodzi" liczy się `mail.password_reset`, a nie „Link do ustawienia hasła".
 *
 * ⚠ **Nieznany typ: usuwanie WYŁĄCZONE** (domyślnie odmawiaj, CLAUDE.md §10). Zadanie dopisane
 * w kodzie i zapomniane tutaj nie zniknie z ekranu — dostanie swój kod jako nazwę i zablokowany
 * kosz. Odwrotna domyślność znaczyłaby, że nowy typ zadania wolno skasować, zanim ktokolwiek
 * rozstrzygnął, co to usunięcie robi.
 *
 * ⚠ **O usuwalności decyduje jedno pytanie: czy to zadanie wróci.** Trzy odpowiedzi:
 *  - zadanie cykliczne (`JobRunner::CYKLICZNE`) wraca w następnym przebiegu crona,
 *  - wiadomość zamówiona przez człowieka (zaproszenie, link do hasła) da się zamówić ponownie
 *    tym samym przyciskiem, którym powstała,
 *  - **alert nie wróci nigdy**: powstał z epizodu, który już minął, a przy ciszy connectora
 *    i nieużytym kluczu slot alertu jest dodatkowo zajęty na całą długość progu
 *    (`TenantSettingRepository::claimAlertSlot`). Usunięcie takiego zadania to cicha utrata
 *    ostrzeżenia — dlatego kosz jest wyłączony, a nie „wyłączony dla pewności".
 */
final class ZadaniaKolejki
{
    public const POWOD_ALERT =
        'Alert powstał ze zdarzenia, które już minęło — po usunięciu nie przyjdzie wcale, '
        . 'a następny dopiero przy kolejnym takim zdarzeniu.';

    public const POWOD_SONDA =
        'Sonda crona jest trwającą odpowiedzią na pytanie „czy cron działa" — po jej usunięciu '
        . 'ekran ustawień twierdziłby, że cron nie odpowiada.';

    public const POWOD_NIEZNANY =
        'Nieznany typ zadania — usuwanie wyłączone, dopóki nie wiadomo, co usunięcie znaczy.';

    /** Zadanie zajęte przez przebieg crona — odmowa chwilowa, nie reguła typu. */
    public const POWOD_W_TOKU = 'Zadanie jest właśnie wykonywane przez crona. Spróbuj po przebiegu.';

    /**
     * Typ zadania → nazwa po polsku, rodzaj wiadomości i reguła usuwania.
     *
     * ⚠ **Dwie formy rodzaju wiadomości, bo o przypadku decyduje POZYCJA W ZDANIU** — ta sama
     * zasada, co w `Odmiana::osoby()` i `osobyDopelniacz()`: biernik po orzeczeniu twierdzącym
     * („wysłał **zaproszenie** do…") i dopełniacz po orzeczeniu zaprzeczonym („nie wysłał
     * **zaproszenia** do…"). Polskie zaprzeczenie rządzi dopełniaczem, więc jedna forma dawała
     * „nie wysłał link do ustawienia hasła" — zmierzone na gotowym zdaniu, nie wyczytane.
     * Puste znaczy „to zadanie nie wysyła poczty".
     *
     * @var array<string, array{nazwa: string, wiadomosc: string, wiadomosc_d: string,
     *     usuwalne: bool, powod: string}>
     */
    private const ZADANIA = [
        AccountMailService::JOB_INVITATION => [
            'nazwa' => 'Zaproszenie do systemu', 'wiadomosc' => 'zaproszenie', 'wiadomosc_d' => 'zaproszenia',
            'usuwalne' => true, 'powod' => '',
        ],
        AccountMailService::JOB_PASSWORD_RESET => [
            'nazwa' => 'Link do ustawienia hasła', 'wiadomosc' => 'link do ustawienia hasła', 'wiadomosc_d' => 'linku do ustawienia hasła',
            'usuwalne' => true, 'powod' => '',
        ],
        RateLimiter::JOB_SECURITY_ALERT => [
            'nazwa' => 'Alert bezpieczeństwa', 'wiadomosc' => 'alert bezpieczeństwa', 'wiadomosc_d' => 'alertu bezpieczeństwa',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        TaskService::JOB_HR_ALERT => [
            'nazwa' => 'Alert do kadr', 'wiadomosc' => 'alert do kadr', 'wiadomosc_d' => 'alertu do kadr',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        TaskService::JOB_HR_ALERT_URGENT => [
            'nazwa' => 'Pilny alert do kadr', 'wiadomosc' => 'pilny alert do kadr', 'wiadomosc_d' => 'pilnego alertu do kadr',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        JobRunner::JOB_KEY_IDLE_ALERT => [
            'nazwa' => 'Alert: connector milczy', 'wiadomosc' => 'alert o ciszy connectora', 'wiadomosc_d' => 'alertu o ciszy connectora',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        JobRunner::JOB_KEY_UNUSED_ALERT => [
            'nazwa' => 'Alert: klucz wydany i nieużyty', 'wiadomosc' => 'alert o nieużytym kluczu', 'wiadomosc_d' => 'alertu o nieużytym kluczu',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        SyncService::JOB_AUTO_UNBLOCK_ALERT => [
            'nazwa' => 'Alert: konta nieodblokowane po powrocie do pracy',
            'wiadomosc' => 'alert o kontach nieodblokowanych z braku licencji',
            'wiadomosc_d' => 'alertu o kontach nieodblokowanych z braku licencji',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        JobRunner::JOB_COMPANY_IDLE_ALERT => [
            'nazwa' => 'Alert: firma się nie synchronizuje', 'wiadomosc' => 'alert o ciszy firmy', 'wiadomosc_d' => 'alertu o ciszy firmy',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        SyncService::JOB_SPLIT_ALERT => [
            'nazwa' => 'Alert: dwie instalacje connectora', 'wiadomosc' => 'alert o dwóch instalacjach connectora', 'wiadomosc_d' => 'alertu o dwóch instalacjach connectora',
            'usuwalne' => false, 'powod' => self::POWOD_ALERT,
        ],
        JobRunner::JOB_SESSION_CLEANUP => [
            'nazwa' => 'Sprzątanie wygasłych sesji', 'wiadomosc' => '', 'wiadomosc_d' => '',
            'usuwalne' => true, 'powod' => '',
        ],
        JobRunner::JOB_SYNC_CLEANUP => [
            'nazwa' => 'Sprzątanie danych synchronizacji', 'wiadomosc' => '', 'wiadomosc_d' => '',
            'usuwalne' => true, 'powod' => '',
        ],
        JobRunner::JOB_KEY_IDLE_CHECK => [
            'nazwa' => 'Sprawdzenie ciszy kluczy', 'wiadomosc' => '', 'wiadomosc_d' => '',
            'usuwalne' => true, 'powod' => '',
        ],
        Canary::JOB_CHECK => [
            'nazwa' => 'Sprawdzenie sygnału kontrolnego', 'wiadomosc' => '', 'wiadomosc_d' => '',
            'usuwalne' => true, 'powod' => '',
        ],
        JobRunner::JOB_CRON_PROBE => [
            'nazwa' => 'Sonda crona', 'wiadomosc' => '', 'wiadomosc_d' => '',
            'usuwalne' => false, 'powod' => self::POWOD_SONDA,
        ],
    ];

    /** Nazwa po polsku; nieznany typ zostaje swoim kodem — ekran nie ukrywa zadania. */
    public static function nazwa(string $typ): string
    {
        return self::ZADANIA[$typ]['nazwa'] ?? $typ;
    }

    /** Rodzaj wiadomości w BIERNIKU („wysłał zaproszenie"); puste = zadanie nie wysyła poczty. */
    public static function wiadomosc(string $typ): string
    {
        return self::ZADANIA[$typ]['wiadomosc'] ?? '';
    }

    /** Ten sam rodzaj w DOPEŁNIACZU („nie wysłał zaproszenia") — patrz uwaga nad `ZADANIA`. */
    public static function wiadomoscDopelniacz(string $typ): string
    {
        return self::ZADANIA[$typ]['wiadomosc_d'] ?? '';
    }

    public static function usuwalne(string $typ): bool
    {
        return self::ZADANIA[$typ]['usuwalne'] ?? false;
    }

    /** Powód odmowy usunięcia — jedno zdanie do `title` przycisku i do odpowiedzi serwera. */
    public static function powod(string $typ): string
    {
        return self::ZADANIA[$typ]['powod'] ?? self::POWOD_NIEZNANY;
    }

    /**
     * Biała lista typów (CLAUDE.md §11) — tylko one mogą przyjść w żądaniu usunięcia.
     *
     * @return list<string>
     */
    public static function typy(): array
    {
        return array_keys(self::ZADANIA);
    }
}
