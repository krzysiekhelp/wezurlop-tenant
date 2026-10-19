<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Kody zdarzeń po polsku (etap 6: ekran dziennika).
 *
 * Czysty helper bez stanu i bez bazy (CLAUDE.md §1) — zamienia `account.block_refused`
 * na „Odmowa blokady konta" i mówi, czy wpis ma być wyróżniony.
 *
 * ⚠ **Kod źródłowy zdarzenia zostaje widoczny na ekranie obok etykiety.** To ekran audytowy:
 * gdyby etykieta była jedyną treścią, nowy kod zdarzenia (albo kod składany w locie, jak
 * `leave.<akcja>`) pokazywałby się jako coś innego, niż jest naprawdę. Ładniejsza kolumna
 * nie jest wart tego, żeby administrator czytał historię przez tłumacza.
 *
 * ⚠ Dlatego brak etykiety **nie jest błędem**: nieznany kod dostaje etykietę rodziny
 * (po prefiksie) albo sam kod. Nowe zdarzenie nigdy nie zniknie z ekranu z powodu
 * zapomnianego wpisu w tej tablicy.
 */
final class KodyZdarzen
{
    /** Wyróżnienie mocne — zdarzenia, po których pyta się „kto i kiedy". */
    public const WAGA_BLAD = 'blad';

    /** Wyróżnienie słabe — warte spojrzenia, ale nie alarm. */
    public const WAGA_OSTRZEZENIE = 'ostrzezenie';

    public const WAGA_ZWYKLA = '';

