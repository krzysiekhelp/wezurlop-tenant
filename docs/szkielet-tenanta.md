# wezurlop — szkielet tenanta (Etap 2)

Dokument wykonawczy. Opisuje **trzon aplikacji tenanta**: bootstrap, routing, sesje, logowanie,
uprawnienia, warstwę widoku i instalator. **Nie zawiera logiki urlopowej** — wnioski, salda,
akceptacje i eskalacje to Etap 3.

Wiążące reguły pisania kodu: `CLAUDE.md` w korzeniu repozytorium. Kontekst produktowy:
`docs/specyfikacja.md`, `docs/architektura.md`, `docs/audyt.md`.
Ten dokument mówi **co zbudować i w jakiej kolejności**; `CLAUDE.md` mówi **jak**.

Stan na: 2026-09-16. Środowisko deweloperskie: AlmaLinux 10, PHP 8.3.33, ionCube Loader 15.5.0,
MySQL 8.4.11, Apache, trzy vhosty HTTPS.

---

## 0. Decyzje wiążące dla tego etapu

| Decyzja | Ustalenie | Konsekwencja |
|---|---|---|
| Język interfejsu | **tylko polski** | teksty wprost w szablonach, bez warstwy tłumaczeń |
| Widok mobilny | **osobny zestaw szablonów** | wspólne kontrolery i serwisy, własny layout i nawigacja |
| Role i sekcje | **tylko trzon** | mechanizm uprawnień działa, ale sekcje biznesowe nie są jeszcze definiowane |
| Nazewnictwo w kodzie i bazie | **angielskie** (`person`, `employment`, `account`) | polski tylko w tekstach interfejsu i w adresach URL |
| Adresy URL | **polskie** (`/logowanie`, `/ustawienia`) | produkt na rynek polski, adresy widzi użytkownik |

### Nazewnictwo — rozstrzygnięte

**Angielski w kodzie i w bazie, polski w interfejsie i w adresach URL.** Powody, w kolejności wagi:

1. **Nie da się utrzymać konsekwencji po polsku.** Połowa nazw i tak przychodzi z zewnątrz:
   `Controller`, `Repository`, `Request`, `Response`, `password_hash`, `created_at` w konwencjach
   Phinxa, nazwy metod Twiga i PDO. Polskie nazwy własne obok nich dają `$zatrudnienie->getCompanyId()`
   — najgorszy z możliwych wariantów, bo przy każdej nazwie trzeba zgadywać, w którym języku jest.
2. **Polskie znaki w identyfikatorach to źródło awarii**, których nikt nie szuka tam, gdzie są:
   kolacja bazy, kodowanie plików, eksporty CSV, nazwy kolumn w narzędziach. Pisanie po polsku
   bez ogonków (`zatrudnienie`, `wniosek_urlopowy`) działa, ale jest kompromisem, który po roku
   wygląda jak niechlujstwo.
3. **Kod czyta też następny programista i następny agent.** Angielskie nazwy encji
   (`person`, `employment`, `account`, `role_assignment`) niosą znaczenie bez kontekstu domenowego.

Polski zostaje tam, gdzie widzi go użytkownik: **teksty interfejsu** i **adresy** (`/logowanie`,
`/wnioski`, `/ustawienia/2fa`). Adres jest częścią produktu — `/leave-requests` w polskiej
aplikacji kadrowej wygląda jak tłumaczone narzędzie, a nie jak nasz system.

Reguła praktyczna: **jeśli tekst zobaczy użytkownik — polski; jeśli tylko programista — angielski.**
Bez wyjątków w jedną i w drugą stronę.

### Czego w tym etapie świadomie NIE ma

Wnioski urlopowe, salda, limity, typy nieobecności, akceptacje, eskalacje, kalendarz zespołu
z danymi, raporty, connector R2, import ZUS, SMS, push. Ekrany z projektów graficznych,
które te rzeczy pokazują, budujemy **jako puste ramy z danymi przykładowymi w kontrolerze**,
żeby nawigacja i layout były gotowe — ale bez repozytoriów, bez tabel i bez logiki.

---

## 1. Repozytorium i struktura

Repozytorium: `wezurlop-tenant`. Katalog na maszynie deweloperskiej: **`/home/dev/tenant`**
(obok `panel` i `auth`), vhost `tenant.wezurlop.pl` wskazuje na `public/`, `AllowOverride All`.

```
/public
  index.php                  <- jedyny wykonywalny PHP
  .htaccess                  <- rewrite + Authorization
  /assets
    /css   tokens.css base.css layout-web.css layout-mobile.css components.css
    /js    app-web.js app-mobile.js /vendor/htmx.min.js /modules/*.js
    /img   logo.svg mascot.png favicon
/src
  /Http          Request Response Router Middleware/*.php
  /Controller    /Auth /App /Install      <- JEDEN zestaw dla web i mobile
  /Repository
  /Service
  /Entity        (DTO: CurrentUser, EmploymentContext, LicenseState...)
  /Security      Csrf Password RateLimiter TotpService BackupCodes TrustedProxy
  /Support       TwigFactory Str Clock
  /Diagnostics
  /License       <<< STREFA KODOWANA ionCube
  /Core          <<< STREFA KODOWANA ionCube (pusta w tym etapie)
/templates
  /layout        web.twig mobile.twig install.twig error.twig
  /partials      csrf.twig nav-web.twig nav-mobile.twig flash.twig
  /fragments     (odpowiedzi htmx, bez layoutu)
  /page          /auth /web /mobile /install
/config          config.php routes.php permissions.php
/db/migrations
/storage
  /logs  /uploads
/var
  /cache/twig  /sessions  /install.lock (po instalacji)
/bin             check-rules.php  diagnose.php
/docs
.env             <- poza repozytorium
```

Reguły z `CLAUDE.md` §2 obowiązują bez zmian: w `/public` nie ma żadnego `.php` poza `index.php`,
nic poza `/public` nie jest dostępne przez HTTP, `/var` i `/storage` nigdy w repozytorium.

### `composer.json`

```json
{
  "require": {
    "php": "^8.2",
    "ext-pdo_mysql": "*", "ext-mbstring": "*", "ext-curl": "*",
    "ext-fileinfo": "*", "ext-openssl": "*",
    "ext-iconv": "*", "ext-xmlwriter": "*",
    "twig/twig": "^3.8",
    "nikic/fast-route": "^1.3",
    "vlucas/phpdotenv": "^5.6",
    "robthree/twofactorauth": "^3.0",
    "bacon/bacon-qr-code": "^2.0",
    "paragonie/sodium_compat": "^1.21",
    "robmorgan/phinx": "^0.16",
    "phpmailer/phpmailer": "^6.9"
  },
  "autoload": { "psr-4": { "Wezurlop\\": "src/" } }
}
```

⚠ **Trzy pozycje do uzgodnienia, zanim to wejdzie do repozytorium** (§17 — nie łamiemy reguł po cichu):

- `phpmailer/phpmailer` nie figuruje w tabeli dozwolonych zależności w `CLAUDE.md` §0, choć §0
  jednocześnie wymaga wysyłki wyłącznie przez SMTP i wskazuje właśnie PHPMailera. Do dopisania do tabeli.
- `ext-iconv` i `ext-xmlwriter` to **twarde** wymagania `bacon/bacon-qr-code` 2.x (renderer SVG
  używa `XMLWriter`). W §0 ich nie ma. Oba są powszechnie dostępne, ale lista wymagań ma być
  prawdziwa, a nie krótka — inaczej u klienta objawi się to niedziałającym parowaniem 2FA
  zamiast komunikatem z diagnostyki.

`firebase/php-jwt` i `ezyang/htmlpurifier` dochodzą dopiero z logowaniem zewnętrznym
i z polami HTML — nie instaluj ich „na zapas". `php` ustawione na `^8.2`, mimo że dev ma 8.3:
docelowy hosting klienta może mieć 8.2, a Encoder też wspiera nowe wersje z opóźnieniem (§15).

### Strażnik reguł — `bin/check-rules.php`

Krótki skrypt CLI szukający w repozytorium wzorców zakazanych przez `CLAUDE.md`, uruchamiany
przed każdym commitem i w każdej sesji przed oddaniem zadania:

| Wzorzec | Gdzie szuka | Wyjątek |
|---|---|---|
| `|raw` | `templates/**.twig` | linia poprzedzona komentarzem `{# raw-ok: ... #}` |
| `on\w+=` , `style="` , `<style` , `<script>` z treścią | `templates/**.twig` | brak |
| `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval(`, `new Function` | `public/assets/js/**` | `vendor/` |
| `global `, `$GLOBALS` | `src/**` | brak |
| konkatenacja w SQL (`"SELECT` … `. $`) | `src/**` | brak |
| `.php` inny niż `index.php` | `public/` | brak |

Skrypt kończy się kodem 1 przy dowolnym trafieniu i wypisuje plik + linię. To nie jest
zabezpieczenie, tylko siatka na ludzką pamięć — ale wyłapuje 90% odstępstw, zanim trafią do repo.

---

## 2. Bootstrap — kolejność jest istotna

`public/index.php` robi dokładnie jedno: ładuje autoloader i oddaje sterowanie do
`Wezurlop\Http\Kernel`. Cała reszta w `src/`.

Kolejność w `Kernel::boot()`:

1. **Handlery błędów** (`set_exception_handler`, `set_error_handler`, `register_shutdown_function`)
   — jako pierwsze, żeby błąd w kroku 2 też trafił do logu, a nie na ekran.
2. **`.env`** przez `phpdotenv`. Ścieżka do `.env` z `WEZURLOP_ENV_PATH` albo domyślna
   `dirname(__DIR__).'/.env'` — **konfigurowalna**, bo w wariancie wdrożenia „aplikacja powyżej
   katalogu domeny" `.env` leży gdzie indziej (§0 reguł).
3. **Sprawdzenie instalacji**: jeśli brak `.env` albo brak znacznika instalacji → przekierowanie
   do instalatora (rozdział 12). Jeśli znacznik jest, a instalator wciąż odpowiada → **błąd
   blokujący** na stronie diagnostycznej.
   ⚠ Instalator powstaje dopiero w M8, więc do tego czasu na maszynie deweloperskiej tworzymy
   `var/install.lock` ręcznie (`touch`). Bez tego każde żądanie w M2 przekierowywałoby do czegoś,
   czego jeszcze nie ma. Przekierowanie do nieistniejącego instalatora musi dawać czytelny
   komunikat, a nie pętlę — to jest też test sam w sobie.
4. **Konfiguracja** (`config/config.php` czyta wyłącznie z `.env`, nie zawiera sekretów).
5. **Nagłówki bezpieczeństwa** (§13 reguł) — wysyłane zanim cokolwiek może wyprodukować treść.
   Dwie zasady: **wysyła je dokładnie jedna warstwa** (PHP, nie serwer WWW) i **wartości HSTS
   pochodzą z konfiguracji**.

   ⚠ Nagłówki muszą iść z PHP, bo u klienta nie kontrolujemy konfiguracji Apache'a — a skoro idą
   z PHP, to serwer nie może ich dokładać drugi raz. Na maszynie deweloperskiej oznacza to
   wyłączenie bloku `Header always set` dla vhostów tenanta; podwójny `Strict-Transport-Security`
   w odpowiedzi to nie kosmetyka, tylko realna niejednoznaczność dla przeglądarki.

   ⚠ HSTS: `HSTS_MAX_AGE` i `HSTS_INCLUDE_SUBDOMAINS` w konfiguracji, domyślnie wartości z §13
   (rok, z subdomenami). **Na maszynie deweloperskiej `HSTS_MAX_AGE=300`, bez `includeSubDomains`
   i nigdy `preload`** — dev pracuje na prawdziwych nazwach domen, więc roczny HSTS zapisany
   w przeglądarce zablokowałby te nazwy także poza tą maszyną, a wpis siedzi w osobnym magazynie
   przeglądarki, nie w ciasteczkach. Nagłówek wysyłamy wyłącznie po HTTPS; `HSTS_MAX_AGE=0`
   oznacza „nie wysyłaj wcale".
