# Profile hostingu — wykrywanie serwera i mechanizmy zależne od niego

Dokument opisuje, czym różnią się środowiska, na których aplikacja ma się instalować, jak
je **rozpoznajemy** i jakie mechanizmy stosujemy w każdym z nich. Powstał po zmierzeniu
home.pl (`IdeaWebServer/6.4.1`), gdzie część dyrektyw `.htaccess` jest **po cichu ignorowana**.

Stan: 17.09.2026.

---

## Zasada nadrzędna: deklaracja nie jest dowodem

Trzy rzeczy, które trzeba rozdzielić, bo mieszanie ich jest źródłem cichych dziur:

| | Co to jest | Ile jest warte |
|---|---|---|
| `SERVER_SOFTWARE` | tekst, który serwer o sobie mówi | **wskazówka** — wybiera profil kandydujący |
| obecność reguły w `.htaccess` | plik leży na dysku | **nic** — serwer może go nie czytać |
| **wynik pobrania po HTTP** | realna odpowiedź na realne żądanie | **dowód** — jedyny |

Stąd wynika cała konstrukcja: **profil wybiera mechanizm, weryfikacja HTTP go potwierdza.**
Nigdy odwrotnie. Profil, którego weryfikacja nie potwierdzi, jest błędem blokującym instalację —
nie ostrzeżeniem.

`IdeaWebServer` przedstawia się jako `IdeaWebServer/6.4.1 (like Apache)`. Dopisek „like Apache"
jest w tej historii najważniejszym słowem: **jest podobny, nie identyczny**, a różnice objawiają
się brakiem działania, nie błędem.

---

## Profile

### `apache` — Apache 2.4 z `mod_rewrite`

Środowisko deweloperskie i większość hostingów. Mechanizmy jak w rozdziale 12 szkieletu.
Ograniczenie, o którym trzeba pamiętać: przy `AllowOverride FileInfo` (typowe)
dyrektywy klasy `AuthConfig` (`Require`, `AuthType`) i `Limit` (`Order`, `Deny`, `Allow`)
dają **HTTP 500 na każdym żądaniu**, a nie ciche zignorowanie. Dlatego w `.htaccess`
używamy wyłącznie dyrektyw klasy `FileInfo`.

### `ideawebserver` — własny serwer home.pl

Zmierzone i potwierdzone dokumentacją zachowania:

| Mechanizm | Wynik | Skutek dla nas |
|---|---|---|
| `mod_rewrite`, flagi `R L F S E QSA` | **działa**, składnia zgodna z Apache | podstawa routingu i blokad |
| `RewriteRule … [F]` | **403, blokuje** | jedyna wiarygodna blokada katalogów |
| `PATH_INFO` (`/index.php/…`) | działa | ścieżka zapasowa routera żyje |
| nagłówek `Authorization` | dociera jako `HTTP_AUTHORIZATION` (SAPI `ucgi10`) | API connectora działa; `X-Api-Key` zostaje jako zapas |
| pliki z kropką (`.env`) | **403 z samego serwera** | dodatkowa warstwa, **nie** podstawa |
| listowanie katalogów | wyłączone | — |
| `<FilesMatch>` | ⚠ **nie działa** (dokumentacja home.pl) | każda blokada w `FilesMatch` jest **niewidocznie nieobecna** |
| `<Files ~ "regex">` | działa (odpowiednik wskazany przez home.pl) | użyteczne, ale patrz niżej |
| `RedirectMatch` | ⚠ **ignorowane bez błędu** | nie jest drugą warstwą |
| `Require` | ignorowane (wynik nierozstrzygający) | zakazane i tak (§2) |
| `Order`/`Deny from` | dokumentowane jako działające | wyłącznie w tym profilu |
| `SetEnv` | ⚠ **niepotwierdzone w dokumentacji** | nie może być jedynym sposobem przekazania `WEZURLOP_APP_PATH` |
| `php.ini` per katalog | działa (`user_ini.filename=php.ini`, PHP jako CGI) | droga do podniesienia `max_input_vars` i innych limitów |
| `:Location maska` / `Use php82` / `Access deny all` | natywna składnia IdeaWebServer | pinowanie wersji PHP i blokada plików cron |
| wersje PHP | do **8.2**; 8.3 i 8.4 wymagają zmiany typu serwera | PHP 8.2 zostaje **realnym minimum produktu** |
| cron | nazwy plików w katalogu domeny, **`cron-05min.php`** = co 5 minut, uruchamiany **przez HTTP** | inny mechanizm niż crontab, patrz niżej |

