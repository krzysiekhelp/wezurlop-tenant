# Środowisko uruchomieniowe tenanta

## Wymagania

| Element | Wymaganie |
|---|---|
| PHP | zakres **8.3–8.5** (`composer.json`: `>=8.3 <8.6`, `config.platform.php` 8.3.0); dev i vhosty testowe na 8.3; poniżej 8.3 instalator blokuje, powyżej 8.5 ostrzega |
| ionCube Loader | wymagany, ładowany przed OPcache |
| Rozszerzenia wymagane | `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `iconv`, `xmlwriter` |
| Rozszerzenia zalecane | `sodium`, `intl`, `zip`, `gd`, OPcache — brak = ostrzeżenie, nie błąd |
| Baza | MySQL 8, dwa konta na bazę: aplikacyjne (DML) i migracyjne (DDL) — §4 |
| Serwer WWW | obsługa `.htaccess` z `AllowOverride` co najmniej `FileInfo`; kod nie zakłada Apache'a |
| `max_execution_time` | zakładamy 30 s — wszystko dzielone na porcje |

Zgodność kodu z zakresem sprawdza statycznie PHPCompatibility (zależność deweloperska `10.0.0-alpha2` —
jedyna wersja znająca PHP 8.x): `vendor/bin/phpcs` (`phpcs.xml.dist`, `testVersion 8.3-8.5`) jest bramką
zakresu instalacyjnego i blokuje build paczki. ⚠ **To jedyny zestaw reguł od 20.09.2026** — drugi
przebieg „od 8.2” (granica składni z Encodera) zniknął, bo Encoder 14 obsługuje składnię 8.3, a 15 — 8.4,
więc przy podłodze 8.3 jeden zestaw pokrywa oba warunki (CLAUDE.md §0 i §15).
Przebiegu wykonawczego na 8.4/8.5 nie było — patrz `docs/audyt.md`.
Wymagania dla hostingu klienta: `docs/wymagania-hostingu.md`.

`sodium` nie jest wymagane: projekt używa `paragonie/sodium_compat`, które przy braku
rozszerzenia liczy w czystym PHP z identycznym API.

### Baza testowa — stan docelowy środowiska deweloperskiego

⚠ **Dane klienta i dane regresji nie dzielą jednej bazy** (rozstrzygnięcie 20.09.2026). Baza robocza
(`DB_NAME` z `.env`) trzyma przebieg integracyjny na prawdziwych danych, a regresja symulatora chodzi
na `<nazwa-bazy>_test`, którą kasuje i odtwarza przy każdym uruchomieniu.

| Co | Jak wskazać bazę testową |
|---|---|
| symulator i migracje z jego poziomu | `bin/sync-symulator.php --baza=<nazwa-bazy>_test` (ustawia `WEZURLOP_DB_NAME`) |
| instancja HTTP paczki (vhost `paczka.localhost:8090`) | `PACZKA_DB=<nazwa-bazy>_test dev/package-vhost.sh` |
| phinx uruchamiany ręcznie | `WEZURLOP_DB_NAME=<nazwa-bazy>_test vendor/bin/phinx migrate -c config/phinx.php` |
| narzędzia deweloperskie | `dev/utworz-administratora.php --baza=…`, `dev/przygotuj-wniosek.php --baza=…` |

⚠ `WEZURLOP_DB_NAME` czytamy **wyłącznie ze zmiennej środowiskowej procesu** — w zwykłym uruchomieniu
obowiązuje `DB_NAME` z `.env`, więc aplikacja u klienta nie zna tego przełącznika.

⚠ Dwie pomyłki, które symulator wyłapuje sam, bo obie kończą się serią `401` i wyglądają jak błąd kodu:
`--baza=` z celem innym niż `paczka` (cele `tenant` i `tenant-cgi` czytają bazę z `.env`) oraz paczka
zbudowana bez `PACZKA_DB` (seed szedłby do jednej bazy, żądania do drugiej).

Pełną listę sprawdza diagnostyka (`Wezurlop\Diagnostics\EnvironmentCheck`, CLAUDE.md §15) —
jeden zestaw sprawdzeń dla instalatora, strony `/diagnostyka` (M8) i CLI:

```bash
php bin/diagnose.php                                   # APP_URL i APP_BASE_PATH z .env
php bin/diagnose.php --resolve=127.0.0.1               # z pominięciem DNS (NAT bez hairpinu)
php bin/diagnose.php --app-url=http://tenant-cgi.wezurlop.pl --base-path=/public --resolve=127.0.0.2
```

W CLI prawa zapisu sprawdzane są jako użytkownik powłoki, nie serwera WWW. Strona `/diagnostyka`
(uprawnienie `admin.settings`) uruchamia te same sprawdzenia w kontekście serwera WWW.

## Baza danych i migracje

Migracje (phinx) puszcza **wyłącznie** konto migracyjne (`DB_MIG_USER`); aplikacja pracuje
kontem `DB_USER` z samym DML. Czas w bazie w UTC (`time_zone = '+00:00'` na połączeniu).

```bash
vendor/bin/phinx migrate  -c config/phinx.php
vendor/bin/phinx rollback -c config/phinx.php -t 0     # do zera
vendor/bin/phinx seed:run -c config/phinx.php          # tylko APP_ENV=dev, tylko pusta baza
```

⚠ **`rollback -t <wersja>` cofa DO tej wersji, czyli WSZYSTKO po niej — nie jeden krok.**
Zmierzone 20.10.2026 (Z67): cofnięcie z `-t` ustawionym na wersję *przed* własną migracją
zdjęło także `CalendarAdminsDefaultRule`, czyli skasowało reguły `admins_all` i odebrało
administratorom wgląd w kalendarz. Żeby cofnąć **tylko ostatnią** migrację, podaje się wersję
tę, która ma zostać (`-t 20261020120001`), albo używa się `rollback` bez `-t`. Przy odbiorze
migracji (`migrate` → `rollback` → `migrate`) sprawdzaj po cofnięciu **nie tylko swoją zmianę**,
ale i to, czy nie zniknęło coś z migracji sąsiedniej.

⚠ **Migracja, która zmienia DANE, a nie schemat, ma powiedzieć, czego `down()` nie odtworzy.**
`20261021180001_normalize_person_phones` przepisuje `person.phone` na jedną postać
(`+48XXXXXXXXX`) regułą z `Support\Telefon` — nie kopią tej reguły w SQL, bo dwie definicje
poprawnego numeru rozjadą się przy pierwszej zmianie. Jej `down()` jest **świadomie puste**:
cofnięcie nie odtworzy stylu zapisu („600 100 200" kontra „600-100-200"), ale żadna informacja
o człowieku nie ginie, bo cyfry zostają te same. Alternatywa — tabela z kopią starych wartości —
byłaby **drugim miejscem przechowującym numery telefonów** (dane osobowe, §14), którego po
migracji nikt nie czyści. Numer, którego nie da się znormalizować, migracja **zostawia** i liczy;
zgadywanie, co ktoś miał na myśli, zmieniałoby daną osobową na podstawie domysłu. Wyjście
migracji i wpis w dzienniku niosą **same liczby** — nigdy numer.

⚠ **Czas w `phinxlog` jest LOKALNY, a w naszych tabelach UTC** — ta sama operacja ma w logu
migracji inną godzinę niż w danych, które założyła. Zmierzone 20.10.2026: migracja
`20261020120001` ma w `phinxlog` `start_time = 10:02:07`, a wiersz, który wstawiła, ma
`created_at = 08:02:07`. Nic tu nie jest zepsute — `phinxlog` zapisuje czas PHP-em
(strefa `Europe/Warsaw`), a nasze tabele biorą czas z bazy (`time_zone = '+00:00'`, patrz wyżej).
Pamiętaj o tym, zanim zaczniesz szukać dwugodzinnej dziury przy diagnozie u klienta; różnica
równa przesunięciu strefy to ta właśnie sytuacja, a nie zdarzenie z przeszłości.

Seed tworzy dwie fikcyjne firmy, administratora tenanta (bez zatrudnienia), osobę z dwoma
zatrudnieniami i osobę z jednym; hasła są losowe i wypisywane jeden raz.

⚠ **Baza robocza dev ma trzy aktywne konta administratora tenanta** (zmierzone 21.09.2026:
konto robocze właściciela, konto bramki ekranowej,
konto pomiarowe biura rachunkowego) i nie jest to zaniedbanie: reguł „nie można
zablokować konta, na którym jesteś zalogowany" i „nie można zablokować ostatniego aktywnego
administratora" **nie da się zmierzyć z jednego konta** — pierwsza wymaga drugiego administratora,
żeby blokada w ogóle była dozwolona, a druga wymaga takiego, który zostanie. Blokady nie mierzymy
na koncie roboczym: pomyłka zamyka dostęp osobie, która ma go odblokować. Hasła tych kont są losowe,
40 znaków, i **leżą poza repozytorium** (plik `~/.wezurlop-dev-konta`, prawa `0600`) — w paczce
klienta żadnego z nich nie ma, bo powstają narzędziem z `dev/`.

⚠ **Dwa konta pracownicze aktywowały się w pomiarach 22.09.2026 i zostają aktywne** —
to materiał do kolejnych pomiarów, nie przypadek:

| Konto | Osoba | Jak się aktywowało |
|---|---|---|
| `#14` | osoba z kartoteki, adres z plusem na skrzynkę właściciela | prawdziwe zaproszenie wysłane kolejką przez SMTP; Krzysztof kliknął link i ustawił hasło (09:18:21 UTC) |
| `#5` | osoba z kartoteki, adres w domenie `example.test` | pomiar ścieżki „Wyloguj się i ustaw hasło" z ekranu wyboru przy zalogowanej sesji (hasło ustawione z linku) |

