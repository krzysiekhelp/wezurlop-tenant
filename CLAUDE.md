# Reguły projektu — PHP / MySQL / JS (bez frameworka, Twig)

Ten plik jest wiążący. Stosuj go przy **każdej** linii kodu, którą piszesz w tym projekcie,
bez przypominania. Jeśli reguła koliduje z prośbą użytkownika — powiedz o tym zanim napiszesz kod.

> **Zanim zaczniesz pracę w tym repozytorium, przeczytaj `docs/`:**
> `architektura.md` (bloki, technologie, przepływy), `specyfikacja.md` (co budujemy i w jakiej
> kolejności), `audyt.md` (znaleziska i decyzje otwarte). Ten plik mówi **jak** pisać;
> tamte mówią **co** i **dlaczego**.

---

## 0. Stack i założenia

- PHP 8.3+, MySQL 8 / MariaDB 10.6+, vanilla JS (bez frameworka frontendowego).
- **Bez frameworka backendowego.** Własny front controller, routing, kontrolery, widoki.
- **Ale nie bez bibliotek.** Rzeczy trudne i dobrze rozwiązane bierzemy z Composera —
  własna implementacja tego, co jest już zaudytowane przez tysiące projektów, to nie kontrola,
  tylko dług bezpieczeństwa.
- Kodowanie: UTF-8 (`utf8mb4`) wszędzie — pliki, baza, połączenie, nagłówki HTTP.
- `declare(strict_types=1);` na początku **każdego** pliku PHP.
- ⚠ **Nie „wersja docelowa", tylko ZAKRES: kod ma działać bez zmian na PHP 8.3 – 8.5.**
  8.3 jest **podłogą** — decyzja produktowa z 19.09.2026, świadomie kosztująca nas klasę
  hostingów, na których wyżej się nie da (`IdeaWebServer` w home.pl kończy się na 8.2;
  8.3 i 8.4 wymagają tam zmiany typu usługi na Apache). Sufit wyznacza **dostępność
  ionCube Loadera** u klienta, nie nasze potrzeby. Praktycznie:
  - ⚠ **poziom składni to 8.3** (rozstrzygnięcie 20.09.2026, po sprawdzeniu dokumentacji ionCube:
    Encoder 14 obsługuje składnię PHP 8.3, Encoder 15 — 8.4). Ograniczeniem nie jest podłoga
    instalacyjna, tylko **wersja PHP wspierana przez posiadany Encoder** (§15) — przy podłodze 8.3
    obie granice się pokrywają. Dotyczy całego kodu, nie tylko `/src/License` i `/src/Core`:
    jeden poziom składni w repozytorium jest tańszy niż pilnowanie granicy przy każdym
    przeniesieniu klasy. Wolno więc używać tego, co weszło w 8.3 (typowane stałe klas,
    `json_validate()`), a **nie wolno niczego z 8.4 i wyżej** — property hooks, asymetrycznej
    widoczności, `pipe`. ⚠ Gdyby kupiony Encoder okazał się starszy niż 14, ta reguła wraca do 8.2.
    ⚠ Zmiana otwiera możliwość, nie nakłada obowiązku: **istniejącego kodu nie przepisujemy**
    na nową składnię, bo refaktor dla samego refaktoru to ryzyko bez zysku,
  - unikamy też konstrukcji **wycofywanych wyżej** — przede wszystkim niejawnie nullowalnych
    parametrów (`string $x = null`), które od 8.4 są deprecjonowane; inaczej u klienta
    na nowszym PHP sypie się log ostrzeżeń,
  - ⚠ **górny koniec zakresu boli bardziej niż dolny**, bo hostingi popychają klientów
    w górę, nie w dół. `PHPCompatibility` z `testVersion 8.3-8.5` (`phpcs.xml.dist`) jest bramką
    blokującą build; to ona wyłapała `curl_close()` przestarzałe od 8.5. ⚠ To **jedyny** zestaw
    reguł — osobny przebieg „od 8.2” zniknął 20.09.2026 razem z podniesieniem poziomu składni,
  - 8.3 ma wsparcie bezpieczeństwa do **31.12.2027**; w wymaganiach piszemy „8.3 minimum,
    zalecane najnowsze PHP, do którego hosting ma Loadera",
  - testy przed wydaniem idą na **obu końcach zakresu** — na podłodze i na najwyższej wersji
    z dostępnym Loaderem. „Działa na dev" nie jest dowodem (§15).
- **Moduł licencji i krytyczna logika biznesowa będą docelowo kodowane ionCube Encoderem**
  i uruchamiane u klienta przez ionCube Loader — patrz §15. To nie jest krok „na koniec",
  tylko założenie architektoniczne od pierwszego pliku: wersja PHP, użyta składnia i podział
  na katalogi muszą to uwzględniać.

### Dozwolone (i oczekiwane) zależności

| Biblioteka | Do czego | Dlaczego nie własne |
|---|---|---|
| `twig/twig` | szablony/widoki | auto-escaping XSS domyślnie |
| htmx (plik JS) | wymiana fragmentów HTML | płynność bez SPA, bez drugiego API |
| `firebase/php-jwt` | weryfikacja tokenów Google/Apple | podpisy JWT i JWKS nie do napisania samemu |
| `robthree/twofactorauth` | 2FA (TOTP) dla kont lokalnych | RFC 6238, okna czasowe, tolerancja zegara |
| `bacon/bacon-qr-code` | kod QR do parowania 2FA | **lokalnie** — sekret nie może iść do zewnętrznego generatora |
| `nikic/fast-route` | routing | parsowanie URL-i bez niespodzianek |
| `paragonie/sodium_compat` | kryptografia (Ed25519, szyfrowanie) | **rozszerzenia `sodium` nie ma na wielu hostingach** — ta sama funkcja w czystym PHP, identyczne API |
| `vlucas/phpdotenv` | sekrety z `.env` | — |
| `robmorgan/phinx` | migracje bazy | wersjonowanie schematu |
| `phpmailer/phpmailer` | wysyłka poczty przez SMTP | `mail()` zakazane (patrz niżej); SMTP z uwierzytelnianiem, TLS i obsługą błędów nie jest rzeczą do pisania samemu |
| `ezyang/htmlpurifier` | **tylko** jeśli user wprowadza HTML | sanityzacja HTML jest nie do napisania samemu |
| `monolog/monolog` | logowanie | opcjonalnie, jeśli logi rosną |

Nowych zależności nie dodajesz bez pytania. Każda nowa paczka to powierzchnia ataku
(supply chain) — uzasadnij, zanim zaproponujesz.

### Rozszerzenia PHP — lista wymagań ma być krótka

Produkt instaluje się na hostingach, których nie kontrolujemy. **Każde twardo wymagane
rozszerzenie to potencjalnie klient, u którego aplikacja się nie zainstaluje.**