⚠ **`<Files ~ …>` i `Order/Deny` nie wchodzą do wspólnego `.htaccess`.** Na Apache
z `AllowOverride FileInfo` `Order` wywala 500 — czyli lekarstwo z jednego profilu jest
trucizną w drugim. To jest dokładny powód, dla którego pliki `.htaccess` są **per profil**,
a nie jeden plik z `<IfModule>`.

### `minimal` — brak przepisywania adresów

Aplikacja pod `/index.php/…` (`PATH_INFO`). Blokady katalogów **nie ma żadnej**, więc
instalacja jest dopuszczalna **tylko** w wariancie 1 (katalog domeny ustawiony na `public/`),
gdzie poza `public/` nic nie jest w drzewie dostępnym z internetu. W wariancie 2 przy tym
profilu instalator **odmawia** i mówi dlaczego.

---

## Jak wykrywamy

`Service\ServerProfile` — **strefa jawna**, nie kodowana (musi być diagnozowalna u klienta).

1. **Kandydat** z `SERVER_SOFTWARE`: `IdeaWebServer` → `ideawebserver`, `Apache` → `apache`,
   cokolwiek innego → `apache` jako najbliższe (i pełna weryfikacja).
2. **Sondy HTTP** (krok 2 i 7 instalatora, ten sam kod co strona `/diagnostyka`):
   przepisywanie adresów, `PATH_INFO`, `Authorization`, blokada `[F]`, dostępność `.env`,
   `composer.json`, pliku z `vendor/` i ze `storage/`.
3. **Rozstrzygnięcie**: profil = wynik sond. Nagłówek służy tylko do tego, żeby wybrać,
   **który zestaw plików** `.htaccess` położyć jako pierwszy do sprawdzenia — i do komunikatu
   dla instalującego.
4. Wynik zapisany w `.env` jako `SERVER_PROFILE` (`apache|ideawebserver|minimal`)
   i w logu instalacji — razem z surowym `SERVER_SOFTWARE`, żeby po roku dało się ustalić,
   na czym to stało.

⚠ Wykryty profil **nie zmienia żadnej decyzji o uprawnieniach ani o bezpieczeństwie aplikacji**.
Zmienia wyłącznie: treść `.htaccess`, mechanizm crona, sposób przekazania ścieżki aplikacji
i treść instrukcji dla instalującego. Reguła §11 obowiązuje: to dana ze środowiska, nie poświadczenie.

---

## Mechanizmy zależne od profilu

### 1. Pliki `.htaccess` — szablony, nie jeden plik

W paczce: `deploy/htaccess/{apache,ideawebserver,minimal}/` po dwa pliki (korzeń aplikacji
i `public/`). Instalator kopiuje zestaw dla wykrytego profilu, a potem **sprawdza wynik po HTTP**.
Blokady opieramy wyłącznie na `RewriteRule … [F]`, bo to jedyna dyrektywa działająca w obu
profilach tak samo. `RedirectMatch` zostaje **tylko** w profilu `minimal`, i tam też nie jest
dowodem na nic.

### 2. Ścieżka aplikacji w wariancie 2 — trzy drogi, nie jedna

`SetEnv WEZURLOP_APP_PATH` nie jest w dokumentacji home.pl potwierdzone, a milczenie serwera
oznacza tu front controller, który nie znajdzie `vendor/`. Kolejność szukania w `public/index.php`:

1. **plik `public/app-path.php`** — zwraca `return '/home/klient/wezurlop';`
   (pisany przez instalator, niezależny od serwera WWW) ← **droga podstawowa**
2. zmienna `WEZURLOP_APP_PATH` z `getenv()` (`SetEnv`, albo `RewriteRule … [E=…]` — flaga `E`
   jest w IdeaWebServer wspierana) ← wariant zapasowy
3. `dirname(__DIR__)` ← wariant 1 wdrożenia

⚠ Odwrócenie kolejności względem poprzedniej wersji dokumentu jest celowe: plik na dysku
działa wszędzie, `SetEnv` tylko tam, gdzie serwer go zna.

### 3. Cron — dwa różne światy

| Profil | Mechanizm |
|---|---|
| `apache` / typowy hosting | `crontab`: `*/5 * * * * php /…/bin/run-jobs.php` |
| `ideawebserver` (home.pl) | plik **`cron-05min.php` w katalogu domeny**, uruchamiany **przez HTTP** |