    /** @var array<string, string> */
    private const ETYKIETY = [
        'auth.login_success'            => 'Udane logowanie',
        'auth.login_failed'             => 'Nieudane logowanie',
        'auth.login_delayed'            => 'Logowanie opóźnione (limit prób)',
        'auth.logout'                   => 'Wylogowanie',
        'auth.password_changed'         => 'Zmiana hasła',
        'auth.password_reset_requested' => 'Prośba o reset hasła',
        'auth.password_ok_2fa_pending'  => 'Hasło poprawne, czeka na kod 2FA',
        'account.invited'               => 'Zaproszenie konta',
        'account.activated'             => 'Aktywacja konta',
        'account.created_uninvited'     => 'Konto utworzone bez zaproszenia',
        'account.created_by_cli'        => 'Konto utworzone narzędziem',
        'account.blocked_manually'      => 'Blokada konta (ręczna)',
        'account.unblocked_manually'    => 'Odblokowanie konta (ręczne)',
        'account.blocked_by_sync'       => 'Blokada konta (synchronizacja)',
        'account.unblocked_by_sync'     => 'Odblokowanie konta (powrót do pracy)',
        'account.block_refused'         => 'Odmowa blokady konta',
        // Rozdział „administrator tenanta ≠ pracownik" (etap3 §8c) złamany PRZEZ KADRY,
        // nie przez nadanie roli — zatrudnienie administratora przyszło z R2.
        'access.admin_employed'         => 'Administrator z zatrudnieniem (z kadr)',
        'account.invitation_resent'     => 'Ponowna wysyłka zaproszenia',
        'account.invitation_revoked'    => 'Unieważnienie zaproszenia',
        'account.invitation_resend_refused' => 'Odmowa ponowienia zaproszenia (odstęp)',
        'account.email_changed'         => 'Zmiana adresu (loginu) konta',
        'access.granted'                => 'Nadanie dostępu do firmy',
        'access.revoked'                => 'Odebranie dostępu do firmy',
        'session.revoked'               => 'Zakończenie sesji',
        'session.revoked_by_admin'      => 'Zakończenie sesji przez administratora',
        'person.email_set'              => 'Ustawienie adresu e-mail osoby',
        'person.contact_changed'        => 'Zmiana danych kontaktowych',
        '2fa.enabled'                   => 'Włączenie 2FA',
        '2fa.disabled'                  => 'Wyłączenie 2FA',
        '2fa.disable_failed'            => 'Nieudane wyłączenie 2FA',
        '2fa.failed'                    => 'Błędny kod 2FA',
        '2fa.delayed'                   => 'Kod 2FA opóźniony (limit prób)',
        '2fa.reset_by_admin'            => 'Reset 2FA przez administratora',
        '2fa.backup_codes_acknowledged' => 'Potwierdzenie kodów zapasowych',
        'context.switched'              => 'Przełączenie firmy',
        'tenant.timezone_changed'       => 'Zmiana strefy czasowej',
        'tenant.company_display_name'   => 'Zmiana nazwy wyświetlanej firmy',
        // ⚠ Zmiana skrzynki alertowej jest zmianą tego, KTO DOSTAJE OSTRZEŻENIA o instalacji
        // (cisza connectora, nieużyty klucz, atak na konta) — stąd własna etykieta, a nie
        // rodzina „Tenant". Wyczyszczenie adresu wyłącza alerty dla całej instalacji.
        'tenant.alert_email_changed'    => 'Zmiana skrzynki na alerty',
        'tenant.reset'                  => 'Wyczyszczenie danych tenanta',
        'install.completed'             => 'Zakończenie instalacji',
        'maintenance.cron_probe'        => 'Sonda crona wykonana',
        'maintenance.cron_probe_requested' => 'Sprawdzenie crona (sonda postawiona)',
        // ⚠ Usunięcie zadania z kolejki jest NIEODWRACALNE dla wiadomości, która w nim stała
        // (Z43 §B) — stąd własna etykieta, a nie rodzina „Utrzymanie".
        'maintenance.job_deleted'       => 'Usunięcie zadania z kolejki',
        // ⚠ Para kodów o FAKCIE wysyłki, nie o zamówieniu wiadomości (Z43 §C). „Zaproszono"
        // i „wysłano zaproszenie" to dwa różne zdarzenia i dziennik rozróżnia je od 19.10.2026.
        'mail.sent'                     => 'Wiadomość wysłana',
        'mail.failed'                   => 'Wiadomość niewysłana',
        'employment.deactivated_by_sync' => 'Wygaszenie zatrudnienia (synchronizacja)',
        // ⚠ Zmiana hierarchii jest zmianą tego, KTO OGLĄDA CZYJE dane kadrowe i przez kogo
        // pójdzie wniosek — dlatego ma własną etykietę, a nie wpada w rodzinę „Zatrudnienie".
        'hierarchy.supervisor_changed' => 'Zmiana przełożonego',
        // ⚠ Rodzina **ogólna** („uprawnienie"), nie „podgląd kalendarzy" — bo tą samą drogą
        // (para konto–spółka, panel osoby, wpis w dzienniku) dojdą w Etapie 3 role przełożonego
        // i kadrowej. Który dokładnie kod nadano, mówi `detail`; rodzina zostaje jedna, żeby
        // audyt „kto komu nadał uprawnienie" był jednym pytaniem, a nie sumą pytań (aneks 23.09).
        'permission.granted'            => 'Nadanie uprawnienia',
        'permission.revoked'            => 'Odebranie uprawnienia',
        // ⚠ Odrzucenie jest ZAPISYWANE, nie ciche: próba nadania roli z ekranu, który jej nie
        // nadaje, jest zdarzeniem bezpieczeństwa, a nie literówką w żądaniu.
        'permission.refused'            => 'Odrzucona próba nadania uprawnienia',
        'security.ddl_blocked'          => 'Zablokowana próba zmiany schematu bazy',
        'security.login_spraying_suspected' => 'Podejrzenie ataku na wiele kont',
        'security.storage_exposed'      => 'Katalog danych osiągalny z internetu',
        'security.db_single_account'    => 'Baza w trybie jednego konta',
        'security.db_app_account_has_ddl' => 'Konto aplikacyjne bazy ma prawa DDL',
        'security.canary_unresolved'    => 'Nierozstrzygnięty sygnał kontrolny',
    ];

