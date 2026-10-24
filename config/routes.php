<?php

declare(strict_types=1);

// Trasy aplikacji — jedyne miejsce ich definicji (CLAUDE.md §3, docs/szkielet-tenanta.md rozdz. 3).
// Każda trasa: metoda, ścieżka, [kontroler, akcja] oraz 'permission' => '...'
// albo jawne 'auth' => 'none' | 'partial'. Trasa bez tego nie pozwala wystartować aplikacji
// w trybie deweloperskim, a w produkcji odpowiada 403.
// 'exempt' => ['2fa', 'context'] — wyłącznie ekran parowania 2FA i wybór firmy (rozdz. 3).
//
// Widok mobilny: te same akcje pod prefiksem /m (rozdz. 8). Trasy z $shared dostają odpowiednik
// mobilny poniżej; $webOnly — nie (instalator i diagnostyka nie istnieją w aplikacji mobilnej).
// Adresy po polsku (widzi je użytkownik), nazwy w kodzie po angielsku.

use Wezurlop\Controller\Api\SyncController;
use Wezurlop\Controller\App\AdminAccountController;
use Wezurlop\Controller\App\AdminIntegrationController;
use Wezurlop\Controller\App\CalendarController;
use Wezurlop\Controller\App\ContextController;
use Wezurlop\Controller\App\DashboardController;
use Wezurlop\Controller\App\DataController;
use Wezurlop\Controller\App\VisibilityController;
use Wezurlop\Controller\App\DeviceController;
use Wezurlop\Controller\App\DiagnosticsController;
use Wezurlop\Controller\App\EventLogController;
use Wezurlop\Controller\App\GroupController;
use Wezurlop\Controller\App\HierarchyController;
use Wezurlop\Controller\App\LeaveRequestController;
use Wezurlop\Controller\App\LicenseController;
use Wezurlop\Controller\App\ProfileController;
use Wezurlop\Controller\App\SecurityController;
use Wezurlop\Controller\App\SettingsController;
use Wezurlop\Controller\App\StyleguideController;
use Wezurlop\Controller\App\SystemPageController;
use Wezurlop\Controller\App\TeamController;
use Wezurlop\Controller\Auth\ExternalLoginController;
use Wezurlop\Controller\Auth\InvitationController;
use Wezurlop\Controller\Auth\LoginController;
use Wezurlop\Controller\Auth\ResetController;
use Wezurlop\Controller\Auth\TokenController;
use Wezurlop\Controller\Auth\TwoFactorController;