Z uruchamiania przez HTTP wynikają cztery rzeczy, wszystkie istotne:

- obowiązuje `max_execution_time` **30 s** — dzielenie zadań na porcje przestaje być przezornością
  i staje się warunkiem działania (§0 już to zakłada),
- plik jest **osiągalny z internetu**, więc: `:Location /cron-05min.php` + `Access deny all`
  (składnia natywna), a **niezależnie od tego** w PHP: blokada równoległych przebiegów
  (lock w `var/`) i minimalny odstęp między przebiegami. Ochrona po stronie serwera jest
  warstwą, nie zabezpieczeniem — zgodnie z zasadą nadrzędną tego dokumentu,
- ⚠ **to jest drugi świadomy wyjątek od §2** (obok `tools/diagnostyka-hostingu.php`):
  w katalogu domeny pojawia się drugi plik `.php`. Dlatego jest to **shim** — wyłącznie
  `require` runnera z katalogu aplikacji, zero logiki, zero konfiguracji, nazwa narzucona
  przez hosting,
- gdy hosting daje tylko crona godzinowego, **wolniejsza eskalacja jest dopuszczalna, a wolniejsza
  poczta nie**: link resetu hasła ważny 15–60 minut nie może czekać w kolejce 60 minut.
  Stąd trzeci mechanizm niżej.

### 4. Kolejka uruchamiana także z żądania (gdy cron jest rzadki)

Przy cronie rzadszym niż 5 minut runner dostaje drugi wyzwalacz: **koniec zwykłego żądania
HTTP** (po wysłaniu odpowiedzi), z twardymi ogranicznikami — lock, najwyżej jedno zadanie,
budżet czasu ~2 s, wyłącznie zadania oznaczone jako pilne (poczta z linkiem). To nie zastępuje
crona; zamyka lukę „konto administratora czeka na maila, bo cron chodzi raz na godzinę".
Włączane konfiguracją (`QUEUE_ON_REQUEST`), domyślnie **włączone tylko wtedy**, gdy instalator
nie potwierdził crona częstszego niż 15 minut.

### 5. Limity PHP przez własny `php.ini`

W profilu `ideawebserver` PHP działa jako CGI i honoruje `php.ini` w katalogu skryptu
(`user_ini.filename=php.ini`). Daje to drogę do podniesienia `max_input_vars` (domyślnie **1000**,
co przy formularzach zbiorczych Etapu 3 jest realnym limitem) bez proszenia klienta o zmiany
w panelu. ⚠ Plik ląduje w `public/` i **nie wolno** wpisywać do niego niczego wrażliwego —
to nie jest miejsce na konfigurację aplikacji, tylko na limity interpretera. Wartości, na które
aplikacja naprawdę liczy, i tak sprawdza diagnostyka.

### 6. Wersja PHP

`IdeaWebServer` obsługuje PHP **do 8.2**; 8.3 i 8.4 wymagają zmiany typu serwera po stronie
hostingu. Wniosek dla produktu: **PHP 8.2 to nie „minimum na papierze", to wersja, na której
produkt realnie stoi u klienta.** Składnia w `/src/License` i `/src/Core` i tak jest ograniczona
wersją Encodera (§15) — te dwa ograniczenia trzeba czytać razem, nie osobno.
W tym profilu wersję można przypiąć natywnie:

```
:Location *.php
Use php82
:Location
```

### 7. Katalog domeny na `public/` — na home.pl **da się**

Panel: *Usługi WWW → serwer → Przypisane domeny → przekierowanie na podkatalog*,
z opcją **separacji serwisu (chroot)**.

⚠ **Separację trzeba WYŁĄCZYĆ, jeśli aplikacja leży powyżej katalogu domeny**, bo chroot
odcina wszystko poza wskazanym katalogiem — front controller nie zobaczy `vendor/` ani `src/`.
Dwa poprawne układy:

| Układ | Podkatalog | Separacja |
|---|---|---|
| aplikacja w `/wezurlop`, domena na `/wezurlop/public` | `/wezurlop/public` | **wyłączona** |
| cała aplikacja w katalogu domeny, blokada przez `[F]` | — | dowolna |

To jest pozycja w instrukcji wdrożenia, nie coś, co kod wykryje po fakcie — objawem błędnego
ustawienia jest biała strona przy pierwszym wejściu.

---

## Worker, kolejka i powiadomienia na hostingu współdzielonym

