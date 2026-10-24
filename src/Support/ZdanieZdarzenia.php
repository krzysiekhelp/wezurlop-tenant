<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Wpis dziennika jako ZDANIE po polsku (zlecenie Z36, 18.10.2026).
 *
 * Czysty helper bez stanu i bez bazy (CLAUDE.md §1): dostaje gotowy wiersz z `EventLogRepository`
 * i zwraca części zdania, ikonę oraz barwę kółka. Szablon niczego nie składa sam (§5).
 *
 * ⚠ **Kod zdarzenia nie znika — schodzi do `title` wiersza.** Dziennik jest ekranem audytowym
 * i zdanie jest tłumaczeniem, nie źródłem: przy sporze liczy się `auth.login_success`,
 * a nie „zalogował(a) się". Dlatego kod jest w znaczniku każdego wiersza, a nie tylko tam,
 * gdzie zdania zabrakło.
 *
 * ⚠ **Kod bez zdania NIE JEST BŁĘDEM.** Nowe zdarzenie dostaje „<kto> wykonał(a) operację"
 * i swój kod w `title`. Ekran audytowy nie może ukryć zdarzenia dlatego, że ktoś zapomniał
 * dopisać zdanie — ta sama zasada, co w `KodyZdarzen::etykieta()`.
 *
 * ⚠ **Żaden surowy JSON nie trafia do zdania.** Szczegóły (`dodani`, `usunieci`, `nazwa`,
 * `client`) wchodzą do treści jako słowa; to, czego katalog nie przewidział, zostaje
 * w `title` i w kodzie, a nie w środku zdania.
 */
final class ZdanieZdarzenia
{
    /** Barwy kółka — nazwy zgodne z modyfikatorami `.dz-znak--*` w CSS. */
    public const BARWA_LOGOWANIE = 'logowanie';
    public const BARWA_DODANIE = 'dodanie';
    public const BARWA_USUNIECIE = 'usuniecie';
    public const BARWA_ZWYKLA = 'zwykla';