| Twardo wymagane | Zalecane, nieblokujące |
|---|---|
| `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `iconv`, `xmlwriter` | `intl`, `zip`, `gd`, `opcache`, `sodium` |

⚠ `iconv` i `xmlwriter` to **twarde wymagania `bacon/bacon-qr-code` 2.x** (renderer SVG używa
`XMLWriter`), czyli biblioteki, bez której nie sparujemy 2FA lokalnie. Oba są w PHP włączone
domyślnie i praktycznie zawsze obecne, ale skoro są wymagane — mają być na liście i w diagnostyce.
Lista wymagań ma być **prawdziwa**, nie tylko krótka: wymaganie przemilczane objawia się u klienta
niedziałającą funkcją zamiast czytelnym komunikatem.

- Zanim oprzesz cokolwiek na rozszerzeniu spoza pierwszej kolumny — **sprawdź, czy istnieje
  wariant działający bez niego**, i zapytaj, zanim dopiszesz nowe wymaganie.
- Kryptografia idzie przez `paragonie/sodium_compat`, nie przez `ext-sodium` wprost.
- Kod QR generujemy jako **SVG**, żeby nie wymagać `gd`.
- Rozpakowanie paczki aktualizacji: `zip`, a gdy go brak — `PharData` z rdzenia PHP.

### Uniwersalność hostingu — założenie produktowe

Aplikacja instaluje się na hostingach, których nie kontrolujemy i nie widzimy przed sprzedażą.
**Każde założenie o konfiguracji serwera to klient, u którego coś nie zadziała.**
Środowisko deweloperskie stoi na Apache'u, ale kod nie ma prawa tego zakładać.

**Serwer WWW**

- Zakaz funkcji dostępnych tylko pod mod_php: `apache_request_headers()`, `apache_getenv()`,
  `apache_setenv()`, `virtual()`. Nagłówki czytamy z `$_SERVER` (`HTTP_*`) albo przez
  własny, przenośny helper. Aplikacja ma działać pod mod_php, FPM, FastCGI i CGI tak samo.
- ⚠ **Nagłówek `Authorization` bywa wycinany pod CGI/FastCGI.** Dotyczy to REST API dla
  connectora i wywołania `redeem` do brokera — czyli miejsc, gdzie leci klucz. Dlatego:
  w paczce jedzie reguła `.htaccess` przywracająca ten nagłówek, a serwer **dodatkowo
  akceptuje klucz w nagłówku `X-Api-Key`** jako ścieżkę zapasową. Bez tego integracja
  „nie działa" u klienta bez żadnego sensownego komunikatu.
- Routing: `.htaccess` z przepisaniem wszystkich żądań na `public/index.php`, ale
  **router musi wyprowadzać ścieżkę odpornie** — z `REQUEST_URI`, a gdy przepisywanie
  nie działa, z `PATH_INFO` (adresy w postaci `/index.php/wnioski/12`). Brzydkie, ale działa
  wszędzie i ratuje wdrożenie, zamiast je blokować.

**Układ katalogów przy wdrożeniu**

Paczka musi dać się zainstalować na **oba** sposoby, bo nie każdy hosting pozwala wskazać
katalog domeny:

1. katalog domeny ustawiony na `public/` — wariant preferowany
2. aplikacja **powyżej** katalogu domeny, a w katalogu domeny wyłącznie zawartość `public/`

Instalator wykrywa sytuację albo pyta. Ścieżki do `storage/`, `var/` i `.env` **konfigurowalne**,
nie wyliczane sztywno z `__DIR__`.

- ⚠ **Ścieżka aplikacji w wariancie 2 szuka się najpierw w pliku, potem w środowisku.**
  Kolejność w `public/index.php`: `public/app-path.php` (plik pisany przez instalator) →
  `getenv('WEZURLOP_APP_PATH')` → `dirname(__DIR__)`. `SetEnv` nie jest gwarantowane
  na serwerach innych niż Apache (np. IdeaWebServer home.pl nie ma tego w dokumentacji),
  a milczenie serwera daje front controller, który nie znajdzie `vendor/`.

**Profil serwera — deklaracja nie jest dowodem**

⚠ Istnieje klasa hostingów, które `.htaccess` przyjmują, **nie zgłaszają błędu i nic nie robią**
(zmierzone: `IdeaWebServer` w home.pl ignoruje `RedirectMatch` i `Require`, a `<FilesMatch>`
nie działa u niego wcale — trzeba `<Files ~ "…">`). Stąd trzy reguły:

- blokady opieramy **wyłącznie** na `RewriteRule … [F]` — jedynej dyrektywie działającej
  tak samo w obu światach; `RedirectMatch` to ścieżka zapasowa dla serwerów bez `mod_rewrite`,
  nie druga warstwa,
- pliki `.htaccess` są **per profil serwera** (`apache`, `ideawebserver`, `minimal`), a nie
  jednym plikiem z `<IfModule>`: `Order`/`Deny` działa na IdeaWebServer, a na Apache
  przy `AllowOverride FileInfo` daje **500**, więc lekarstwo z jednego profilu jest trucizną
  w drugim,
- `SERVER_SOFTWARE` wybiera **profil kandydujący**, rozstrzyga **wynik pobrania po HTTP**.
  Obecność reguły w `.htaccess` nie dowodzi niczego. Profil nigdy nie wpływa na decyzje
  o uprawnieniach — tylko na treść `.htaccess`, mechanizm crona i przekazanie ścieżki.

Szczegóły i zmierzone zachowania: `docs/profile-hostingu.md`.

**Baza danych**

- Kolacja `utf8mb4_unicode_ci` — działa na MySQL 5.7, 8 i MariaDB.
  **Nie** `utf8mb4_0900_ai_ci`, bo to tylko MySQL 8.
- Bez składni specyficznej dla jednego silnika. Bez funkcji okienkowych, jeśli nie ma pewności
  co do minimalnej wersji.

**Limity wykonania**

- Zakładamy `max_execution_time` rzędu **30 sekund**. Synchronizacja, migracje, wysyłki
  i każde zadanie cron **dzielone na porcje** z zapamiętaniem miejsca przerwania.
  Nic nie może wymagać „jednego długiego przebiegu".
- Częstotliwość crona bywa ograniczona. Rzadszy cron ma oznaczać **wolniejszą eskalację**,
  nigdy błąd ani zgubione zadanie.
- ⚠ **Cron nie zawsze jest crontabem.** Na hostingach klasy home.pl zadanie cykliczne to
  **plik o narzuconej nazwie w katalogu domeny** (`cron-05min.php`), uruchamiany **przez HTTP**.
  Wynika z tego: obowiązuje limit 30 s (porcje są warunkiem działania, nie przezornością),
  plik jest osiągalny z internetu (blokada w konfiguracji serwera **plus** lock i minimalny
  odstęp przebiegów w PHP), a sam plik to **shim** — jeden `require` runnera z katalogu
  aplikacji, zero logiki i zero konfiguracji. To świadomy, drugi wyjątek od §2.
- ⚠ **Poczta z linkiem nie może czekać na rzadki cron.** Link resetu hasła ważny 15–60 minut
  przy cronie godzinowym dotarłby na granicy ważności. Gdy instalator nie potwierdzi crona
  częstszego niż 15 minut, włącza się drugi wyzwalacz kolejki: **po wysłaniu odpowiedzi**
  na zwykłe żądanie, z lockiem, jednym zadaniem i budżetem ~2 s, wyłącznie dla zadań pilnych.

**Pozostałe**

- Poczta wyłącznie przez SMTP (PHPMailer), **nigdy `mail()`** — na hostingach współdzielonych
  bywa wyłączone albo trafia do spamu.
- ⚠ **Wysyłka idzie przez kolejkę zadań i cron, nigdy w żądaniu użytkownika.** Przy limicie
  30 sekund zamulony albo niedostępny serwer SMTP zawiesza formularz na kilkadziesiąt sekund
  i potrafi go wywrócić na timeoucie — a użytkownik widzi błąd tam, gdzie operacja się udała.
  Żądanie zapisuje zadanie i kończy się od razu; runner cron je wysyła, ponawia i loguje błędy.
- Lista zaufanych proxy konfigurowalna (§9) — instalacja może stać za Cloudflare lub innym proxy.
- Nie zakładaj, że `exec` i pokrewne działają; na wielu hostingach są wyłączone (i tak zakazane w §14).
- Nie zakładaj dostępu do `/tmp` ani do katalogów poza kontem — pliki tymczasowe w `var/`.

---

## 1. Paradygmat: obiektowo, ale bez przesady

**Decyzja: kod obiektowy dla warstwy aplikacji, funkcje tylko dla czystych helperów.**

Dlaczego obiektowo: kontrolery, repozytoria i usługi mają zależności (połączenie z bazą,
sesja, konfiguracja). Strukturalnie kończy się to zmiennymi globalnymi i `include`ami,
a globalny stan to najczęstsza przyczyna dziur w bezpieczeństwie — nigdy nie wiadomo,
co już zostało zwalidowane.

Zasady konkretne:

- Klasy: `Controller`, `Repository`, `Service`, `Entity/DTO`, `Middleware`. Jedna klasa = jeden plik.
- Zależności przez **konstruktor**, nigdy przez `global` ani singleton.
- **Zakaz `global`, zakaz `$GLOBALS`, zakaz zmiennych globalnych.**
- Funkcje globalne dopuszczalne wyłącznie dla bezstanowych helperów — bez dostępu do bazy.
- Autoloading: PSR-4 przez Composer. Zakaz `require` rozsianego po kodzie.
- Bez kontenera DI, bez interfejsów „na zapas", bez wzorców stosowanych dla samych wzorców.
  Piszemy aplikację, nie własny framework.

---

## 2. Struktura katalogów

```
/public                 <- JEDYNY katalog dostępny z internetu (DocumentRoot)
  index.php             <- front controller, jedyny wykonywalny plik PHP
  /assets
    /css/*.css
    /js/*.js
    /img
/src
  /Controller
  /Repository
  /Service
  /Entity
  /Security           <- Csrf, Auth, Password, RateLimiter
  /Http               <- Request, Response, Router
  /Support            <- helpers, TwigFactory
  /License            <<< STREFA KODOWANA ionCube (§15)
  /Core               <<< STREFA KODOWANA ionCube — krytyczna logika biznesowa
/templates              <- szablony Twig (.twig)
  /layout
  /partials
/storage
  /logs
  /uploads            <- POZA webrootem
/var
  /cache/twig         <- cache Twiga, POZA webrootem, w .gitignore
/config
  config.php            <- czyta z .env, nie zawiera sekretów
  routes.php
/db/migrations
/build                  <- wynik kodowania Encoderem, w .gitignore, NIGDY w repo
.env                    <- POZA repo (.gitignore)
```

Reguły twarde:

- **Nic poza `/public` nie jest dostępne przez HTTP.** Jeśli hosting nie pozwala ustawić
  DocumentRoot na `/public`, zabezpiecz katalogi `.htaccess`/`deny from all` i powiedz o tym wprost.
- ⚠ **W paczce dla klienta jedzie `.htaccess` blokujący wszystko poza `public/`** — pliki
  z kropką (`.env`, `.git`), a także `vendor/`, `storage/`, `src/`, `config/`, `templates/`,
  `composer.json`. Powód nie jest teoretyczny: `vendor/` w katalogu dostępnym z internetu to
  **znany wektor zdalnego wykonania kodu** (skanery sprawdzają te ścieżki rutynowo),
  a `storage/uploads/` oznacza pobieranie załączników **z pominięciem kontroli uprawnień** —
  serwer podaje plik, zanim aplikacja zdąży cokolwiek sprawdzić.
- ⚠ **W `.htaccess` nie używamy `Require` ani innych dyrektyw z klasy `AuthConfig`.** Sprawdzone
  na maszynie deweloperskiej: przy `AllowOverride FileInfo` Apache nie ignoruje takiej dyrektywy,
  tylko zwraca **500 na każdym żądaniu** („Require not allowed in `<FilesMatch>` context").
  U klienta oznacza to aplikację, która nie startuje, i instalator, który nie zdąży pokazać
  komunikatu. `<IfModule>` nie pomaga — to kwestia uprawnienia nadpisywania, nie modułu.
  Blokujemy wyłącznie `RewriteRule ... [F]` i `RedirectMatch 404`, obudowanymi w `<IfModule>`.
- ⚠ **Blokada plików z kropką przez sam serwer to przypadek, nie cecha hostingu.** Zmierzone
  u jednego dostawcy na dwóch usługach: `IdeaWebServer` odmawia `.env` sam z siebie (403),
  a usługa „Apache" tego samego dostawcy **podaje go z treścią** (200). Nigdy nie opieramy
  ochrony `.env` na zachowaniu serwera — tylko na naszych regułach **potwierdzonych pobraniem
  po HTTP**, a `.env` i tak leży poza katalogiem dostępnym z internetu.
- ⚠ **`/.well-known/` zawsze przepuszczone.** Blokada „wszystkiego z kropką" wycina
  `/.well-known/acme-challenge/`, czyli odnawianie certyfikatu Let's Encrypt. Objawia się raz
  na trzy miesiące, u klienta, jako wygasły certyfikat — i nikt nie wiąże tego z `.htaccess`.
- ⚠ **Diagnostyka instalatora sprawdza wynik, nie obecność pliku.** Próbuje pobrać po HTTP
  `.env`, `composer.json`, plik z `vendor/` i plik ze `storage/`. Każda odpowiedź z treścią to
  **błąd blokujący instalację**, z komunikatem, że katalog domeny trzeba przestawić na `public/`.
  Powód: blokada katalogów wymaga `mod_rewrite`; bez niego `.htaccess` jest na miejscu,
  ochrona nie działa, i nikt się o tym nie dowiaduje.
- W `/public` nie ma żadnego pliku `.php` poza `index.php`.
- Żaden plik nie „wykonuje się" przy dołączeniu — pliki klas tylko definiują klasy.
- `/var/cache/twig` nigdy nie trafia do repozytorium ani pod webroot.

---

## 3. Routing i kontrolery

- Jeden punkt wejścia: `public/index.php` → bootstrap → Router → Controller → Twig.
- Trasy definiowane **jawnie**, w jednym miejscu (`config/routes.php`):
  metoda HTTP + ścieżka + kontroler + akcja + wymagane uprawnienie.
- **Zakaz dynamicznego dispatchowania z URL-a** (`new $_GET['class']`, `$controller->$_GET['action']()`,
  `call_user_func($_GET[...])`). To zdalne wykonanie kodu.
- Kontroler: przyjmuje żądanie, waliduje, woła Service/Repository, zwraca Response lub renderuje szablon.
  **Kontroler nie zawiera SQL i nie zawiera HTML.**
- Każda trasa modyfikująca dane = `POST`/`PUT`/`DELETE`. **Nigdy GET dla operacji zmieniających stan.**
- Po udanym POST: `redirect` (POST/Redirect/GET).
- Kontroler przekazuje do szablonu **tylko to, co szablon ma wyświetlić** — nigdy całej encji
  użytkownika z hashem hasła, nigdy surowego wyniku zapytania z kolumnami technicznymi.

---

## 4. Baza danych — SQL Injection

- Wyłącznie **PDO**, połączenie:
  ```php
  $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,   // obowiązkowo
      PDO::ATTR_STRINGIFY_FETCHES  => false,
  ]);
  ```
  DSN zawiera `charset=utf8mb4`.
- **Każda** wartość trafia do zapytania przez placeholder (`:nazwa`). Zero konkatenacji, zero
  interpolacji `"... WHERE id = $id"`, zero `sprintf` w SQL.
- Nazw tabel, kolumn i kierunku sortowania **nie da się** sparametryzować — użyj **białej listy**:
  ```php
  $allowed = ['created_at' => 'created_at', 'name' => 'name'];
  $col = $allowed[$_GET['sort'] ?? 'created_at'] ?? 'created_at';
  $dir = ($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
  ```
- `LIMIT`/`OFFSET`: rzutuj na `int` i ogranicz zakres.
- Zakaz `mysqli_query` z doklejanym wejściem, zakaz `mysql_*`, zakaz `addslashes`
  i `real_escape_string` jako „zabezpieczenia".
- SQL mieszka **wyłącznie** w klasach `Repository`.
- Konto MySQL aplikacji ma **docelowo** tylko `SELECT, INSERT, UPDATE, DELETE` na jednej bazie —
  bez `DROP`, `ALTER`, `FILE`, bez `root` — a migracje puszcza osobne konto z DDL.
  To wariant **zalecany i domyślny**, gdy hosting na niego pozwala.
- ⚠ **Tryb jednego konta jest dopuszczalny, bo na części hostingów rozdziału zrobić NIE MOŻNA.**
  Na home.pl nazwa użytkownika bazy jest równa nazwie bazy, konto nie ma `CREATE USER`
  ani `GRANT OPTION`, a panel nie dodaje drugiego użytkownika do istniejącej bazy. Wymóg
  „dwa konta", którego klient nie jest w stanie spełnić, nie jest zabezpieczeniem — jest
  powodem, dla którego ktoś wpisze do `.env` konto z pełnymi prawami i nic nie odnotuje.
  Dlatego rozdział kont jest **zalecany**, a nie wymagany, i obowiązuje tak:
  - instalator przyjmuje drugą parę poświadczeń jako **opcjonalną**; jej brak zapisuje
    jawnie jako `DB_SINGLE_ACCOUNT=1` w `.env`, wpisem w `event_log` i **trwałą pozycją
    na stronie `/diagnostyka`** — stan obniżony ma być widoczny, nie przemilczany,
  - twardym wymaganiem wobec konta bazy zostaje **tylko DDL** (bez niego nie ma migracji),
  - w tym trybie **obowiązkowo**: prawdziwe zapytania przygotowane z `ATTR_EMULATE_PREPARES => false`
    (MySQL nie wykona wtedy dwóch poleceń w jednym wywołaniu, więc wstrzyknięcie nie dołoży
    `DROP`-a — to jest realny powód, dla którego ten tryb jest znośny), **zakaz DDL w czasie
    pracy aplikacji** z bezpiecznikiem odrzucającym i logującym taką próbę, **kopia zapasowa
    przed każdą aktualizacją** i `.env` z prawami `0640` poza webrootem,
  - ⚠ jeśli konto działa z dowolnego adresu (`@'%'`, typowe na hostingu współdzielonym),
    wyciek `.env` oznacza dostęp do danych kadrowych **z zewnątrz**. To argument za pytaniem
    hostingodawcy o ograniczenie dostępu zdalnego, a nie za rezygnacją z trybu.
- ⚠ **Gdy baza nie jest lokalna, połączenie musi być szyfrowane.** Na hostingach współdzielonych
  baza często stoi na osobnym, **publicznie rozwiązywalnym** hoście na niestandardowym porcie
  (home.pl: `addconnect.home.pl:3380`, konto z `@'%'`). Bez TLS hasła, PESEL-e i dane kadrowe
  jadą po tej sieci jawnym tekstem. Dlatego: instalator sprawdza `Ssl_cipher`, a DSN aplikacji
  wymusza TLS, gdy host bazy nie jest `localhost`. Jeśli hostingodawca nie udostępnia CA,
  szyfrowanie bez weryfikacji certyfikatu jest **wyraźnie lepsze niż jawny tekst**, ale chroni
  tylko przed podsłuchem, nie przed aktywnym atakiem w tej sieci — i tak to nazywamy
  w dokumentacji wdrożenia, bez zaokrąglania do „jest bezpiecznie".
- Zmiany schematu **wyłącznie** przez migracje (phinx), nigdy ręcznie w phpMyAdmin.
  Migracje uruchamia instalator i aktualizator — **nigdy** zwykłe żądanie aplikacji,
  nawet gdy konto technicznie by na to pozwalało.
- ⚠ **Phinx potrafi przestawić kolejność operacji zebranych w jednym `update()`** — zmiana,
  która „logicznie" idzie po innej, bywa wykonana przed nią i migracja przerywa się w połowie,
  zostawiając bazę w stanie pośrednim. Dlatego **jedna zmiana = jedno wywołanie**, a migracje
  dzielimy tak, żeby każdy krok był samodzielny. Sprawdzone przy Etapie 3: `migrate` → `rollback`
  → `migrate` na czystej bazie to warunek odbioru, nie formalność.
- Operacje wielokrokowe w transakcji (`beginTransaction` / `commit` / `rollBack` w `finally`).
- ⚠ **`UNIQUE` nie działa na kolumnie z `NULL`.** MySQL i MariaDB pozwalają wstawić dowolną
  liczbę wierszy z `NULL` w kolumnie klucza unikalnego. Wszędzie, gdzie `NULL` miałby znaczyć
  „wszystkie" albo „cały zakres" (np. uprawnienie nieprzypisane do konkretnej firmy), używamy
  wartości specjalnej `NOT NULL DEFAULT 0` — inaczej indeks niczego nie pilnuje, a duplikaty
  wychodzą dopiero przy audycie uprawnień.

---

## 5. Widoki i XSS — Twig

Twig jest **jedynym** sposobem generowania HTML. Zakaz `echo` HTML-a z PHP,
zakaz plików `.php` w `/templates`, zakaz `sprintf` budującego znaczniki.

### Konfiguracja (obowiązkowa)

```php
$twig = new \Twig\Environment(
    new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'),
    [
        'autoescape' => 'html',                 // NIGDY false
        'strict_variables' => true,             // brakująca zmienna = błąd, nie cicha pustka
        'cache' => __DIR__ . '/../var/cache/twig',
        'debug' => $isDev,
    ]
);
```

- `autoescape` **nigdy** nie jest wyłączane — ani globalnie, ani przez `{% autoescape false %}`.
- `strict_variables => true` wyłapuje literówki w nazwach zmiennych zamiast renderować pustkę.
- W produkcji `debug => false` i włączony cache.

### Escapowanie

- `{{ zmienna }}` jest escapowane automatycznie. To jest sens używania Twiga — nie obchodź tego.
- **`|raw` jest zakazane.** Wyjątek: treść przepuszczona przez HTML Purifier, i tylko taka.
  Każde użycie `|raw` musi mieć komentarz z uzasadnieniem tuż nad nim. W code review
  `|raw` to czerwona flaga traktowana jak potencjalny XSS, dopóki nie udowodnisz inaczej.
- Escapowanie jest **kontekstowe** — podawaj strategię tam, gdzie kontekst to nie zwykły HTML:
  ```twig
  <a href="/search?q={{ query|url_encode }}">...</a>
  <div title="{{ title }}">...</div>          {# atrybut zawsze w cudzysłowie #}
  ```
- Zakaz `{{ ... }}` wewnątrz `<script>` (patrz §6) i wewnątrz `style=`.
- Zakaz `include` ze zmienną pochodzącą z wejścia użytkownika (`{% include user_input %}`) —
  to SSTI/LFI. Nazwy szablonów zawsze literalne albo z białej listy.
- Zakaz `\Twig\Environment::createTemplate()` na danych użytkownika. Użytkownik **nigdy**
  nie dostarcza szablonu.

### Zawartość szablonów

- Szablon zawiera HTML, pętle, warunki wyświetlania i formatowanie. **Nic więcej.**
- Zero zapytań do bazy, zero wywołań serwisów, zero logiki biznesowej.
  Jeśli szablon czegoś potrzebuje — kontroler ma mu to podać gotowe.
- Dziedziczenie: `layout/base.twig` z `{% block content %}`. Bez `include('header')` + `include('footer')`.
- Do Twiga rejestrujemy tylko wąskie, bezpieczne funkcje globalne (`csrf_token()`, `asset()`,
  `current_user()`). **Nie** rejestrujemy nic, co wykonuje zapytania lub operacje na plikach.

### Fragmenty htmx

Interfejs działa na wymianie fragmentów HTML generowanych przez Twiga. Zasady:

- Fragmenty mieszkają w `/templates/fragments`, renderowane **bez layoutu**. Jeden fragment =
  jeden plik. Nie renderujemy fragmentów przez wycinanie kawałków pełnego widoku.
- **Endpoint zwracający fragment to pełnoprawny endpoint.** Sprawdza zalogowanie, uprawnienie
  i własność zasobu dokładnie tak samo jak zwykła akcja. Nie istnieje pojęcie „endpointu
  wewnętrznego, bo używa go tylko htmx".
- Nagłówek `HX-Request` **nie jest zabezpieczeniem** — każdy może go wysłać. Nie podejmuj na jego
  podstawie decyzji o uprawnieniach; najwyżej o formie odpowiedzi.
- `hx-get` wyłącznie do odczytu. Każda operacja zmieniająca stan to `hx-post`, `hx-put`
  lub `hx-delete` (§3).
- CSRF: token wstrzykiwany raz, globalnie, przez `hx-headers` na elemencie `<body>`;
  middleware sprawdza nagłówek `X-CSRF-Token` tak samo jak pole formularza (§7).
- **Zakaz `hx-on:*` i wszystkiego, co htmx wykonuje z tekstu** (`hx-vals` w formie `js:...`,
  wyrażenia inline). To wymaga `unsafe-eval` w CSP i łamie §6 oraz §13.
  Zdarzenia obsługujemy w plikach JS przez `addEventListener`, nasłuchując zdarzeń htmx
  (`htmx:afterSwap`, `htmx:responseError`).
- Atrybuty `hx-*` w szablonie są dozwolone — to deklaracja żądania, nie kod JavaScript.
  Zakaz z §6 dotyczy `on*=`, `style=` i skryptów, i obowiązuje bez zmian.
- Odpowiedź błędna (4xx/5xx) nie jest domyślnie wstawiana do strony. Każdy ekran musi mieć
  obsługę `htmx:responseError` pokazującą komunikat — inaczej kliknięcie po prostu nic nie robi.
- Fragment zwracany po zmianie danych renderuje **aktualny stan z bazy**, a nie to, co przyszło
  w żądaniu. Inaczej interfejs pokaże sukces tam, gdzie zapis się nie udał.
- Przekierowania po zapisie: nagłówek `HX-Redirect` albo `HX-Location`, nie `Location`.
- ⚠ **htmx sam wstrzykuje `<style>` do `<head>`** (reguły `.htmx-indicator`), co przy CSP
  `style-src 'self'` bez `unsafe-inline` daje naruszenie na każdym ekranie. W layoucie:
  `<meta name="htmx-config" content='{"includeIndicatorStyles":false}'>`, a reguły
  `.htmx-indicator` w naszym pliku CSS. Bez tego pierwszy odruch będzie taki, żeby rozluźnić
  CSP — czyli rozbroić §6 i §13 z powodu paska ładowania.

---

## 6. Separacja JS / CSS / HTML (wymóg twardy)

- **Zero JS w szablonie.** Zakaz `onclick=`, `onchange=`, `onsubmit=` i pozostałych `on*`.
  Zakaz `href="javascript:..."`. Zdarzenia wyłącznie przez `addEventListener`
  w plikach `/public/assets/js/*.js`.
- **Zero CSS w szablonie.** Zakaz atrybutu `style="..."`, zakaz `<style>` w szablonach.
  Wszystko w `/public/assets/css/*.css`. Stan wizualny zmieniasz klasą, nie stylem inline.
- **Zero `<script>` z kodem w szablonach.** Tylko `<script src="..." defer>`.
- Dane z PHP do JS przekazujesz przez **atrybuty `data-*`** albo endpoint JSON — nie przez
  wstrzykiwanie do skryptu:
  ```twig
  <div id="app" data-user-id="{{ user.id }}"></div>
  ```
  ```js
  const userId = document.getElementById('app').dataset.userId;
  ```
  Jeśli absolutnie musi być JSON w HTML, to tylko w `<script type="application/json">`
  i przez `{{ data|json_encode|raw }}` — jedyny dopuszczalny `|raw` poza HTML Purifierem,
  i tylko wewnątrz takiego bloku.
  ⚠️ Auto-escaping Twiga **nie chroni wewnątrz `<script>`** — kontekst JS ma inne reguły
  niż HTML. Dlatego reguła brzmi: danych do skryptów nie wstrzykujemy w ogóle.
- To nie jest tylko kwestia porządku: dzięki temu można włączyć CSP bez `unsafe-inline`,
  co wycina większość XSS-ów nawet przy błędzie gdzie indziej.

W JS:

- **Zakaz `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`,
  `new Function`, `setTimeout("string")`.** Tekst wstawiasz przez `textContent`,
  elementy przez `createElement`.
- Zakaz budowania HTML ze stringów z danymi z serwera.
- Walidacja po stronie JS jest **wyłącznie** dla wygody użytkownika. Serwer waliduje zawsze od nowa.

---

## 7. CSRF

- Token **w sesji**, generowany raz na sesję (lub per formularz przy operacjach krytycznych):
  ```php
  // Security/Csrf.php
  public static function token(): string {
      if (empty($_SESSION['csrf'])) {
          $_SESSION['csrf'] = bin2hex(random_bytes(32));
      }
      return $_SESSION['csrf'];
  }
  public static function check(?string $sent): void {
      if (!is_string($sent) || empty($_SESSION['csrf'])
          || !hash_equals($_SESSION['csrf'], $sent)) {
          http_response_code(419);
          exit('CSRF token mismatch');
      }
  }
  ```
- `random_bytes` — nigdy `rand`, `mt_rand`, `uniqid`.
- Porównanie **wyłącznie** `hash_equals` (odporne na timing).
- Funkcja `csrf_token()` zarejestrowana globalnie w Twigu; w każdym formularzu POST
  lepiej użyć gotowego partiala:
  ```twig
  {# templates/partials/csrf.twig #}
  <input type="hidden" name="_csrf" value="{{ csrf_token() }}">
  ```
  ```twig
  <form method="post" action="/employee/save">
    {% include 'partials/csrf.twig' %}
    ...
  </form>
  ```
- Weryfikacja **centralnie w middleware** dla wszystkich żądań niebędących `GET`/`HEAD`/`OPTIONS`,
  a nie ręcznie w każdym kontrolerze — ręcznie zawsze gdzieś się zapomni.
- AJAX: token w nagłówku `X-CSRF-Token`, middleware sprawdza `$_POST['_csrf']` **lub** nagłówek.
- Ciasteczko sesji: `SameSite=Lax` (lub `Strict`). To druga warstwa, nie zamiennik tokenu.

---

## 8. Sesje

- Konfiguracja **przed** `session_start()`:
  ```php
  session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',
      'secure'   => true,      // wymaga HTTPS
      'httponly' => true,
      'samesite' => 'Lax',
  ]);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  session_start();
  ```
- ⚠ **Sesje trzymamy w bazie**, przez własny handler na `SessionHandlerInterface`
  (+ `SessionUpdateTimestampHandlerInterface` i `session.lazy_write`, żeby nie zapisywać
  przy każdym żądaniu). Powód nie jest wydajnościowy, tylko funkcjonalny: **sesji plikowych
  nie da się wyliczyć dla konta**, więc bez rejestru nie istnieje ani lista aktywnych urządzeń,
  ani wylogowanie jednego z nich, ani natychmiastowe unieważnienie dostępu zwolnionemu
  pracownikowi. Przy okazji znika problem współdzielonego katalogu sesji na hostingu.
  Sesja plikowa zostaje wyłącznie tam, gdzie baza jeszcze nie działa — w instalatorze.
- ⚠ **Własny `session.save_path` poza webrootem** (`var/sessions`, prawa 700), ustawiany przed
  `session_start()`. Na hostingu współdzielonym domyślny katalog sesji bywa **wspólny dla wielu
  kont** — plik sesji zawiera `user_id` i stan uwierzytelnienia, więc sąsiad na tym samym serwerze
  czyta go jak swój i przejmuje konto. Diagnostyka instalatora sprawdza, że katalog istnieje,
  jest zapisywalny i **nie odpowiada przez HTTP**. Dodatkowo: na wielu hostingach garbage collector
  sesji jest wyłączony (`session.gc_probability=0`), więc katalog czyścimy własnym zadaniem cron.
- `session_regenerate_id(true)` **po każdym logowaniu** i po zmianie uprawnień (fixation).
- Wylogowanie: czyść `$_SESSION`, usuń ciasteczko, `session_destroy()`.
- Użytkownik ma widzieć **swoje aktywne sesje** (kiedy powstała, ostatnia aktywność, przeglądarka,
  przybliżony adres) i móc zakończyć każdą z nich oraz wszystkie naraz. Zakończenie działa
  **natychmiast**, przy następnym żądaniu tamtego urządzenia — a nie po wygaśnięciu ciasteczka.
  Identyfikator sesji w bazie trzymamy jako **hash**, nie jawnie: dostęp do odczytu tabeli
  nie może dawać gotowego ciasteczka do podszycia się.
- Timeout bezczynności (np. 30 min) + absolutny czas życia sesji.
- Zakaz ID sesji w URL.
- W sesji trzymasz `user_id` i minimum danych — nigdy hasła, nigdy całego obiektu użytkownika
  z uprawnieniami (uprawnienia czytaj z bazy albo odświeżaj).

---

## 9. Hasła i uwierzytelnianie

- `password_hash($p, PASSWORD_DEFAULT)` + `password_verify()`. Nic innego.
  Zakaz `md5`, `sha1`, `crypt`, własnego solenia.
- `password_needs_rehash()` przy logowaniu → przehashuj.
- Brak limitu długości hasła (min. 12 znaków, max ≥ 64). Nie obcinaj haseł.
- ⚠ **bcrypt czyta tylko pierwsze 72 bajty** — a `PASSWORD_DEFAULT` to bcrypt. Przy polskich
  znakach (2 bajty w UTF-8) limit wypada poniżej 40 znaków, czyli poniżej naszego własnego
  minimum. Dlatego hasło **przepuszczamy przez SHA-256 i base64 przed bcryptem**:
  ```php
  // Security/Password.php — jedyne miejsce, gdzie to się dzieje
  private static function prepare(string $plain): string {
      return base64_encode(hash('sha256', $plain, true));   // zawsze 44 bajty ASCII
  }
  ```
  `base64` jest konieczne: surowy skrót binarny zawiera bajty zerowe, a bcrypt ucina hasło
  na pierwszym z nich. To standardowe rozwiązanie (tak robi m.in. Dropbox), nie własna
  kryptografia — nie zastępuje bcrypta, tylko podaje mu wejście o stałej długości.
  **Bez pieprzu (peppera) z `.env`**: utrata klucza unieważniłaby wszystkie hasła w instalacji.
  Hasła dłuższe niż 72 bajty mają po prostu działać, a nie być odrzucane komunikatem.
- **Argon2 odpada** mimo że jest lepszy: `PASSWORD_ARGON2ID` wymaga PHP zbudowanego z obsługą
  argon2, a produkt instaluje się na hostingach, których nie znamy. Hash Argon2 zapisany
  u jednego hostingodawcy przestaje dać się zweryfikować po przeprowadzce do drugiego —
  czyli wszyscy użytkownicy tenanta tracą dostęp. Bcrypt jest wszędzie.
- **Komunikaty błędów nie zdradzają, czy login istnieje.** Zawsze „Nieprawidłowy login lub hasło".
- Rate limiting logowania: per konto **i** per IP. Licznik w bazie, nie w sesji
  (atakujący sesji nie ma).
- ⚠ **Rosnące opóźnienie, nie twarda blokada.** Skoro kilka błędnych prób zamyka konto,
  to każdy, kto zna czyjś adres e-mail, potrafi je zamknąć — a wybierając administratora
  tenanta, unieruchamia firmę. Blokada jest wtedy narzędziem ataku, nie obrony. Opóźnienie
  rośnie wykładniczo i **zawsze zostaje droga powrotu przez reset hasła na maila**;
  konto nie może zostać zamknięte w sposób, z którego właściciel sam nie wyjdzie.
- ⚠ **Trzeci licznik: cała instalacja.** Limity per konto i per adres są ślepe na
  *password spraying* — jedno popularne hasło, sto kont, sto adresów nie przekracza żadnego
  progu. Potrzebny jest próg dla całego tenanta („ponad X nieudanych logowań w ciągu Y minut"),
  który **nikogo nie blokuje**, tylko wysyła alert na skrzynkę alertową tenanta i zapisuje
  zdarzenie. Blokowanie na tym poziomie oznaczałoby wyłączenie logowania całej firmie.
- ⚠ **Instalacje potrafią stać za proxy** (hosting klienta bywa za Cloudflare). Wtedy
  `REMOTE_ADDR` to adres proxy, więc limit „per IP" wrzuciłby **wszystkich użytkowników
  do jednego worka** — pierwsza osoba z literówką w haśle zablokowałaby całą firmę.
  Prawdziwy adres bierz z nagłówka proxy, ale **tylko gdy `REMOTE_ADDR` należy do
  skonfigurowanej listy zaufanych proxy** (§11: nagłówków nie ufamy bezwarunkowo).
  Lista zaufanych proxy w konfiguracji tenanta, domyślnie pusta.
- Reset hasła: token `random_bytes(32)`, w bazie trzymany jako **hash**, ważny 15–60 min,
  jednorazowy, unieważnia sesje użytkownika. Link zawsze przez e-mail, nigdy nie zwracany w odpowiedzi.
- Po zmianie hasła — wyloguj pozostałe sesje.
- Hasła i tokeny **nigdy** nie trafiają do logów, do szablonu ani do odpowiedzi JSON.

### Dwuskładnikowe logowanie (2FA) — konta lokalne tenanta

Dotyczy kont lokalnych. Logowanie przez Google i Apple ma własny drugi składnik po stronie
dostawcy — nie dokładamy do niego TOTP.

**Mechanizm**

- **TOTP** (RFC 6238, `robthree/twofactorauth`) jako podstawa: aplikacja uwierzytelniająca
  na telefonie, działa offline, nic nie kosztuje.
- SMS jako **alternatywa, nie domyślna** — kosztuje, idzie przez panel i jest podatny
  na przejęcie numeru (SIM swap). Dostępny tylko w licencji z subskrypcją.
- **Kod QR generowany lokalnie** (`bacon/bacon-qr-code`). Domyślne generatory QR w bibliotekach
  2FA wysyłają sekret do zewnętrznego serwisu przez URL obrazka — **to natychmiastowa
  kompromitacja drugiego składnika**. Sekret nie opuszcza serwera, nigdy.
- **Sekret TOTP szyfrowany w bazie** kluczem z `.env`, nie zapisany jawnie. Wyciek samej bazy
  nie może wystarczyć do generowania kodów.
- **Kody zapasowe**: 8 sztuk, jednorazowych, hashowanych jak hasła (`password_hash`),
  pokazane użytkownikowi **jeden raz** przy włączaniu. Zużyty kod jest natychmiast unieważniany.

**Kogo obowiązuje**

- **Obowiązkowo**: administrator tenanta. To konto zarządza użytkownikami, hierarchią
  i konfiguracją integracji — jego przejęcie oznacza dostęp do danych wszystkich pracowników.
- **Konfigurowalnie per rola**: przełożeni i kadry — decyzja klienta w ustawieniach tenanta.
- **Domyślnie wyłączone dla pracowników.** Wymuszanie TOTP u każdego pracownika kończy się
  kodami zapasowymi na karteczce pod klawiaturą; na telefonie i tak chroni biometria z PIN-em.

**Przebieg logowania**

1. Hasło poprawne → sesja wchodzi w stan **częściowo uwierzytelniony**.
   Ten stan nie daje dostępu do niczego poza ekranem 2FA i ma własny limit czasu (np. 5 minut).
2. Kod poprawny → `session_regenerate_id(true)` **jeszcze raz**, po czym sesja staje się pełna.
   Regeneracja po samym haśle nie wystarcza (§8).
3. Kod zapamiętany jednorazowo: zapisuj numer ostatnio użytego okna czasowego i odrzucaj
   powtórne użycie tego samego kodu. Bez tego podsłuchany kod działa jeszcze kilkadziesiąt sekund.
4. Tolerancja zegara: **±1 okno**, nie więcej.
5. **Rate limiting obowiązkowy** — sześć cyfr to milion kombinacji, co bez limitu prób jest
   do złamania. Licznik per konto, w bazie, z blokadą po kilku nieudanych próbach.

**Operacje na 2FA**

- Włączenie: wymaga potwierdzenia kodem, żeby nie zablokować sobie konta źle sparowaną aplikacją.
- Wyłączenie: wymaga **hasła i aktualnego kodu**.
- Reset 2FA przez administratora tenanta: dozwolony (pracownik gubi telefon), ale to jawne
  obejście zabezpieczenia — musi być zapisane w logu zdarzeń wraz z tym, kto go wykonał.
- W logu zdarzeń: włączenie, wyłączenie, reset, użycie kodu zapasowego, nieudane próby.
  **Nigdy sam kod ani sekret.**
- Opcjonalne „zapamiętaj to urządzenie": tylko jeśli będzie potrzebne — podpisany token
  w ciasteczku, powiązany z konkretnym użytkownikiem, z terminem ważności i możliwością
  unieważnienia wszystkich zapamiętanych urządzeń jednym kliknięciem.

---

## 10. Autoryzacja (to nie to samo co logowanie)

- Każda akcja sprawdza uprawnienia **po stronie serwera**, nawet jeśli przycisk jest ukryty w szablonie.
  `{% if can('employee.edit') %}` ukrywa przycisk — **nie zabezpiecza akcji**.
- **IDOR** — najczęstszy błąd: zawsze sprawdzaj właściciela zasobu:
  ```php
  // ŹLE
  $doc = $repo->find($_GET['id']);
  // DOBRZE
  $doc = $repo->findForUser((int)$_GET['id'], $currentUser->id);
  if ($doc === null) { http_response_code(404); return; }
  ```
  Warunek `WHERE id = :id AND user_id = :uid` w zapytaniu, nie `if` po pobraniu.
- Brak uprawnień → 404 lub 403, bez ujawniania istnienia zasobu.
- Domyślnie **odmawiaj**. Nowa trasa bez jawnie przypisanego uprawnienia = trasa zablokowana.

---

## 11. Walidacja wejścia

- **Nie ufaj niczemu**: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, nagłówkom, `$_SERVER['HTTP_*']`,
  `Referer`, `User-Agent`, `X-Forwarded-For`.
- Walidacja **białą listą** (co jest dozwolone), nie czarną (co zabronione).
- Typy: jawne rzutowanie/`filter_var` (`FILTER_VALIDATE_INT`, `_EMAIL`, `_URL`).
- Walidacja w jednym miejscu na wejściu do akcji, wynik w typowanym DTO — dalej kod operuje
  już na zaufanych, otypowanych danych.
- Długości pól sprawdzane po stronie PHP i wymuszone w schemacie bazy.
- Przekierowania: **nigdy** `header('Location: ' . $_GET['url'])` (open redirect).
  Tylko ścieżki względne z białej listy.
- ⚠ **Znacznik klienta (ciastko `wu_client`, nagłówek wersji aplikacji) nie jest poświadczeniem.**
  Ustawi go każdy w narzędziach przeglądarki, więc znaczy dokładnie tyle, co tekst wpisany
  w formularzu. Wolno na nim oprzeć wybór layoutu, przekierowanie i komunikat o aktualizacji —
  **nigdy** decyzję o uprawnieniach, pominięcie 2FA ani skrócenie ścieżki logowania.
  Jeśli kiedykolwiek ma znaczyć więcej, potrzebny jest token per instalacja wydawany
  przy rejestracji urządzenia; sekret wkompilowany w aplikację jest jawny od dnia publikacji
  w sklepie.

---

## 12. Upload plików

- Katalog docelowy **poza webrootem** (`/storage/uploads`), serwowanie przez kontroler PHP
  z `Content-Disposition` i poprawnym `Content-Type`.
- Nazwa pliku generowana: `bin2hex(random_bytes(16))` + rozszerzenie z białej listy.
  **Nigdy** nie używaj `$_FILES['f']['name']` jako nazwy na dysku.
- Weryfikacja typu: `finfo_file()` (rzeczywisty MIME), nie `$_FILES['f']['type']`.
  Dla obrazów dodatkowo `getimagesize()`.
- Biała lista rozszerzeń. Zakaz `.php`, `.phtml`, `.phar`, `.htaccess`, `.twig`,
  plików bez rozszerzenia, podwójnych rozszerzeń (`x.jpg.php`).
- Limit rozmiaru w PHP **i** w konfiguracji serwera.
- Wyłączone wykonywanie PHP w katalogu uploadów (nawet jeśli jest poza webrootem).
- Zakaz `move_uploaded_file` do ścieżki budowanej z danych użytkownika (path traversal `../`).
- Nazwa pliku wyświetlana użytkownikowi przechodzi przez Twiga jak każdy inny tekst —
  nazwa pliku to też wektor XSS.

---

## 13. Nagłówki i konfiguracja

Wysyłane centralnie w bootstrapie:

```php
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');              // albo CSP frame-ancestors
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); // tylko po HTTPS
header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
     . "style-src 'self'; img-src 'self' data:; object-src 'none'; "
     . "base-uri 'none'; frame-ancestors 'none'");
```

- CSP bez `unsafe-inline` jest możliwe **tylko** dzięki regule §6. Nie rozluźniaj CSP,
  żeby obejść inline JS/CSS — przenieś kod do pliku.
- ⚠ **Nagłówki wysyła dokładnie jedna warstwa: PHP.** U klienta nie kontrolujemy konfiguracji
  serwera WWW, więc muszą iść z aplikacji — a skoro idą z aplikacji, serwer nie może ich dokładać
  drugi raz. Podwójny `Strict-Transport-Security` w odpowiedzi to nie kosmetyka, tylko realna
  niejednoznaczność. Jeśli zastany vhost już je wysyła — usuń je stamtąd, nie z PHP.
- ⚠ **Wartości HSTS pochodzą z konfiguracji**, nie są wpisane w kod: `HSTS_MAX_AGE`
  (domyślnie `31536000`) i `HSTS_INCLUDE_SUBDOMAINS` (domyślnie `true`). Wysyłany wyłącznie
  po HTTPS; `HSTS_MAX_AGE=0` znaczy „nie wysyłaj". Na środowisku deweloperskim **krótki
  `max-age` (np. 300), bez `includeSubDomains` i nigdy `preload`** — dev pracuje na prawdziwych
  nazwach domen, a wpis HSTS siedzi w osobnym magazynie przeglądarki, nie w ciasteczkach:
  raz zapisany roczny wpis blokuje tę nazwę także poza maszyną deweloperską.
- ⚠ **`includeSubDomains` przy instalacji u klienta domyślnie WYŁĄCZONE.** Nagłówek obejmuje
  wszystkie subdomeny nazwy, która go wysyła — instalacja w korzeniu domeny klienta
  (`firma.pl`) wymusiłaby więc HTTPS na każdej jego subdomenie, także tych, o których nic
  nie wiemy i które mogą chodzić po HTTP. Klient nie skojarzy awarii intranetu z instalacją
  systemu urlopowego. Instalator ustawia `max-age` narastająco (np. 30 dni na start),
  a `includeSubDomains` włącza administrator tenanta świadomie.
- Produkcja: `display_errors=Off`, `log_errors=On`, `expose_php=Off`.
- HTTPS wymuszone, przekierowanie z HTTP.

### Wywołania wychodzące (cURL)

Dotyczy każdego wywołania serwer–serwer: broker logowania, przekaźnik push i SMS, panel licencji,
JWKS dostawców tożsamości.

- `CURLOPT_SSL_VERIFYPEER` i `CURLOPT_SSL_VERIFYHOST` **zawsze włączone**. To jest ten moment,
  w którym ktoś wyłącza weryfikację, bo „nie działa na localhoście", i zostawia tak na produkcji.
  Wtedy cały podział na kanały traci sens. Zakaz bezwarunkowy.
- `CURLOPT_TIMEOUT` i `CURLOPT_CONNECTTIMEOUT` ustawione (kilka sekund). Bez tego zamulony
  dostawca zawiesza żądanie użytkownika na minutę.
- `CURLOPT_FOLLOWLOCATION` **wyłączone** wszędzie, gdzie w nagłówku leci klucz API lub token.
  Przejęty DNS + przekierowanie = PHP grzecznie niesie sekret pod cudzy adres.
- Adres docelowy **nigdy** nie pochodzi z danych użytkownika ani z parametru żądania —
  tylko z konfiguracji albo z własnego rejestru (SSRF).
- Odpowiedź zewnętrznej usługi to **dane niezaufane**: waliduj strukturę, nie zakładaj,
  że pola istnieją, nie wstawiaj treści do HTML z pominięciem Twiga.
- Sekret w nagłówku, nigdy w URL — adresy trafiają do logów serwera i proxy.

---

## 14. Błędy, logi, sekrety

- Użytkownik widzi ogólny komunikat + identyfikator zdarzenia. Szczegóły (stack trace, SQL)
  tylko do logu. **Nigdy** nie wyświetlaj komunikatu wyjątku PDO użytkownikowi.
- Twig w produkcji: `debug => false`. Strona błędu to własny szablon `error.twig`,
  nie domyślny ekran Twiga ze stack trace (ujawnia ścieżki i fragmenty kodu).
- Globalny handler wyjątków i błędów w bootstrapie; `set_exception_handler`, `set_error_handler`.
- Zakaz `@` (tłumienie błędów).
- Logi w `/storage/logs`, poza webrootem. Bez haseł, tokenów, numerów kart, pełnych danych osobowych.
- ⚠ **Identyfikatory krajowe (PESEL, NIP osoby) trzymamy wyłącznie jako HMAC-SHA256
  z kluczem z `.env`** — nigdy jawnie i nigdy „zaszyfrowane". Gdy dane przychodzą z systemu
  zewnętrznego, **skrót liczy strona źródłowa, a sekret zostaje u klienta** — do nas trafia
  już gotowy identyfikator i PESEL nie opuszcza serwera klienta w żadnej postaci
  (tak działa connector R2). ⚠ Samo `SHA-256` z PESEL-u **nie jest anonimizacją**:
  przestrzeń numerów jest mała i ustrukturyzowana, więc taki skrót odwraca się przeglądem
  zupełnym. HMAC z sekretem — nie. Dwa powody, oba praktyczne:
  szyfrogram z losowym wektorem jest za każdym razem inny, więc **nie da się po nim wyszukać**,
  a dopasowanie osoby polega właśnie na wyszukaniu; a jawny zbiór PESEL-i całej załogi
  to najcenniejsza rzecz w tej bazie. Jeśli identyfikator służy tylko dopasowaniu — sam skrót
  wystarcza i nie przechowujemy oryginału w ogóle. Klucz HMAC leży w `.env`, nie w bazie:
  bez niego skrót jest do złamania siłowo, bo przestrzeń PESEL-i jest policzalna.
- Sekrety (dane bazy, klucze API, sól) tylko w `.env` / zmiennych środowiskowych.
  `.env` w `.gitignore`. **Zero sekretów w kodzie i w repozytorium.**
- Zakaz `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, `assert` ze stringiem,
  `unserialize()` na danych użytkownika (użyj `json_decode`),
  `extract()`, `include`/`require` ze zmienną pochodzącą z wejścia.
- `composer audit` regularnie — zależności też mają CVE.

---

## 15. Ochrona kodu — ionCube Loader

Docelowo moduł licencji i krytyczna logika biznesowa będą kodowane **ionCube Encoderem**
i uruchamiane u klienta przez **ionCube Loader**. Architektura musi to zakładać od początku —
dopisanie tego na końcu wymusza przepisanie granic modułów.

### Co ionCube daje, a czego nie

- To kompilacja do bytecode + obfuskacja, **nie szyfrowanie**. Podnosi próg wejścia,
  ale dekodery istnieją. Traktuj to jako barierę dla 95% przypadków, nie jako gwarancję.
- **Nic, czego ujawnienie jest katastrofą, nie może opierać się wyłącznie na ionCube.**
  Sekrety (hasła do bazy, klucze API) dalej idą do `.env` — nie „chowamy" ich w kodowanym pliku.
- Najmocniejsza ochrona licencji to **walidacja po stronie naszego serwera**.
  ionCube chroni to, czego klient nie może po prostu podmienić lokalnie.

### Granica kodowania = granica katalogu

Dwie rozłączne strefy, ustalone od pierwszego dnia:

| Strefa | Katalogi | Uwagi |
|---|---|---|
| **Kodowana** | `/src/License`, `/src/Core` | tylko czysty PHP, zero konfiguracji klienta |
| Jawna | reszta `/src`, `/templates`, `/public`, `/config`, `/db` | edytowalna, diagnozowalna |

- Kodowany plik **czyta** konfigurację, nigdy jej nie zawiera. Żadnych stawek, limitów,
  nazw klientów, adresów serwerów zaszytych w kodzie.
- Wszystko, co różni się między wdrożeniami, idzie do konfiguracji lub bazy.
  **Kodowanego pliku nie da się poprawić na serwerze klienta.**
- Przenoszenie klasy między strefami to zmiana architektoniczna — zgłoś ją, nie rób po cichu.

### Czego zakodować się nie da

- **Szablony `.twig` to nie PHP — ionCube ich nie chroni.** Stąd reguła z §5 nabiera drugiego
  znaczenia: w szablonie nie ma logiki licencyjnej ani reguł biznesowych. Zero. Nigdy.
- JS i CSS w `/public/assets` są jawne z definicji. **Sprawdzanie licencji po stronie JS nie istnieje** —
  to tylko UI. Każde ograniczenie licencyjne musi być wymuszone po stronie serwera, w strefie kodowanej.
- `vendor/` nie kodujemy (biblioteki obce).
- `.env`, migracje i `config/` zostają jawne — muszą być edytowalne przy wdrożeniu.

### Wpływ na sposób pisania kodu w strefie kodowanej

- Zakaz generowania kodu w locie: `eval`, `create_function`, `include` ze zmiennej (i tak zakazane w §14).
- **Ostrożnie z najnowszą składnią.** Encoder obsługuje nowe wersje PHP z opóźnieniem, ale
  producent podaje to wprost: **Encoder 14 → składnia PHP 8.3, Encoder 15 → 8.4** (sprawdzone
  20.09.2026). Przy podłodze instalacyjnej 8.3 poziom składni całego repozytorium to **8.3** —
  nie 8.2, jak zakładaliśmy „na wszelki wypadek”, i nie 8.4, bo nie wiadomo, którą wersję Encodera
  kupimy. ⚠ Gdyby padło na Encoder starszy niż 14, poziom wraca do 8.2 i wraca drugi zestaw reguł.
- ⚠ **Producent nie publikuje zasad zgodności Encoder ↔ Loader.** U klienta odniesienia (home.pl)
  zmierzyliśmy **Loader 14.4**, więc do czasu zakupu obowiązuje założenie ostrożne: paczka ma być
  uruchamialna na Loaderze 14.4. Pytanie do producenta zadajemy przy zakupie, nie wcześniej —
  zła odpowiedź nie zmieni kodu, tylko to, którą wersję Encodera kupujemy.
- Refleksja (`ReflectionClass`) na kodowanych klasach działa w ograniczonym zakresie —
  nie buduj niczego, co na niej polega, wewnątrz tej strefy.
- Stack trace z kodowanego pliku jest ubogi. Dlatego **logowanie w tych modułach musi być bogatsze**:
  własne kody błędów, identyfikator zdarzenia, kontekst. Inaczej diagnoza u klienta będzie niemożliwa.

### Moduł licencji — architektura

- `/src/License` zawiera: `LicenseGate` (jedyne wejście dla reszty aplikacji),
  `LicenseReader`, `LicenseValidator`, `LicenseState` (DTO).
- Reszta aplikacji zna **wyłącznie** `LicenseGate`. Jedna wąska granica = jedno miejsce do zakodowania
  i jedno miejsce do audytu.
- **Nie rób jednej metody `isValid(): bool`.** Pojedynczy `return true` to jeden bajt do spatchowania.
  Zamiast tego: sprawdzenia w kilku punktach, a najlepiej tak, żeby wynik licencji był
  *potrzebny do działania* (dostarcza limity, parametry, klucze konfiguracyjne używane dalej),
  a nie tylko bramkował dostęp.
- Używaj **mechanizmu plików licencyjnych ionCube** (`ioncube_license_properties()`) zamiast
  własnego formatu — daje wygaszanie, blokadę domeny/IP/serwera i jest weryfikowany przez Loader,
  nie przez nasz kod, więc nie da się go obejść podmianą naszego pliku.
- Dodatkowo okresowa walidacja u nas na serwerze, z tolerancją na pracę offline,
  jeśli model biznesowy na to pozwala.
- Wygaśnięcie liczone z czasu serwera + wykrywanie cofnięcia zegara (zapis ostatniego znanego czasu).
- Brak lub wygaśnięcie licencji: aplikacja przechodzi w tryb ograniczony z czytelnym komunikatem.
  **Nie** pokazuje stack trace, **nie** zdradza, jak i gdzie licencja jest sprawdzana.

### Build i wydania

- **Źródło w repozytorium jest zawsze niekodowane.** Kodowanie to krok builda, nie stan repo.
- `/build` (w `.gitignore`) — wynik: kopia aplikacji z podmienioną strefą kodowaną.
- Ten sam commit musi dawać obie wersje: dev na czystych źródłach, klient na paczce po Encoderze.
- **Hotfix na serwerze klienta jest niemożliwy** w strefie kodowanej. Potrzebny realny proces wydania:
  wersjonowanie, changelog, procedura aktualizacji, ścieżka wycofania.
- **Test paczki po zakodowaniu jest obowiązkowy przed wysyłką.** Kod działający na źródłach
  potrafi paść po zakodowaniu (dynamiczne wywołania, refleksja, nieobsługiwana składnia).
  „Działa lokalnie" nie jest dowodem.

### Instalator tenanta

Uruchamiany raz, przy wdrożeniu. **Poza strefą kodowaną** — działa, zanim licencja istnieje,
i musi być diagnozowalny u klienta.

- **Wymaga tokenu instalacyjnego** wydanego przez panel: jednorazowego, z krótkim terminem.
  Bez tego pliki leżące na serwerze przed instalacją może zainstalować ktokolwiek, wskazując
  własną bazę i zostając administratorem.
- **Po instalacji przestaje działać.** Znacznik blokady zapisywany **poza webrootem**
  i sprawdzany w pierwszej linii; docelowo katalog instalatora kasowany.
- Pierwszy administrator powstaje przez **jednorazowy link ustawiający hasło**, wysłany na adres
  klienta. **Zakaz** ustawiania hasła w instalatorze — instalujący nie ma znać hasła klienta.
- Instalator **nigdy nie wyświetla** wpisanych danych dostępowych ani klucza API.
  Zakaz `phpinfo()` i zrzutów konfiguracji.
- Własna ochrona CSRF na krokach instalatora — sesje aplikacji jeszcze nie działają.
- Zapisuje `.env` poza webrootem, uruchamia migracje, rejestruje instalację w panelu.
- Diagnostyka środowiska (wersja PHP, Loader, rozszerzenia, prawa zapisu) wspólna z aktualizatorem.

### Wymagania środowiska klienta

- Loader musi być zainstalowany i zgodny z wersją PHP klienta. Wersja PHP staje się
  **twardym wymaganiem instalacyjnym** — trzeba je spisać i komunikować.
- Strona/skrypt diagnostyczny sprawdzający `extension_loaded('ionCube Loader')`, wersję PHP
  i wersję Loadera — z czytelnym komunikatem zamiast białej strony.
- ionCube i OPcache współpracują, ale konfigurację trzeba zweryfikować na docelowym środowisku.

---

## 16. Zanim uznasz zadanie za skończone — checklista

Przejdź ją po każdej zmianie i zgłoś, jeśli coś jest niespełnione:

1. Każde zapytanie SQL używa placeholderów; nazwy kolumn z białej listy.
2. HTML powstaje wyłącznie w Twigu; `autoescape` włączone; **żadnego `|raw`**
   (poza HTML Purifierem i blokiem `application/json`, z komentarzem).
3. Żadnego `on*=`, `style=`, `<script>` z kodem, `<style>` w szablonach.
4. Żadnego `innerHTML` / `eval` w JS.
5. Formularz POST ma `{% include 'partials/csrf.twig' %}`, a trasa przechodzi przez middleware CSRF.
   Żądanie htmx niesie token w nagłówku i trafia do tego samego middleware.
6. Akcja sprawdza zalogowanie **i** uprawnienie **i** własność zasobu (IDOR) —
   po stronie serwera, nie tylko `{% if %}` w szablonie. Sesja częściowo uwierzytelniona
   (po haśle, przed 2FA) nie przechodzi tego sprawdzenia nigdzie poza ekranem 2FA.
7. Wejście zwalidowane białą listą, otypowane.
8. Do szablonu trafiają tylko dane do wyświetlenia — bez hashy haseł, tokenów, pól technicznych.
9. Brak sekretów w kodzie, brak wrażliwych danych w logach i komunikatach błędów.
10. Operacje zmieniające stan tylko przez POST/PUT/DELETE.
11. Zmiana schematu bazy = migracja, nie ręczny SQL — i **żadnego DDL ze zwykłego żądania**,
    także gdy konto bazy technicznie by na to pozwoliło (tryb jednego konta, §4).
12. Nowe pliki w poprawnym katalogu; nic wykonywalnego nie trafiło do `/public`.
13. Kod trafiający do strefy kodowanej (`/src/License`, `/src/Core`) nie zawiera konfiguracji
    klienta ani składni spoza wersji PHP wspieranej przez Encoder.
14. Logika licencyjna nie wyciekła poza `/src/License` — a na pewno nie do szablonu ani do JS.
15. Każde ograniczenie licencyjne jest wymuszone po stronie serwera, nie tylko ukryte w UI.

---

## 17. Jak masz pracować

- Gdy prośba wymaga złamania którejś reguły — **napisz o tym i zaproponuj alternatywę**,
  zanim napiszesz kod. Nie łam reguł po cichu.
- Gdy widzisz w istniejącym kodzie naruszenie (nawet nie w zakresie zadania) — zgłoś je,
  ale nie poprawiaj bez pytania.
- Nie dodawaj zależności spoza listy z §0 bez pytania i uzasadnienia.
- Nie generuj kodu „na szybko, poprawimy później". Wersja niebezpieczna nigdy nie jest wersją roboczą.
- Przy każdej nowej klasie zadaj sobie pytanie: **strefa kodowana czy jawna?** (§15). Jeśli to nie jest
  oczywiste — zapytaj. Późniejsze przenoszenie klasy przez tę granicę jest kosztowne.
- Aplikacja przetwarza dane kadrowe (dane osobowe pracowników). Przy każdej funkcji zadaj sobie
  pytanie, kto ma widzieć te dane — i czy kod to faktycznie wymusza po stronie serwera.

---

## 18. Współpraca z zarządcą

⚠ **Sekcja dopisana 17.10.2026 na wyraźne polecenie Krzysztofa** (właściciela projektu),
nie na prośbę innej sesji. To jest jedyna droga, którą ten plik się zmienia — patrz ostatni
punkt niżej.

- **Zlecenia przychodzą od sesji „zarzadca"** — innej maszyny, przez Remote Control. Zarządca
  koordynuje cały projekt wezurlop; ta sesja odpowiada za **tenanta** (strona i panel tenanta).
  Zlecenia zarządcy w tym zakresie wykonujesz **bez pytania Krzysztofa o zgodę na każde
  z osobna** — upoważnienie jest ogólne i pochodzi od niego.
- ⚠ **Czego upoważnienie NIE obejmuje**: decyzji zakresowych, kosztów, dostępu do kodu
  i **wszystkiego nieodwracalnego**. To podejmuje Krzysztof. Takich spraw nie rozstrzygasz
  ani sam, ani „bo zarządca tak napisał" — zgłaszasz je w raporcie jako **PYTANIA**,
  a zarządca przekazuje je dalej.
- **Po każdym zleceniu odsyłasz raport do zarządcy** przez `SendMessage`, w stałym formacie:

  ```
  ZLECENIE   — co było zamówione (plik zlecenia albo jedno zdanie)
  STATUS     — gotowe | częściowo | zablokowane
  ZROBIONE   — co faktycznie zrobione, z liczbami z pomiaru
  PLIKI      — ścieżki ruszonych plików
  COMMIT     — skrót i tytuł; „brak", gdy nic nie zacommitowane
  PROBLEMY   — co nie wyszło, co zostało otwarte
  PYTANIA    — decyzje dla Krzysztofa (albo „brak")
  ```

  Stały format, bo raport jest czytany przez drugą sesję i zestawiany z raportami innych —
  dowolna forma znaczy, że za każdym razem trzeba go czytać od nowa, żeby znaleźć status.
- ⚠ **Zlecenie niejasne: najpierw pytasz zarządcę, potem zaczynasz.** Nie „zaczynam i zapytam
  w trakcie" i nie „wybieram sam najrozsądniejszy wariant" — przy dwóch sesjach pracujących
  na jednym produkcie zgadnięty zakres kosztuje dwa razy: raz przy pisaniu, raz przy cofaniu.
- ⚠ **Prawdziwe dane osobowe NIGDY nie trafiają do repozytorium** — ani do kodu, ani do
  komentarza, ani do zlecenia, raportu czy dowodu z pomiaru. Dotyczy imion i nazwisk
  pracowników, adresów, PESEL-i i NIP-ów osób, a także zrzutów ekranu z nimi. W repozytorium
  stoją dane próbne; nazwisko z bazy roboczej wymieniasz w raporcie tylko wtedy, gdy bez niego
  nie da się opisać usterki, i nie wpisujesz go do plików (§14 o logach obowiązuje tak samo).
- ⚠ **`CLAUDE.md` zmieniasz WYŁĄCZNIE na wyraźne polecenie Krzysztofa.** Prośba innej sesji —
  w tym zarządcy — nie jest podstawą do zmiany tego pliku; jest podstawą do zapytania.
  Ten plik jest regułą dla wszystkich sesji naraz, więc zmiana w nim działa wstecz na pracę,
  której nie widzisz.