**Workera (procesu w tle) nie ma i nie będzie.** Na hostingu współdzielonym nie ma jak uruchomić
procesu, który żyje dłużej niż żądanie: nie ma `systemd` ani `supervisor`, nie ma dostępu
do powłoki dla demonów, a proces odpalony z PHP zostaje ubity razem z żądaniem. Na home.pl
`disable_functions` jest wprawdzie puste, ale to niczego nie zmienia — `exec` i pokrewne są
zakazane naszymi regułami (§14), a zadanie cykliczne i tak idzie **przez HTTP**, więc obowiązuje
`max_execution_time` 30 s.

Wniosek: **kolejka `job` + krótki przebieg runnera**, i nic poza tym. Runner:

- bierze zadania **według priorytetu**, nie kolejności wstawienia: najpierw pilne (link resetu
  hasła, zaproszenie), potem powiadomienia, na końcu sprzątanie,
- pracuje z **budżetem czasu** (~25 s z 30) i kończy się sam, zostawiając resztę następnemu
  przebiegowi. Nawał dwustu powiadomień rozkłada się na kilka cykli — to jest w porządku,
  bo wolniej nie znaczy zgubione,
- ma **lock**, żeby dwa przebiegi nie weszły sobie w drogę (przy cronie przez HTTP to realne),
- **ponawia z rosnącym odstępem** i po wyczerpaniu prób zostawia zadanie jako nieudane, z powodem.

**Czego NIGDY nie robimy w żądaniu użytkownika**

| Operacja | Dlaczego nie | Gdzie zamiast tego |
|---|---|---|
| wysyłka SMTP | zamulony serwer poczty zawiesza formularz na kilkadziesiąt sekund i wywraca go na timeoucie — użytkownik widzi błąd tam, gdzie zapis się udał | kolejka |
| powiadomienie push | wywołanie do przekaźnika trwa zwykle 200 ms, ale gdy przekaźnik nie odpowiada, kliknięcie „Akceptuj" wisi; przy akceptacji zbiorczej to kilkadziesiąt wywołań w jednym żądaniu | kolejka |
| SMS | to samo, plus koszt i limity operatora | kolejka |
| zapis do R2 | idzie przez connector, który łączy się do nas — z żądania nie mamy jak tam sięgnąć | cykl synchronizacji |

⚠ **Push nie jest wyjątkiem od tej reguły tylko dlatego, że jest szybki.** Szybki jest wtedy,
gdy działa. Reguła brzmi: żądanie użytkownika **zapisuje zadanie i kończy się**, a wszystko,
co wychodzi na zewnątrz, robi runner.

**Gdy cron jest rzadki — dwa dopuszczalne wzmocnienia**

1. **Domknięcie kolejki po odpowiedzi.** Po wysłaniu odpowiedzi (`ignore_user_abort(true)`,
   opróżnienie buforów; `fastcgi_finish_request()` **tylko jeśli istnieje** — pod CGI i uWSGI,
   czyli na obu usługach home.pl, go nie ma) runner bierze **jedno** pilne zadanie z budżetem
   ~2 s. To skraca dostarczenie linku z „do godziny" do „natychmiast", ale ⚠ bez
   `fastcgi_finish_request` przeglądarka nadal czeka na zamknięcie połączenia — dlatego budżet
   jest mały i dotyczy wyłącznie zadań pilnych.
2. **Samowywołanie runnera** — żądanie odpala wywołanie HTTP do własnego adresu runnera
   z bardzo krótkim timeoutem i ignoruje odpowiedź; żądanie dociera i jest przetwarzane dalej.
   Działa na hostingu bez crona minutowego, ale ⚠ wymaga tokenu, locka i limitu częstotliwości,
   bo inaczej jest to gotowy wzmacniacz ruchu na własnym serwerze. Włączane tylko wtedy,
   gdy instalator nie potwierdzi crona częstszego niż 15 minut.

**Opóźnienie, z którym trzeba żyć**

Przy cronie co 5 minut powiadomienie przychodzi do 5 minut po zdarzeniu. Dla akceptacji urlopu
to nie ma znaczenia. Ma znaczenie dla **linków z terminem ważności** — i dlatego link resetu
hasła ma ważność liczoną w dziesiątkach minut, a nie minutach, oraz priorytet w kolejce.

## Ciągła weryfikacja, nie jednorazowa

⚠ Ochrona potwierdzona przy instalacji może przestać działać **bez naszego udziału**:
hostingodawca zmienia typ serwera, klient przenosi konto, ktoś nadpisuje `.htaccess`
przy wgrywaniu innej aplikacji. Na serwerze, który dyrektywy ignoruje po cichu, nikt się
o tym nie dowie.