    /**
     * Katalog zdań: kod → [orzeczenie, ikona, barwa].
     *
     * Orzeczenie jest **dalszym ciągiem zdania**, którego podmiotem jest zawsze „kto" —
     * dzięki temu nie powtarzamy sprawcy w każdym wpisie i nie da się zbudować zdania
     * bez niego. Zapis:
     *   `{klucz}`   — wartość ze szczegółów albo podmiotu, wstawiana **pogrubieniem**,
     *   `[... ]`    — część opcjonalna: znika w całości, gdy któryś `{klucz}` w środku jest pusty.
     *
     * ⚠ Pogrubiane jest **to, co pochodzi z danych** — nazwisko, nazwa grupy, uprawnienie.
     * Dzięki temu w zdaniu widać gołym okiem, co jest treścią wpisu, a co szablonem zdania,
     * i nie trzeba o tym decydować przy każdym kodzie osobno.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const ZDANIA = [
        // ——— logowanie i sesje ———
        'auth.login_success'            => ['zalogował(a) się[ {gdzie}]', 'klodka', self::BARWA_LOGOWANIE],
        'auth.logout'                   => ['wylogował(a) się', 'klodka', self::BARWA_ZWYKLA],
        'auth.login_failed'             => ['nie zalogował(a) się — błędny login lub hasło', 'klodka', self::BARWA_USUNIECIE],
        'auth.login_delayed'            => ['musiał(a) zaczekać przed kolejną próbą logowania', 'zegar', self::BARWA_ZWYKLA],
        'auth.password_changed'         => ['zmienił(a) swoje hasło', 'klodka', self::BARWA_ZWYKLA],
        'auth.password_reset_requested' => ['poprosił(a) o link do ustawienia hasła', 'koperta', self::BARWA_ZWYKLA],
        'auth.password_ok_2fa_pending'  => ['podał(a) poprawne hasło i czeka na kod 2FA', 'klodka', self::BARWA_LOGOWANIE],
        'auth.password_link_other_session' => ['otworzył(a) link do hasła w innej sesji', 'klodka', self::BARWA_ZWYKLA],
        'session.revoked'               => ['zakończył(a) sesję', 'prostokaty', self::BARWA_ZWYKLA],
        'session.revoked_by_admin'      => ['zakończył(a) sesję konta {podmiot}', 'prostokaty', self::BARWA_USUNIECIE],
        '2fa.enabled'                   => ['włączył(a) logowanie dwuskładnikowe', 'klodka', self::BARWA_DODANIE],
        '2fa.disabled'                  => ['wyłączył(a) logowanie dwuskładnikowe', 'klodka', self::BARWA_USUNIECIE],
        '2fa.disable_failed'            => ['nie zdołał(a) wyłączyć logowania dwuskładnikowego', 'klodka', self::BARWA_USUNIECIE],
        '2fa.failed'                    => ['podał(a) błędny kod 2FA', 'klodka', self::BARWA_USUNIECIE],
        '2fa.delayed'                   => ['musiał(a) zaczekać przed kolejnym kodem 2FA', 'zegar', self::BARWA_ZWYKLA],
        '2fa.reset_by_admin'            => ['zresetował(a) 2FA na koncie {podmiot}', 'klodka', self::BARWA_USUNIECIE],
        '2fa.backup_codes_acknowledged' => ['potwierdził(a) odebranie kodów zapasowych', 'klodka', self::BARWA_ZWYKLA],
        '2fa.backup_codes_regenerated'  => ['wygenerował(a) nowe kody zapasowe', 'klucz', self::BARWA_ZWYKLA],
        '2fa.backup_code_used'          => ['zalogował(a) się kodem zapasowym', 'klodka', self::BARWA_LOGOWANIE],

        // ——— konta, dostęp, uprawnienia ———
        'account.invited'               => ['zaprosił(a) {podmiot}', 'osoba_plus', self::BARWA_DODANIE],
        'account.activated'             => ['aktywował(a) konto', 'konto', self::BARWA_DODANIE],
        'account.created_uninvited'     => ['utworzył(a) konto {podmiot} bez zaproszenia', 'osoba_plus', self::BARWA_DODANIE],
        'account.created_by_cli'        => ['utworzył(a) konto {podmiot} narzędziem serwisowym', 'osoba_plus', self::BARWA_DODANIE],
        'account.blocked_manually'      => ['zablokował(a) konto {podmiot}', 'klodka', self::BARWA_USUNIECIE],
        'account.blocked_by_sync'       => ['zablokował konto {podmiot} po zmianie w kadrach', 'klodka', self::BARWA_USUNIECIE],
        'account.unblocked_manually'    => ['odblokował(a) konto {podmiot}', 'klodka', self::BARWA_DODANIE],
        'account.unblocked_by_sync'     => ['odblokował konto {podmiot} po powrocie do pracy', 'klodka', self::BARWA_DODANIE],
        'account.block_refused'         => ['próbował(a) zablokować konto {podmiot} — odmowa', 'klodka', self::BARWA_USUNIECIE],
        // ⚠ Bez „(a)" w drugim zdaniu: podmiotem jest synchronizacja, nie człowiek — tak samo
        // jak w `account.blocked_by_sync` i `account.unblocked_by_sync` obok.
        'account.unblock_refused'       => ['próbował(a) odblokować konto {podmiot} — brak wolnych licencji', 'klodka', self::BARWA_USUNIECIE],
        'account.auto_unblock_refused'  => ['nie odblokował konta {podmiot} po powrocie do pracy — brak wolnych licencji', 'klodka', self::BARWA_USUNIECIE],
        'account.email_changed'         => ['zmienił(a) adres logowania konta {podmiot}', 'koperta', self::BARWA_USUNIECIE],
        'account.invitation_resent'     => ['wysłał(a) zaproszenie do {podmiot} jeszcze raz', 'koperta', self::BARWA_ZWYKLA],
        'account.invitation_revoked'    => ['unieważnił(a) zaproszenie dla {podmiot}', 'koperta', self::BARWA_USUNIECIE],
        'account.invitation_resend_refused' => ['nie wysłał(a) zaproszenia ponownie — za krótki odstęp', 'zegar', self::BARWA_ZWYKLA],
        'account.deleted_test_data'     => ['usunął(ęła) dane próbne konta {podmiot}', 'kosz', self::BARWA_USUNIECIE],
        'access.granted'                => ['nadał(a) {podmiot} dostęp do firmy', 'firma', self::BARWA_DODANIE],
        'access.revoked'                => ['odebrał(a) {podmiot} dostęp do firmy', 'firma', self::BARWA_USUNIECIE],
        'access.admin_employed'         => ['dostał(a) zatrudnienie z kadr, mimo roli administratora', 'osoba_bez', self::BARWA_USUNIECIE],
        'permission.granted'            => ['nadał(a) {podmiot} uprawnienie {uprawnienie}[ na poziomie {poziom}]', 'klodka', self::BARWA_DODANIE],
        'permission.revoked'            => ['odebrał(a) {podmiot} uprawnienie {uprawnienie}', 'klodka', self::BARWA_USUNIECIE],
        'permission.refused'            => ['próbował(a) nadać uprawnienie {uprawnienie} — odrzucone', 'klodka', self::BARWA_USUNIECIE],
        'calendar.visibility_granted'   => ['nadał(a) {podmiot} podgląd kalendarza[ na poziomie {poziom}]', 'kalendarz', self::BARWA_DODANIE],
        'calendar.visibility_revoked'   => ['odebrał(a) {podmiot} podgląd kalendarza', 'kalendarz', self::BARWA_USUNIECIE],
        // ⚠ Zdania mówią **kto widzi kogo**, bo tym jest reguła — a nie „nadano uprawnienie X".
        // W treści technicznej są same identyfikatory (§5 zlecenia Z53, CLAUDE.md §18); nazwy
        // składa ekran dziennika z tych identyfikatorów, a nie zapis w bazie.
        // ⚠ Nazwy podmiotu i celu składa `ZdanieDostepu` z identyfikatorów, przy odczycie —
        // w treści wpisu zostają numery (Z53 §5). Znak „›", nie „→": strzałki nie ma w kroju
        // pisma i rysuje się jako pusty prostokąt (zmierzone zrzutem 20.10.2026).
        'calendar_access.granted'       => ['nadał(a) dostęp do kalendarza: {kto} › {czyje} · {zakres}', 'kalendarz', self::BARWA_DODANIE],
        'calendar_access.changed'       => ['zmienił(a) dostęp do kalendarza: {kto} › {czyje} · {zakres}[ (było: {bylo})]', 'olowek', self::BARWA_ZWYKLA],
        'calendar_access.revoked'       => ['odebrał(a) dostęp do kalendarza: {kto} › {czyje} · {zakres}', 'kalendarz', self::BARWA_USUNIECIE],
        // ⚠ **Reguła stała firmy mówi inaczej, bo da się w niej zmienić WYŁĄCZNIE zakres**
        // („Wszyscy przełożeni", „Administratorzy" — podmiot i cel są z definicji). Zdanie
        // o nadaniu i odebraniu dostępu brzmiałoby przy niej nieprawdziwie: tej reguły nikt
        // nie nadaje ani nie odbiera, ona istnieje.
        'calendar_access.granted#stala' => ['ustawił(a) zakres reguły {kto}: {zakres}', 'kalendarz', self::BARWA_ZWYKLA],
        'calendar_access.changed#stala' => ['zmienił(a) zakres reguły {kto}: {bylo} › {zakres}', 'olowek', self::BARWA_ZWYKLA],
        // ⚠ Orzeczenie, nie bezosobowe „dostęp zmieniony": podmiotem zdania jest sprawca
        // z wpisu, więc bez czasownika wychodziło „Kowalski Jan dostęp do kalendarza zmieniony".
        'calendar_access.auto_changed'  => ['zmienił(a) dostęp do kalendarza po usunięciu grupy[ — reguł skasowanych: {skasowanych}][, skróconych: {skroconych}]', 'kosz', self::BARWA_USUNIECIE],
        // ⚠ Sprawcą jest „Migracja" (etykieta `migracja` niżej), więc orzeczenie w rodzaju
        // żeńskim: wcześniej czytało się „migracja przeniósł".
        'calendar_access.migrated'      => ['przeniosła dawne poziomy podglądu do reguł dostępu[ — reguł: {regul}][, bez firmy: {bez_firmy}]', 'kalendarz', self::BARWA_ZWYKLA],
        // ——— status „aktywna" (zlecenie Z80 cz. B) ———
        // ⚠ Zdanie mówi, CZEGO reguła dotyczyła (`{kto} › {czyje}`), a nie jej numeru: numer
        // nic nie znaczy dla czytającego dziennik, a para „kto widzi › czyje" jest całą treścią
        // decyzji o dostępie do danych kadrowych.
        'calendar_access.disabled'      => ['wyłączył(a) regułę dostępu: {kto} › {czyje}[ — {powod}]', 'oko_przekreslone', self::BARWA_USUNIECIE],
        'calendar_access.enabled'       => ['włączył(a) regułę dostępu: {kto} › {czyje} · {zakres}', 'oko', self::BARWA_DODANIE],
        // ⚠ Sprawcą jest system (etykieta `system`), więc orzeczenie bez formy osobowej w nawiasie:
        // „system wyłączył(a)" czytałoby się jak błąd, a nie jak zdanie.
        'calendar_access.auto_disabled' => ['wyłączył regułę dostępu, która przestała cokolwiek dawać: {kto} › {czyje}[ — {powod}]', 'oko_przekreslone', self::BARWA_USUNIECIE],
        'person.email_set'              => ['ustawił(a) adres e-mail osobie {podmiot}', 'koperta', self::BARWA_ZWYKLA],
        'person.contact_changed'        => ['zmienił(a) dane kontaktowe osoby {podmiot}', 'olowek', self::BARWA_ZWYKLA],
        // ⚠ Zdarzenie SYSTEMOWE (migracja), więc zdanie bez sprawcy osobowego i bez numerów:
        // w dzienniku stoi sama liczba przepisanych wierszy (zlecenie Z74, CLAUDE.md §14).
        'person.phones_normalized'      => ['ujednolicił(a) zapis numerów telefonów', 'olowek', self::BARWA_ZWYKLA],

        // ——— grupy ———
        'group.created'                 => ['utworzył(a) grupę {nazwa}', 'etykieta', self::BARWA_DODANIE],
        'group.renamed'                 => ['zmienił(a) nazwę grupy z {z} na {na}', 'olowek', self::BARWA_ZWYKLA],
        'group.deleted'                 => ['usunął(ęła) grupę {nazwa}', 'kosz', self::BARWA_USUNIECIE],
        'group.members_changed'         => ['zmienił(a) skład grupy[ — dodani: {dodani}][ — usunięci: {usunieci}]', 'etykieta', self::BARWA_ZWYKLA],

        // ——— hierarchia, firmy, tenant ———
        // ⚠ Gotowe zdanie z `detail.zdanie` po dwukropku, nie po myślniku: samo zdanie ma już
        // myślnik w środku („X — przełożony: Y"), więc drugi robił z wiersza łamigłówkę.
        'hierarchy.supervisor_changed'  => ['zmienił(a) przełożonego[: {zdanie}]', 'drzewo', self::BARWA_ZWYKLA],
        'context.switched'              => ['przełączył(a) się na inną firmę', 'firma', self::BARWA_ZWYKLA],
        'tenant.company_display_name'   => ['zmienił(a) nazwę firmy z {z} na {na}', 'olowek', self::BARWA_ZWYKLA],
        'tenant.timezone_changed'       => ['zmienił(a) strefę czasową[ na {na}]', 'zegar', self::BARWA_ZWYKLA],
        'tenant.mail_settings_changed'  => ['zmienił(a) ustawienia poczty', 'koperta', self::BARWA_ZWYKLA],
        // ⚠ Dwa zdania na jeden kod, bo skutek jest różny: ustawienie adresu i WYŁĄCZENIE alertów.
        // Rozstrzyga `pusty` ze szczegółów — składa je kontroler, bo to on wie, co się stało.
        // Wartość „z" i „na" zostaje w szczegółach wiersza: bez poprzedniego adresu nie da się
        // odtworzyć, na co alerty szły wcześniej.
        'tenant.alert_email_changed'    => ['zmienił(a) skrzynkę na alerty[ na {na}][ — {pusty}]', 'dzwonek', self::BARWA_ZWYKLA],
        // ⚠ Tych dwóch kodów **już nie zapisujemy** (Z43 §C): wysyłka próbna idzie tą samą parą
        // `mail.sent` / `mail.failed`, co każda inna wiadomość, i niesie odbiorcę. Zdania zostają
        // dla wpisów SPRZED 19.10.2026 — ekran audytowy nie ma prawa stracić historii dlatego,
        // że zmienił się kod. Nowych wpisów z tymi kodami nie powstaje.
        'tenant.mail_test_sent'         => ['wysłał(a) list próbny', 'koperta', self::BARWA_DODANIE],
        'tenant.mail_test_failed'       => ['nie zdołał(a) wysłać listu próbnego', 'koperta', self::BARWA_USUNIECIE],
        'tenant.reset'                  => ['wyczyścił(a) dane tenanta', 'kosz', self::BARWA_USUNIECIE],
        'employment.deactivated_by_sync' => ['wygasił zatrudnienie osoby {podmiot}', 'osoba_bez', self::BARWA_USUNIECIE],
        'install.completed'             => ['zakończył(a) instalację', 'serwer', self::BARWA_DODANIE],

        // ——— integracja ———
        'api.key_issued'                => ['wydał(a) klucz instalacji', 'klucz', self::BARWA_DODANIE],
        'api.key_regenerated'           => ['wydał(a) nowy klucz w miejsce poprzedniego', 'klucz', self::BARWA_ZWYKLA],
        'api.key_replaced'              => ['wymienił(a) klucz instalacji', 'klucz', self::BARWA_ZWYKLA],
        'api.key_superseded'            => ['unieważnił stary klucz po pierwszym przebiegu nowego', 'klucz', self::BARWA_ZWYKLA],
        'api.key_revoked'               => ['unieważnił(a) klucz instalacji', 'klucz', self::BARWA_USUNIECIE],
        'api.key_deleted'               => ['skasował(a) unieważniony klucz', 'kosz', self::BARWA_USUNIECIE],
        'api.key_overlap_finished'      => ['zakończył okres pracy dwóch kluczy naraz', 'klucz', self::BARWA_ZWYKLA],
        'api.key_replacement_reverted'  => ['cofnął(ęła) wymianę klucza', 'klucz', self::BARWA_ZWYKLA],
        'api.key_test_reverted'         => ['cofnął(ęła) próbny klucz', 'klucz', self::BARWA_ZWYKLA],
        'api.key_unused_replaced'       => ['wymienił(a) klucz, którego connector nigdy nie użył', 'klucz', self::BARWA_ZWYKLA],
        'api.key_scope_changed'         => ['zmienił(a) zakres klucza[ — dodane: {dodane}][ — usunięte: {usuniete}]', 'firma', self::BARWA_ZWYKLA],
        'api.company_approved'          => ['zatwierdził(a) zgłoszenie firmy', 'firma', self::BARWA_DODANIE],
        'api.company_rejected'          => ['odrzucił(a) zgłoszenie firmy', 'firma', self::BARWA_USUNIECIE],
        'api.company_signup_reopened'   => ['cofnął(ęła) odrzucenie zgłoszenia firmy', 'firma', self::BARWA_ZWYKLA],
        'api.transient_error'           => ['zgłosił błąd przejściowy', 'wtyczka', self::BARWA_USUNIECIE],
        'integration.key_idle'          => ['zauważył, że connector milczy', 'dzwonek', self::BARWA_USUNIECIE],
        'integration.key_unused'        => ['zauważył, że wydany klucz nie został użyty', 'dzwonek', self::BARWA_ZWYKLA],
        'integration.company_idle'      => ['zauważył, że firma nie synchronizuje się od progu', 'dzwonek', self::BARWA_ZWYKLA],
        'sync.identity_accepted'        => ['przyjął nowy odcisk tożsamości osób', 'wtyczka', self::BARWA_ZWYKLA],
        // ⚠ Kody składane ze stałych (`SyncService::EVENT_SPLIT`) — w spisie kodów łatwo je
        // przeoczyć, bo w `record()` nie ma literału. Rozjazd NIE JEST awarią: synchronizacja
        // działa, tylko osoby z dwóch firm nie będą scalane (ustalenie 20.09.2026).
        'sync.identity_split'           => ['zgłosił dwie instalacje connectora na jednym kluczu', 'wtyczka', self::BARWA_USUNIECIE],
        'sync.identity_split_resolved'  => ['zgłosił koniec rozjazdu instalacji connectora', 'wtyczka', self::BARWA_DODANIE],
        'sync.identity_changed'         => ['zgłosił zmianę odcisku tożsamości osób', 'wtyczka', self::BARWA_USUNIECIE],
        'sync.review_requested'         => ['zamówił(a) przegląd kontrolny firmy', 'odswiezenie', self::BARWA_ZWYKLA],
        'sync.foreign_connector_entry'  => ['przyjął wpis z innego connectora', 'wtyczka', self::BARWA_ZWYKLA],
        'sync.limit_removed_with_requests' => ['usunął limit, do którego były wnioski', 'wnioski', self::BARWA_USUNIECIE],
        'sync.limit_kind_ignored'       => ['pominął limit nieznanego rodzaju', 'wnioski', self::BARWA_ZWYKLA],
        'sync.kadrowe_with_length'      => ['przysłał nieobecność kadrową z długością', 'kalendarz', self::BARWA_ZWYKLA],

        // ——— wnioski urlopowe ———
        'leave.request_submitted'       => ['złożył(a) wniosek urlopowy[ na {dni}]', 'wnioski', self::BARWA_DODANIE],
        'leave.approve'                 => ['zatwierdził(a) wniosek urlopowy', 'wnioski', self::BARWA_DODANIE],
        'leave.reject'                  => ['odrzucił(a) wniosek urlopowy', 'wnioski', self::BARWA_USUNIECIE],
        'leave.cancel_request'          => ['poprosił(a) o cofnięcie wniosku', 'wnioski', self::BARWA_ZWYKLA],
        'leave.cancel_approved'         => ['cofnął(ęła) zatwierdzony wniosek', 'wnioski', self::BARWA_USUNIECIE],
        'leave.cancel_closed_locally'   => ['zamknął(ęła) wniosek po stronie aplikacji', 'wnioski', self::BARWA_ZWYKLA],
        'leave.changed_in_r2'           => ['zmienił wniosek w programie kadrowym', 'wnioski', self::BARWA_ZWYKLA],
        'leave.r2_warning'              => ['zgłosił ostrzeżenie z programu kadrowego', 'dzwonek', self::BARWA_ZWYKLA],
        'leave.test_request_removed'    => ['usunął(ęła) próbny wniosek', 'kosz', self::BARWA_USUNIECIE],

        // ——— poczta: FAKT wysyłki, nie zamówienie wiadomości (Z43 §C) ———
        // ⚠ Podmiotem zdania jest sprawca z wpisu, więc czyta się „Zadanie cykliczne wysłało…"
        // albo „<administrator> wysłał wiadomość próbną…" — a nie bezosobowe „Wysłano…".
        // Katalog zdań nie umie zdania bez podmiotu i nie dokładamy mu drugiego trybu dla
        // jednej rodziny: sprawca jest w tym dzienniku tą daną, o którą pyta się najpierw.
        // ⚠ `{droga}` pojawia się tylko na `APP_ENV=dev` bez SMTP („zrzut do pliku”) — inaczej
        // zdanie twierdziłoby, że wiadomość wyszła w świat, a ona wylądowała w `var/mail`.
        'mail.sent'                     => ['wysłał(a) {rodzaj} do {odbiorca}[ — {droga}]', 'koperta', self::BARWA_DODANIE],
        // ⚠ `{rodzaj}` przychodzi tu w DOPEŁNIACZU („zaproszenia"), bo zaprzeczone orzeczenie
        // go wymaga — formę dobiera `ZadaniaKolejki`, nie to zdanie.
        'mail.failed'                   => ['nie wysłał(a) {rodzaj} do {odbiorca}[ — {powod}]', 'koperta', self::BARWA_USUNIECIE],

        // ——— bezpieczeństwo i utrzymanie ———
        'security.ddl_blocked'          => ['zablokował próbę zmiany schematu bazy', 'dzwonek', self::BARWA_USUNIECIE],
        'security.login_spraying_suspected' => ['zauważył próby logowania na wiele kont naraz', 'dzwonek', self::BARWA_USUNIECIE],
        'security.storage_exposed'      => ['stwierdził, że katalog danych jest osiągalny z internetu', 'dzwonek', self::BARWA_USUNIECIE],
        'security.db_single_account'    => ['stwierdził, że baza pracuje na jednym koncie', 'dzwonek', self::BARWA_USUNIECIE],
        'security.db_app_account_has_ddl' => ['stwierdził, że konto aplikacji ma prawa do zmiany schematu', 'dzwonek', self::BARWA_USUNIECIE],
        'security.canary_unresolved'    => ['zostawił nierozstrzygnięty sygnał kontrolny', 'dzwonek', self::BARWA_USUNIECIE],
        'maintenance.cron_probe'        => ['wykonał sondę crona', 'zegar', self::BARWA_ZWYKLA],
        'maintenance.cron_probe_requested' => ['postawił(a) sondę crona', 'zegar', self::BARWA_ZWYKLA],
        'maintenance.session_cleanup'   => ['posprzątał wygasłe sesje', 'zegar', self::BARWA_ZWYKLA],
        'maintenance.sync_cleanup'      => ['posprzątał dane synchronizacji', 'zegar', self::BARWA_ZWYKLA],
        'maintenance.key_idle_check'    => ['sprawdził ciszę kluczy', 'zegar', self::BARWA_ZWYKLA],
        'maintenance.canary_check'      => ['sprawdził sygnał kontrolny', 'zegar', self::BARWA_ZWYKLA],
        // ⚠ Liczba stoi w zdaniu TYLKO wtedy, gdy jest większa od jednego, i dokłada ją
        // kontroler (`deleteQueueJob`): jeden klik usuwa wszystkie czekające zadania tego typu,
        // więc „usunął zadanie" przy trzech usuniętych zaniżałoby wpis audytowy — a „sztuk: 1"
        // przy jednym byłoby szumem w każdym normalnym wierszu.
        'maintenance.job_deleted'       => ['usunął(ęła) z kolejki zadanie {zadanie}[, sztuk: {ile}]', 'kosz', self::BARWA_USUNIECIE],
    ];

    /** Ikona rodziny — dla kodu bez własnego zdania. */
    private const IKONY_RODZIN = [
        'auth.' => 'klodka', '2fa.' => 'klodka', 'session.' => 'prostokaty',
        'account.' => 'konto', 'access.' => 'firma', 'permission.' => 'klodka',
        'person.' => 'konto', 'group.' => 'etykieta', 'hierarchy.' => 'drzewo',
        'calendar.' => 'kalendarz', 'context.' => 'firma', 'tenant.' => 'ustawienia',
        'api.' => 'klucz', 'integration.' => 'wtyczka', 'sync.' => 'wtyczka',
        'employment.' => 'zespol', 'leave.' => 'wnioski', 'security.' => 'dzwonek',
        'maintenance.' => 'zegar', 'install.' => 'serwer', 'mail.' => 'koperta',
    ];