    /** Etykieta rodziny — dla kodów bez własnego wpisu (również składanych w locie). */
    private const RODZINY = [
        'auth.'        => 'Uwierzytelnianie',
        'account.'     => 'Konto',
        'access.'      => 'Dostęp do firmy',
        'session.'     => 'Sesja',
        'person.'      => 'Osoba',
        '2fa.'         => 'Dwuskładnikowe logowanie',
        'api.'         => 'Integracja (API)',
        'integration.' => 'Integracja',
        'sync.'        => 'Synchronizacja',
        'leave.'       => 'Wniosek urlopowy',
        'employment.'  => 'Zatrudnienie',
        'hierarchy.'   => 'Hierarchia przełożonych',
        'permission.'  => 'Uprawnienia',
        'tenant.'      => 'Tenant',
        'security.'    => 'Bezpieczeństwo',
        'install.'     => 'Instalacja',
        'mail.'        => 'Poczta',
        'maintenance.' => 'Utrzymanie',
    ];

    /**
     * Zdarzenia wyróżnione mocno.
     *
     * ⚠ `account.block_refused` stoi tu **obok** samej blokady, na wyraźne życzenie z odbioru:
     * odrzucona próba pozbawienia tenanta zarządzania jest tym wpisem, który pierwszy powie,
     * że ktoś próbował. Wpis o niepowodzeniu bywa ważniejszy od wpisu o sukcesie.
     *
     * @var list<string>
     */
    private const MOCNE = [
        'account.blocked_manually', 'account.blocked_by_sync', 'account.block_refused',
        'auth.login_failed', '2fa.failed', '2fa.disable_failed', '2fa.disabled', '2fa.reset_by_admin',
        'account.email_changed',
        // ⚠ Skasowanie klucza stoi obok unieważnienia, bo jest **nieodwracalne**: unieważniony
        // klucz zostaje w wykazie i widać, że istniał, a skasowany znika z ekranu — jedynym
        // śladem jest ten wpis. Etykiety indywidualnej nie dodaję: rodzina `api.*` nie ma ich
        // wcale, a wiersz dziennika pokazuje kod obok plakietki (`event-log.twig`).
        'session.revoked_by_admin', 'api.key_revoked', 'api.key_deleted', 'tenant.reset',
    ];

    /** @var list<string> */
    private const SLABE = [
        // ⚠ `session.revoked` wyróżniamy słabo, nie mocno: najczęstsze źródło to własne
        // „zakończ pozostałe urządzenia" z ustawień konta, a nie blokada. Gdy towarzyszy blokadzie,
        // wiersz obok (`account.blocked_*`) jest już wyróżniony mocno i on nosi ciężar zdarzenia.
        'session.revoked',
        'auth.login_delayed', '2fa.delayed', 'access.revoked', 'account.unblocked_manually',
        'account.invitation_resend_refused',
        // ⚠ Nieudana wysyłka jest wyróżniona słabo, nie mocno: zadanie jest PONAWIANE, więc
        // pojedynczy wpis nie jest jeszcze awarią — ale ciąg takich wpisów jest jedynym miejscem,
        // w którym widać, że poczta klienta przestała wychodzić.
        'mail.failed', 'maintenance.job_deleted', 'tenant.alert_email_changed',
        'employment.deactivated_by_sync', 'integration.key_idle', 'integration.key_unused',
        'integration.company_idle',
        'sync.review_requested', 'sync.identity_changed', 'sync.foreign_connector_entry',
        'leave.r2_warning', 'sync.limit_removed_with_requests', 'sync.kadrowe_with_length',
    ];