6. **Sesja** — konfiguracja ciasteczka i `save_path`, potem `session_start()`.
7. **Router** → dopasowanie trasy → łańcuch middleware → kontroler → `Response::send()`.

### Sesja — `save_path` to nie detal

```php
ini_set('session.save_path', $config->varPath . '/sessions');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.gc_maxlifetime', '3600');
```

⚠ Na hostingu współdzielonym domyślny `session.save_path` bywa wspólnym katalogiem dla wielu
kont. Pliki sesji są wtedy czytelne dla sąsiada na tym samym serwerze, a **plik sesji zawiera
`user_id` i stan uwierzytelnienia** — to gotowe przejęcie konta. Własny `save_path` w `var/sessions`
(poza webrootem, prawa 700) jest obowiązkowy i musi być sprawdzany przez diagnostykę: katalog
istnieje, jest zapisywalny i **nie jest dostępny przez HTTP**.

Katalog `var/sessions` czyszczony własnym zadaniem cron — na wielu hostingach garbage collector
PHP jest wyłączony (`session.gc_probability=0`), więc pliki zostają tam na zawsze.

### Wykrywanie HTTPS i adresu klienta

Za Cloudflare `$_SERVER['HTTPS']` bywa puste, a `REMOTE_ADDR` to adres proxy (§9 reguł).
Jedna klasa `Security/TrustedProxy` rozstrzyga oba pytania i **tylko ona** czyta nagłówki
`X-Forwarded-*`:

```php
// zwraca adres klienta; nagłówki proxy honorowane WYŁĄCZNIE gdy REMOTE_ADDR
// należy do listy z konfiguracji (domyślnie pustej)
public function clientIp(array $server): string
public function isHttps(array $server): bool
```

Reszta aplikacji nie dotyka `$_SERVER['HTTP_X_FORWARDED_FOR']` nigdzie indziej. Lista zaufanych
proxy w `.env` (`TRUSTED_PROXIES=`), domyślnie pusta, ustawiana przy wdrożeniu.

---

## 3. Routing

`nikic/fast-route` z cache tras w `var/cache/routes.php` (regenerowany, gdy `routes.php`
jest nowszy). Trasy **wyłącznie** w `config/routes.php`, jawnie, z metodą, uprawnieniem
i wymaganym poziomem uwierzytelnienia.

```php
// config/routes.php
return [
    // publiczne
    ['GET',  '/logowanie',            [Auth\LoginController::class, 'form'],    'auth' => 'none'],
    ['POST', '/logowanie',            [Auth\LoginController::class, 'submit'],  'auth' => 'none'],
    ['GET',  '/haslo/reset',          [Auth\ResetController::class, 'request'], 'auth' => 'none'],

    // po haśle, przed 2FA
    ['GET',  '/logowanie/kod',        [Auth\TwoFactorController::class, 'form'],   'auth' => 'partial'],
    ['POST', '/logowanie/kod',        [Auth\TwoFactorController::class, 'verify'], 'auth' => 'partial'],

    // zalogowani
    ['GET',  '/',                     [App\DashboardController::class, 'index'], 'permission' => 'app.use'],
    ['POST', '/kontekst/{id:\d+}',    [App\ContextController::class, 'switch'],  'permission' => 'app.use'],
    ['GET',  '/ustawienia/2fa',       [App\SecurityController::class, 'index'],  'permission' => 'account.manage_own'],
];
```

**Domyślnie odmawiaj.** Przy budowie tablicy tras `Router` sprawdza, że każda trasa ma albo
`'permission' => '...'`, albo jawne `'auth' => 'none'`. Trasa bez jednego i drugiego to
**wyjątek przy starcie** w trybie deweloperskim i 403 w produkcji — nigdy cicho przepuszczona.

Poziomy `auth`: `none` (anonim), `partial` (**co najmniej** po haśle), `full`.
Domyślnie `full`. Sesja częściowo uwierzytelniona nie przechodzi przez żadną trasę
poza ekranem 2FA i wylogowaniem (§16 pkt 6).

Dodatkowy klucz `'exempt' => ['2fa', 'context']` wyłącza trasę spod konkretnych ogniw
łańcucha. Potrzebny dokładnie w dwóch miejscach i nigdzie więcej: ekran parowania 2FA
nie może być blokowany przez `Require2fa` (bo wtedy konto z wymuszonym 2FA nie ma jak go
włączyć), a ekran wyboru firmy nie może być blokowany przez `EmploymentContext` (bo kontekst
dopiero tam powstaje). ⚠ To jest **jedyne** dopuszczalne użycie `exempt` — każde inne jest
obchodzeniem łańcucha i wymaga decyzji, nie commita.

### Wyprowadzanie ścieżki — dwie drogi

Router **nie** ufa, że przepisywanie adresów działa:

```php
$uri = $server['REQUEST_URI'] ?? '';

if ($uri !== '') {
    // źródło podstawowe: REQUEST_URI bez query stringa i bez prefiksu z konfiguracji
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $path = $this->stripBasePath($path);        // APP_BASE_PATH, nie zgadywany

    // brak przepisywania adresów: /index.php/wnioski/12  ->  /wnioski/12
    if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
        $path = substr($path, 10);              // 10 === strlen('/index.php')
    }
} else {
    // serwery, które nie ustawiają REQUEST_URI (rzadkie, ale istnieją)
    $path = $server['PATH_INFO'] ?? $server['ORIG_PATH_INFO'] ?? '/';
}

if ($path === '') { $path = '/'; }
```