    /** Sprawcy, którzy nie są ludźmi — etykieta z bazy na nazwę po polsku. */
    private const SPRAWCY = [
        'sync'       => 'Connector',
        'api'        => 'Connector',
        'narzedzie'  => 'Narzędzie serwisowe',
        'instalator' => 'Instalator',
        'system'     => 'System',
        'cron'       => 'Zadanie cykliczne',
        // ⚠ Etykieta zapisana przez migrację bazy (Z55). Bez tego wiersza na ekranie stało
        // gołe „migracja" małą literą, jak nazwisko zapisane z błędem.
        'migracja'   => 'Migracja',
        'przelozony' => 'Przełożony',
    ];

    /** Nazwa klienta z `detail.client` jako okolicznik zdania. */
    private const KLIENCI = [
        'web'     => 'w przeglądarce',
        'mobile'  => 'w aplikacji mobilnej',
        'desktop' => 'w aplikacji na komputer',
    ];

    /**
     * @param array<string, mixed> $wpis wiersz z `EventLogRepository::page()`
     * @return array{kto: string, czesci: list<array{t: string, mocno: bool}>, ikona: string, barwa: string, znane: bool}
     */
    public static function zbuduj(array $wpis): array
    {
        $kod = (string) $wpis['kod'];
        $wartosci = self::wartosci($wpis);
        // ⚠ **Wariant zdania wybiera DANA, nie kod.** Jeden kod zdarzenia opisuje dwie różne
        // rzeczy, gdy regułą jest reguła stała firmy (da się w niej zmienić tylko zakres) —
        // a kodu rozdzielić nie wolno: po kodach filtruje się dziennik i stoją w `KodyZdarzen`
        // razem z wpisami sprzed tej zmiany. Wariant podaje warstwa, która zna treść wpisu
        // (`ZdanieDostepu`), i nigdy nie przychodzi z żądania.
        $wariant = (string) ($wartosci['wariant'] ?? '');
        $wzor = ($wariant === '' ? null : self::ZDANIA[$kod . '#' . $wariant] ?? null)
            ?? self::ZDANIA[$kod]
            ?? null;

        return [
            'kto'    => self::kto($wpis),
            'czesci' => $wzor === null
                // ⚠ Zdanie zastępcze mówi prawdę i nic ponadto: co się stało, jest w kodzie,
                // a kod stoi w `title` wiersza.
                ? [['t' => 'wykonał(a) operację', 'mocno' => false]]
                : self::zloz($wzor[0], $wartosci),
            'ikona'  => $wzor[1] ?? self::ikonaRodziny($kod),
            'barwa'  => $wzor[2] ?? self::BARWA_ZWYKLA,
            'znane'  => $wzor !== null,
        ];
    }