    /**
     * Filtry rodzaju na ekranie dziennika (zlecenie Z36 §3): klucz z adresu → nazwa i prefiksy kodów.
     *
     * ⚠ **Zestaw pokrywa WSZYSTKIE rodziny kodów**, choć zlecenie dopuszczało 6–7 przycisków.
     * Gdyby którakolwiek rodzina została poza filtrami, jej wpisy dałoby się zobaczyć wyłącznie
     * w „Wszystkich" — a dotyczy to także `security.*`, czyli jedynej rodziny, której ktoś
     * naprawdę szuka w pośpiechu. Dlatego ostatnia pozycja jest zbiorcza („Integracja i system")
     * zamiast zostawiać ogon rodzin bez filtra.
     *
     * ⚠ Prefiksy pochodzą STĄD, nie z adresu: klucz z `?rodzaj=` jest tylko wyborem z tej tablicy
     * (biała lista, CLAUDE.md §11), więc do zapytania nigdy nie trafia tekst użytkownika.
     *
     * @var array<string, array{nazwa: string, prefiksy: list<string>}>
     */
    /**
     * ⚠ Nazwy małą literą, bo od Z42 są POZYCJAMI LISTY ROZWIJANEJ obok „wszystkie konta",
     * a nie napisami na chipach. Lista czyta się wtedy jak zdanie („wszystkie zdarzenia",
     * „konta i uprawnienia"), a nie jak siedem tytułów.
     */
    private const RODZAJE = [
        'logowania' => ['nazwa' => 'logowania', 'prefiksy' => ['auth.', '2fa.', 'session.']],
        'konta'     => ['nazwa' => 'konta i uprawnienia', 'prefiksy' => ['account.', 'access.', 'permission.', 'person.', 'calendar.']],
        'grupy'     => ['nazwa' => 'grupy', 'prefiksy' => ['group.']],
        'hierarchia' => ['nazwa' => 'hierarchia', 'prefiksy' => ['hierarchy.']],
        'firmy'     => ['nazwa' => 'firmy', 'prefiksy' => ['tenant.', 'context.', 'employment.']],
        // ⚠ Poczta dostaje WŁASNY rodzaj (Z43 §C), bo od 19.10.2026 rodzina `mail.*` to wpisy
        // o każdej wysłanej i niewysłanej wiadomości — czyli pierwsze miejsce, do którego się
        // zagląda przy „nie dostałem zaproszenia". W zbiorczej „integracji i systemie" ginęły
        // między przebiegami connectora.
        // ⚠ Prefiks `mail.` stoi w DOKŁADNIE JEDNYM rodzaju: dwa filtry pokazujące ten sam wpis
        // to dwa liczniki jednej rzeczy, a przy zestawianiu wyników rozjeżdżają się bez ostrzeżenia.
        'poczta'    => ['nazwa' => 'poczta', 'prefiksy' => ['mail.']],
        'integracja' => ['nazwa' => 'integracja i system', 'prefiksy' => [
            'api.', 'integration.', 'sync.', 'leave.', 'security.', 'maintenance.', 'install.',
        ]],
    ];

    /**
     * Pozycje listy filtra — „wszystkie zdarzenia" dokłada szablon, bo to brak filtra, a nie rodzaj.
     *
     * @return list<array{klucz: string, nazwa: string}>
     */
    public static function rodzaje(): array
    {
        $out = [];
        foreach (self::RODZAJE as $klucz => $rodzaj) {
            $out[] = ['klucz' => $klucz, 'nazwa' => $rodzaj['nazwa']];
        }

        return $out;
    }

    /**
     * Prefiksy kodów dla wybranego rodzaju; pusta lista = brak filtra (klucz spoza białej listy).
     *
     * @return list<string>
     */
    public static function prefiksyRodzaju(?string $klucz): array
    {
        return $klucz !== null && isset(self::RODZAJE[$klucz]) ? self::RODZAJE[$klucz]['prefiksy'] : [];
    }

    public static function etykieta(string $kod): string
    {
        if (isset(self::ETYKIETY[$kod])) {
            return self::ETYKIETY[$kod];
        }
        foreach (self::RODZINY as $prefiks => $nazwa) {
            if (str_starts_with($kod, $prefiks)) {
                return $nazwa;
            }
        }

        return $kod;
    }

    /** `blad`, `ostrzezenie` albo pusty ciąg — nazwy zgodne z makrem `partials/plakietka.twig`. */
    public static function waga(string $kod): string
    {
        if (in_array($kod, self::MOCNE, true) || str_starts_with($kod, 'security.')) {
            return self::WAGA_BLAD;
        }

        return in_array($kod, self::SLABE, true) ? self::WAGA_OSTRZEZENIE : self::WAGA_ZWYKLA;
    }
}