⚠ Oba **liczą się do licencji** (licencja liczy dostępy do spółek przy niezablokowanych kontach),
więc licznik na `/admin/konta` i `/licencja` jest o tyle wyższy — to nie usterka licznika.
⚠ Hasło konta `#5` powstało w pomiarze i **nie jest zapisane** nigdzie; gdyby było potrzebne,
idzie przez reset hasła jak każde inne.

⚠ **Pięć kont w stanie `invited` BEZ czynnego zaproszenia** (sprzątnięte 22.09.2026, decyzja
Krzysztofa „unieważniamy"): `#4`, `#11`, `#12`, `#17`, `#18`. Konta `#17` i `#18` **powstały w pomiarach** terminu wysyłki
(zaproszenia partią dla osób 148 i 76), reszta to ponowienia i pomiar naprawy zapraszania.

- stan „zaproszone bez czynnego linku" jest **zamierzony i odwracalny** przyciskiem
  „Wyślij ponownie" — ten sam stan powstaje normalnie między unieważnieniem a przebiegiem kolejki,
- konta `invited` **nie liczą się do licencji** (liczą się dostępy przy kontach niezablokowanych,
  a te nie mają ani hasła, ani dostępu),
- ⚠ adres prywatny osoby `76` **zostaje niezmieniony** — jest tam ten, który
  ma być; konto dopisano obok, adresu nie ruszono,
- w dzienniku każde unieważnienie ma wpis `account.invitation_revoked` z etykietą aktora
  „sprzątanie danych próbnych (CLI)" — żeby za tydzień nie szukać, kto je zdjął.

⚠ W tym samym pliku leżą hasła **dwóch kont bez uprawnień administracyjnych** (`#3` bez dostępu,
`#6` pracownik z dwoma zatrudnieniami). Powód jest taki sam jak wyżej: „ekran wymaga uprawnienia"
da się zmierzyć tylko z konta, które **działa**, a tego uprawnienia nie ma — z konta administratora
wychodzi 200, a z konta bez żadnego dostępu 403 na każdym adresie, więc żadne z nich niczego
nie dowodzi (etap 6: 200 na `/` i `/wnioski`, 403 na `/admin/dziennik`).

## Kolejka zadań i poczta

Wysyłka poczty idzie wyłącznie przez kolejkę (`job`) — żądanie użytkownika tylko zapisuje zadanie.
`bin/run-jobs.php` wykonuje zadania porcjami (budżet 20 s), z ponowieniami co 1, 4, 9, 16 minut
(5 prób). Tokeny z linków powstają dopiero przy wysyłce — w bazie i w ładunku zadania nie ma ich
jawnej postaci.

```bash
# cron co 5 minut, jako ten sam użytkownik co PHP serwera WWW (na dev: apache)
*/5 * * * * /usr/bin/php /home/dev/tenant/bin/run-jobs.php >/dev/null
# zaproszenie nowej osoby (link wyśle kolejka)
sudo -u apache php bin/invite.php --email=... --imie=... --nazwisko=... --rola=employee --firma=1
```

### Konfiguracja poczty (etap 7.2)

⚠ **`MAIL_*` w `.env` nie jest już czytane.** Serwer SMTP, port, szyfrowanie, użytkownik, hasło,
adres i nazwa nadawcy siedzą w **bazie** (`tenant_setting`, klucze `mail_*`) i zmienia się je
na ekranie **Ustawienia → Poczta** (`/ustawienia/poczta`, uprawnienie `admin.settings`).
Powód: serwer poczty klienta zmienia się po instalacji, a `.env` pisze instalator raz — edycja
pliku przez FTP to ryzyko literówki w miejscu, które przy błędzie wywraca **całą** aplikację.
Dlatego z `Support\Config` zniknęło też sprawdzanie konfiguracji poczty: brak `MAIL_HOST`
dawał wyjątek w konstruktorze konfiguracji, czyli biały ekran na każdym adresie.

- **hasło SMTP szyfrowane** kluczem `APP_KEY` (`SecretBox`, ten sam mechanizm co sekret TOTP);
  w bazie widać wyłącznie szyfrogram `v1:…`. Ekran nigdy nie oddaje hasła do przeglądarki:
  ⚠ **puste pole przy zapisie znaczy „nie zmieniaj"**, a wyczyszczenie hasła jest osobnym
  przyciskiem (zmierzone: zapis z pustym polem zmienia port, a hasło zostaje),
- **wysyłka próbna** z ekranu idzie **w żądaniu** (świadomy wyjątek od „poczta tylko kolejką"),
  z limitem 10 s, i pokazuje komunikat serwera SMTP **wprost** — bez niego „nie udało się wysłać"
  nie mówi, czy problem jest w haśle, porcie, czy w certyfikacie,
- ⚠ **transport plikowy tylko przy `APP_ENV=dev` i tylko gdy poczta jest nieskonfigurowana.**
  W produkcji brak konfiguracji to **błąd**: zadanie kolejki kończy się niepowodzeniem
  z czytelnym komunikatem i jest ponawiane. Zmierzone: `APP_ENV=production` bez SMTP →
  zadanie `mail.security_alert` wraca do `pending` z błędem „Poczta wychodząca nie jest
  skonfigurowana…", zamiast zameldować sukces po zapisie do pliku,
- `/diagnostyka` ma grupę **Poczta**: czym wysyłamy, **kiedy ostatnio coś wyszło** (znacznik
  stawia tylko wysyłka SMTP — zapis do pliku udaje się zawsze, więc nie dowodzi niczego)
  i czy skrzynka alertowa ma adresata.

Na dev bez konfiguracji SMTP: wiadomości w `var/mail/*.eml` (katalog 700, pliki 600, właściciel
`apache`) — zawierają jednorazowe tokeny, więc nie ma ich w `storage/logs`. Podgląd:
`sudo ls -t var/mail/ | head` i `sudo cat var/mail/<plik>`.

## Logowanie i 2FA — uwagi deweloperskie

- Hasła: SHA-256 + base64 przed bcryptem (CLAUDE.md §9) — hasła dłuższe niż 72 bajty działają.
- Limity prób: po 5 nieudanych na konto (30 na adres) w 15 min kolejne są opóźniane 1, 2, 4… s
  (maks. 5 min); próby w trakcie opóźnienia nie są liczone; reset hasła zeruje licznik konta.
  Próg dla całej instalacji (`tenant_setting.login_alert_threshold`, domyślnie 50 / 15 min)
  nikogo nie blokuje — wpis `security.login_spraying_suspected` i alert na `alert_email`,
  najwyżej raz na godzinę.
- Lista urządzeń: `/ustawienia/urzadzenia`; administrator kończy sesje cudzego konta przez
  `POST /admin/konta/{id}/sesje/zakoncz`.
- Sekret TOTP w bazie zaszyfrowany `APP_KEY` (`v1:` + base64); zmiana `APP_KEY` wymusza ponowne
  parowanie wszystkich kont.
- Kod QR: `GET /ustawienia/2fa/kod.svg`, lokalnie (Bacon), tylko w trwającym parowaniu (10 min).
- Kody zapasowe widoczne i do pobrania wyłącznie do potwierdzenia „zapisałem je” (sesja, zaszyfrowane);
  do tego czasu `Require2fa` kieruje na ekran kodów.
- Ten sam kod TOTP nie przejdzie drugi raz — także w innym formularzu w tym samym 30-sekundowym oknie
  (np. logowanie i zaraz potem wyłączenie 2FA).
- Reset 2FA innego konta: `POST /admin/konta/{id}/2fa/reset` (uprawnienie `admin.users`, wpis
  `2fa.reset_by_admin` w `event_log`). Ekran listy kont — M7.

## Dziennik zdarzeń — ekran (etap 6)

- `GET /admin/dziennik`, uprawnienie **`admin.event_log`** (do etapu 6 nadane, ale nieużywane przez
  żadną trasę). ⚠ Sprawdza je `Authorize` na każdym żądaniu; pozycja w menu pilnuje **tego samego**
  uprawnienia, a ekran **nie ma wariantu fragmentowego** — stronicowanie i filtr to zwykłe `GET`-y,
  więc nie istnieje druga droga do tych danych (CLAUDE.md §5, §10). Zmierzone: konto pracownika
  z działającą sesją (200 na `/` i `/wnioski`) dostaje **403** na `/admin/dziennik`, także
  z parametrami filtra, i nie widzi pozycji w menu.
- 50 wpisów na stronę (`?strona=`), filtr `?konto=` z **białej listy kont obecnych w dzienniku** —
  wartość spoza listy znaczy „wszystkie", nie błąd.
- ⚠ Filtr po koncie ma **trzy członki**: konto jako sprawca, konto jako podmiot i osoba tego konta
  jako podmiot. Bez drugiego i trzeciego historia konta nie zawierałaby jego **własnej** blokady —
  przy `account.blocked_manually` sprawcą jest administrator. Zmierzone na koncie #15: 14 wpisów
  jako sprawca, 21 po filtrze.
- Kolumna „kto": osoba rozwiązywana z `account_id` (konto → osoba) **w chwili czytania**,
  `actor_label` tylko dla wpisów bez konta (`sync`, `narzedzie`); gdy nie uda się nic —
  `konto #N — nieznane`, nigdy pusta komórka. Powód: `actor_label` jest `NULL` w 427 z 481 wpisów.
- Etykiety kodów: `Support/KodyZdarzen.php`. ⚠ **Surowy kod zostaje widoczny obok etykiety** —
  nowy albo składany w locie kod (`leave.<akcja>`) nie może pokazywać się na ekranie audytowym
  jako coś innego, niż jest.
- `account.block_refused` ma **to samo wyróżnienie co blokada** (`chip--danger`) z powodem odmowy
  w kolumnie szczegółów: odrzucona próba pozbawienia tenanta zarządzania to wpis, który pierwszy
  powie, że ktoś próbował.
- Blokada konta (ręczna i z synchronizacji) dopisuje **jeden** wpis `session.revoked`
  z licznikiem: `{"count": N, "powod": "blokada", "zrodlo": "reczna|sync"}` — konto z sześcioma
  urządzeniami nie daje sześciu wierszy o tej samej decyzji.
- Ładunki (`detail`) pokazujemy bez filtra: audyt 481 wpisów przed uruchomieniem ekranu nie znalazł
  adresów, PESEL-i, NIP-ów, telefonów ani tokenów. ⚠ Gdy pojawi się pierwszy ładunek budowany
  z danych użytkownika, reguła należy do **zapisu**, nie do wyświetlania (CLAUDE.md §14).
  `ip_hash` do widoku nie wychodzi wcale.

## Widok (M7)

- Dwa warianty jednej aplikacji: web i mobilny pod prefiksem `/m` (wybór wyłącznie po adresie;
  nagłówek `X-Wezurlop-App` przy wejściu na `/` przekierowuje raz do `/m`).
- Zasoby lokalnie w `public/assets`: htmx 2.0.10 (`js/vendor/htmx.min.js`, sha256
  `71ea6718…c0de`, licencja w `htmx.LICENSE.txt`), font Inter 5.3.0 z @fontsource-variable
  (`fonts/inter`, SIL OFL w `LICENSE.txt`), logo i maskotka z paczek graficznych.
  Po aktualizacji zasobów podnieść `ASSET_VERSION` w `.env`.
- htmx: `includeIndicatorStyles:false`, `allowEval:false`, `allowScriptTags:false`,
  `selfRequestsOnly:true` (meta `htmx-config` w `partials/head.twig`).
- Testy przeglądarkowe (CSP w konsoli, htmx, brak JS) uruchamiane Playwrightem z Chromium spoza
  repozytorium; Chromium potrzebuje bibliotek systemowych (`nss`, `atk`, `mesa-libgbm`, `libX*`,
  `alsa-lib`, `libxkbcommon`) — doinstalowane z AppStream.

### Klient mobilny i `/styleguide` (M8b)

- Rozpoznanie powłoki: ciastka `wu_client=app` i `wu_app=1.0.3/android/1` (zapasowo nagłówek
  `X-Wezurlop-App`). Po zalogowaniu decyduje `session.client` / `session.app_version`.
  To znacznik prezentacji — nie wpływa na uprawnienia.
- `mobile_access` w `tenant_setting`: migracja wpisuje `app_only`, seed deweloperski ustawia `open`
  (przeglądarka otwiera `/m/…`). Przy `app_only` przeglądarka na `/m/…` dostaje przekierowanie
  na adres webowy (tylko GET/HEAD).
- Symulacja powłoki w przeglądarce: w narzędziach dodać ciastko `wu_client=app` dla hosta tenanta.
  Na `tenant-cgi` (HTTP) ciastko musi być bez flagi `Secure` — powłoka ustawia `Secure`, więc
  na produkcji wymagany jest HTTPS.
- `/styleguide` i `/m/styleguide` — wszystkie komponenty na jednym ekranie, **tylko** przy
  `APP_ENV=dev` (w produkcji trasa nie powstaje: `config/routes.php` zwraca funkcję `(bool $isDev)`).
- Po zmianie CSS/JS podbić `ASSET_VERSION` w `.env` (na dev: data zmiany).

## Licencja (M8)

- Strefa kodowana `src/License` (`LicenseGate`, `LicenseReader`, `LicenseValidator`, `LicenseState`);
  reszta aplikacji zna wyłącznie `LicenseGate`. Plik licencji `var/license.json`
  (`{"payload": base64, "signature": base64}`, podpis Ed25519 przez `sodium_compat`).
- Klucz **publiczny** panelu w pliku (`LICENSE_PUBLIC_KEY_PATH`, domyślnie `config/license-public.key`,
  w repozytorium zaślepka). Klucza prywatnego nie ma w repozytorium ani na tej maszynie poza
  scratchpadem testowym.
- Na dev: `LICENSE_PUBLIC_KEY_PATH=var/license-dev-public.key` (testowa para kluczy), `LICENSE_FILE=`
  puste → brak pliku licencji. Przy `APP_ENV=dev` brak pliku = tryb deweloperski (`L001`), bez limitów;
  przy `prod` = tryb ograniczony (`L100`).
- Karencja subskrypcji: `license_grace_days` w `tenant_setting` (domyślnie 7, górna granica 90).
  `updates_until` nie ogranicza działania — blokuje tylko nowe wersje (`LicenseGate::updatesAllowedFor`).
  Test obu wariantów: `php tests/license-terms.php`.
- Kody stanów: `L000` ważna, `L001` dev, `L100`–`L108` tryb ograniczony (brak pliku, zły podpis,
  inny host, wygasła, cofnięty zegar…), `L200` karencja (7 dni po terminie).
  Ostatni znany czas: `var/license-clock`.
- Tryb ograniczony: po zalogowaniu wszystkie trasy poza wylogowaniem, 2FA i `/licencja`
  (uprawnienie `admin.license`) kończą się ekranem „Licencja wymaga uwagi” (403).
  Limit kont z licencji wymusza `InvitationService` (wyjątek: pierwszy administrator z instalatora).

## Instalator (M8)

- `/instalacja` działa wyłącznie bez `var/install.lock`; po instalacji ten adres daje 404.
  Przed instalacją każdy inny adres przekierowuje do instalatora, a `/_wezurlop_probe` odpowiada sondzie
  wykrywania prefiksów (i zgłasza, czy dociera nagłówek `Authorization`).
- Przed tokenem stan jedzie w podpisanym ciasteczku `wezurlop_install_pre` (HMAC sekretem
  `var/install-secret`) — nikt nikogo nie blokuje; limit 5 błędnych tokenów na adres w 15 min
  (`var/install-attempts.json`). Po poprawnym tokenie stan w `var/install-state.json` (600), klucz
  w ciasteczku `wezurlop_install`, **jeden przebieg naraz** (2 h bezczynności); drugi posiadacz
  tokenu dostaje 409. Po instalacji wszystkie trzy pliki są usuwane.
- Token instalacyjny (zaślepka panelu): `INSTALL_TOKEN` we **wstępnym** `.env`, min. 16 znaków.
  Wstępny `.env` musi być zapisywalny dla serwera (`660`), a do zaciśnięcia praw serwer musi być jego
  **właścicielem** (na dev: `sudo chown apache:apache .env`). Instalator nadpisuje plik w miejscu,
  usuwa token i sam ustawia `0640`; nieudany `chmod` to błąd kroku (bez blokady instalatora).
  Diagnostyka zgłasza błąd, dopóki `.env` ma prawa szersze niż `0640`.
- HSTS przy instalacji produkcyjnej: `HSTS_MAX_AGE=2592000` (30 dni), `HSTS_INCLUDE_SUBDOMAINS=false`.
- Przeprowadzenie instalacji od zera na dev:
  ```
  cp -p .env <kopia poza repo>
  vendor/bin/phinx rollback -c config/phinx.php -t 0     # potem DROP TABLE phinxlog kontem migracyjnym
  mv var/install.lock <kopia>
  printf "INSTALL_TOKEN='%s'\n" "$(php -r 'echo bin2hex(random_bytes(16));')" > .env.tmp && cat .env.tmp > .env && rm .env.tmp
  sudo chown apache:apache .env && sudo chmod 660 .env
  # https://tenant.wezurlop.pl/instalacja — 10 kroków, na końcu var/install.lock
  ```
  Powrót do danych deweloperskich: `sudo chown dev:apache .env`, przywrócić `.env` treścią
  (`cat kopia > .env`, prawa 640),
  `rollback -t 0`, `migrate`, `seed:run`.
- Poczta w instalatorze: wysyłka testowa jest synchroniczna (świadomy wyjątek od kolejki — bez niej
  administrator nie dostanie linku), link administratora wychodzi od razu przez `JobRunner`.

## Wdrożenie i kolejka (Etap 3, blok A)

- **Połączenie z bazą:** `Db\Connection` z profilami `data` (aplikacja, `DB_*`) i `schema` (migracje,
  `DB_MIG_*`). Profil `data` ma zawsze bezpiecznik DDL (`Db\GuardedPdo`): polecenie zaczynające się
  od `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `RENAME`, `GRANT`, `REVOKE` kończy się wyjątkiem i wpisem
  `security.ddl_blocked` w `event_log` (osobnym połączeniem, więc wpis przeżywa wycofanie transakcji).
  Strażnik reguł zgłasza DDL w kodzie poza migracjami i instalatorem.
- **TLS do bazy:** `DB_SSL=auto` szyfruje, gdy host nie jest lokalny (`localhost`, `127.*`, `::1`).
  Na mysqlnd TLS włącza dopiero ustawienie `MYSQL_ATTR_SSL_CA` — pusty CA = szyfrowanie bez weryfikacji.
  Na dev (MySQL 8.4, certyfikat samopodpisany) `DB_SSL=required` daje `TLS_AES_256_GCM_SHA384`.
- **Tryb jednego konta:** `DB_SINGLE_ACCOUNT=1`, `DB_*` = `DB_MIG_*`; instalator włącza go, gdy nie podano
  konta aplikacyjnego. Podział kont instalator sprawdza próbą `CREATE TABLE` na koncie aplikacyjnym.
- **Kolejka:** kolumna `job.priority` (10 — linki z terminem, 50 — powiadomienia, 90 — sprzątanie
  i kontrole); runner bierze zadania według priorytetu, budżet 25 s. `QUEUE_ON_REQUEST=1` domyka jedno
  pilne zadanie po odpowiedzi (lock `var/queue-on-request.lock`, budżet 2 s).
- **Potwierdzenie crona:** każdy przebieg (`bin/run-jobs.php`, cron HTTP) dotyka `var/cron-heartbeat`
  (`Service\CronHeartbeat`). Deklaracja z instalatora nie jest dowodem. Jeśli znacznika nie ma albo jest
  starszy niż 3 interwały (co najmniej 20 min), kolejka po odpowiedzi włącza się także przy
  `QUEUE_ON_REQUEST=0`. `/diagnostyka` pokazuje „Ostatni przebieg crona”.
- **Cron przez HTTP:** `deploy/cron-05min.php` (shim) → `bin/cron-http.php` → `Console\HttpCronCommand`
  (lock `var/cron-http.lock`, odstęp min. 240 s — `var/cron-http.last`). Instalator kopiuje shim do katalogu
  domeny tylko przy cronie przez HTTP. Na dev cron to crontab użytkownika `apache` (`bin/run-jobs.php`).
  ⚠ Pliku nie da się zablokować po adresie (woła go hosting z własnej infrastruktury). Ochroną jest lock,
  odstęp, ignorowanie wejścia i odpowiedź **bez treści**: 204 = przebieg wykonany, 429 z `Retry-After` =
  nic nie zrobiono; błąd = 500 bez treści. W profilu z cronem HTTP `/diagnostyka` wywołuje plik dwa razy
  pod rząd (`Diagnostics\CronHttpProbe`): drugie wywołanie musi dać 429, oba bez treści.
- **Profile serwera:** `deploy/htaccess/{apache,ideawebserver,minimal}/{root,public}.htaccess`. Pliki
  `.htaccess` w repozytorium to profil `apache` — strażnik reguł pilnuje, żeby były identyczne z szablonem.
- **Ścieżka aplikacji (wariant 2):** `public/app-path.php` → `WEZURLOP_APP_PATH` → katalog nad `public/`.
  Na `tenant-v2` sprawdzone: plik wygrywa z błędnym `SetEnv`.
- **Kanarek:** `storage/canary-<losowe>.txt` (bez kropki), nazwa i treść w `tenant_setting`; zadanie
  `maintenance.canary_check` raz na dobę pobiera go pod `APP_URL` przez `SELF_ADDR` (na dev `127.0.0.1` —
  bez tego serwer nie dosięga własnego adresu publicznego). Wynik na `/diagnostyka` (grupa „Wdrożenie”).

## Model danych urlopowych (Etap 3, blok B)

- Migracja `20260919210001_create_leave_domain`: `company.tax_id` → `nip` (NOT NULL, UNIQUE),
  `company.external_id` usunięte, `employment.external_id` → `r2_employment_ref` (zwykły indeks),
  `employment` + `supervisor_employment_id`, `fte` DECIMAL(6,4), `r2_calendar_ref`, `deactivated_at`;
  `person.external_person_id` + tabela `person_external_ref` (jednoznaczność bez NULL-i); nowe tabele
  `leave_type` (UNIQUE `source`+`code`), `leave_request`, `leave_request_day`, `leave_request_r2_record`,
  `leave_decision`, `leave_comment`, `leave_balance` (minuty), `external_absence`, `company_calendar`,
  `sync_run`, `sync_conflict`.
- ⚠ phinx nie gwarantuje kolejności operacji w jednym `update()` (usunięcie indeksu po zmianie nazwy
  kolumny) — zmiany nazw i indeksów w osobnych krokach. MySQL nie ma transakcyjnego DDL: przerwana
  migracja zostawia stan pośredni.
- Kalendarz: `Service\Calendar\WorkingCalendar` + `PolishHolidays` (Wielkanoc algorytmem, Wigilia od 2025),
  ustawienia `calendar_workdays`, `calendar_exceptions`, `calendar_source`. Test: `php tests/calendar.php`
  (2026 i 2027: po 253 dni robocze).
- Seed: NIP-y 1234567009 (A) i 9876543210 (B), z poprawną sumą kontrolną; identyfikatory zatrudnień R2 jak w `docs/przyklady-api.md`
  (207 Anna, 311 Jan, 312 Piotr, 101 Zenon; w B 5001 Beata, 5021 Anna).

## API connectora (Etap 3, blok C)

- Trasy `/api/v1/sync/{start,dane,przeglad,zadania,wyniki,koniec,zgloszenie-firmy}` są w
  `config/routes.php` z `'auth' => 'api'` i uprawnieniem `api.sync`. `Kernel` rozpoznaje `/api/`
  **przed** startem sesji i przekazuje żądanie do `Http\ApiKernel`: bez sesji i bez CSRF, klucz
  sprawdza `Middleware\ApiKeyAuth`. Każda odpowiedź, także 404/405/500, jest JSON-em
  `{"blad":{"kod","komunikat","zdarzenieId"}}`, a szczegóły trafiają do logu pod tym samym `zdarzenieId`.
- **NIP** (`Support\Nip`): najpierw normalizacja (myślniki, spacje, prefiks `PL`), potem suma kontrolna
  (wagi 6 5 7 2 3 4 5 6 7, mod 11). Błędny NIP → `422 NIP_NIEPOPRAWNY` na każdej operacji, **przed**
  sprawdzeniem zakresu klucza. Ta sama reguła obowiązuje w zakresie NIP-ów klucza na ekranie integracji.
  ⚠ Zgłoszenie „klient wpisał NIP z myślnikami/spacjami/`PL` i nie działa”: taki zapis jest poprawny
  (normalizacja jest przed sumą kontrolną), więc przyczyną jest błędna suma — sprawdź cyfry.
- **`zgloszenie-firmy`** ma trzy stany: `202 oczekuje`, `200 zatwierdzona` (NIP już w zakresie klucza),
  `200 odrzucona` (administrator odrzucił; cofnięcie odrzucenia przywraca `oczekuje`). Powtórka nie tworzy
  duplikatu.
- Klucz instalacji to 64 znaki hex, w bazie wyłącznie SHA-256 (`api_key`), z zakresem NIP-ów
  (`api_key_company`). Wydaje go `Service\Sync\ApiKeyService` (jawna postać pokazywana raz).
  Klucz odczytujemy z `Authorization: Bearer`, a gdy go brak, z `X-Api-Key`. Na `tenant-cgi`
  sprawdzono, że `Authorization` dochodzi.
- Limity:
  - 120 żądań na minutę na klucz (`api_rate_counter`, niezależnie od limitów logowania);
  - `zgloszenie-firmy`: 10 na godzinę;
  - treść do 2 MB, gzip rozpakowywany strumieniowo z odcięciem na 8 MB;
  - do 500 rekordów w paczce, powyżej → 413.
- Odpowiedzi na `X-Idempotency-Key` są pamiętane 7 dni (`sync_idempotency`). Sprząta je zadanie
  `maintenance.sync_cleanup`, uruchamiane co godzinę; to samo zadanie usuwa ślady przerwanych przeglądów.
- Próg przeglądu kontrolnego to `tenant_setting.sync_review_hours` (domyślnie 1). Okno synchronizacji
  liczymy od daty serwera: nieobecność jest poza oknem, gdy `dataDo` < 1 stycznia roku poprzedniego,
  a przez 2 dni po Nowym Roku okno jest o rok szersze (`Service\Sync\SyncWindow`).
- Profil połączenia `data` ustawia `innodb_lock_wait_timeout = 10`, bo domyślne 50 s przekracza
  budżet żądania. Przejściowe błędy bazy (blokada, zakleszczenie, brak połączenia) API zamienia na
  `503 PRZERWA_TECHNICZNA` z `Retry-After: 30`; paczka jest wtedy wycofana, a odpowiedź niezapamiętana.
  Profil `schema` (migracje) zostaje na wartości domyślnej. Każde 503 liczy `sync_run.stats.przerwyTechniczne`
  (gdy znany przebieg) i wpis `api.transient_error` w `event_log`; `/diagnostyka` pokazuje liczbę z 24 h
  i 7 dni (ostrzeżenie powyżej 5 na dobę).
- **Wpis podpisany jako nasz, którego nie wydaliśmy** (`zrodloWpisu: "connector"`, `requestId` nieznany):
  trafia do nieobecności zewnętrznych jak każdy inny, **bez konfliktu**, ale raz na rekord idzie
  informacja do kadr (`sync.foreign_connector_entry` w `event_log`, zadanie `mail.hr_alert`, licznik
  `obceWpisyConnectora` w `sync_run`). Powód: to albo pozostałość po testach, albo **druga instalacja
  pisząca do tej samej bazy kadrowej** — to drugie ma być widoczne, nie ciche.
- Usunięcie nieobecności **zatrudnienia już nieaktywnego** (docs/etap3-logika-urlopowa.md 5b): zdarzenie
  `nieobecnosciZatrudnieniaNieaktywnego` w `sync_run`, nieobecność zewnętrzna znika z kalendarza;
  konfliktu nie tworzymy, a wniosek i jego historia zostają bez zmian.
- ⚠ Sprawdzenie limitu przed wydaniem zadania nie istnieje w tej turze. Jego miejsce jest wydzielone
  i puste: `TaskService::verifyLimit()`; regułę dostarcza tura B z `/src/Core`.

## Integracja: ekran, symulator, OpenAPI (Etap 3, blok D)

- `/admin/integracja` (tylko web) wymaga uprawnienia `integration.manage`; menu i trasa sprawdzają je
  tym samym wywołaniem. Uprawnienie ma `permission.tenant_only = 1`: działa wyłącznie z przypisania na
  całego tenanta (company_id = 0), więc administrator jednej firmy go nie ma i nie widzi pozycji w menu.
  Ekran pozwala:
  - wydać klucz; jawna postać trafia do sesji tylko na czas przekierowania po POST, jest odczytywana
    jednorazowo (usuwana przy renderowaniu), przepada po 5 minutach; odświeżenie pokazuje „klucz był już
    wyświetlony” z akcją „Wygeneruj nowy” (poprzedni unieważniony od razu);
  - zmienić zakres NIP-ów;
  - wymienić klucz z nakładką 30 dni;
  - unieważnić klucz (wymaga zaznaczenia potwierdzenia);
  - zatwierdzić albo odrzucić zgłoszenie firmy (zatwierdzenie zakłada firmę i dodaje NIP do zakresu
    klucza, który ją zgłosił) oraz cofnąć odrzucenie;
  - zlecić przegląd kontrolny firmy (np. po odtworzeniu bazy z kopii);
  - przejrzeć ostatnie przebiegi.
- `docs/openapi-sync-v1.json` opisuje 7 operacji w OpenAPI 3.0.3. Plik jest w JSON-ie, a nie YAML-u,
  bo symulator czyta go bez nowej zależności. Dokumentem wiodącym dla człowieka jest
  `docs/api-connector.md`; **każda zmiana protokołu idzie do obu plików w jednym commicie**. Schematy rekordów są wygenerowane z
  `config/sync-schema.php` i symulator pilnuje, żeby się nie rozjechały.
- Kod symulatora leży w `dev/` (przestrzeń `Wezurlop\Dev`, `autoload-dev` w `composer.json`);
  `bin/sync-symulator.php` to tylko punkt wejścia. Symulator działa **tylko przy `APP_ENV=dev`**, bo przed każdym celem odtwarza bazę
  z seeda (rollback → migrate → seed przez API phinxa). Hasła kont deweloperskich zmieniają się wtedy
  i nie są wypisywane; po symulatorze uruchom `phinx seed:run` od zera, żeby je poznać.
  ```
  php bin/sync-symulator.php                                   # tenant i tenant-cgi, wszystkie scenariusze
  php bin/sync-symulator.php --cel=tenant --scenariusze=przyklady,powtorki
  php bin/sync-symulator.php --scenariusze=pomiar --rekordy=10000
  ```
  Scenariusze:
  - `przyklady` — `docs/przyklady-api.md` porównywane co do pola: kształt i wartości z dokumentu,
    a wzorcem tylko pola zmienne z natury (`przebiegId`, `zdarzenieId`, `requestId`);
  - `przeglad` — przerwany przegląd niczego nie kasuje;
  - `powtorki` — powtórzona paczka i powtórzony wynik;
  - `pracownik` — usunięcie pracownika z zatrudnieniem i nieobecnościami;
  - `okno` — usunięcie nieobecności z roku poza oknem;
  - `pomiar` — czasy pełnego ładowania.

  Poza tym symulator sprawdza każdy blok przykładów i każdą odpowiedź API względem OpenAPI.
- Testy jednostkowe: `php tests/sync.php` (okno na przełomie roku, limity treści, walidacja, 503).
- **Paczka dla klienta:** `dev/build-package.sh` → `build/paczka`. Kopiuje wyłącznie pliki śledzone w git,
  zgodne z **listą dopuszczonych** `dev/package-allowlist.txt` (nie listą wykluczonych), instaluje
  `vendor/` przez `composer install --no-dev` i weryfikuje wynik: plik spoza listy, `tests/`, `docs/`,
  `dev/`, `db/seeds`, symulator albo przestrzeń `Wezurlop\Dev` w autoloadzie przerywają build.
  Kodowanie strefy `/src/License` i `/src/Core` ionCube Encoderem to osobny, późniejszy krok.
  Przed kopiowaniem build uruchamia PHPCompatibility (8.3–8.5 i 8.2–8.5), po instalacji zależności
  `composer check-platform-reqs --no-dev` — każdy błąd przerywa build.
- **Test na paczce:** `dev/package-vhost.sh` buduje paczkę i przygotowuje ją pod vhost
  `/etc/httpd/conf.d/tenant-paczka.conf` — `http://paczka.localhost:8090`, nasłuch **wyłącznie
  127.0.0.1**, katalog domeny = `build/paczka/public` (profil `apache`, PHP przez PHP-FPM — AlmaLinux 10
  nie ma mod_php). `.env` paczki powstaje z deweloperskiego (inny `APP_URL`), baza wspólna z dev,
  instalację zastępuje `var/install.lock`. `VAR_PATH` i `STORAGE_PATH` wskazują `build/paczka-stan`
  (poza katalogiem builda) — inaczej kolejny build nie usunąłby plików, które apache tworzy prawami 700.
  Symulator: `php bin/sync-symulator.php --cel=paczka`.
  Ten sam vhost posłuży do sprawdzenia paczki po kodowaniu ionCube.

## Katalog domeny

Przez HTTP może być dostępny **wyłącznie** katalog `public/`. Wszystko inne musi być
nieosiągalne:

- `vendor/` — znany wektor RCE (bezpośrednie wywoływanie skryptów z paczek),
- `storage/` — pobieranie załączników z pominięciem kontroli uprawnień aplikacji,
- `.env`, `composer.json`, `src/`, `var/`, `db/` — hasła, konfiguracja, kod.

### Układ zalecany: katalog domeny = `public/`

Reszta aplikacji leży poza webrootem. `public/.htaccess` blokuje pliki i katalogi zaczynające
się od kropki (z wyjątkiem `/.well-known/`), przywraca nagłówek `Authorization` i przepisuje
żądania na `index.php`.

### Układ awaryjny: katalog domeny = korzeń aplikacji

Dopuszczalny tylko wtedy, gdy hosting nie pozwala ustawić katalogu domeny na `public/`.
Ochronę zapewnia `.htaccess` w korzeniu aplikacji. Treść obu plików:
`docs/szkielet-tenanta.md`, rozdział 14.

- z **mod_rewrite**: każde żądanie (poza `/.well-known/`) trafia do `public/`, więc `/.env`,
  `/vendor/...`, `/storage/...` obsługuje już aplikacja i kończą się 404 (pliki z kropką: 403);
- **bez mod_rewrite**: `RedirectMatch 404` (mod_alias) zamyka wszystko poza `public/`
  i `/.well-known/`; aplikacja działa pod adresami `/public/index.php/...` (`PATH_INFO`).

⚠ **Tylko dyrektywy klasy `FileInfo`** (`RewriteRule`, `RedirectMatch`), zawsze w `<IfModule>`.
**Zakaz `Require`** i innych dyrektyw `AuthConfig`: przy `AllowOverride FileInfo` Apache zwraca
**500 na każdym żądaniu** („Require not allowed in `<FilesMatch>` context”) — sprawdzone
na maszynie deweloperskiej 16.09.2026. Aplikacja i instalator wtedy w ogóle nie startują.

**O dopuszczeniu instalacji decyduje wynik pobrania, nie obecność modułów czy plików.**
Jeśli test ekspozycji (niżej) pobierze treść chronionego pliku — instalacja jest zablokowana
z komunikatem, że katalog domeny trzeba przestawić na `public/`. Dotyczy to m.in. serwera
bez `mod_rewrite` i bez `mod_alias` albo ignorującego `.htaccess`.

### Test ekspozycji plików (błąd blokujący)

Diagnostyka próbuje pobrać przez HTTP z własnego serwera (`SERVER_ADDR`, bez udziału DNS/NAT):

| Plik | Wyciek, gdy |
|---|---|
| `.env` | odpowiedź zawiera treść pliku lub klucze `DB_*` |
| `composer.json` | odpowiedź zawiera treść pliku |
| `vendor/composer/installed.json` | odpowiedź zawiera treść pliku |
| `vendor/autoload.php` | kod 2xx (skrypt został wykonany) lub widoczne źródło PHP |
| `storage/uploads/` | odpowiedź zawiera treść pliku-sondy tworzonego na czas testu |

Każdy plik jest sprawdzany pod adresem korzenia aplikacji, ścieżką względną od skryptu
(`/../`, `/%2e%2e/`) oraz w korzeniu domeny. **Liczy się brak treści, nie konkretny kod** —
w układzie awaryjnym odpowiada router aplikacji (404). Odpowiedź z treścią pliku to **błąd
blokujący**; brak odpowiedzi (serwer nie dopyta sam siebie) — wynik nierozstrzygnięty,
wymagający jawnego potwierdzenia. Brak `vendor/` (przed `composer install`) daje ostrzeżenie
„nie sprawdzono”.

## Maszyna deweloperska (AlmaLinux 10)

Odwzorowuje hosting możliwie wiernie: Apache z `AllowOverride All`, PHP-FPM, osobne vhosty
dla `tenant`, `panel`, `auth`, katalog domeny = `public/`.

Vhosty testowe (tylko adresy pętli zwrotnej — wystawiają katalogi z prawdziwym `.env`):

| Vhost | Adres | Układ | Ustawienia |
|---|---|---|---|
| `tenant-cgi.wezurlop.pl` | `127.0.0.2:80` | katalog domeny = korzeń repozytorium, `AllowOverride FileInfo` | `SetEnv APP_BASE_PATH /public`, `SetEnv APP_FRONT_CONTROLLER /index.php` |
| `tenant-v2.wezurlop.pl` | `127.0.0.3:80` | wariant 2: katalog domeny `/home/dev/tenant-www` z **kopią** zawartości `public/`, aplikacja w `/home/dev/tenant` | `SetEnv WEZURLOP_APP_PATH /home/dev/tenant` w `.htaccess` katalogu domeny |

`127.0.0.1:80` obsługuje domyślny vhost `*:80` (pierwszy z `conf.d`, czyli `auth`), nie vhosty testowe.
Testy: `curl --resolve tenant-cgi.wezurlop.pl:80:127.0.0.2 …`, `--resolve tenant-v2.wezurlop.pl:80:127.0.0.3 …`.

⚠ `/home/dev/tenant-www` to kopia — po zmianie `public/` trzeba ją odświeżyć
(`cp -a public/. ../tenant-www/` i ponownie dopisać blok `SetEnv` do `.htaccess`).

Gałęzi „bez `mod_rewrite`” żaden z nich nie sprawdza (moduł jest załadowany globalnie); do tego
służy osobna, tymczasowa instancja Apache'a z listą modułów bez `mod_rewrite`
(`httpd -f <konfiguracja> -k start`, port 8083, te same `SetEnv` co `tenant-cgi`).

Odstępstwa od hostingu i od pierwotnej instrukcji instalacji:

- PHP 8.3 z AppStream **bez `sodium`** (brak pakietu w repozytoriach AlmaLinux 10; bez EPEL),
- MySQL **8.4** (`mysql8.4-server`; pakietu `mysql-server` nie ma),
- certbot z pip w `/opt/certbot` (w repozytoriach tylko przez EPEL), odnawianie przez
  własny `certbot-renew.timer`,
- `opcache.enable_cli = 0` w `/etc/php.d/99-dev.ini` — ionCube z OPcache w CLI powoduje
  segfault przy `php -l`,
- `display_errors = On` (dev), `max_execution_time = 30` (jak na hostingu),
- nagłówki bezpieczeństwa tenanta wysyła **wyłącznie PHP** (CLAUDE.md §13), HSTS z `.env`:
  `HSTS_MAX_AGE=300`, `HSTS_INCLUDE_SUBDOMAINS=false`, nigdy `preload`; blok `Header always set`
  jest w `/etc/httpd/conf.d/security-headers.inc` i dołączają go tylko vhosty `panel` i `auth`,
  które nie mają jeszcze aplikacji,
- `.env` ma prawa `640 dev:apache` (PHP-FPM działa jako `apache`); edycja narzędziem, które
  tworzy nowy plik (np. `sed -i`) w sesji bez grupy `apache`, zmienia grupę na `dev` —
  aplikacja zgłasza wtedy błąd „brak prawa odczytu .env”,
- sesje w bazie (tabela `session`, identyfikator jako SHA-256) — katalogu `var/sessions` już nie ma;
  wygasłe wiersze usuwa zadanie `maintenance.session_cleanup` z kolejki (co godzinę),
- `var/install.lock` zapisuje instalator (M8); jego brak otwiera `/instalacja`,
- `max_execution_time = 30` również w PHP-FPM,
- skrypty z `bin/`, które zapisują do `storage/logs` lub `var/`, uruchamiamy jako `apache`
  (`sudo -u apache php bin/...`) — pliki tworzy PHP-FPM, więc inny użytkownik nie może do nich dopisywać;
  `crontab` kolejki jest zainstalowany dla użytkownika `apache`.

## Odbiór Etapu 2 (szkielet)

- **16–17.09.2026: 32/32 scenariusze z rozdziału 16 zaliczone na obu vhostach** (`tenant`, HTTPS,
  katalog domeny = `public/`; `tenant-cgi`, HTTP na 127.0.0.2, katalog domeny = korzeń aplikacji,
  adresy `/public/index.php/…`). Scenariusze 24 i 27 na osobnych instancjach: `php -S` bez ionCube
  oraz Apache bez `mod_rewrite` w obu układach katalogu domeny.
- Poprawka z odbioru: bez zatrudnienia przechodzi wyłącznie `tenant_admin` z `company_id = 0`.
- **M8b (17.09.2026):** scenariusze 33–35 zaliczone na obu vhostach; regresja 17 (47 ekranów
  na vhost + 4 ekrany instalatora, 0 naruszeń CSP) i 20 (bez JavaScriptu) bez zmian.
- Narzędzia testowe (Playwright, skrypty scenariuszy) leżały w katalogu roboczym sesji, poza
  repozytorium; w repozytorium jest tylko `tests/license-terms.php`.
- Stan po odbiorze: dane z seeda (3 konta, bez 2FA administratora), `mobile_access=open`.