⚠ `PATH_INFO` **nie może mieć pierwszeństwa**. Pod FastCGI/CGI — a taki jest IdeaWebServer/UltraCGI
na home.pl — bywa ustawiane także wtedy, gdy przepisywanie adresów działa poprawnie, i router
wziąłby wtedy ścieżkę ze złego źródła na poprawnie skonfigurowanym serwerze. Dlatego ścieżkę
wyprowadzamy z `REQUEST_URI`, a `/index.php` odcinamy **jawnie** — a nie sprawdzamy, czy adres
się na nim kończy. Adres `/public/index.php/logowanie` (wariant „gorszego hostingu") kończy się
na `/logowanie`, więc warunek „kończy się na `/index.php`" nigdy by się nie spełnił i trasa
dawałaby 404 zamiast ekranu logowania.

⚠ **`APP_BASE_PATH` nie zawsze może stać w `.env`.** Na maszynie deweloperskiej oba vhosty
(`tenant` i `tenant-cgi`) korzystają z tego samego repozytorium i tego samego `.env`, a potrzebują
różnych prefiksów. Dlatego konfiguracja czyta go w kolejności: `$_SERVER['APP_BASE_PATH']`
(ustawiany w vhoście przez `SetEnv`), potem `.env`, na końcu pusty. U klienta jest jedna instalacja
i jeden adres, więc tam wystarczy `.env` — wpisywany przez instalator.

### Dwa prefiksy, nie jeden

⚠ Jeden prefiks nie wystarczy — to wyszło przy pierwszym teście bez `mod_rewrite`. Adres zasobu
i adres trasy to **dwie różne rzeczy**: bez przepisywania `/public/assets/app.css` działa,
ale `/public/logowanie` daje 404, bo do routera trzeba wejść przez `/public/index.php/logowanie`.
Jeden prefiks dla obu oznacza formularze i przekierowania prowadzące donikąd.

| Klucz | Znaczenie | Normalny hosting | Katalog domeny = korzeń, bez `mod_rewrite` |
|---|---|---|---|
| `APP_BASE_PATH` | gdzie aplikacja jest zamontowana (zasoby) | `` (pusty) | `/public` |
| `APP_FRONT_CONTROLLER` | co trzeba dołożyć, żeby trafić do routera | `` (pusty) | `/index.php` |

```php
url('/logowanie')   => APP_BASE_PATH . APP_FRONT_CONTROLLER . '/logowanie'
asset('css/app.css') => APP_BASE_PATH . '/assets/css/app.css'
```

Przy działającym przepisywaniu oba są puste — także w wariancie „katalog domeny = korzeń
aplikacji", bo `.htaccess` przepisuje wszystko do `public/` i adresy pozostają czyste.
**Obie wartości ustala instalator** przez test (rozdział 12), a nie zgadywanie; na maszynie
deweloperskiej vhost `tenant-cgi` dostaje je przez `SetEnv`.

Prefiks bazowy z konfiguracji (`APP_BASE_PATH`, domyślnie pusty), nie wyliczany ze `SCRIPT_NAME` —
zgadywanie tu kończy się aplikacją, która działa na jednym hostingu i nie działa na drugim.
Generowanie adresów w szablonach **wyłącznie** przez funkcję `url()`, która ten sam prefiks
dokleja z powrotem. Twardych `href="/wnioski"` w szablonach nie ma.

Czwarty vhost (rozdział 14) istnieje po to, żeby ta ścieżka była wykonywana codziennie,
a nie odkryta u pierwszego klienta.

---

## 4. Łańcuch middleware

Kolejność jest częścią zabezpieczenia — każdy element zakłada, że poprzednie już się wykonały.

| # | Middleware | Zadanie | Efekt odmowy |
|---|---|---|---|
| 1 | `SessionLifetime` | timeout bezczynności (30 min) i absolutny (12 h) | wyloguj → `/logowanie?powod=wygasla` |
| 2 | `Csrf` | wszystkie metody poza `GET`/`HEAD`/`OPTIONS`; `$_POST['_csrf']` **lub** `X-CSRF-Token` | 419 + strona „formularz wygasł" |
| 3 | `Authenticate` | ustala `CurrentUser` z `$_SESSION['account_id']`, **uprawnienia czyta z bazy przy każdym żądaniu** | zależnie od `auth` trasy |
| 4 | `Require2fa` | konto z rolą wymagającą 2FA, które go nie ma → tylko ekran parowania | przekierowanie na `/ustawienia/2fa` |
| 5 | `EmploymentContext` | ustala aktywne zatrudnienie/firmę | brak kontekstu → ekran wyboru |
| 6 | `LicenseGuard` | pyta `LicenseGate` o stan; tryb ograniczony | strona „tryb ograniczony" |
| 7 | `Authorize` | uprawnienie wymagane przez trasę | 403 (a dla zasobów: 404) |

Nagłówków bezpieczeństwa i startu sesji **nie ma w łańcuchu** — robi je bootstrap (kroki 5–6).
Drugie `session_start()` w tym samym żądaniu to `E_WARNING` przy każdym żądaniu, a przy globalnym
handlerze błędów z §14 — wpis w logu przy każdym żądaniu.

Uwagi wiążące:

- **Uprawnień nie trzymamy w sesji** (§8 reguł). Odebranie uprawnienia musi działać natychmiast,
  a nie po ponownym zalogowaniu. Jedno zapytanie na żądanie, wynik w `CurrentUser` jako DTO.
- `Csrf` jest **przed** `Authenticate`: sprawdzenie tokenu nie wymaga wiedzy, kim jest użytkownik,
  a odwrotna kolejność otwiera drogę do wymuszania akcji na sesji anonimowej (np. logowania CSRF).
- 419 ma własną stronę z przyciskiem „wróć i spróbuj ponownie", a dla żądań htmx — nagłówek
  `HX-Redirect` na tę stronę. Bez tego wygaśnięcie tokenu objawia się kliknięciem, które nic nie robi.
- `LicenseGuard` **po** uwierzytelnieniu: tryb ograniczony ma pokazywać czytelny komunikat
  zalogowanemu administratorowi, a nie blokować ekran logowania.
---

## 5. Baza danych — migracje szkieletu

Wszystko przez phinx (§4 reguł). Kolacja `utf8mb4_unicode_ci`, silnik InnoDB, brak funkcji
okienkowych i składni specyficznej dla MySQL 8.

Dwie zasady, które łatwo przeoczyć, a potem drogo poprawiać:

- **Czas w bazie zawsze w UTC.** Strefa użytkownika to sprawa warstwy widoku. Instalacja
  u klienta za granicą, zmiana czasu i eksport do R2 przestają być wtedy problemem.
  `account.password_changed_at` z dokładnością do mikrosekund — porównujemy je ze znacznikiem
  z sesji, a sekunda to za mało, gdy zmiana hasła i żądanie trafiają w tę samą sekundę.
- **Phinx 0.16 domyślnie tworzy kolumny jako `NULL`.** Każda kolumna ma mieć jawne
  `['null' => false]` albo `true` — domyślność, która mówi „nie wiem", jest gorsza niż błąd.

### Model tożsamości — osoba ≠ zatrudnienie

To jest fundament i nie da się go dołożyć później. Tenant może być biurem rachunkowym
obsługującym kilka firm; **ta sama osoba może mieć etat w dwóch firmach jednego tenanta**
(np. ½ + ½). Z tego wynika:

- **konto logowania należy do osoby**, nie do zatrudnienia — człowiek loguje się raz,
- **uprawnienia należą do pary (konto, firma)** — ktoś bywa przełożonym w firmie A
  i zwykłym pracownikiem w firmie B,
- każdy ekran z danymi pracowniczymi działa **w kontekście jednego zatrudnienia**,
- identyfikator z programu kadrowego (R2) siedzi na **zatrudnieniu**, nie na osobie —
  w każdej firmie jest inny.

```
company        id, name, tax_id, external_id, is_active, created_at
person         id, first_name, last_name, email_private, phone, created_at
employment     id, person_id, company_id, external_id, employee_number,
               hired_at, terminated_at, fte_numerator, fte_denominator,
               is_active, created_at
               UNIQUE (company_id, external_id)
account        id, person_id, email, password_hash, status,
               totp_secret_enc, totp_enabled_at, totp_last_window,
               password_changed_at, last_login_at, created_at
               UNIQUE (email)
account_identity  id, account_id, provider ('google'|'apple'), subject, linked_at
               UNIQUE (provider, subject)
```

`account.status`: `invited`, `active`, `blocked`. Konto `invited` nie ma hasła i nie loguje się.

### Uprawnienia

```
role             id, code, name, is_system, requires_2fa
permission       id, code, name                      -- słownik, wypełniany migracją
role_permission  role_id, permission_id
role_assignment  id, account_id, role_id, company_id NOT NULL DEFAULT 0, granted_at, granted_by
                 UNIQUE (account_id, role_id, company_id)
```

`company_id = 0` oznacza uprawnienie na całego tenanta (administrator).

⚠ Celowo **nie** `NULL`. MySQL i MariaDB nie wymuszają unikalności na wierszach z `NULL`
w kolumnie klucza — przy `company_id NULL` dałoby się wstawić dowolną liczbę identycznych
przypisań `(konto, rola, cały tenant)`, a indeks by tego nie zatrzymał. Zero w roli wartości
specjalnej jest brzydkie, ale działa; alternatywa to kolumna generowana.

Role w szkielecie:
`tenant_admin` i `employee` — tylko tyle, ile potrzeba, żeby mechanizm działał i dał się przetestować.
Sekcje biznesowe i role `supervisor`/`hr` dochodzą w Etapie 3; mechanizm jest już wtedy gotowy.

Uprawnienia szkieletu: `app.use`, `account.manage_own`, `admin.users`, `admin.settings`,
`admin.event_log`, `admin.license`.

### Bezpieczeństwo kont

```
backup_code           id, account_id, code_hash, used_at
password_reset_token  id, account_id, token_hash, expires_at, used_at, requested_ip_hash
invitation            id, account_id, token_hash, expires_at, used_at, created_by
login_attempt         id, account_id NULL, ip_hash, kind, succeeded, created_at
                      INDEX (account_id, created_at), INDEX (ip_hash, created_at)
event_log             id, occurred_at, account_id NULL, company_id NULL, actor_label,
                      event_code, subject_type, subject_id, ip_hash, detail JSON
remembered_device     id, account_id, selector, validator_hash, expires_at, label, created_at
session               id CHAR(64) (hash identyfikatora), account_id NULL, employment_id NULL,
                      client ENUM('web','app') NOT NULL DEFAULT 'web', app_version NULL,
                      payload, ip_hash, user_agent, created_at, last_seen_at, expires_at,
                      revoked_at NULL
                      INDEX (account_id, last_seen_at), INDEX (expires_at)
```

Zasady wiążące:

- **Tokeny trzymamy wyłącznie jako hash** (`hash('sha256', $token)`), nigdy jawnie.
  Wyciek kopii bazy nie może dać nikomu możliwości resetu cudzego hasła.
- `ip_hash` zamiast adresu IP — adres to dana osobowa, a do limitowania prób wystarczy
  `hash_hmac('sha256', $ip, $appKey)`. Do logu zdarzeń to samo.
- `login_attempt.kind`: `password`, `totp`, `backup_code`, `reset` — limity liczone osobno
  dla każdego rodzaju.
- `event_log.detail` jako JSON, **nigdy** z hasłem, tokenem, sekretem TOTP ani kodem zapasowym (§9).
- `remembered_device` jest w migracjach, ale funkcja „zapamiętaj to urządzenie" **nie jest
  implementowana w tym etapie** — tabela powstaje teraz, żeby nie robić migracji na produkcji
  dla jednej opcji.

### Sesje w bazie

⚠ To jest zmiana mechanizmu, nie dodatek. **Sesji plikowych nie da się wyliczyć dla konta** —
plik wie, kim jest użytkownik, ale użytkownik nie wie, jakie ma pliki. Bez rejestru w bazie
nie istnieje lista „Twoje urządzenia", nie da się wylogować zgubionego telefonu i nie da się
natychmiast odciąć zwolnionego pracownika. Przy aplikacji mobilnej z biometrią to nie jest
funkcja kosmetyczna.

- Handler na `SessionHandlerInterface` **plus** `SessionUpdateTimestampHandlerInterface`
  i `session.lazy_write=1` — zapis do bazy tylko wtedy, gdy dane się zmieniły.
  `last_seen_at` odświeżamy najwyżej raz na minutę, żeby nie robić zapisu na każde żądanie.
- **Identyfikator sesji w bazie jako hash** (`hash('sha256', $id)`), nigdy jawnie. Odczyt tabeli
  nie może dawać gotowego ciasteczka do podszycia się — to ten sam powód co przy tokenach resetu.
- `account_id` wypełniany dopiero przy pełnym uwierzytelnieniu; sesja anonimowa i częściowo
  uwierzytelniona też ma wiersz, ale bez powiązania z kontem.
- `revoked_at` sprawdzane w `Authenticate` przy **każdym** żądaniu — unieważnienie ma działać
  natychmiast, nie po wygaśnięciu ciasteczka.
- Sprzątanie wygasłych wierszy zadaniem cron (`job`), nie garbage collectorem PHP —
  na wielu hostingach jest wyłączony.
- Sesja plikowa zostaje wyłącznie w instalatorze, gdzie baza jeszcze nie działa (rozdział 12).

To zamyka też problem współdzielonego `session.save_path` na hostingu — plików sesji
po prostu nie ma.

### Ustawienia tenanta i kolejka zadań

```
tenant_setting  key, value, updated_at, updated_by        -- klucz główny: key
job             id, type, payload JSON, status, attempts, run_after, locked_until,
                last_error, created_at
                INDEX (status, run_after)
```

`tenant_setting` powstaje teraz, bo specyfikacja w kilku miejscach mówi „konfigurowalne per tenant"
(wymóg 2FA dla ról, lista zaufanych proxy, skrzynka na alerty, adres serwera REST), a ekran
`/ustawienia` i uprawnienie `admin.settings` już w szkielecie są. Ten sam argument co przy
`remembered_device`: migracja na produkcji dla jednego pola to kiepski pomysł.

`job` to konsekwencja §0 reguł: **brak procesów w tle i `max_execution_time` rzędu 30 s**.
Już w tym etapie wysyłamy pocztę (reset hasła, zaproszenia), a wysyłka SMTP w trakcie żądania
użytkownika oznacza, że zamulony serwer poczty zawiesza formularz na kilkadziesiąt sekund
i potrafi go wywrócić na limicie czasu. Dlatego wysyłka trafia do kolejki, a `bin/run-jobs.php`
(cron, porcjami, z zapamiętaniem miejsca przerwania) ją opróżnia. W Etapie 3 tą samą kolejką
pójdą eskalacje, alerty i push — i wtedy nie trzeba już nic przebudowywać.

### Sekret TOTP

Szyfrowany kluczem z `.env` (`APP_KEY`, 32 bajty, base64) przez `sodium_crypto_secretbox`
z `paragonie/sodium_compat`. Nonce zapisywany razem z szyfrogramem w tym samym polu.
Osobna klasa `Security/SecretBox` z dwiema metodami (`seal`, `open`) — nikt poza nią nie dotyka
kryptografii. **`APP_KEY` generuje instalator** i zapisuje do `.env`; jego utrata oznacza,
że wszyscy użytkownicy muszą sparować 2FA od nowa (i tak ma być opisane w dokumentacji wdrożenia).

### Konta bazodanowe

Zgodnie z §4: konto aplikacji ma `SELECT, INSERT, UPDATE, DELETE` na jednej bazie.
Migracje puszcza **drugie** konto, z prawami DDL, używane wyłącznie przez phinx —
w `.env` jako osobna para poświadczeń. Na środowisku deweloperskim oba konta już istnieją.

---

## 6. Logowanie i konta

### Stany sesji

```
ANONYMOUS  --hasło ok-->  PARTIAL  --kod 2FA ok-->  FULL  --wybór firmy-->  FULL + kontekst
```

- `PARTIAL` ma własny limit czasu **5 minut** i przechowuje wyłącznie `account_id`
  oraz znacznik czasu. Nie daje dostępu do niczego poza `/logowanie/kod` i `/wyloguj`.
- `session_regenerate_id(true)` **dwa razy**: po poprawnym haśle i po poprawnym kodzie 2FA (§9).
- Konto bez włączonego 2FA przechodzi z `ANONYMOUS` prosto do `FULL` — ale i wtedy
  regeneracja identyfikatora sesji jest obowiązkowa.

### Formularz logowania

- Komunikat błędu zawsze jeden i ten sam: „Nieprawidłowy login lub hasło" — niezależnie
  od tego, czy konto istnieje, jest zablokowane, czy hasło jest złe.
- Czas odpowiedzi nie może zdradzać istnienia konta: przy nieznanym e-mailu i tak wykonaj
  `password_verify` na stałym, sztucznym hashu.
- `password_needs_rehash()` → przehashuj po udanym logowaniu.
- Limitowanie: licznik w `login_attempt` **per konto i per `ip_hash`**, rosnące opóźnienie,
  blokada po kilku próbach. Adres klienta **wyłącznie** z `TrustedProxy` (rozdział 2) —
  bez tego pierwsza literówka blokuje całą firmę siedzącą za jednym proxy.
- **Progi muszą być różne**: konto 5 prób / 15 min, adres 30 prób / 15 min. Przy wspólnym progu
  zablokowanie jednego konta wyczerpuje jednocześnie licznik adresu i blokuje kolegę przy
  sąsiednim biurku — a to jest dokładnie ten scenariusz, który ma nie wystąpić (test 2).
- **Opóźnienie rośnie, konto się nie zamyka.** Po przekroczeniu progu kolejne próby są opóźniane
  wykładniczo (np. 1, 2, 4, 8… sekund, z górnym ograniczeniem), a nie odrzucane na sztywny czas.
  ⚠ Twarda blokada jest narzędziem ataku: każdy, kto zna czyjś adres e-mail, potrafi zamknąć
  to konto, a wybierając administratora tenanta — unieruchomić firmę. Reset hasła na maila
  **zawsze** pozostaje drogą powrotu i sam nie jest blokowany przez ten licznik.
- **Trzeci licznik — cała instalacja.** Limity per konto i per adres są ślepe na *password
  spraying*: jedno popularne hasło, sto kont, sto adresów nie przekracza żadnego progu, bo to
  jedna próba na konto i jedna na adres. Dlatego liczymy też nieudane logowania w całym tenancie
  (np. 50 / 15 min przy instalacji do 100 kont — próg konfigurowalny w `tenant_setting`).
  Przekroczenie **nikogo nie blokuje** — blokada na tym poziomie wyłączyłaby logowanie całej
  firmie, czyli dałaby atakującemu dokładnie to, o co mu chodzi. Zamiast tego: wpis do
  `event_log` i alert na skrzynkę alertową tenanta, przez kolejkę `job`. Alert wysyłany
  najwyżej raz na godzinę, żeby atak nie zamienił się w zalew poczty.
- Po udanym logowaniu: `event_log` (`auth.login_success`), po nieudanym (`auth.login_failed`).

### Wybór kontekstu (firmy)

Po pełnym uwierzytelnieniu `EmploymentContext` liczy aktywne zatrudnienia konta:

- **zero** → strona „Twoje konto nie ma aktywnego zatrudnienia", z kontaktem do administratora.
  Administrator tenanta bez zatrudnienia przechodzi dalej — jego uprawnienia mają `company_id = 0`.
- **jedno** → kontekst ustawiany automatycznie, użytkownik nic nie widzi.
- **więcej niż jedno** → ekran wyboru firmy; wybór w sesji jako `employment_id`,
  ale **przy każdym żądaniu weryfikowany zapytaniem** (`WHERE id = :id AND account_id = :acc
  AND is_active = 1`). Identyfikator w sesji to wskazówka, nie uprawnienie.
- Przełącznik firmy widoczny w nagłówku tylko wtedy, gdy zatrudnień jest więcej niż jedno.
  Przełączenie to `POST` (§3 reguł), nie link.

### 2FA (TOTP)

Zgodnie z §9 reguł. W szkielecie implementujemy w całości, bo bez tego nie da się wpuścić
administratora tenanta:

1. **Parowanie**: sekret generowany serwerowo, QR jako **SVG z `bacon/bacon-qr-code`, lokalnie** —
   nigdy przez zewnętrzny generator obrazków. Sekret pokazany także tekstem (do ręcznego wpisania).
   Kod QR trafia na stronę **osobnym endpointem** `GET /ustawienia/2fa/kod.svg`
   (`Content-Type: image/svg+xml`, `Cache-Control: no-store`, to samo uprawnienie co ekran,
   ważny tylko w trwającej sesji parowania), a w szablonie jest zwykły `<img src="…">` —
   tak jak w projekcie graficznym. ⚠ Wklejanie SVG wprost w szablon wymagałoby `{{ qr|raw }}`,
   czyli złamania §5 i zapalenia `bin/check-rules.php`. Decyzja zapada tutaj, żeby nikt jej
   nie podejmował w pośpiechu przy kodowaniu.
2. Włączenie **wymaga potwierdzenia kodem** — inaczej użytkownik zablokuje sobie konto źle
   sparowaną aplikacją.
3. Po włączeniu: 8 kodów zapasowych, pokazane **raz**, hashowane `password_hash`,
   z przyciskiem „pobierz jako plik tekstowy" i wymuszonym potwierdzeniem „zapisałem je".
4. Weryfikacja: tolerancja **±1 okno**, zapis numeru użytego okna w `totp_last_window`,
   odrzucenie powtórzenia. Limitowanie prób obowiązkowe (`login_attempt.kind = 'totp'`).
5. Wyłączenie: **hasło + aktualny kod**.
6. Reset przez administratora tenanta: dozwolony, ale zapisany w `event_log` z informacją kto
   i komu zresetował. To jawne obejście zabezpieczenia i ma zostawiać ślad.
7. Obowiązkowo dla roli `tenant_admin`: konto z tą rolą bez włączonego 2FA po zalogowaniu
   trafia na ekran parowania i nie idzie dalej.

### Reset hasła

- Token `random_bytes(32)`, w bazie jako hash, ważny **30 minut**, jednorazowy.
- Formularz „zapomniałem hasła" odpowiada **zawsze tak samo**, niezależnie od tego,
  czy e-mail istnieje: „Jeśli konto istnieje, wysłaliśmy wiadomość".
- Link wyłącznie e-mailem przez SMTP (PHPMailer; `mail()` zakazane, §0), wysyłany **przez kolejkę
  `job`**, nie w trakcie żądania.
- Adres w linku budowany **wyłącznie** z `APP_URL` z konfiguracji. ⚠ Nigdy z nagłówka `Host` —
  to dane od klienta (§11), a link resetu zbudowany z podstawionego `Host` to gotowe przejęcie konta.
- Na środowisku deweloperskim przy braku SMTP: zrzut wiadomości do `var/mail/` (prawa 700, poza
  `storage/logs`, poza rotacją i poza paczką wydania). ⚠ Treść zawiera jednorazowy token, więc
  **nie wolno jej zapisywać do zwykłego logu** (§9, §14). W produkcji brak konfiguracji SMTP
  jest błędem instalacji, nie trybem zapasowym. Nigdy wyświetlenie linku w odpowiedzi HTTP.
- Po zmianie hasła: unieważnij pozostałe sesje konta, unieważnij niezużyte tokeny resetu,
  wpis do `event_log`.
- Limitowanie żądań resetu: **3 na konto i 10 na adres w ciągu 15 minut** (progi różne,
  z tego samego powodu co przy logowaniu).

⚠ **Unieważnienie pozostałych sesji** wymaga, żeby sesje dało się powiązać z kontem. Przy
plikowym magazynie sesji nie da się tego zrobić czysto. Rozwiązanie w szkielecie: kolumna
`account.password_changed_at` i porównanie ze znacznikiem zapisanym w sesji przy logowaniu —
sesja starsza niż ostatnia zmiana hasła jest odrzucana przez `Authenticate`. Proste,
przenośne i działa na każdym hostingu.

### Zarządzanie urządzeniami

Ekran „Twoje urządzenia" w ustawieniach konta (uprawnienie `account.manage_own`):

- lista aktywnych sesji: kiedy powstała, ostatnia aktywność, przeglądarka i system
  (z `User-Agent` — **wyłącznie do wyświetlenia**, nigdy do decyzji, §11), przybliżony adres
- **bieżąca sesja wyraźnie oznaczona** — inaczej użytkownik wyloguje sam siebie i uzna,
  że aplikacja się zepsuła
- zakończenie pojedynczej sesji oraz „wyloguj wszystkie poza tą"
- ⚠ operacje przez `POST` z tokenem CSRF i sprawdzeniem właściciela **w zapytaniu**
  (`WHERE id = :id AND account_id = :acc`) — cudza sesja to modelowy IDOR
- zakończenie sesji, zmiana hasła i wyłączenie 2FA trafiają do `event_log`
- administrator tenanta może zakończyć sesje **cudzego** konta (odejście pracownika,
  podejrzenie przejęcia) — z wpisem w `event_log` mówiącym kto i komu

### Rejestracja zaproszeniowa

Rejestracji otwartej nie ma — konta zakłada administrator albo powstają z synchronizacji kadrowej.

1. Administrator dodaje osobę → powstaje `account` w stanie `invited`, bez hasła.
2. Wysyłany jest link z tokenem (hash w `invitation`, ważność **7 dni**, jednorazowy).
3. Użytkownik ustawia hasło → konto `active`, `event_log`, automatyczne zalogowanie
   **nie następuje** — kieruj na ekran logowania.
4. Ten sam mechanizm tworzy pierwszego administratora z instalatora (rozdział 12).

### Logowanie zewnętrzne (Google / Apple)

W szkielecie **tylko miejsce i przyciski**: trasa `/logowanie/zewnetrzne/{provider}` i obsługa
powrotu z brokera `auth.wezurlop.pl` zaślepione kontrolerem zwracającym „w przygotowaniu".
Tabela `account_identity` i ekran są gotowe. Pełna integracja z brokerem to osobny blok —
wymaga `firebase/php-jwt`, rejestru w panelu i biletów jednorazowych (patrz specyfikacja §5).
Ważne, żeby przyciski istniały w layoucie od początku, bo dołożenie ich później rozwala
kompozycję ekranu logowania w obu wariantach graficznych.

---

## 7. Autoryzacja

```php
// Entity/CurrentUser.php — DTO budowane raz na żądanie
public function can(string $permission): bool
public function isAdmin(): bool
public function accountId(): int
public function activeEmployment(): ?EmploymentContext
```

- `{% if can('admin.users') %}` w szablonie **ukrywa przycisk, nie zabezpiecza akcji** —
  każda trasa ma własne uprawnienie sprawdzane przez middleware (§10 reguł).
- **IDOR**: każde pobranie zasobu przez repozytorium z warunkiem właściciela w zapytaniu
  (`WHERE id = :id AND company_id = :company`), nie `if` po pobraniu. W szkielecie dotyczy to
  na razie kont i zatrudnień, ale wzorzec metod repozytoriów (`findForContext(...)`)
  ustalamy teraz, bo w Etapie 3 powstanie ich kilkadziesiąt.
- Brak uprawnienia do istniejącego zasobu → **404**, nie 403, jeśli sam fakt istnienia zasobu
  jest informacją (np. cudzy wniosek). 403 tylko dla sekcji, których istnienie jest jawne.
- Endpoint zwracający fragment htmx sprawdza dokładnie to samo co zwykła akcja (§5 reguł).
---

## 8. Widok — Twig, htmx, dwa layouty

Konfiguracja Twiga dokładnie jak w §5 reguł: `autoescape => 'html'`, `strict_variables => true`,
cache w `var/cache/twig`, `debug` tylko na dev.

Funkcje globalne zarejestrowane w Twigu — **tylko te i nic więcej**:
`csrf_token()`, `asset(path)`, `url(path)`, `current_user()`, `can(permission)`, `active(route)`.
Żadna z nich nie wykonuje zapytań ani operacji na plikach (§5).

`asset()` dokleja `?v=` z **jednego** identyfikatora wersji zasobów (`ASSET_VERSION` z konfiguracji,
ustawiany przez instalator i aktualizator). Cache przeglądarki po aktualizacji u klienta to realny
problem zgłoszeniowy, a bundlera nie mamy. ⚠ `filemtime` przy każdym renderze odpada: §5 zabrania
rejestrowania w Twigu funkcji wykonujących operacje na plikach, a przy kilkunastu zasobach
to kilkanaście `stat` na każde żądanie.

### Dwa layouty, wspólne kontrolery

```
templates/layout/web.twig      sidebar 260px + topbar 84px, treść max 1400px
templates/layout/mobile.twig   splash/nagłówek + dolna nawigacja + safe-area-inset
templates/page/web/*.twig      dziedziczą z web.twig
templates/page/mobile/*.twig   dziedziczą z mobile.twig
templates/page/auth/*.twig     logowanie, 2FA, reset, zaproszenie — własny, wąski layout
```

Kontroler **nie wie**, który layout zostanie użyty. Wybiera go jedna klasa
`Support/ViewResolver`, na podstawie:

1. jawnego prefiksu trasy (`/m/...` → mobilny) — **główny mechanizm**, jednoznaczny,
2. nagłówka `X-Wezurlop-App` wysyłanego przez powłokę Fluttera — tylko jako
   automatyczne przekierowanie przy wejściu na `/`.

⚠ Wykrywania po `User-Agent` **nie ma**. Jest zawodne i nie wolno na nim opierać niczego,
co użytkownik zobaczy jako „aplikacja się zepsuła". Adres decyduje.

### Rozpoznanie klienta — ciastko, nie nagłówek

Powłoka Fluttera ustawia przed pierwszym załadowaniem ciastko **`wu_client=app`** dla hosta
tenanta (`Secure`, `SameSite=Lax`, `HttpOnly`, długi termin), przez `CookieManager`.
Drugie ciastko `wu_app=1.0.3/android/1` niesie wersję.

Dlaczego ciastko, a nie nagłówek `X-Wezurlop-App`:

- nagłówek ustawiony w `flutter_inappwebview` obejmuje **nawigację główną, nie żądania
  `fetch`/`XHR`** — znika więc przy każdym wywołaniu htmx, czyli przy połowie interakcji.
  Aplikacja byłaby mobilna przy wejściu na ekran i webowa po pierwszym kliknięciu w liście;
- ciastko idzie samo przy każdym żądaniu, także po przekierowaniu i przy POST formularza,
  i nie bywa wycinane tak jak nagłówki własne pod CGI/FastCGI;
- działa **przed zalogowaniem**, gdy sesji jeszcze nie ma, a ekran logowania też musi wiedzieć,
  który layout pokazać.

Nagłówek `X-Wezurlop-App` zostaje wyłącznie jako wariant zapasowy dla starszych powłok —
mostka nie zmieniamy, tylko dopisujemy (rozdział 10).

⚠ **`wu_client` jest znacznikiem prezentacji, nie poświadczeniem.** Ustawi je każdy w narzędziach
przeglądarki jedną linijką, więc znaczy dokładnie tyle, co tekst wpisany w formularzu. Wolno na
nim oprzeć wybór layoutu i przekierowanie — **nigdy** decyzję o uprawnieniach, pominięcie 2FA
ani skrócenie ścieżki logowania.

**Ciastko decyduje raz, potem decyduje sesja.** Przy logowaniu `ClientKind` zapisuje wartość
do `session.client` i `session.app_version`. Dzięki temu wyczyszczenie ciastka w trakcie pracy
niczego nie psuje, ekran „Twoje urządzenia" pokazuje „aplikacja Android" zamiast „przeglądarka",
a w `event_log` widać, skąd nastąpiło logowanie.

### Dostęp do wariantu mobilnego z przeglądarki

Przełącznik `MOBILE_ACCESS` w konfiguracji tenanta: **`app_only` na produkcji, `open` na dev**.
Przy `app_only` żądanie na `/m/wnioski` od klienta `web` dostaje **przekierowanie na `/wnioski`**,
a nie 403 ani 404.

⚠ Przekierowanie, nie blokada — to jest istotne. Gdyby u jakiegoś klienta ciastko nie dotarło
(proxy, nietypowa konfiguracja WebView), twarda blokada zamienia to w „aplikacja nie działa",
a przekierowanie w „aplikacja wygląda jak strona". Drugie zgłoszenie diagnozuje się w pięć minut
i nie blokuje ludziom pracy.

⚠ I jeszcze raz, bo to jest ten mechanizm, który za rok ktoś awansuje na zabezpieczenie:
`/m/…` przechodzi przez **dokładnie ten sam łańcuch middleware** co wersja webowa. Otwarcie
mobilnego layoutu w przeglądarce nie daje dostępu do niczego — `MOBILE_ACCESS` to porządek,
nie ochrona.

**Jeden zestaw kontrolerów**, nie dwa. Trasy mobilne to te same akcje pod prefiksem `/m/`
(`['GET', '/m/kalendarz', [App\\CalendarController::class, 'index'], ...]`), a kontroler zwraca
nazwę widoku bez wariantu — `ViewResolver` dokłada `page/web/` albo `page/mobile/`. Osobne
katalogi kontrolerów oznaczałyby każdą akcję napisaną dwa razy i dwa miejsca do zapomnienia
o sprawdzeniu uprawnienia.

Wspólne są: kontrolery, serwisy, repozytoria, walidacja, uprawnienia i **tokeny CSS**.
Osobne: layouty, nawigacja, część komponentów i arkusze `layout-web.css` / `layout-mobile.css`.

### Fragmenty htmx

- `templates/fragments/*.twig`, renderowane bez layoutu, jeden fragment = jeden plik.
- Token CSRF wstrzykiwany raz: `<body hx-headers='{"X-CSRF-Token": "..."}'>`.
- `hx-get` wyłącznie do odczytu; każda zmiana stanu przez `hx-post`/`hx-put`/`hx-delete`.
- **Zakaz `hx-on:*` i `hx-vals` w formie `js:`** — wymagałyby `unsafe-eval` w CSP (§5, §6, §13).
- Obsługa `htmx:responseError` i `htmx:sendError` w `app-web.js` / `app-mobile.js`:
  wspólny pasek komunikatu (`partials/flash.twig`, element `#flash`), wypełniany przez
  `textContent`. Bez tego błąd serwera objawia się kliknięciem, które nic nie robi.
- ⚠ **htmx sam wstrzykuje `<style>`** do `<head>` (reguły `.htmx-indicator`), co przy CSP
  `style-src 'self'` bez `unsafe-inline` daje naruszenie na każdym ekranie. Dlatego w obu layoutach:
  `<meta name="htmx-config" content='{"includeIndicatorStyles":false}'>`, a reguły
  `.htmx-indicator` przenosimy do `components.css`.
- Po zapisie fragment renderuje **stan z bazy**, nie to, co przyszło w żądaniu.
- Przekierowania po zapisie: `HX-Redirect` / `HX-Location`, nigdy `Location`.

### Strona `/styleguide` (tylko dev)

Wszystkie komponenty na jednym ekranie: przyciski we wszystkich stanach, pola formularzy
z błędami, komunikaty, karty, tabele, badge, paski postępu, stany puste, pasek `#flash`.
Dostępna **wyłącznie** przy `APP_ENV=dev` — w produkcji trasa nie istnieje (nie jest ukryta).

To nie jest ozdoba: poprawianie wyglądu na jednym ekranie zamiast klikania przez czterdzieści
pięć skraca każdą rundę zmian z godzin do minut, a przy okazji wyłapuje komponenty, które
rozjechały się tylko na jednym widoku.

### Zasoby

```
tokens.css        zmienne (kolory, promienie, font) — wspólne dla web i mobile
base.css          reset, typografia, elementy formularzy
components.css    przyciski, karty, tabele, switche, badge, modal
layout-web.css    sidebar, topbar, siatka treści
layout-mobile.css splash, dolna nawigacja, safe-area
```

Tokeny przenosimy **bez zmian** z projektu graficznego:
`--bg:#f4f7fb`, `--surface:#ffffff`, `--line:#e3ebf5`, `--navy:#0d2a74`, `--muted:#66779c`,
`--green:#1fbe6f`, `--green-dark:#10965a`, `--blue:#2f7cf6`, `--orange:#f7a300`,
`--pink:#ff5d91`, `--red:#f25353`, `--radius-xl:24px`, `--sidebar-width:260px`,
`--topbar-height:84px`, `--content-max:1400px`, font Inter.

⚠ Inter musi być **plikiem lokalnym** w `assets/fonts`, nie z Google Fonts: CSP z §13
dopuszcza tylko `'self'`, a poza tym ładowanie fontu z zewnątrz przy każdym wejściu
pracownika to wyciek informacji o użytkowaniu systemu kadrowego na zewnątrz.

JS wyłącznie w plikach, ładowany z `defer`, zdarzenia przez `addEventListener`.
Dane z PHP do JS **wyłącznie** przez `data-*` albo endpoint JSON (§6).

---

## 9. Warstwa graficzna — co zrobić z projektami

Mamy **cztery** paczki graficzne:

| Paczka | Zawartość |
|---|---|
| `wezurlop-web-panel` | ekrany aplikacyjne web: dashboard, kalendarz, wnioski, nowy wniosek, zespół, ustawienia |
| `wezurlop-mobile-webview` | ekrany aplikacyjne mobilne + dolna nawigacja, splash, `safe-area-inset` |
| `wezurlop-web-security` | ekrany systemowe web: logowanie, reset, zaproszenie, 2FA, kontekst, błędy, licencja, instalator, stany htmx |
| `wezurlop-mobile-security` | to samo w wariancie mobilnym |

**Nie przenosimy ich jeden do jednego** — demo trzyma stan w przeglądarce, docelowa aplikacja
trzyma go na serwerze.

### Przeróbki obowiązkowe (naruszenia `CLAUDE.md` §6) — razem, cztery paczki

| Co | Ile | Co zrobić |
|---|---|---|
| atrybuty `style="..."` w HTML | **68** (panel 20, webview 2, security web 23, security mobile 23) | klasy w `components.css`; stan wizualny klasą, nie stylem |
| `innerHTML` w `app.js` | **21** (6 / 5 / 5 / 5) | `textContent` / `createElement`, a listy i tabele — jako fragmenty Twiga z serwera |
| `localStorage` jako magazyn stanu | **15** (3 / 2 / 5 / 5) | stan na serwerze; `localStorage` zostaje najwyżej na drobiazgi UI |
| wpisany e-mail i hasło w formularzu demo | login web i mobile | usunąć razem z przyciskiem „zaloguj jako demo" |
| „jednorazowe pokazanie kodów zapasowych przez `localStorage`" | 2 pliki | ⚠ jednorazowość rozstrzyga **serwer** (`backup_code` + znacznik potwierdzenia), nie przeglądarka — inaczej wyczyszczenie danych witryny pokazuje kody drugi raz |
| `index.html` — spis ekranów demo | 2 pliki | nie przenosimy, to nawigacja po paczce |

Dobra wiadomość, konsekwentnie we wszystkich czterech paczkach: **zero `on*=`, zero `<script>`
z treścią, zero `<style>`** — najtrudniejszy warunek CSP bez `unsafe-inline` projekty już spełniają.
Kod QR jest w nich osadzony jako `<img src="assets/2fa-qr.png">`, czyli dokładnie tak, jak wymaga
tego decyzja z rozdziału 6 (osobny endpoint zwracający SVG) — tu nic nie trzeba przerabiać.

### Co paczki systemowe pokrywają, a czego nadal brakuje

Pokryte (web i mobile): reset — żądanie i ustawienie hasła, zaproszenie/pierwsze hasło,
2FA — parowanie, kod, kody zapasowe, wybór kontekstu zatrudnienia, logowanie, powrót z brokera,
błędy 403/404/419/500, sesja wygasła, tryb ograniczony licencji, stany fragmentów htmx.

Brakuje **dwóch** ekranów:

1. **„konto bez aktywnego zatrudnienia"** — web i mobile. Występuje realnie: pracownik po
   rozwiązaniu umowy, konto założone przed pierwszym dniem pracy, osoba wypisana z jedynej firmy.
   Bez tego ekranu takie logowanie kończy się pustym pulpitem albo błędem.
2. **strona diagnostyczna** (`/diagnostyka`) — tylko web.

### Czego w wariancie mobilnym nie budujemy

Paczka `wezurlop-mobile-security` jest kopią webowej i zawiera ekrany, które w aplikacji
nie mają prawa się pojawić:

- **instalator** (`installer.html`) — instalacja tenanta odbywa się w przeglądarce, na serwerze
  klienta, przez osobę wdrażającą. Aplikacja ze sklepu łączy się z **już zainstalowanym** tenantem,
  więc trasy instalatora nie mają wariantu mobilnego w ogóle (nie „są ukryte" — nie istnieją).
- **diagnostyka** — z tego samego powodu.
- `index.html` — spis ekranów demo.

Pozostałe ekrany systemowe wariant mobilny **ma mieć**, łącznie z trybem ograniczonym licencji
(pracownik musi zobaczyć czytelny komunikat, a nie pustą aplikację) i powrotem z brokera
logowania zewnętrznego.

### Ekrany biznesowe w tym etapie

`/kalendarz`, `/wnioski`, `/wniosek/nowy`, `/zespol`, `/ustawienia` powstają jako **puste ramy**:
poprawny layout, nawigacja, stany puste, dane przykładowe podane wprost z kontrolera.
Bez repozytoriów, bez tabel, bez liczenia dni roboczych. Chodzi o to, żeby nawigacja, tytuły,
uprawnienia i responsywność były przetestowane, zanim dołoży się do nich logikę.

---

## 10. Mostek natywny (Flutter WebView)

Powłoka natywna i tenant to dwa osobno wydawane byty: aplikacja w sklepie jest **wspólna dla
wszystkich tenantów**, a tenant u klienta aktualizuje się osobno i bywa o wersje w tyle.
Dlatego kontrakt mostka ma jedną twardą regułę:

> **Mostek jest tylko do dopisywania.** Raz opublikowanej metody nie zmieniamy i nie usuwamy —
> zmiana zachowania oznacza nową nazwę metody. Starsza powłoka musi działać z nowszym tenantem
> i odwrotnie.

Kontrakt (JS w WebView; nazwa przestrzeni `window.WezUrlop`):

| Wywołanie | Kierunek | Zwraca |
|---|---|---|
| `WezUrlop.version` | odczyt | `{app: "1.0.3", platform: "android", bridge: 1}` |
| `WezUrlop.biometricAvailable()` | JS → natywne | `Promise<bool>` |
| `WezUrlop.biometricUnlock(reason)` | JS → natywne | `Promise<bool>` |
| `WezUrlop.pushToken()` | JS → natywne | `Promise<string|null>` |
| `WezUrlop.pickDocument(opts)` | JS → natywne | `Promise<{name, mime, size, id}>` |
| `WezUrlop.openExternal(url)` | JS → natywne | `void` |
| `WezUrlop.haptic(kind)` | JS → natywne | `void` |

Zasady:

- **Strona musi działać bez mostka.** Każde wywołanie poprzedzone sprawdzeniem
  `typeof window.WezUrlop?.biometricAvailable === 'function'`; brak mostka = zwykła przeglądarka
  i wariant zapasowy (hasło zamiast biometrii, `<input type="file">` zamiast `pickDocument`).
- Powłoka wysyła nagłówek `X-Wezurlop-App: <wersja>/<platforma>/<bridge>` przy każdym żądaniu.
- **Mostek nie uwierzytelnia.** Biometria odblokowuje aplikację lokalnie; sesję po stronie
  serwera zakłada wyłącznie logowanie. Zaufanie wynikowi `biometricUnlock()` po stronie PHP
  byłoby zaufaniem danym z urządzenia, którym steruje użytkownik (§11 reguł).
- Powłoka ma **listę dozwolonych adresów nawigacji** (tylko host tenanta); wszystko inne
  otwiera się w przeglądarce systemowej. To zabezpieczenie po stronie aplikacji, opisane
  osobno przy bloku mobilnym.
- W tym etapie: kontrakt spisany w `docs/mostek-natywny.md` + warstwa JS
  (`assets/js/modules/bridge.js`) z wykrywaniem i wariantami zapasowymi. Powłoka Fluttera
  to osobny blok.

---

## 11. Licencja — strefa kodowana od pierwszego pliku

`/src/License` i `/src/Core` są od początku osobnymi katalogami (§15 reguł), nawet jeśli
`/src/Core` jest w tym etapie pusty. Przenoszenie klas przez tę granicę później jest kosztowne.

W tym etapie powstaje:

```
src/License/LicenseGate.php        jedyne wejście dla reszty aplikacji      [KODOWANE]
src/License/LicenseReader.php      odczyt pliku licencji / właściwości ionCube [KODOWANE]
src/License/LicenseValidator.php   weryfikacja podpisu Ed25519 i terminów   [KODOWANE]
src/License/LicenseState.php       DTO: limity, funkcje, termin, tryb       [KODOWANE]
src/Service/LicenseFetcher.php     pobranie odnowienia z panelu i zapis     [JAWNE]
```

⚠ `LicenseFetcher` **musi zostać poza strefą kodowaną** — to ustalenie ze specyfikacji.
Kod dzwoniący do panelu po odnowienie ma działać **także wtedy, gdy licencja wygasła**,
i musi być diagnozowalny u klienta (§15: „hotfix w strefie kodowanej jest niemożliwy").
Przechowuje: ostatnią podpisaną odpowiedź panelu, klucz publiczny Ed25519 panelu
(plik konfiguracyjny, nie kod) i **znacznik ostatniego znanego czasu** — bez niego cofnięcie
zegara serwera przedłuża wygasłą licencję w nieskończoność.

`LicenseGate` w szkielecie zwraca stan „deweloperski, bez ograniczeń", ale **interfejs jest
docelowy** i reszta aplikacji zna wyłącznie jego:

```php
public function state(): LicenseState;          // nie isValid(): bool  (§15)
public function limit(string $key): ?int;       // np. 'accounts'
public function featureEnabled(string $key): bool;
```

- Żadnego `isValid(): bool` — pojedynczy `return true` to jeden bajt do spatchowania.
  Wynik licencji ma być **potrzebny do działania**: dostarcza limity i parametry używane dalej.
- W `/src/License` nie ma konfiguracji klienta: żadnych adresów, nazw, stawek. Wszystko czytane
  z konfiguracji albo z bazy — kodowanego pliku nie da się poprawić na serwerze klienta.
- **Zero logiki licencyjnej w szablonach i w JS** (§15). `{% if %}` po stanie licencji
  jest dopuszczalne wyłącznie do ukrycia przycisku; blokada jest po stronie serwera.
- Składnia w tej strefie ograniczona do wersji PHP wspieranej przez posiadany Encoder.
  ⚠ Do rozstrzygnięcia przed zakupem: dev ma Loader **15.5.0**, home.pl **14.4.1** —
  paczka zakodowana pod nowszy Loader nie uruchomi się u klienta.
- Logowanie w tej strefie bogatsze niż gdzie indziej: własne kody błędów + identyfikator
  zdarzenia, bo stack trace z kodowanego pliku jest bezużyteczny.

---

## 12. Instalator tenanta

**Poza strefą kodowaną** — działa, zanim licencja istnieje, i musi być diagnozowalny u klienta.
Katalog `src/Controller/Install`, szablony `templates/page/install`, własny layout.

Kroki:

1. **Diagnostyka środowiska** — wersja PHP, ionCube Loader i jego wersja, rozszerzenia
   (`pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `iconv`, `xmlwriter`), prawa zapisu
   do `var/` i `storage/`,
   czy przepisywanie adresów działa, czy nagłówek `Authorization` dociera do PHP,
   `max_execution_time`, dostępność SMTP.
1b. **Wykrycie profilu serwera** (`apache` / `ideawebserver` / `minimal`, zapis do `.env`
   jako `SERVER_PROFILE` razem z surowym `SERVER_SOFTWARE`). Profil decyduje o zestawie
   plików `.htaccess`, mechanizmie crona i sposobie przekazania ścieżki aplikacji —
   **nigdy o uprawnieniach**. Rozstrzyga wynik sond HTTP, nie nagłówek (`docs/profile-hostingu.md`).
2. **Wykrycie przepisywania adresów i ustalenie prefiksów.** Instalator pobiera po HTTP
   adres próbny bez `index.php` (np. `/_probe`) i sprawdza, czy odpowiedziała aplikacja.
   Wynik zapisuje jako `APP_BASE_PATH` i `APP_FRONT_CONTROLLER` (rozdział 3). Zgadywanie
   z `SCRIPT_NAME` nie wchodzi w grę — hostingi kłamią na tym polu.
   Przy wariancie „aplikacja powyżej katalogu domeny" ustala też ścieżkę aplikacji.
   `public/index.php` szuka jej w **trzech** miejscach, w tej kolejności:
   **`public/app-path.php`** (plik pisany przez instalator, `return '/…/wezurlop';`) →
   `getenv('WEZURLOP_APP_PATH')` (`SetEnv` w `public/.htaccess`, albo `RewriteRule … [E=…]`) →
   `dirname(__DIR__)`.
   ⚠ **Kolejność jest celowa: plik, nie zmienna.** `mod_env` należy wprawdzie do klasy
   `FileInfo`, ale na serwerach innych niż Apache `SetEnv` nie jest udokumentowany
   (IdeaWebServer home.pl) i bywa ignorowany bez błędu — a wtedy front controller nie znajdzie
   `vendor/` ani `config/` i klient widzi białą stronę. Plik na dysku działa wszędzie.
3. **Token instalacyjny** wydany przez `panel.wezurlop.pl` — jednorazowy, krótko ważny.
   Bez niego pliki leżące na serwerze przed instalacją może zainstalować ktokolwiek,
   wskazując własną bazę i zostając administratorem.
   ⚠ **Blokada „jeden przebieg naraz" powstaje dopiero po podaniu poprawnego tokenu.**
   Założona wcześniej zamienia instalator w cel: ktokolwiek zajmuje przebieg i trzyma
   instalację zakładnikiem, odnawiając blokadę co kwadrans. Przed tokenem żadnej blokady
   nie ma — jest tylko limit prób samego tokenu.
4. **Dane bazy — jedna para obowiązkowa, druga zalecana.** Instalator pyta o konto z prawami
   do zmiany schematu (`DB_MIG_*`, wymagane — bez DDL nie ma migracji) i **opcjonalnie**
   o ograniczone konto aplikacyjne (`DB_*`, `SELECT, INSERT, UPDATE, DELETE`). ⚠ Nazewnictwo
   zgodne z kodem: **`DB_*` to zawsze połączenie aplikacji**, `DB_MIG_*` to migracje;
   w trybie jednego konta oba zestawy mają te same wartości. Testuje każde
   podane połączenie, sprawdza kolację (`utf8mb4_unicode_ci`) i — jeśli podano drugie —
   **weryfikuje podział**: na koncie aplikacyjnym próbuje `CREATE TABLE` i oczekuje odmowy.
   Udane `CREATE TABLE` znaczy, że to jedno konto wpisane dwa razy → ostrzeżenie widoczne
   w instalacji, nie cicha zgoda.
   ⚠ **Instalator nie tworzy kont bazy i nie wymaga dwóch.** Na hostingach współdzielonych
   konto klienta nie ma `CREATE USER` ani `GRANT OPTION` (home.pl: `1227 Access denied …
   CREATE USER`), a bywa, że **jedna baza ma dokładnie jednego użytkownika o tej samej nazwie**
   i drugiego nie da się dodać nawet z panelu. Wymóg, którego klient nie może spełnić, kończy się
   wpisaniem konta z pełnymi prawami bez żadnej adnotacji — więc zamiast wymogu jest **tryb
   jednego konta**, zapisany jawnie:
   - `DB_SINGLE_ACCOUNT=1` w `.env`, wpis w `event_log`, **trwała pozycja na `/diagnostyka`**,
   - `Db\Connection` udostępnia dwa profile: `data` (aplikacja) i `schema` (migracje).
     W trybie jednego konta poświadczenia są te same, ale profil `data` ma **bezpiecznik DDL**:
     polecenie zaczynające się od `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `RENAME`, `GRANT`
     lub `REVOKE` jest odrzucane wyjątkiem i zapisywane jako zdarzenie bezpieczeństwa.
     To ochrona przed **naszym** błędem, nie przed atakującym z poświadczeniami — i tak trzeba
     ją nazywać, żeby nikt jej nie przecenił,
   - kopia zapasowa przed aktualizacją przestaje być dobrą praktyką i staje się **warunkiem**
     wydania aktualizacji (`exec` jest zakazane, więc zrzut robi PHP, porcjami),
   - realną obroną pozostaje `ATTR_EMULATE_PREPARES => false`: MySQL nie wykona dwóch poleceń
     w jednym wywołaniu, więc wstrzyknięcie nie dołoży `DROP`-a do zapytania aplikacji.
5. **Ścieżki i adres** — `.env`, `var/`, `storage/`; wykrycie wariantu wdrożenia (domena na
   `public/` albo aplikacja powyżej katalogu domeny) z możliwością ręcznego nadpisania;
   **`APP_URL`** — adres, pod którym aplikacja jest widoczna. Wszystkie linki wysyłane mailem
   budowane są wyłącznie z niego, nigdy z nagłówka `Host`.
6. **Poczta** — serwer SMTP, nadawca, **skrzynka na alerty** (specyfikacja §5). Warunkiem
   przejścia dalej jest **udana wysyłka testowa** — inaczej instalacja kończy się bez działającej
   poczty i bez konta administratora, bo link do ustawienia hasła nigdy nie dociera.
7. **Weryfikacja HTTP** — instalator **sam pobiera przez HTTP** (pod `APP_URL`, nie pod adresem
   wpisanym w formularzu — §13, cel wywołania nie pochodzi z danych wejściowych) `/.env`,
   `/composer.json`, plik z `vendor/` i plik z `storage/`. Trzy możliwe wyniki, nie dwa:
   **chronione** (403/404) → idziemy dalej; **odsłonięte** (jakakolwiek treść) → **błąd blokujący**;
   **nierozstrzygnięte** (timeout, błąd DNS, odbicie od proxy — na wielu hostingach serwer nie
   dopyta sam siebie) → wymaga jawnego potwierdzenia przez instalującego i zostaje w logu instalacji.
   ⚠ Obecność `.htaccess` nie jest dowodem, że cokolwiek działa.
8. **Migracje** — uruchomienie phinx porcjami, z zapamiętaniem miejsca przerwania
   (zakładamy `max_execution_time` 30 s, §0).
9. **Administrator** — podanie **adresu e-mail** klienta i wysłanie jednorazowego linku
   ustawiającego hasło. **Zakaz ustawiania hasła w instalatorze** — instalujący nie ma znać
   hasła klienta.
10. **Rejestracja w panelu** i zapis `APP_KEY`, `ASSET_VERSION`, poświadczeń i konfiguracji
   do `.env` poza webrootem.
10b. **Cron i kolejka** — zależnie od profilu: wpis `crontab` (`*/5`) albo plik
   **`cron-05min.php`** w katalogu domeny (shim: jeden `require` runnera, blokada dostępu
   z internetu w konfiguracji serwera **plus** lock i minimalny odstęp w PHP).
   Instalator zapisuje potwierdzoną częstotliwość; gdy jest rzadsza niż 15 minut, włącza
   `QUEUE_ON_REQUEST` (kolejka domykana po odpowiedzi, budżet ~2 s, tylko zadania pilne) —
   inaczej link resetu hasła dotarłby na granicy ważności. Zakłada też **plik-kanarka**
   w `storage/` i dobowe zadanie sprawdzające, czy nie stał się dostępny po HTTP.
11. **Blokada i zaciśnięcie uprawnień** — znacznik zapisywany **poza webrootem**
    (`var/install.lock`), sprawdzany w pierwszej linii każdego kroku; docelowo katalog
    instalatora usuwany. Instalator **sam** ustawia `.env` z powrotem na `0640`
    (zapis wymagał `0660`). ⚠ Przypomnienie na ekranie końcowym nie jest zabezpieczeniem —
    nikt go nie wykona. Jeśli `chmod` się nie uda, to **błąd**, nie ostrzeżenie, a diagnostyka
    zgłasza go przy każdym uruchomieniu, dopóki prawa nie wrócą do `0640`.

**Stan instalatora** trzymamy w `var/install-state.json` (prawa 600, klucz stanu w ciasteczku,
token CSRF związany z tym plikiem). ⚠ Nie w sesji PHP: instalator działa, zanim istnieje
konfiguracja `save_path`, więc trafiłby do domyślnego, współdzielonego katalogu sesji na hostingu —
a jego stan zawiera **poświadczenia do bazy**. To ten sam problem, który rozdział 2 opisuje
jako krytyczny, tylko z gorszą zawartością.

Dodatkowo: własna ochrona CSRF, **zakaz `phpinfo()`** i zakaz wyświetlania wpisanych poświadczeń
oraz klucza API.

---

## 13. Błędy, logi, diagnostyka

- Globalne handlery w bootstrapie. Użytkownik widzi własny `error.twig` z ogólnym komunikatem
  i **identyfikatorem zdarzenia** (`bin2hex(random_bytes(8))`); szczegóły wyłącznie do logu.
- Nigdy komunikat wyjątku PDO na ekranie. W produkcji `display_errors=Off`, Twig `debug=false`.
- Logi w `storage/logs`, rotowane po dacie, bez haseł, tokenów i danych osobowych ponad potrzebę.
- Dla żądań htmx strona błędu nie jest wstawiana do treści — kontroler błędu rozpoznaje
  `HX-Request` i zwraca **fragment** z komunikatem (to jedyny dopuszczalny użytek tego nagłówka:
  forma odpowiedzi, nigdy decyzja o uprawnieniach).
- `bin/diagnose.php` (CLI) i strona `/diagnostyka` (tylko `admin.settings`) dzielą ten sam
  zestaw sprawdzeń co krok 1 instalatora — jeden kod, trzy miejsca użycia.
- ⚠ **Istniejący `src/Diagnostics` z etapu środowiskowego trzeba przepisać**, nie przenieść:
  ma operator `@` (§14), generuje HTML w PHP (§5), używa `require_once` i wykonuje kod przy
  dołączeniu pliku (§1, §2), a funkcja globalna bez przestrzeni nazw nie zostanie znaleziona
  przez PSR-4. Docelowo: klasa `Wezurlop\Diagnostics\EnvironmentCheck` **zwracająca tablicę
  wyników** i nic nie wypisująca; `bin/diagnose.php` drukuje je tekstem, a strona `/diagnostyka`
  renderuje je Twigiem (M8). To zadanie należy do M1 — razem z powstaniem autoloadera.

---

## 14. `.htaccess` i czwarty vhost

### Pliki `.htaccess`

`public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On

  # Authorization bywa wycinany pod CGI/FastCGI — PRZED regułą przepisującą
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

  # pliki z kropką (.env, .git, .htpasswd) — poza /.well-known/
  RewriteRule (^|/)\.(?!well-known/) - [F]

  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.php [QSA,L]
</IfModule>

# Bez mod_rewrite zostaje przynajmniej blokada plików z kropką.
<IfModule !mod_rewrite.c>
  <IfModule mod_alias.c>
    RedirectMatch 404 (^|/)\.(?!well-known/)
  </IfModule>
</IfModule>
```

Dwie rzeczy, które łatwo przeoczyć:

- ⚠ **`<IfModule>` jest obowiązkowe.** Bez `mod_rewrite` dyrektywa `RewriteEngine On` w `.htaccess`
  nie jest ignorowana — Apache zwraca **500** („Invalid command 'RewriteEngine'"). Cała ścieżka
  zapasowa `PATH_INFO` istnieje właśnie na wypadek braku przepisywania, więc bez `<IfModule>`
  aplikacja padłaby dokładnie tam, gdzie miała się uratować.
- ⚠ **Zakaz `Require` w `.htaccess`** — i to nie jest przezorność, tylko wynik testu na maszynie
  deweloperskiej (16.09.2026). `Require` należy do klasy `AuthConfig`; przy `AllowOverride FileInfo`
  Apache **nie ignoruje go, tylko wywala 500** na każdym żądaniu: „Require not allowed in
  `<FilesMatch>` context". Efekt u klienta: aplikacja nie startuje w ogóle, instalator też nie,
  więc nikt nie zobaczy żadnego komunikatu. `<IfModule>` tego nie uratuje — problem jest
  w uprawnieniu nadpisywania, nie w module. Blokujemy wyłącznie dyrektywami z klasy `FileInfo`:
  `RewriteRule` i `RedirectMatch`.
- Reguła `Authorization` stoi **przed** przepisaniem. Po wewnętrznym przekierowaniu zmienna
  potrafi dotrzeć do PHP z prefiksem, dlatego `Http\Request` czyta **trzy** źródła:
  `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` i nagłówek `X-Api-Key`.

`.htaccess` w **korzeniu aplikacji** — dla wdrożenia bez możliwości przestawienia katalogu domeny.
Nie blokuje wszystkiego z osobna, tylko **wpuszcza ruch do `public/`**, przez co reszta drzewa
przestaje być osiągalna sama z siebie:

```apache
# Katalog domeny = korzeń aplikacji. Wszystko wchodzi do public/,
# więc /.env, /vendor/..., /src/... trafiają do public/ i kończą się 404.
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_URI} !^/public/
  RewriteCond %{REQUEST_URI} !^/\.well-known/
  RewriteRule ^(.*)$ public/$1 [L]
</IfModule>

# Bez mod_rewrite nie da się przepisać — wtedy przynajmniej zamykamy resztę drzewa.
# Aplikacja jest wtedy dostępna pod /public/index.php/... (PATH_INFO, rozdział 3).
<IfModule !mod_rewrite.c>
  <IfModule mod_alias.c>
    RedirectMatch 404 ^/(?!public/|\.well-known/).*$
  </IfModule>
</IfModule>
```

Dwie pułapki w tym pliku:

- ⚠ **`/.well-known/` musi zostać przepuszczone.** Blokada „wszystkiego z kropką" wycina
  `/.well-known/acme-challenge/...`, czyli **odnawianie certyfikatu Let's Encrypt**. Objawia się
  to raz na trzy miesiące, u klienta, jako wygasły certyfikat — i nikt nie kojarzy tego
  z `.htaccess` sprzed pół roku.
- Ten sam wyjątek obowiązuje w regule blokującej pliki z kropką w `public/.htaccess`.

⚠ `vendor/` dostępny z internetu to **znany wektor zdalnego wykonania kodu** (skrypty
przykładowe w pakietach), a `storage/uploads/` dostępny bezpośrednio oznacza pobieranie
załączników **z pominięciem kontroli uprawnień**. Serwer, który nie honoruje `.htaccess`,
musi zostać wykryty przez krok **7** instalatora (weryfikacja HTTP), a nie odkryty po incydencie.

### Klasa hostingu, która dyrektywy po cichu ignoruje

⚠ Jest trzecie zachowanie, między „honoruje" i „zwraca 500": **serwer przyjmuje `.htaccess`,
nie zgłasza błędu i nic nie robi**. Zmierzone na home.pl (17.09.2026, serwer `IdeaWebServer`):
`RewriteRule … [F]` daje **403** i faktycznie blokuje, `RewriteCond`/`RewriteRule` działa
normalnie, ale `Require` **i `RedirectMatch`** są ignorowane bez śladu — żadnego 500,
żadnego wpisu, po prostu plik jest dostępny.

Wnioski, które z tego wynikają i obowiązują w kodzie:

- Blokady opieramy na `RewriteRule … [F]`, a `RedirectMatch` traktujemy **wyłącznie** jako
  ścieżkę zapasową dla serwerów bez `mod_rewrite` — nie jako drugą warstwę, bo tam,
  gdzie `mod_rewrite` nie działa, `mod_alias` bywa równie nieobecny.
- Wariant 2 wdrożenia (aplikacja powyżej katalogu domeny) na takim serwerze **jest wykonalny**,
  bo cała ochrona sprowadza się do jednego przepisania ruchu do `public/`, a przepisywanie działa.
- ⚠ **Obecność reguły w `.htaccess` nie dowodzi niczego.** Jedynym wiarygodnym sprawdzeniem
  jest **wynik pobrania po HTTP** — czyli krok 7 instalatora. Dlatego jego trzeci wynik
  („nierozstrzygnięte") nie może być cicho traktowany jak „chronione".
- ⚠ **`<FilesMatch>` na IdeaWebServer nie działa w ogóle** (dokumentacja home.pl; działa
  `<Files ~ "…">`). To tłumaczy ciche ignorowanie: blokada zapisana w `FilesMatch` jest tam
  **niewidocznie nieobecna**. Nie przenosimy jednak `<Files>` ani `Order`/`Deny` do wspólnego
  pliku — na Apache z `AllowOverride FileInfo` `Order` daje **500**.

**Pliki `.htaccess` są per profil serwera**, nie jednym plikiem z `<IfModule>`:
`deploy/htaccess/{apache,ideawebserver,minimal}/`. Instalator wykrywa profil
(`Service\ServerProfile`, strefa jawna), kopiuje zestaw i **potwierdza wynik po HTTP**.
Pełny opis profilów, zmierzonych zachowań i mechanizmów zależnych od serwera — cron przez HTTP,
`php.ini` per katalog, pinowanie wersji PHP, plik-kanarek — jest w `docs/profile-hostingu.md`.

Dodatkowo serwer REST API dla connectora akceptuje klucz w nagłówku `Authorization`
**albo** `X-Api-Key` — ścieżka zapasowa dla hostingów, które pierwszego nie przepuszczają (§0).
W szkielecie: sama warstwa odczytu klucza w `Http\Request`, bez endpointów API.

### Czwarty vhost — „gorszy hosting"

Na maszynie deweloperskiej dokładamy `tenant-cgi.wezurlop.pl` wskazujący **na korzeń aplikacji
zamiast na `public/`**, z `AllowOverride FileInfo` (a nie `All`). Odtwarza wdrożenie u klienta,
który nie może przestawić katalogu domeny **i** nie dostaje pełnego `AllowOverride`.
W konfiguracji tego vhosta: `SetEnv APP_BASE_PATH /public` **oraz**
`SetEnv APP_FRONT_CONTROLLER /index.php` (patrz rozdział 3 — dwa prefiksy).

⚠ Ten vhost **nie sprawdza** gałęzi „brak `mod_rewrite`" — moduł jest na tej maszynie załadowany
globalnie, więc `<IfModule !mod_rewrite.c>` nigdy się na nim nie wykona. Scenariusz 27 wymaga
osobnego przebiegu: tymczasowa instancja Apache'a bez `mod_rewrite` albo osobny kontener.
Nie zaliczaj go „z rozpędu" na podstawie tego, że czwarty vhost działa.

Testujemy na nim przy każdej istotnej zmianie:

- czy `/.env`, `/config/config.php`, plik z `/vendor/` i plik z `/storage/` są niedostępne —
  **403 albo 404**. Przy wariancie z przepisywaniem do `public/` żądanie obsługuje już aplikacja,
  więc odpowiedzią jest 404 z routera, i to jest wynik poprawny: liczy się brak treści,
  nie konkretny kod. 200 z jakąkolwiek zawartością to błąd blokujący.
- czy routing przez `PATH_INFO` działa: **`/public/index.php/logowanie`** renderuje ekran logowania
  (uwaga: przy tym wariancie `index.php` leży pod `/public/`, nie w korzeniu — testowanie
  `/index.php/...` da 404 i nie sprawdzi niczego),
- czy `url()` generuje adresy z prefiksem `/public`, po których da się poruszać po aplikacji,
- czy formularze i żądania htmx działają pod tym wariantem adresów.

Wariant 2 z §0 reguł (katalog domeny zawiera **zawartość** `public/`, a aplikacja leży piętro
wyżej) sprawdzamy na tym samym vhoście przez ustawienie `WEZURLOP_ENV_PATH` i ścieżek do
`var/` oraz `storage/` poza katalogiem domeny — to jest właśnie ten test, który udowadnia,
że ścieżki są konfigurowalne, a nie wyliczane z `__DIR__`.

Bez tego obie ścieżki zapasowe z reguł uniwersalności zostaną wykonane po raz pierwszy
dopiero u klienta.
---

## 15. Kolejność wykonania

Osiem kamieni milowych. Każdy ma warunek ukończenia, który da się sprawdzić — „działa lokalnie"
nie jest warunkiem ukończenia.

| # | Zakres | Ukończone, gdy |
|---|---|---|
| **M1** | Repozytorium, `composer.json`, struktura katalogów, `bin/check-rules.php`, `.htaccess`, vhost | `check-rules.php` przechodzi; `/` zwraca „szkielet działa"; `/.env` daje 403 na obu vhostach |
| **M2** | Bootstrap: błędy, `.env`, konfiguracja, nagłówki, sesja z własnym `save_path`, `TrustedProxy` | nagłówki z §13 widoczne w odpowiedzi; plik sesji powstaje w `var/sessions`; `error.twig` z identyfikatorem zdarzenia przy wymuszonym wyjątku |
| **M3** | Router + `PATH_INFO`, `config/routes.php`, walidacja „trasa bez uprawnienia = błąd", middleware CSRF | trasa bez `permission`/`auth` wywala start w trybie dev; POST bez tokenu → 419 z własną stroną; oba warianty adresów działają |
| **M4** | Migracje: `company`, `person`, `employment`, `account`, role i uprawnienia, tabele bezpieczeństwa, `tenant_setting`, `job` | `phinx migrate` i `rollback` przechodzą na czystej bazie; seed tworzy firmę, osobę z **dwoma** zatrudnieniami i administratora |
| **M5** | Logowanie, limitowanie prób, wylogowanie, timeout, reset hasła, zaproszenia, kolejka `job` + `bin/run-jobs.php` | scenariusze testowe z rozdziału 16 pkt 1–6 i 25 przechodzą |
| **M6** | 2FA: parowanie, QR SVG lokalnie, kody zapasowe, weryfikacja z oknem, reset przez administratora | scenariusze 7–11 przechodzą; sekret w bazie nieczytelny gołym okiem |
| **M6c** | sesje w bazie (handler + tabela `session`), unieważnianie, sprzątanie cronem | scenariusze 28–30 przechodzą; w `var/sessions` nie powstają już pliki |
| **M7** | Twig + htmx, dwa layouty, tokeny CSS, nawigacja, ekrany błędów, wybór firmy, „Twoje urządzenia", puste ramy ekranów biznesowych | `check-rules.php` czysty; CSP bez `unsafe-inline` nie zgłasza naruszeń w konsoli na żadnym ekranie |
| **M8** | `LicenseGate` z DTO, instalator z diagnostyką i weryfikacją HTTP, strona `/diagnostyka` | instalacja od zera na pustej bazie kończy się linkiem dla administratora; ponowne wejście do instalatora zablokowane |

M1–M3 idą po kolei. M4 można zacząć równolegle z M3. M5 i M6 wymagają M4. M7 wymaga M3.
M8 wymaga M4 i M2.

---

## 16. Scenariusze testowe (warunek odbioru)

Każdy wykonywany **na obu vhostach** — normalnym i „gorszym hostingu".

**Uwierzytelnianie**

1. Złe hasło i nieistniejący e-mail dają **identyczny** komunikat i zbliżony czas odpowiedzi.
2. Po 5 nieudanych próbach konto jest chwilowo zablokowane, ale inne konto z tego samego adresu
   **nadal działa** (progi konta i adresu są różne: 5/15 min i 30/15 min). Próba z listą zaufanych
   proxy pustą i wypełnioną daje różny wynik dla `X-Forwarded-For`.
3. Identyfikator sesji zmienia się po zalogowaniu (porównaj ciasteczko przed i po).
4. Sesja bezczynna ponad limit → wylogowanie z czytelnym komunikatem, nie 500.
5. Reset hasła: link jednorazowy, wygasa, a po zmianie hasła **druga, wcześniej otwarta sesja
   przestaje działać**.
6. Link z zaproszenia zużyty raz nie działa drugi raz.

**2FA**

7. Włączenie 2FA bez potwierdzenia kodem jest niemożliwe.
8. Ten sam kod TOTP użyty drugi raz jest odrzucany.
9. Kod z okna −2 jest odrzucany, z okna −1 przyjmowany.
10. Kod zapasowy działa raz; po użyciu znika z listy dostępnych.
11. Konto `tenant_admin` bez 2FA nie wchodzi nigdzie poza ekran parowania.

**Autoryzacja i kontekst**

12. Konto bez uprawnienia dostaje 403/404 także wtedy, gdy trafi na adres **bezpośrednio**
    (przycisk ukryty w szablonie niczego nie chroni).
13. Podmiana `employment_id` w sesji na cudzy identyfikator nie daje dostępu
    (weryfikacja zapytaniem przy każdym żądaniu).
14. Osoba z dwoma zatrudnieniami widzi przełącznik; osoba z jednym go nie widzi.
15. Sesja częściowo uwierzytelniona (po haśle, przed 2FA) nie otwiera żadnej trasy poza 2FA.

**Widok i reguły**

16. `bin/check-rules.php` kończy się bez trafień.
17. Konsola przeglądarki nie zgłasza naruszeń CSP na żadnym ekranie (włącznie z instalatorem).
18. Błąd 500 w żądaniu htmx pokazuje pasek komunikatu, a nie „kliknięcie, które nic nie robi".
19. Wygaśnięcie tokenu CSRF w żądaniu htmx przekierowuje na stronę 419, a nie wstawia jej w treść.
20. Wyłączenie JavaScriptu nie blokuje logowania ani nawigacji (formularze działają zwykłym POST-em).

**Wdrożenie**

21. `/.env`, `/config/config.php`, plik z `vendor/` i plik z `storage/` są niedostępne
    przez HTTP — sprawdzone **pobraniem**, nie obecnością `.htaccess`.
22. Krok **7** instalatora (weryfikacja HTTP) wykrywa celowo odsłonięty plik i **blokuje instalację**.
23. Po instalacji ponowne wejście do instalatora jest zablokowane znacznikiem spoza webroota.
24. `/diagnostyka` poprawnie raportuje brak ionCube Loadera na środowisku bez niego.
28. Zalogowanie się w drugiej przeglądarce pokazuje **dwie** pozycje na liście urządzeń;
    zakończenie tej drugiej odcina ją **przy następnym żądaniu**, nie po wygaśnięciu ciasteczka.
29. Użytkownik nie może zakończyć cudzej sesji, także podając jej identyfikator wprost.
30. W tabeli `session` nie ma jawnego identyfikatora sesji — wartość z ciasteczka nie występuje
    w bazie w żadnej kolumnie.
31. Po przekroczeniu progu konto **nie jest zamknięte**: kolejne próby są opóźniane, a poprawne
    hasło po odczekaniu wpuszcza użytkownika. Reset hasła działa mimo wyczerpanego licznika.
32. Po jednej nieudanej próbie na każdym z 20 różnych kont (żaden licznik konta ani adresu
    nie przekroczony) powstaje wpis w `event_log` i **jedno** zadanie alertu w kolejce;
    powtórzenie ataku w ciągu godziny nie dokłada kolejnych wiadomości.
33. Przy `MOBILE_ACCESS=app_only` wejście na `/m/wnioski` bez ciastka `wu_client` **przekierowuje**
    na `/wnioski` (nie 403, nie 404); z ciastkiem renderuje layout mobilny. Przy `open` oba
    warianty otwierają się w przeglądarce.
34. Żądanie htmx z wnętrza widoku mobilnego **nadal jest mobilne** — ciastko dociera tam,
    gdzie nagłówek by nie dotarł.
35. Wyczyszczenie ciastka `wu_client` w trakcie zalogowanej sesji nie zmienia wariantu —
    decyduje `session.client`. Na liście urządzeń ta sesja jest opisana jako aplikacja.
25. Reset hasła przy **wyłączonym** serwerze SMTP nie blokuje formularza: żądanie kończy się
    natychmiast, zadanie czeka w kolejce, `bin/run-jobs.php` ponawia je i odnotowuje błąd.
26. Link w wiadomości ma adres z `APP_URL` także wtedy, gdy żądanie przyszło z podstawionym
    nagłówkiem `Host`.
27. `.htaccess` na serwerze bez `mod_rewrite` nie powoduje 500 — aplikacja działa adresami
    z `PATH_INFO`.

---

## 17. Czego ten etap nie rozstrzyga

- **Wersja Encodera vs Loader u klienta** (dev 15.5.0, home.pl 14.4.1) — do sprawdzenia
  przed zakupem; wpływa na dopuszczalną składnię w `/src/License` i `/src/Core`.
- **A3 — mechanizm discovery** (statyczne podpisane pliki poza panelem) — rekomendacja czeka
  na decyzję; nie blokuje szkieletu.
- **A6 — język connectora** — zależny od dokumentacji R2.
- Broker `auth.wezurlop.pl` — w szkielecie tylko miejsce i przyciski.
- Push, SMS, załączniki, retencja i pełny log audytowy (Z1–Z4) — Etap 3.
- **home.pl nadal na PHP 8.1.30 (EOL)** — do przestawienia na 8.2+ przed pierwszym wdrożeniem.
- ~~Uzupełnienie `CLAUDE.md` §0 o `phpmailer/phpmailer`, `ext-iconv` i `ext-xmlwriter`~~ —
  **zrobione 16.09.2026**, pozycje są już w regułach.
- **Nazewnictwo**: angielski w kodzie i bazie, polski w interfejsie i adresach — do potwierdzenia
  przed M4, bo po pierwszych migracjach zmiana jest droga.
- Dwa brakujące ekrany (rozdział 9): „konto bez aktywnego zatrudnienia" i strona diagnostyczna.

---

## 18. Podsumowanie w jednym akapicie

Budujemy trzon: jeden punkt wejścia, jawne trasy z wymaganym uprawnieniem, łańcuch middleware,
który sprawdza CSRF, sesję, uwierzytelnienie, kontekst firmy i uprawnienie — zanim kontroler
zobaczy żądanie. Pod spodem model, w którym **konto należy do osoby, a uprawnienia do pary
(konto, firma)**, bo ta sama osoba bywa zatrudniona w dwóch firmach jednego tenanta. Nad spodem
dwa layouty Twiga zbudowane z tokenów istniejącego projektu graficznego, wymieniające fragmenty
przez htmx, bez jednej linii JS w szablonie. Na brzegach: instalator, który sam sprawdza, czy
serwer klienta nie odsłania `vendor/`, i `LicenseGate`, który od pierwszego dnia stoi w katalogu
przeznaczonym do zakodowania. Logiki urlopowej nie ma — i to jest zamierzone.