    /** Czy kod ma własne zdanie w katalogu (do spisu „kody bez zdania"). */
    public static function maZdanie(string $kod): bool
    {
        return isset(self::ZDANIA[$kod]);
    }

    /**
     * Kody z własnym zdaniem — **bez wariantów** (`kod#wariant`). Wariant nie jest kodem
     * zdarzenia: nie zapisuje się go w bazie i nie da się po nim filtrować dziennika.
     *
     * @return list<string>
     */
    public static function kody(): array
    {
        $kody = [];
        foreach (array_keys(self::ZDANIA) as $klucz) {
            $kody[explode('#', $klucz)[0]] = true;
        }

        return array_keys($kody);
    }

    /**
     * Wartości, które NIE są pogrubiane mimo że pochodzą z danych.
     *
     * ⚠ „w przeglądarce" to tłumaczenie `client: web`, a nie treść wpisu: pogrubione czytało się
     * jak nazwa własna i przeciągało wzrok z nazwiska na okolicznik. Pogrubienie zostaje dla tego,
     * czego wpis dotyczy — kto, komu, jaką grupę.
     *
     * ⚠ `powod` i `droga` (Z43) są tu z tego samego powodu: w zdaniu o niewysłanej wiadomości
     * daną jest ODBIORCA, a komunikat serwera SMTP to okolicznik. Pogrubiony przeciągał wzrok
     * na trzy linijki techniczne i zasłaniał to, czego wpis dotyczy.
     *
     * @var list<string>
     */
    private const ZWYKLE = ['gdzie', 'powod', 'droga', 'pusty'];