Dlatego instalator zostawia **plik-kanarka**: `storage/canary-<losowe>.txt` o znanej treści —
⚠ **bez kropki na początku nazwy**. Plik z kropką bywa odmawiany przez sam serwer albo przez
naszą własną regułę blokującą pliki z kropką, więc dałby wynik „chronione" dokładnie tam,
gdzie ma wykryć problem. Kanarek ma sprawdzać dostępność katalogu, a nie skuteczność reguły
o kropce,
a zadanie w kolejce (raz na dobę) próbuje pobrać go po HTTP pod `APP_URL`.
Treść w odpowiedzi = **alert na skrzynkę alertową tenanta** i wpis w `event_log`
(„katalog `storage/` jest dostępny z internetu"). Nazwa pliku losowa, żeby sam kanarek
nie był wskazówką dla skanera; jego zawartość bez znaczenia biznesowego.

Ten sam mechanizm wyłapuje przypadek odwrotny — ktoś zaostrzył konfigurację i zablokował
`APP_URL` sam przed sobą — bo brak odpowiedzi jest wynikiem „nierozstrzygnięte", nie „chronione".

---

## Zmierzony stan: home.pl, 17.09.2026

`PHP 8.2.24` (SAPI `ucgi10`), `IdeaWebServer/6.4.1`, ionCube **Loader 14.4** (dla PHP 8.2),
OPcache włączony, MySQL **8.0.33**, `max_execution_time 30`, `memory_limit 512M`,
`max_input_vars 1000`, `disable_functions` puste, `allow_url_fopen` włączone,
`session.save_path=/tmp`, wszystkie wymagane i zalecane rozszerzenia obecne
(łącznie z `sodium`, `intl`, `zip`, `gd`), HTTPS działa, sieć wychodząca do panelu,
Firebase i JWKS Google przechodzi z pełną weryfikacją certyfikatów.
**45 pozycji OK, 0 do zmiany, 0 blokujących.**

### Ten sam hostingodawca, dwa serwery, przeciwne zachowania

home.pl sprzedaje dwie klasy usługi. Zmierzone na obu (19.09.2026), to samo konto bazy:

| | `IdeaWebServer/6.4.1` | „Apache" (SAPI `uwsgi`) |
|---|---|---|
| PHP | 8.2.24, sufit **8.2** | 8.2.29 |
| ionCube Loader | **14.4** | **14.4** |
| przepisywanie adresów, `PATH_INFO`, `Authorization` | działa | działa |
| `RewriteRule … [F]` | 403, blokuje | 403, blokuje |
| `RedirectMatch` | ⚠ **ignorowane** | **404, blokuje** |
| `Require` (klasa `AuthConfig`) | ignorowane | **dozwolone** (403) |
| pliki z kropką (`.env`) bez naszych reguł | **403 z serwera** | ⚠ **SERWOWANE, HTTP 200** |
| host bazy | `addconnect.home.pl:3380` | ten sam |
| `max_input_vars`, `max_execution_time` | 1000 / 30 s | 1000 / 30 s |

Dwa wnioski, oba przekładają się na kod:

1. ⚠ **Blokada plików z kropką przez serwer to przypadek, nie cecha hostingu.** Na jednej usłudze
   tego samego dostawcy `.env` jest odmawiany przez serwer, na drugiej **podawany z treścią**.
   Gdyby ktoś zobaczył pierwszy wynik i uznał to za „dodatkową warstwę, na której można polegać",
   drugi klient tego samego dostawcy dostałby `.env` do pobrania. Stąd reguła bez wyjątku:
   ochrony `.env` **nigdy** nie opieramy na zachowaniu serwera, a krok 7 instalatora
   (weryfikacja HTTP) jest **blokujący**, nie informacyjny.
2. **Obie usługi mają Loader 14.4**, więc decyzja „Encoder 14" obejmuje całego dostawcę,
   a nie jedną usługę. Wersja PHP też wypada identycznie (8.2), mimo że profil `apache`
   teoretycznie pozwala wyżej — o ile istnieje do niej Loader, co trzeba sprawdzić osobno.

Profil `apache` u tego dostawcy honoruje `Require` i `RedirectMatch`, więc **mógłby** mieć drugą
warstwę blokady. Nie włączamy jej domyślnie: maszyna deweloperska ma `AllowOverride FileInfo`,
gdzie `Require` daje **500**, a różnica między „ignorowane" i „500" jest różnicą między
aplikacją działającą a martwą. Jeśli kiedykolwiek ją dołożymy, to wyłącznie przez instalator,
mechanizmem **napisz → sprawdź po HTTP → cofnij przy błędzie**, nigdy jako stały plik w paczce.

### Baza danych: osobny host, konto z `@'%'`, jedno konto

Trzy fakty, które razem zmieniają model zagrożeń tej instalacji:

- baza nie jest lokalna: `addconnect.home.pl:3380` — nazwa **rozwiązuje się publicznie**
  (79.96.118.242 w dniu pomiaru), port niestandardowy,
- konto bazy ma grant na `@'%'`, czyli **z dowolnego adresu**,
- rozdziału kont na tym hostingu zrobić nie można (jedna baza = jeden użytkownik), więc to samo
  konto ma `CREATE`, `DROP` i `ALTER`.

Wnioski:

1. **Wyciek `.env` to nie „dostęp do danych", to pełny dostęp do bazy z internetu** — o ile port
   jest z internetu osiągalny, czego z konta hostingowego nie da się rzetelnie sprawdzić.
   To pytanie do hostingodawcy, nie do skryptu. `.env` z prawami `0640` poza webrootem
   i plik-kanarek przestają być higieną, a stają się głównym zabezpieczeniem tej warstwy.
2. **Połączenie z bazą musi być szyfrowane** (`CLAUDE.md` §4). Diagnostyka sprawdza teraz
   `Ssl_cipher` i — gdy szyfrowania nie ma — próbuje zestawić połączenie TLS, żeby odróżnić
   „hosting nie umie" od „my nie włączyliśmy".
3. `max_allowed_packet` = **8 MB** przy zdalnej bazie: kopia zapasowa przed aktualizacją
   (obowiązkowa w trybie jednego konta) musi operować porcjami wyraźnie mniejszymi,
   a nie jednym wielkim `INSERT`-em.

Otwarte pozycje do potwierdzenia u hostingodawcy: dokładne **parametry SMTP** skrzynki klienta
(dokumentacja podaje 587 bez szyfrowania i porty szyfrowane z panelu, limitów wysyłki nie podaje),
oraz **limity crona uruchamianego przez HTTP** (czy przebieg co 5 minut nie jest traktowany
jako nadużycie przy dłuższych zadaniach).

---

## Źródła

- [Dyrektywa FilesMatch na serwerze w home.pl](https://pomoc.home.pl/baza-wiedzy/czemu-nie-dziala-dyrektywa-filesmatch-na-serwerze-w-home-pl)
- [Wykaz popularnych funkcji pliku .htaccess](https://pomoc.home.pl/baza-wiedzy/wykaz-najbardziej-popularnych-funkcji-pliku-htaccess)
- [Reguły przepisania w mod_rewrite](https://pomoc.home.pl/baza-wiedzy/reguly-przepisania-w-mod_rewrite)
- [Zmiana wersji PHP za pomocą .htaccess (IdeaWebServer)](https://pomoc.home.pl/baza-wiedzy/ustawienie-interpretera-skryptow-za-pomoca-htaccess)
- [Cykliczne uruchamianie — CRON](https://pomoc.home.pl/baza-wiedzy/cykliczne-uruchamianie-cron)
- [Jak korzystać z CRON na hostingu w home.pl?](https://pomoc.home.pl/baza-wiedzy/jak-korzystac-z-cron-na-hostingu)
- [Jak przekierować domenę na wybrany katalog?](https://pomoc.home.pl/baza-wiedzy/jak-przekierowac-domene-na-wybrany-katalog)
- [Czym jest separacja serwisu WWW](https://pomoc.home.pl/baza-wiedzy/czym-jest-separacja-serwisu-www-ktora-wystepuje-przy-przekierowaniu-na-katalog)
- [Blokowanie użytkowników po IP/host przez .htaccess](https://pomoc.home.pl/baza-wiedzy/jak-blokowac-uzytkownikow-po-adresie-iphost-z-wykorzystaniem-htaccess)
- [Zmienne środowiskowe na serwerze w home.pl](https://pomoc.home.pl/baza-wiedzy/zmienne-srodowiskowe-na-serwerze-w-home-pl)
- [Adresy serwerów pocztowych (SMTP, IMAP)](https://pomoc.home.pl/baza-wiedzy/gdzie-znajde-adresy-serwerow-pocztowych-dla-skrzynki-email)
