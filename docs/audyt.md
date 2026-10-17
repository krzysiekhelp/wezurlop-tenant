# wezurlop — przegląd architektury

Stan: 15.09.2026. Pełna wersja z wyjaśnieniami: artefakt **„Audyt architektury wezurlop"**.

## Rozstrzygnięte

**A1 — wersjonowanie kontraktów.** Aplikacja niesie swoją wersję; tenant sprawdza ją przy
uruchomieniu i w razie potrzeby pokazuje komunikat o konieczności aktualizacji. Paczka aktualizacji
tenanta zawiera sekcję z wymaganą wersją aplikacji mobilnej. Connector przedstawia się wersją
przy połączeniu do REST API — za stary dostaje blokadę.

Drugi kierunek (nowa aplikacja, stary tenant) załatwia sama konstrukcja: nowe funkcje jadą
ze strony renderowanej w WebView, więc stary tenant pokazuje po prostu stary interfejs.
Ale prawdziwym kontraktem jest **mostek natywny** — biometria, PIN, skan, aparat, wybór pliku,
rejestracja tokenu, sygnał o zalogowaniu. Stąd reguła:

> **Mostek natywny jest tylko do dopisywania.** Nigdy nie zmieniamy nazwy istniejącego komunikatu
> i nigdy go nie usuwamy — dokładamy nowe. Aplikacja działa poprawnie także wtedy, gdy strona
> nigdy nie zawoła nowszych.

Kontrakt spisany: `docs/mostek-natywny.md`.

**A2 — panel zasila broker logowania.** Broker jest połączony z panelem i trzyma rejestr tenantów
lokalnie (istnienie, prawo do usługi, adres powrotny); panel wypycha zmiany. Broker działa mimo
awarii panelu. *Konsekwencja:* zmiana adresu tenanta musi trafić do brokera natychmiast, inaczej
po przeprowadzce klienta logowanie odsyła pod stary adres.

**A5 — dwa klucze podpisujące.**

- **Wydawniczy** — paczki aktualizacji i pliki discovery. Prywatny nigdy nie dotyka serwera:
  budujesz i podpisujesz u siebie, do panelu wgrywasz gotowy, podpisany artefakt.