// Plik zwraca funkcję: część tras istnieje tylko w trybie deweloperskim (np. /styleguide) —
// w produkcji nie jest ukryta, tylko nie powstaje (docs/szkielet-tenanta.md rozdz. 8).
return static function (bool $isDev): array {
$shared = [
    // publiczne
    ['GET',  '/logowanie',                               [LoginController::class, 'form'],             'auth' => 'none'],
    ['POST', '/logowanie',                               [LoginController::class, 'submit'],           'auth' => 'none'],
    ['GET',  '/logowanie/zewnetrzne/{provider:google|apple}', [ExternalLoginController::class, 'start'], 'auth' => 'none'],
    ['GET',  '/haslo/reset',                             [ResetController::class, 'request'],          'auth' => 'none'],
    ['POST', '/haslo/reset',                             [ResetController::class, 'send'],             'auth' => 'none'],
    ['GET',  '/haslo/nowe',                              [ResetController::class, 'form'],             'auth' => 'none'],
    ['POST', '/haslo/nowe',                              [ResetController::class, 'save'],             'auth' => 'none'],
    ['GET',  '/zaproszenie',                             [InvitationController::class, 'form'],        'auth' => 'none'],
    ['POST', '/zaproszenie',                             [InvitationController::class, 'save'],        'auth' => 'none'],
    ['GET',  '/formularz-wygasl',                        [SystemPageController::class, 'formExpired'], 'auth' => 'none'],
    // ⚠ Świeży token dla karty, która stała otwarta (zlecenie Z56 §2). GET, bo niczego nie
    // zmienia: oddaje tę samą wartość, którą ta sesja ma w każdym formularzu. Limit na adres
    // jest w kontrolerze, a `no-store` w odpowiedzi.
    ['GET',  '/token-formularza',                        [TokenController::class, 'token'],            'auth' => 'none'],

    // po haśle (także przed 2FA)
    ['GET',  '/logowanie/kod',                           [TwoFactorController::class, 'form'],         'auth' => 'partial', 'exempt' => ['2fa', 'context']],
    ['POST', '/logowanie/kod',                           [TwoFactorController::class, 'verify'],       'auth' => 'partial', 'exempt' => ['2fa', 'context']],
    ['POST', '/wyloguj',                                 [LoginController::class, 'logout'],           'auth' => 'partial', 'exempt' => ['2fa', 'context']],
    // ⚠ Jedyne miejsce, w którym otwarcie linku do hasła kończy sesję — i tylko po kliknięciu
    // przycisku na ekranie wyboru (zlecenie `link-hasla-przy-zalogowanej-sesji` §2.2).
    ['POST', '/haslo/link/wyloguj',                      [LoginController::class, 'logoutToLink'],     'auth' => 'partial', 'exempt' => ['2fa', 'context']],

    // zalogowani
    ['GET',  '/',                                        [DashboardController::class, 'index'],        'permission' => 'app.use', 'zakres' => 'spolka'],
    ['GET',  '/kalendarz',                               [CalendarController::class, 'index'],         'permission' => 'app.use', 'zakres' => 'spolka'],
    // ⚠ Zapis WYBORU osób na kalendarzu zespołu — POST, bo zmienia stan (CLAUDE.md §3).
    // To filtr, nie nadanie dostępu: zapisywany jest wynik sprawdzenia zakresu, nie wejście.
    // ⚠ GET oddaje samą KONTROLKĘ (szukanie po stronie serwera), POST zapisuje wybór — ta sama
    // ścieżka, dwie metody, jak przy potwierdzeniach na /admin/integracja. GET niczego nie zmienia.
    ['GET',  '/kalendarz/osoby',                         [CalendarController::class, 'kontrolka'],     'permission' => 'app.use', 'zakres' => 'spolka'],
    ['POST', '/kalendarz/osoby',                         [CalendarController::class, 'osoby'],         'permission' => 'app.use', 'zakres' => 'spolka'],
    ['GET',  '/wnioski',                                 [LeaveRequestController::class, 'index'],     'permission' => 'app.use', 'zakres' => 'spolka'],
    ['GET',  '/wniosek/nowy',                            [LeaveRequestController::class, 'create'],    'permission' => 'app.use', 'zakres' => 'spolka'],
    ['GET',  '/zespol',                                  [TeamController::class, 'index'],             'permission' => 'app.use', 'zakres' => 'spolka'],
    ['GET',  '/ustawienia',                              [SettingsController::class, 'index'],         'permission' => 'admin.settings', 'zakres' => 'tenant'],
    ['POST', '/ustawienia/strefa',                       [SettingsController::class, 'saveTimezone'],  'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Sonda crona, nie „uruchom teraz": stawia zadanie i wraca. Wykonać ma je przebieg crona.
    ['POST', '/ustawienia/cron/sprawdz',                 [SettingsController::class, 'checkCron'],     'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Lekki GET do odpytywania stanu sondy (zlecenie Z65) — sam wiersz, bez reszty ekranu.
    // `GET`, bo niczego nie zmienia: sondę stawia `POST` wyżej (CLAUDE.md §3).
    ['GET',  '/ustawienia/cron/sonda',                   [SettingsController::class, 'cronProbe'],     'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Skrzynka na alerty (Z49): zapis i wysyłka próbna jednym POST-em, bo próba dotyczy adresu
    // WPISANEGO w pole, a nie zapisanego — rozstrzyga nazwa wciśniętego przycisku.
    ['POST', '/ustawienia/alerty',                       [SettingsController::class, 'saveAlertEmail'], 'permission' => 'admin.settings', 'zakres' => 'tenant'],
    ['GET',  '/ustawienia/poczta',                       [SettingsController::class, 'mail'],          'permission' => 'admin.settings', 'zakres' => 'tenant'],
    ['POST', '/ustawienia/poczta',                       [SettingsController::class, 'saveMail'],      'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Wysyłka próbna w żądaniu to świadomy wyjątek od „poczta tylko przez kolejkę": sens tego
    // przycisku polega na tym, że administrator widzi błąd SMTP od razu. Limit transportu 10 s.
    ['POST', '/ustawienia/poczta/proba',                 [SettingsController::class, 'testMail'],      'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Usunięcie zadań z kolejki (Z43 §B): POST, bo zmienia stan (§3), uprawnienie to samo,
    // co reszta ekranu, a o tym, których typów wolno dotknąć, decyduje biała lista
    // w `Support\ZadaniaKolejki` — nie parametr żądania.
    ['POST', '/ustawienia/poczta/kolejka/usun',          [SettingsController::class, 'deleteQueueJob'], 'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ Stary adres osobnego ekranu nazw — od Z29 przekierowuje na `/ustawienia?okno=nazwy`,
    // czyli do tego samego okna, które otwiera przycisk „Zmień nazwy". Trasa zostaje, bo prowadzą
    // do niej zakładki, zrzuty ekranu i kryterium bramki.
    ['GET',  '/ustawienia/spolki',                       [SettingsController::class, 'companies'],     'permission' => 'admin.settings', 'zakres' => 'tenant'],
    // ⚠ JEDEN zapis dla wszystkich firm (Z29): jednoznaczność nazw dotyczy całego zestawu,
    // więc zapis per wiersz odbijał się o klucz unikalny przy zwykłej zamianie dwóch nazw.
    ['POST', '/ustawienia/spolki',                       [SettingsController::class, 'saveCompanyNames'], 'permission' => 'admin.settings', 'zakres' => 'tenant'],
    ['GET',  '/kontekst',                                [ContextController::class, 'choose'],         'permission' => 'app.use', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['POST', '/kontekst/{id:\d+}',                       [ContextController::class, 'switch'],         'permission' => 'app.use', 'exempt' => ['context'], 'zakres' => 'tenant'],
    // ⚠ **Drugi kształt tej samej drogi** (zlecenie 10.10.2026 §1): konto bez zatrudnienia
    // w spółce nie ma czym jej wskazać, a `?spolka=` znika z adresów. Ten sam kontroler,
    // ten sam wpis `context.switched`, ta sama walidacja listą z bazy.
    ['POST', '/kontekst/spolka/{id:\d+}',                [ContextController::class, 'switchCompany'],  'permission' => 'app.use', 'exempt' => ['context'], 'zakres' => 'tenant'],

    // konto: profil, 2FA, urządzenia
    ['GET',  '/konto',                                   [ProfileController::class, 'index'],          'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    // ⚠ **Telefon i hasło zmienia sam właściciel konta** (zlecenie Z69, decyzja Krzysztofa):
    // `account.manage_own`, bez `admin.users` — nie ma tu żadnego identyfikatora osoby
    // w żądaniu, bo kontroler bierze `person_id` z konta w sesji.
    ['POST', '/konto/telefon',                           [ProfileController::class, 'telefon'],        'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['POST', '/konto/telefon/usun',                      [ProfileController::class, 'telefonUsun'],    'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['POST', '/konto/haslo',                             [ProfileController::class, 'haslo'],          'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    // ⚠ Nowe kody zapasowe zostają w `SecurityController` razem z całą logiką 2FA (Z69).
    ['POST', '/ustawienia/2fa/kody/nowe',                [SecurityController::class, 'regenerate'],    'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['GET',  '/ustawienia/2fa',                          [SecurityController::class, 'index'],         'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/2fa/rozpocznij',               [SecurityController::class, 'start'],         'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['GET',  '/ustawienia/2fa/kod.svg',                  [SecurityController::class, 'qr'],            'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/2fa/wlacz',                    [SecurityController::class, 'enable'],        'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['GET',  '/ustawienia/2fa/kody',                     [SecurityController::class, 'codes'],         'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/2fa/kody/pobierz',             [SecurityController::class, 'download'],      'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/2fa/kody/potwierdz',           [SecurityController::class, 'acknowledge'],   'permission' => 'account.manage_own', 'exempt' => ['2fa', 'context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/2fa/wylacz',                   [SecurityController::class, 'disable'],       'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['GET',  '/ustawienia/urzadzenia',                   [DeviceController::class, 'index'],           'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/urzadzenia/zakoncz',           [DeviceController::class, 'revoke'],          'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
    ['POST', '/ustawienia/urzadzenia/zakoncz-pozostale', [DeviceController::class, 'revokeOthers'],    'permission' => 'account.manage_own', 'exempt' => ['context'], 'zakres' => 'tenant'],
];

$webOnly = [
    // podgląd danych z kadr — WYŁĄCZNIE odczyt (żadnej trasy POST); `data.view` działa też z przypisania
    // per firma, a filtr po firmie jest w zapytaniach repozytorium (CLAUDE.md §10)
    ['GET',  '/dane',                                    [DataController::class, 'companies'],         'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/dane/firma/{id:\d+}',                     [DataController::class, 'employees'],         'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/dane/pracownik/{id:\d+}',                 [DataController::class, 'employee'],          'permission' => 'data.view', 'zakres' => 'tenant'],
    // Fragment karty nieobecności (§5.8) — pełnoprawna trasa z tym samym uprawnieniem co ekran.
    ['GET',  '/dane/pracownik/{id:\d+}/nieobecnosci',      [DataController::class, 'absences'],  'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/dane/pracownik/{id:\d+}/kalendarz',       [DataController::class, 'calendar'],          'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/dane/synchronizacja',                     [DataController::class, 'runs'],              'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/dane/synchronizacja/{id:\d+}',            [DataController::class, 'run'],               'permission' => 'data.view', 'zakres' => 'tenant'],
    ['GET',  '/diagnostyka',                             [DiagnosticsController::class, 'index'],      'permission' => 'admin.settings', 'zakres' => 'tenant'],
    ['GET',  '/licencja',                                [LicenseController::class, 'index'],          'permission' => 'admin.license', 'zakres' => 'tenant'],
    // ⚠ Ekran audytowy: uprawnienie `admin.event_log` (martwe od instalacji) sprawdza `Authorize`
    // na każdym żądaniu. Nie ma tu wariantu fragmentowego — stronicowanie i filtr to zwykłe GET-y.
    // ⚠ Hierarchia ma WŁASNE uprawnienie: ten ekran nie zmienia kont ani dostępów, tylko to,
    // kto oglądać będzie czyje dane kadrowe i przez kogo pójdzie wniosek. Pozycja w menu
    // pilnuje tego samego uprawnienia — inaczej menu kłamie o dostępie.
    ['GET',  '/admin/hierarchia',                        [HierarchyController::class, 'index'],       'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['GET',  '/admin/hierarchia/osoba/{id:\d+}',         [HierarchyController::class, 'person'],      'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    // ⚠ **Podgląd skutku niezapisanych zmian jest `GET`-em** (zlecenie Z80 cz. C): nie zapisuje
    // nic i nie bierze blokad na wierszach, a pasek zmian odpytuje go przy każdym przeciągnięciu
    // karty. To samo uprawnienie i ten sam zakres spółki co zapis — fragment htmx jest
    // pełnoprawnym endpointem (CLAUDE.md §5).
    ['GET',  '/admin/hierarchia/podglad',                [HierarchyController::class, 'podglad'],     'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/hierarchia/zapisz',                 [HierarchyController::class, 'save'],        'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    // ⚠ **Grupy pod TYM SAMYM uprawnieniem co hierarchia** — `admin.groups` świadomie
    // NIE powstało (zlecenie 08.10 §0): uprawnienie bez roli, której można by je nadać, jest
    // uprawnieniem „na zapas" (§1 reguł) i wraca razem z rolą kadrowej. Skutek jest jawny:
    // kto prowadzi hierarchię, prowadzi też grupy. Dziś to wyłącznie administrator tenanta.
    // ⚠ Pozycja w menu pilnuje TEGO SAMEGO uprawnienia — inaczej menu kłamie o dostępie.
    ['GET',  '/admin/grupy',                             [GroupController::class, 'index'],           'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/grupy',                             [GroupController::class, 'create'],          'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['GET',  '/admin/grupy/{id:\d+}',                    [GroupController::class, 'show'],            'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    // ⚠ **Dwa punkty zapisu, oba POST i oba z listą `osoby[]`** (zlecenie 10.10 §3: zapis
    // natychmiastowy). `GET …/osoby` zwraca obie kolumny — do szukania w prawej. Lista,
    // a nie identyfikator w ścieżce, bo „Dodaj wszystkich pokazanych" i jego „Cofnij" mają
    // mieć ten sam kształt co pojedyncze `+` i `✕`.
    ['GET',  '/admin/grupy/{id:\d+}/osoby',              [GroupController::class, 'fragment'],        'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/grupy/{id:\d+}/osoby/dodaj',        [GroupController::class, 'add'],             'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/grupy/{id:\d+}/osoby/usun',         [GroupController::class, 'remove'],          'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/grupy/{id:\d+}/nazwa',              [GroupController::class, 'rename'],          'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],
    ['POST', '/admin/grupy/{id:\d+}/usun',               [GroupController::class, 'delete'],          'permission' => 'admin.hierarchy', 'zakres' => 'spolka'],

    // ⚠ **Własne uprawnienie, nie `admin.hierarchy`** (zlecenie Z53 §4). Hierarchia i grupy
    // układają STRUKTURĘ, a ten ekran rozdaje **wgląd w nieobecności**, czyli w dane o zdrowiu.
    // Zlanie ich znaczyłoby, że nie da się powierzyć komuś porządkowania działów bez oddania mu
    // kalendarzy całej firmy. ⚠ Pozycja w menu pilnuje TEGO SAMEGO uprawnienia.
    // ⚠ Kroki okna idą `GET`-em, bo niczego nie zmieniają (CLAUDE.md §3); zapis i usunięcie `POST`-em.
    ['GET',  '/admin/widocznosc',                        [VisibilityController::class, 'index'],       'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    ['GET',  '/admin/widocznosc/okno',                   [VisibilityController::class, 'okno'],        'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    ['GET',  '/admin/widocznosc/pozycje',                [VisibilityController::class, 'pozycje'],     'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    ['POST', '/admin/widocznosc/zapisz',                 [VisibilityController::class, 'zapisz'],      'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    ['POST', '/admin/widocznosc/{id:\d+}/usun',          [VisibilityController::class, 'usun'],        'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    // Z80 B2: włączenie i wyłączenie reguły. `POST`, bo zmienia stan (CLAUDE.md §3),
    // i to samo uprawnienie co reszta ekranu — kto rozstrzyga o widoczności, ten ją włącza.
    ['POST', '/admin/widocznosc/{id:\d+}/przestaw',      [VisibilityController::class, 'przestaw'],    'permission' => 'calendar.visibility_manage', 'zakres' => 'spolka'],
    ['GET',  '/admin/dziennik',                          [EventLogController::class, 'index'],         'permission' => 'admin.event_log', 'zakres' => 'tenant'],
    ['GET',  '/admin/konta',                             [AdminAccountController::class, 'index'],     'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/zapros',                      [AdminAccountController::class, 'inviteBatch'],   'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/osoba/{id:\d+}/adres',        [AdminAccountController::class, 'saveEmail'],     'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['GET',  '/admin/konta/administracyjne',             [AdminAccountController::class, 'adminAccountForm'], 'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/administracyjne',             [AdminAccountController::class, 'createAdminAccount'], 'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['GET',  '/admin/konta/osoba/{id:\d+}',              [AdminAccountController::class, 'personPanel'],   'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/osoba/{id:\d+}/zapisz',       [AdminAccountController::class, 'savePerson'],    'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['GET',  '/admin/konta/osoba/{id:\d+}/blokada',      [AdminAccountController::class, 'blockConfirm'],  'permission' => 'admin.users', 'zakres' => 'spolka'],
    // ⚠ Ponowienie zaproszenia: normalny bieg rzeczy (link wygasa po 7 dniach, poczta bywa
    // w spamie), a bez tej drogi literówka w adresie blokowała osobę na zawsze.
    ['POST', '/admin/konta/osoba/{id:\d+}/zapros-ponownie', [AdminAccountController::class, 'resendInvitation'], 'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/osoba/{id:\d+}/blokada',      [AdminAccountController::class, 'toggleBlock'],   'permission' => 'admin.users', 'zakres' => 'spolka'],
    // usunięte w Z61: dostęp do firm zapisuje się razem z danymi konta (`/zapisz`) 
    // ⚠ Nazwa OGÓLNA („uprawnienia"), choć dziś sekcja ma dokładnie jedną pozycję — poziom
    // widoczności kalendarza. Powód: w Etapie 3 tą samą drogą (para konto–spółka, panel osoby,
    // wpis w dzienniku) dojdą role przełożonego i kadrowej, więc druga pozycja ma się dołożyć
    // jako WIERSZ, a nie jako przepisanie ekranu (aneks 23.09 §1).
    // ⚠ To NIE jest mechanizm „nadaj dowolną rolę": endpoint nie przyjmuje nazwy roli w żadnej
    // postaci, a `tenant_admin` jest jawnie odrzucany i zapisywany w dzienniku (aneks §2).
        ['POST', '/admin/konta/{id:\d+}/2fa/reset',          [AdminAccountController::class, 'resetTwoFactor'], 'permission' => 'admin.users', 'zakres' => 'spolka'],
    ['POST', '/admin/konta/{id:\d+}/sesje/zakoncz',      [AdminAccountController::class, 'revokeSessions'], 'permission' => 'admin.users', 'zakres' => 'spolka'],
    // integracja z kadrami: integration.manage działa wyłącznie z przypisania na całego tenanta (permission.tenant_only)
    ['GET',  '/admin/podlaczenie',                       [AdminIntegrationController::class, 'connect'],      'permission' => 'integration.manage', 'zakres' => 'tenant'],
    // fragment odpytywany przez htmx co kilka sekund — pełnoprawny endpoint, to samo uprawnienie (§5)
    ['GET',  '/admin/podlaczenie/stan',                  [AdminIntegrationController::class, 'connectState'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['GET',  '/admin/integracja',                        [AdminIntegrationController::class, 'index'],       'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze',                 [AdminIntegrationController::class, 'issue'],       'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze/{id:\d+}/nowy',   [AdminIntegrationController::class, 'regenerate'],  'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze/{id:\d+}/firmy',  [AdminIntegrationController::class, 'setCompanies'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    // ⚠ Ta sama ścieżka pod GET i POST: GET POKAZUJE potwierdzenie i niczego nie zmienia, POST wykonuje
    // (docs/system-wizualny.md §5.6, CLAUDE.md §3). Operacji nieodwracalnej nie robimy jednym kliknięciem.
    ['GET',  '/admin/integracja/klucze/{id:\d+}/wymien', [AdminIntegrationController::class, 'confirmReplace'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze/{id:\d+}/wymien', [AdminIntegrationController::class, 'replace'],     'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['GET',  '/admin/integracja/klucze/{id:\d+}/uniewaznij', [AdminIntegrationController::class, 'confirmRevoke'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze/{id:\d+}/uniewaznij', [AdminIntegrationController::class, 'revoke'],  'permission' => 'integration.manage', 'zakres' => 'tenant'],
    // ⚠ Skasowanie UNIEWAŻNIONEGO klucza — ta sama ścieżka w dwóch metodach, jak przy
    // unieważnieniu i wymianie: `GET` pokazuje krok potwierdzenia i niczego nie zmienia,
    // `POST` kasuje (CLAUDE.md §3). Czynnego klucza tą drogą nie da się usunąć i pilnuje tego
    // warunek w zapytaniu, nie obecność przycisku.
    ['GET',  '/admin/integracja/klucze/{id:\d+}/usun',    [AdminIntegrationController::class, 'confirmDelete'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/klucze/{id:\d+}/usun',    [AdminIntegrationController::class, 'delete'],      'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/zgloszenia/{id:\d+}/zatwierdz', [AdminIntegrationController::class, 'approve'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['GET',  '/admin/integracja/zgloszenia/{id:\d+}/odrzuc', [AdminIntegrationController::class, 'confirmReject'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/zgloszenia/{id:\d+}/odrzuc', [AdminIntegrationController::class, 'reject'],  'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/zgloszenia/{id:\d+}/przywroc', [AdminIntegrationController::class, 'reopen'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/firmy/{id:\d+}/przeglad', [AdminIntegrationController::class, 'forceReview'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['GET',  '/admin/integracja/firmy/{id:\d+}/tozsamosc', [AdminIntegrationController::class, 'confirmIdentity'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
    ['POST', '/admin/integracja/firmy/{id:\d+}/tozsamosc', [AdminIntegrationController::class, 'acceptIdentity'], 'permission' => 'integration.manage', 'zakres' => 'tenant'],
];

if ($isDev) {
    $shared[] = ['GET', '/styleguide', [StyleguideController::class, 'index'], 'auth' => 'none'];
    // ⚠ Reset instalacji: trasa ISTNIEJE WYŁĄCZNIE przy APP_ENV=dev — u klienta nie ma jej w ogóle,
    // niezależnie od uprawnień. Kontroler sprawdza warunek drugi raz (CLAUDE.md §10: domyślnie odmawiaj).
    $webOnly[] = ['POST', '/diagnostyka/reset', [DiagnosticsController::class, 'reset'], 'permission' => 'admin.settings', 'zakres' => 'tenant'];
}

$mobile = [];
foreach ($shared as $route) {
    $route[1] = $route[1] === '/' ? '/m' : '/m' . $route[1];
    $mobile[] = $route;
}

// API dla connectora (docs/api-connector.md): klucz instalacji zamiast sesji, bez CSRF, uprawnienie api.sync.
$api = [
    // ⚠ Test połączenia: nie otwiera przebiegu i nie rusza kursorów (rozstrzygnięcie 20.09.2026).
    ['GET',  '/api/v1/sync/stan',             [SyncController::class, 'stan'],            'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/start',            [SyncController::class, 'start'],           'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/dane',             [SyncController::class, 'dane'],            'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/przeglad',         [SyncController::class, 'przeglad'],        'auth' => 'api', 'permission' => 'api.sync'],
    ['GET',  '/api/v1/sync/zadania',          [SyncController::class, 'zadania'],         'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/wyniki',           [SyncController::class, 'wyniki'],          'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/koniec',           [SyncController::class, 'koniec'],          'auth' => 'api', 'permission' => 'api.sync'],
    ['POST', '/api/v1/sync/zgloszenie-firmy', [SyncController::class, 'zgloszenieFirmy'], 'auth' => 'api', 'permission' => 'api.sync'],
];

return [...$shared, ...$mobile, ...$webOnly, ...$api];
};