    /**
     * Rozwinięcie wzoru na części: tekst zwykły i wartości z danych (pogrubione).
     *
     * ⚠ Część `[...]` znika W CAŁOŚCI, gdy brakuje którejkolwiek wartości w środku. Bez tego
     * zdanie kończyło się „na poziomie" albo „— dodani:" i wyglądało na uciętą treść,
     * a nie na brak szczegółu.
     *
     * @param array<string, string> $wartosci
     * @return list<array{t: string, mocno: bool}>
     */
    private static function zloz(string $wzor, array $wartosci): array
    {
        // Najpierw części opcjonalne — każda rozstrzyga się sama, niezależnie od pozostałych.
        $wzor = (string) preg_replace_callback(
            '/\[([^\[\]]*)\]/u',
            static function (array $m) use ($wartosci): string {
                preg_match_all('/\{([a-z_]+)\}/u', $m[1], $klucze);
                foreach ($klucze[1] as $klucz) {
                    if (($wartosci[$klucz] ?? '') === '') {
                        return '';
                    }
                }

                return $m[1];
            },
            $wzor,
        );

        $czesci = [];
        $kawalki = preg_split('/(\{[a-z_]+\})/u', $wzor, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach (is_array($kawalki) ? $kawalki : [] as $kawalek) {
            if (preg_match('/^\{([a-z_]+)\}$/u', $kawalek, $m) === 1) {
                $wartosc = $wartosci[$m[1]] ?? '';
                if ($wartosc !== '') {
                    $czesci[] = ['t' => $wartosc, 'mocno' => !in_array($m[1], self::ZWYKLE, true)];
                }

                continue;
            }
            $czesci[] = ['t' => $kawalek, 'mocno' => false];
        }

        return $czesci;
    }

    /**
     * Wartości do wstawienia w zdanie — ze szczegółów wpisu i z podmiotu.
     *
     * ⚠ **Tablice zamieniamy na wyliczenie nazw, nigdy na JSON** (Z36 §5): `{"dodani":["…"]}`
     * w środku zdania to dokładnie ten surowy JSON, który ekran miał przestać pokazywać.
     * Wartość nieskalarna i nieznana staje się pustym napisem, przez co część opcjonalna
     * zdania znika — zamiast wyświetlić coś, czego nie umiemy nazwać.
     *
     * @param array<string, mixed> $wpis
     * @return array<string, string>
     */
    private static function wartosci(array $wpis): array
    {
        $dane = is_array($wpis['detale'] ?? null) ? $wpis['detale'] : [];
        $out = [];
        foreach ($dane as $klucz => $wartosc) {
            $klucz = (string) $klucz;
            $out[$klucz] = match (true) {
                is_string($wartosc) || is_int($wartosc) || is_float($wartosc) => trim((string) $wartosc),
                is_bool($wartosc) => $wartosc ? 'tak' : 'nie',
                is_array($wartosc) => self::wyliczenie($wartosc),
                default => '',
            };
        }
        // Nazwy w cudzysłowie drukarskim — grupa „Magazyn" czyta się inaczej niż grupa Magazyn.
        foreach (['nazwa', 'z', 'na'] as $klucz) {
            if (($out[$klucz] ?? '') !== '' && !is_numeric($out[$klucz])) {
                $out[$klucz] = '„' . $out[$klucz] . '”';
            }
        }
        // Liczba dni z odmianą — „na 2 dni", nie „na 2".
        if (($out['dni'] ?? '') !== '') {
            $out['dni'] = $out['dni'] . ' ' . Odmiana::dni((int) $out['dni']);
        }
        $out['gdzie'] = self::KLIENCI[(string) ($dane['client'] ?? '')] ?? '';
        $out['podmiot'] = self::podmiot($wpis);

        return $out;
    }

    /**
     * Tablica (np. `dodani`) jako wyliczenie — **najwyżej trzy pozycje, reszta jako liczba**.
     *
     * ⚠ Zmierzone na bazie roboczej: jedna zmiana składu grupy miała 36 nazwisk, czyli jeden
     * wpis dziennika na trzy linijki ekranu i listę, której i tak nikt nie przeczyta. Pełna
     * treść zostaje w `title` wiersza (szczegóły wpisu), a w zdaniu stoi tyle, ile daje się
     * objąć wzrokiem.
     *
     * @param array<array-key, mixed> $wartosci
     */
    private static function wyliczenie(array $wartosci): string
    {
        $pozycje = array_values(array_map(
            static fn (mixed $x): string => (string) $x,
            array_filter($wartosci, static fn (mixed $x): bool => is_scalar($x)),
        ));
        if ($pozycje === []) {
            return '';
        }
        $widoczne = array_slice($pozycje, 0, 3);
        $reszta = count($pozycje) - count($widoczne);

        return implode(', ', $widoczne) . ($reszta > 0 ? ' i jeszcze ' . $reszta : '');
    }

    /** @param array<string, mixed> $wpis */
    private static function podmiot(array $wpis): string
    {
        if (is_string($wpis['podmiot'] ?? null) && $wpis['podmiot'] !== '') {
            return (string) $wpis['podmiot'];
        }
        $typ = $wpis['podmiot_typ'] ?? null;
        $id = $wpis['podmiot_id'] ?? null;
        if ($typ === 'account' && $id !== null) {
            // ⚠ Konto skasowane albo bez kartoteki: numer i jawne „nieznane" (Z36 §5).
            // Puste miejsce w audycie wygląda jak brak zdarzenia, a nie jak brak nazwy.
            return 'konto #' . $id . ' (nieznane)';
        }

        return '';
    }

    /** @param array<string, mixed> $wpis */
    private static function kto(array $wpis): string
    {
        if (is_string($wpis['aktor'] ?? null) && $wpis['aktor'] !== '') {
            return (string) $wpis['aktor'];
        }
        $etykieta = (string) ($wpis['aktor_etykieta'] ?? '');
        if ($etykieta !== '') {
            return self::SPRAWCY[$etykieta] ?? $etykieta;
        }
        if (($wpis['konto_id'] ?? null) !== null) {
            return 'konto #' . $wpis['konto_id'] . ' (nieznane)';
        }

        return 'System';
    }

    private static function ikonaRodziny(string $kod): string
    {
        foreach (self::IKONY_RODZIN as $prefiks => $ikona) {
            if (str_starts_with($kod, $prefiks)) {
                return $ikona;
            }
        }

        return 'wnioski';
    }
}