- **Operacyjny** — odpowiedzi licencyjne, podpisywane w locie, więc leży na panelu.
- **Rotacja:** operacyjny jest poświadczany wydawniczym (mały podpisany plik „ten klucz jest ważny
  do dnia X"). W instalacjach zaszyty jest tylko klucz publiczny wydawniczy, który się nie zmienia —
  wymiana operacyjnego nie wymaga ruszania niczego u klientów.
- **Promień rażenia:** włamanie do panelu daje najwyżej fałszywe odpowiedzi licencyjne;
  nie pozwala podpisać aktualizacji ani przekierować aplikacji.
- *Zostaje:* gdzie fizycznie stoi maszyna podpisująca, kto ma dostęp, gdzie zaszyfrowana kopia
  zapasowa klucza wydawniczego. Utrata bez kopii = żadna instalacja nie przyjmie już aktualizacji.

**L5 — zdanie „panel widzi tylko telemetrię" wycofane.** Przez infrastrukturę przechodzą:
tokeny urządzeń i treści powiadomień (przekaźnik push), numery telefonów (bramka SMS),
adresy e-mail (broker logowania). Obowiązujący opis: **ścieżki przelotowe, nie magazynujące** —
dane istnieją na czas jednego wywołania, w logach zostaje identyfikator tenanta, czas i wynik.

## Rekomendacja — do decyzji

**A3 — discovery jako pliki statyczne poza panelem.** Discovery to nie nowy element, tylko
mechanizm przyjęty zamiast CNAME. Otwarte było, gdzie mieszka. Po decyzji o ekranie błędu
jego dostępność = dostępność aplikacji dla wszystkich pracowników wszystkich klientów.

Propozycja: jeden podpisany plik na tenanta, `https://d.wezurlop.pl/t/firmax.json` o treści
`{"url":"https://urlopy.firmax.pl","active":true,"sig":"…"}`, na hoście serwującym wyłącznie
pliki statyczne. Panel generuje i podpisuje przy zakładaniu klienta albo zmianie adresu.
Osobny plik na tenanta, nie jedna lista (wspólna lista = wykaz klientów dla każdego).

CNAME odpada z tego samego powodu co wcześniej: wejście na `firmax.wezurlop.pl` wymaga
certyfikatu na **tę** nazwę, a klient ma certyfikat na swoją domenę — efektem jest błąd
certyfikatu, a nie ekran „brak połączenia". Plik statyczny daje dodatkowo pole `active`
i natychmiastową zmianę adresu, bez czekania na cache DNS.

## Odłożone do rozmowy o danym bloku

- **A4** cena braku drogi z góry na dół (czas reakcji = interwał odpytywania)
  → przy projektowaniu połączenia tenant–panel
- **A6** język connectora (PHP = dwa języki zamiast trzech; rozstrzyga dokumentacja R2)
  → przy projektowaniu connectora
- **A7** czy telemetria niesie sygnały bezpieczeństwa → przy projektowaniu telemetrii

| Id | Rzecz | Blok |
|---|---|---|
| B1 | Backup przed aktualizacją poza webrootem | tenant |
| B2 | Klucz i weryfikator podpisu poza zasięgiem aktualizacji | tenant |
| B3 | Kontrola ścieżek przy rozpakowaniu paczki (zip slip) | tenant |
| B4 | Odświeżanie i unieważnianie zapisanego adresu tenanta | aplikacja |
| B5 | Token powiadomień związany z zalogowanym użytkownikiem | tenant + aplikacja |
| B6 | Dwa klucze publiczne discovery w aplikacji od pierwszej wersji | aplikacja |
| B7 | Limit zapytań w discovery (enumeracja klientów) | panel |
| L1 | Instalacja tenanta: `.env`, klucz API, pierwszy administrator | tenant + panel |
| L2 | Cykle w hierarchii, eskalacja na szczycie | tenant |
| L3 | Zwolnienie przerywa urlop i zwraca dni | tenant + integracja |
| L4 | Klucz connectora ograniczony do listy firm | connector |
| L6 | Saldo z wnioskami oczekującymi, jedna transakcja | tenant |
| Z1 | Log audytowy | tenant |
| Z2 | Retencja i usuwanie danych (przed projektem bazy) | tenant |
| Z3 | Procedura odejścia pracownika | tenant |
| Z4 | Dostęp do załączników osobno od dostępu do wniosku | tenant |

**L1 jest realizowane w Etapie 2** — instalator tenanta, `docs/szkielet-tenanta.md` rozdział 12.

## Stan niepełny — do domknięcia przed wydaniem

- **Zakres PHP 8.3–8.5: dolny koniec sprawdzony wykonawczo, górny wyłącznie statycznie.** Podłoga
  to 8.3 (decyzja z 19.09.2026, CLAUDE.md §0), a dev i wszystkie trzy vhosty testowe stoją na 8.3 —
  dolny koniec zakresu jest więc pokryty przebiegiem symulatora, nie deklaracją. Górny koniec (8.4, 8.5)
  sprawdza PHPCompatibility (`phpcs.xml.dist`, bramka w `dev/build-package.sh`) i `composer
  check-platform-reqs` na paczce; ⚠ **przebiegu wykonawczego na 8.4/8.5 nie było** i do tego czasu
  nie twierdzimy, że górny koniec jest przetestowany. ⚠ Drugi zestaw reguł (`phpcs-encoder.xml.dist`,
  od 8.2) **usunięty 20.09.2026**: dokumentacja ionCube podaje, że Encoder 14 obsługuje składnię 8.3,
  a 15 — 8.4, więc przy podłodze 8.3 osobne sprawdzenie niczego nie dokładało. Wraca, gdyby kupiony
  Encoder okazał się starszy niż 14. ⚠ Zasad zgodności Encoder ↔ Loader producent nie publikuje,
  a u klienta odniesienia zmierzyliśmy Loader 14.4 — założenie do czasu zakupu: paczka ma działać na 14.4.
- ⚠ **Jedna gałąź etapu 6 niezmierzona wykonawczo: `RecordApplier::deactivate()` → wpis
  `session.revoked`.** Blokada konta po wygaśnięciu wszystkich zatrudnień dopisuje do dziennika
  jeden wpis z licznikiem uciętych sesji (`{"count": N, "powod": "blokada", "zrodlo": "sync"}`).
  Zmierzona jest **cała zmieniona część repozytorium** (`SyncDomainRepository::blockAccountOfPerson()`
  zwraca `{"konto":6,"sesje":1}`, sesje faktycznie ucięte, żądanie tamtego konta dostaje
  `powod=konto-zablokowane`), ale sam wpis powstaje w `RecordApplier`, a jedynym jego wykonawcą
  jest przebieg synchronizacji. Symulatora nie uruchamiamy (decyzja 21.09.2026), a wygaszenie
  prawdziwego zatrudnienia w bazie roboczej dla jednego pomiaru jest droższe niż zysk.
  **Domknie się samo** przy pierwszym przebiegu synchronizacji w Etapie B — do tego czasu
  nie twierdzimy, że ta gałąź jest sprawdzona. Droga ręczna (`AccountService::block()`) jest
  zmierzona końcem w koniec.
- ⚠ **Znane ograniczenie bramki ekranowej: jest ślepa na wszystko, co zależy od cache'u
  przeglądarki.** Playwright startuje z **pustym profilem**, więc pobiera każdy zasób od nowa.
  Zmierzone 22.09.2026: przez pięć dni arkusze jechały pod ręcznie wpisanym `?v=20260917`,
  więc przeglądarka pracująca od rana pokazywała **stary arkusz z nowym HTML-em** — a bramka
  i wszystkie pomiary agenta pokazywały stan poprawiony. **Żaden z tych pomiarów nie był zły
  i żaden nie mógł tego zobaczyć.** Wniosek na przyszłość: skutki wdrożenia sprawdza się
  na profilu przeglądarki, który **ma** poprzedni stan (`launch_persistent_context`), a nie
  na świeżej instancji; a pomiar po wdrożeniu robi się po **świeżym wejściu na adres**,
  nie w karcie stojącej otwarte (to samo w drugą stronę: stary HTML wskazuje stare adresy
  zasobów i wygląda jak usterka, której nie ma).
- ⚠ **Otwarte przy `Cache-Control` dla `/assets/` (zlecenie po 7.2): stała wartość zapasowa
  `ASSET_VERSION`.** Zasób spoza manifestu dostaje dziś **stałą** wersję, czyli zachowuje się
  przy cache'u dokładnie jak data wpisana ręcznie. Z `immutable` i rocznym `max-age` taki plik
  zamarza u klienta **na rok bez drogi wyjścia**, a w paczce kodowanej Encoderem nie naprawimy
  tego zdalnie. Warunek wejścia `immutable`: `dev/build-package.php verify` odrzuca paczkę,
  w której którykolwiek zasób **używany w szablonach** nie jest w manifeście, a lista zasobów
  powstaje z **przeszukania szablonów**, nie z listy wpisanej ręcznie. Do tego czasu `max-age`
  liczony w godzinach.
- ⚠ **Bramka ekranowa nie patrzy na okno laptopa — dwa ekrany przewijają się w bok i nikt tego
  nie widzi.** Zmierzone 22.09.2026: `dev/bramka-ekranowa.py` chodzi po **1920 px** (web)
  i **374 px** (telefon). Przy 1920 karta ma 1558 px i wszystko się mieści; przy typowych
  szerokościach laptopa nadmiar w `.table-scroll` wynosi:

  | Tabela | 1920 | 1600 | 1440 |
  |---|---|---|---|
  | `/dane/synchronizacja` (9 kolumn) | 0 | **79 px** | **239 px** |
  | `/diagnostyka` tabela 7 | 0 | **189 px** | **349 px** |
  | `/diagnostyka` tabela 9 | 0 | **55 px** | **215 px** |
  | `/diagnostyka` tabela 10 | 0 | 0 | **28 px** |

  ⚠ Kolejność naprawy ustalona 22.09.2026: **7.2 (SMTP) → zawężenie tych dwóch ekranów →
  dołożenie 1440 px do bramki, w jednym zleceniu.** Powód takiej kolejności: bramka, która
  świeci na czerwono od pierwszego dnia, przestaje być bramką — a te usterki są starsze
  niż sprawdzenie, które je znalazło.
- **Klasa hostingów odpadła świadomie:** usługa `IdeaWebServer` w home.pl kończy się na PHP 8.2,
  więc tam instalacja wymaga zmiany typu usługi na Apache. Profil `ideawebserver` (`.htaccess`, cron
  przez HTTP, `PATH_INFO`) zostaje w kodzie i w testach — zachowanie serwera nie zależy od wersji PHP.

---

## Hosting odniesienia — home.pl, zmierzony 17.09.2026

Wynik uruchomienia `tools/diagnostyka-hostingu.php` na koncie produkcyjnym.
**Wniosek ogólny: hosting się kwalifikuje.** Cztery rzeczy zmieniają jednak decyzje projektowe:

| Znalezisko | Skutek |
|---|---|
| ionCube **Loader 14.4** | paczki kodujemy **Encoderem 14**, nie 15 — Loader 15 uruchomi wyjście Encodera 14, ale Loader 14.4 **nie uruchomi** wyjścia Encodera 15, a „buduj pod starszy Loader" nie jest udokumentowaną opcją. Do sprawdzenia u ionCube: czy Encoder 14 jest jeszcze do kupienia. |
| konto MySQL **bez `CREATE USER` i bez `GRANT OPTION`** (`1227 Access denied`), a w panelu **jedna baza = jeden użytkownik o tej samej nazwie** | rozdziału kont na tym hostingu **zrobić nie można**, więc wymóg zostaje obniżony: dwa konta są **zalecane**, jedno **dopuszczalne** (`DB_SINGLE_ACCOUNT=1`, wpis w `event_log`, trwała pozycja na `/diagnostyka`, bezpiecznik DDL na profilu aplikacji, obowiązkowa kopia przed aktualizacją). Wymóg, którego klient nie umie spełnić, kończy się kontem z pełnymi prawami i ciszą w dokumentacji. |
| `IdeaWebServer` **po cichu ignoruje** `Require` i `RedirectMatch`, ale honoruje `RewriteRule … [F]` (403) | blokady opieramy na `[F]`; obecność reguły w `.htaccess` nie dowodzi niczego — liczy się wyłącznie **weryfikacja HTTP** z kroku 7. Wariant 2 wdrożenia pozostaje wykonalny, bo przepisywanie działa. |
| listowanie katalogów wyłączone, HTTPS obecne | bez zmian w projekcie. |

Pełny wynik pomiaru (45 pozycji OK, 0 do zmiany) i wszystkie zachowania serwera:
**`docs/profile-hostingu.md`**. Trzy z otwartych pytań domknęła dokumentacja home.pl:

| Pytanie | Odpowiedź |
|---|---|
| katalog domeny na `public/` | **da się** — panel, „przekierowanie na podkatalog"; ⚠ **separację serwisu (chroot) trzeba wyłączyć**, jeśli aplikacja leży powyżej katalogu domeny, bo odcina `vendor/` i `src/` |
| cron | **jest, co 5 minut**, ale mechanizm jest inny: plik **`cron-05min.php` w katalogu domeny**, uruchamiany **przez HTTP** (a więc limit 30 s i osiągalny z internetu) |
| SMTP | port 587 bez szyfrowania, porty szyfrowane i adres serwera z panelu klienta; **limitów wysyłki dokumentacja nie podaje** — zostaje do potwierdzenia |

Dwa znaleziska, których nie szukaliśmy, a które zmieniają projekt:

- ⚠ **`<FilesMatch>` na IdeaWebServer nie działa** (potwierdzone dokumentacją home.pl; działa
  `<Files ~ "…">`). Każda blokada napisana w `FilesMatch` jest tam **niewidocznie nieobecna** —
  to wyjaśnia ciche ignorowanie z pomiaru. Blokady opieramy wyłącznie na `RewriteRule … [F]`.
- ⚠ **`SetEnv` nie jest w dokumentacji home.pl potwierdzone**, a `WEZURLOP_APP_PATH` stał na nim
  jako na jedynej drodze. Kolejność szukania odwrócona: **plik `public/app-path.php`** jest
  drogą podstawową, zmienna środowiskowa wariantem zapasowym.

Ryzyko Etapu 3 (`max_input_vars = 1000`) ma teraz obejście: w tym profilu PHP działa jako CGI
i honoruje **`php.ini` w katalogu skryptu** (`user_ini.filename=php.ini`), więc limit podnosimy
sami. Formularze zbiorcze i tak projektujemy stronicowane — obejście jest zabezpieczeniem,
nie zwolnieniem z projektu.

---

## Poczta: kolejka raportuje „przekaźnik przyjął", nie „doszło" (22.09.2026)

⚠ **Znane ograniczenie, świadomie niezbudowane.** Zadanie `mail.invitation` kończy się stanem
`done` w chwili, gdy serwer SMTP **przyjął** wiadomość — i to jest jedyna rzecz, którą kolejka
wie. Zmierzone na zadaniu `#176`: `created_at 09:41:47`, `finished_at 09:45:02`. To znaczy
„wyszło od nas”, a **nie** „dotarło do adresata”.

Odbicia (bounce) wracają **asynchronicznie, na skrzynkę nadawcy**, której nie czytamy: adres
nieistniejący, pełna skrzynka i odrzucenie przez filtr antyspamowy przychodzą jako osobna
wiadomość kilka sekund do kilku dni później. W systemie nie pojawia się nic — konto zostaje
w stanie `zaproszony`, panel pokazuje „wiadomość wyszła”, a człowiek nigdy jej nie dostał.

Dlatego ekrany mówią dokładnie tyle, ile wiemy: „**wiadomość wyszła** 22.09.2026, 12:47:45
(Europe/Warsaw) — tyle wie kolejka; potwierdzenia dostarczenia nie mamy”. Zaokrąglenie tego
do „wysłano” byłoby tą samą klasą nieprawdy co „1 min temu” na karcie otwartej przed godziną.

**Obsługa odbić to osobna praca** i nie jest w tym etapie zbudowana. Wymaga co najmniej:
skrzynki zwrotnej (`Return-Path`/VERP z identyfikatorem konta), jej odpytywania (IMAP —
**nowa zależność i nowe wymaganie wobec hostingu**), rozróżnienia odbicia trwałego od
chwilowego oraz stanu konta „adres nie działa”. Do tego czasu jedyną drogą jest to, co jest:
administrator widzi datę wysłania, poprawia adres w panelu i wysyła ponownie.

## Usterka: zaproszenie osoby, której konto `niezaproszone` ma już ten adres (22.09.2026)

⚠ **Zmierzone, nienaprawione** (do decyzji — CLAUDE.md §17). `InvitationService::inviteExisting()`
odrzuca żądanie **zanim** dojdzie do gałęzi, która je obsługuje:

```
POST /admin/konta/zapros  osoby[]=107   (osoba z kartoteki, konto #12 status `niezaproszone`,
                                         adres już użyty przez inne konto)
→ Pominięte (1 z 1): „adres jest już użyty przez inne konto"
```

Sprawdzenie `findByEmail($email) !== null` nie wyłącza **własnego konta tej osoby**, więc
ścieżka `niezaproszone → invited` (`promoteToInvited`, dopisana świadomie właśnie na ten
przypadek) jest **martwa** wszędzie tam, gdzie istniejące konto ma już zapisany adres.
To ta sama rodzina co pomiar z 21.09: *wcześniejsza warstwa odrzuca żądanie, więc poprawka
w warstwie późniejszej wygląda słusznie i nigdy się nie wykonuje.*

Skutek u klienta: osoba, której nadano dostęp przed zaproszeniem, **nie da się zaprosić**
z listy kont — bez żadnej drogi obejścia w interfejsie. Naprawa to jeden warunek
(pominąć konto o `person_id` równym zapraszanej osobie), ale zmienia zachowanie ścieżki
zaproszeń, więc nie robię jej przy okazji tego zlecenia.

## Pytania do autora connectora R2 (22.09.2026)

Dwie rzeczy, których **nie rozstrzygniemy u siebie** — obie wyszły przy budowie widoku
pracownika i obie dotyczą tego, co R2 **naprawdę** przysyła i pokazuje.

### 1. Dzielnik minut przy dniówce innej niż 8 h

`ksztalt-danych-r2.md` (rekord `limit`) mówi: *„dni = min ÷ 480, zawsze 480, także przy
niepełnym etacie”*. To zdanie rozstrzyga **niepełny etat** — pół etatu nie zmienia dzielnika.
Nie rozstrzyga **dniówki równoważnej**: w danych roboczych są kalendarze `Równoważny(12)`
i `Równoważny(14h)` z `dobowaNormaMin` 720 i 840, a `normaUrlopowaMin` mimo to 480.

**Pytanie:** co program kadrowy pokazuje pracownikowi z kalendarzem 12-godzinnym, który ma
`pozostaloMin = 5760`? **12 dni** (5760 ÷ 480) czy **8 dni** (5760 ÷ 720)?

⚠ Do czasu odpowiedzi obowiązuje **480 na wszystkich naszych ekranach** (rozstrzygnięcie
z 22.09.2026, `WorkTime::MINUT_W_DNIU`). Powód wyboru nie jest merytoryczny, tylko taki,
że **dwie różne liczby dni na dwóch naszych ekranach to najgorszy możliwy wynik**: pracownik
i kadrowa czytają z tych samych minut co innego i nie ma czym rozstrzygnąć, która wersja
jest ważna. Zanim była ta decyzja, karta kadrowej dzieliła przez `normaUrlopowaMin`,
a pulpit pracownika przez 480 — czyli dokładnie ten stan.

### 2. Czy R2 wypełnia pole `kontynuacja`

Zwolnienie rozbite w kadrach na kilka wpisów sklejamy w jedną pozycję **wyłącznie** na podstawie
pola `kontynuacja` (`external_absence.continuation`). W danych roboczych to pole jest
**`NULL` we wszystkich 1572 wpisach**, więc ta ścieżka nie wykonała się ani razu na prawdziwych
danych — sprawdza ją tylko test.

⚠ Jednocześnie w danych **są** pary wyglądające na jeden ciąg rozdzielony granicą miesiąca
(ten sam rodzaj, sąsiadujące dni), np. `26–31.01` i `01–06.02`. **Nie sklejamy ich** — domyślanie
się ciągu z samych dat byłoby liczeniem za kadry, a wtedy pracownik widziałby liczbę dni,
której nie ma w żadnym rekordzie.

**Pytanie:** czy R2 to pole w ogóle wypełnia, i czym rozdziela ciąg przechodzący przez granicę
miesiąca? Jeśli nie wypełnia, pracownik będzie widział dwie choroby tam, gdzie była jedna —
i zgłosi to jako błąd naszego systemu.
