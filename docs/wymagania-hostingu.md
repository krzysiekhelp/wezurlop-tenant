# Wymagania środowiska hostingowego — wezurlop

Dokument zamyka temat hostingu. Mówi, **co musi być**, **co jest zalecane**, **czego nie
przyjmujemy na słowo** i **o co zapytać hostingodawcę przed wdrożeniem**.
Podstawa: pomiary dwóch usług home.pl (17 i 19.09.2026) oraz dokumentacja dostawcy.

Szczegóły techniczne zachowań serwerów: `docs/profile-hostingu.md`.

---

## 1. Wymagania twarde — bez nich instalacja się nie uda

| Obszar | Wymaganie |
|---|---|
| PHP | **8.3 – 8.5**; 8.3 to minimum, zalecana najnowsza wersja, do której hosting ma Loadera |
| ionCube | **Loader zainstalowany i zgodny z wybraną wersją PHP**; wersja Loadera nie może być starsza niż Encoder użyty do paczki |
| Rozszerzenia PHP | `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `iconv`, `xmlwriter` |
| Baza | MySQL 8 lub MariaDB 10.6+, kolacja `utf8mb4_unicode_ci`, prawdziwe zapytania przygotowane (`ATTR_EMULATE_PREPARES=false`), konto z prawem zmiany schematu |
| HTTPS | działający certyfikat; aplikacja wymusza HTTPS i wysyła HSTS |
| Adresy | `mod_rewrite` **albo** działające `PATH_INFO` (`/index.php/…`) — wystarczy jedno |
| Zapis | katalog **poza katalogiem dostępnym z internetu**, zapisywalny: `var/`, `storage/`, `.env` |
| Poczta | SMTP z uwierzytelnianiem (`mail()` nie jest używane i nie wystarczy) |
| ⚠ Domena nadawcy | **adres nadawcy z domeny, którą obsługuje ten serwer SMTP**, oraz **rekord SPF** tej domeny obejmujący serwer wysyłający; zalecany DKIM i DMARC — patrz §1a |
| Zadania cykliczne | dowolny mechanizm crona, **minimum raz na godzinę** |
| Sieć wychodząca | HTTPS do panelu licencji, JWKS dostawców logowania i przekaźnika powiadomień, **z weryfikacją certyfikatów** |
| Limity | `max_execution_time` ≥ 30 s, `memory_limit` ≥ 128 MB |

## 1a. ⚠ Uwierzytelnienie poczty — wymaganie, nie porada

⚠ **To jest najczęstszy sposób, w jaki ta aplikacja zawodzi po poprawnej instalacji: serwer SMTP
przyjmuje wiadomość, aplikacja melduje „wysłano", a zaproszenie ląduje w spamie albo znika.**
Klient dowiaduje się o tym od pracownika, który „nie dostał zaproszenia", a my nie mamy czego
naprawiać w kodzie — bo kod zrobił swoje.

Dlatego od klienta wymagamy **trzech rzeczy o domenie adresu nadawcy** (tego, który wpisuje się
na ekranie „Ustawienia → Poczta"):

| | Wymaganie | Jak sprawdzić |
|---|---|---|
| 1 | **Adres nadawcy należy do domeny, którą obsługuje ten serwer SMTP.** Dowolny adres (np. prywatna skrzynka w innej domenie) da wiadomości, które „wychodzą" i nie docierają | `dig +short TXT <domena>` oraz porównanie z adresem serwera SMTP |
| 2 | **Rekord SPF domeny nadawcy obejmuje serwer wysyłający** — mechanizmem `a`, `mx`, `ip4:` albo `include:` dostawcy | `dig +short TXT <domena>` → `v=spf1 …`; adres serwera: `dig +short <host SMTP>` |
| 3 | **Rekord DMARC** (`_dmarc.<domena>`), choćby `p=none` z adresem raportów; DKIM, jeśli dostawca go daje | `dig +short TXT _dmarc.<domena>` |

⚠ **Punktu 1 nie da się obejść konfiguracją aplikacji.** Instalator i ekran ustawień przyjmą każdy
adres nadawcy, bo nie mają jak sprawdzić, czyja jest domena — to wymaganie wobec **klienta i jego
dostawcy poczty**, spisane tutaj właśnie dlatego, że jego niespełnienie wygląda jak usterka
oprogramowania.

**Zmierzone na środowisku odniesienia 22.09.2026** (wysyłka zaproszenia kolejką):

```
adres nadawcy          alerty@yaho.pl
serwer SMTP            addconnect.home.pl → 79.96.118.242
SPF domeny nadawcy     yaho.pl  TXT  "v=spf1 mx a ptr ~all"
  A yaho.pl            79.96.118.242     ← mechanizm „a" obejmuje serwer wysyłający
  MX yaho.pl           79.96.118.242     ← mechanizm „mx" też
  PTR 79.96.118.242    yaho.pl.          ← mechanizm „ptr" też
DMARC domeny nadawcy   _dmarc.yaho.pl — BRAK REKORDU
```

Czyli: SPF dla tej domeny **powinien przechodzić** (serwer wysyłający jest jednocześnie hostem
domeny), a DMARC **nie istnieje** — i to jest pozycja do uzupełnienia u klienta, bo część odbiorców
(m.in. Gmail dla wysyłek masowych) traktuje brak DMARC surowiej niż `p=none`.
⚠ To, czy SPF i DKIM **faktycznie przeszły**, widać wyłącznie w nagłówkach `Authentication-Results`
odebranej wiadomości — DNS mówi, co powinno się stać, nagłówek mówi, co się stało.

**I to zostało zmierzone — na prawdziwej, DORĘCZONEJ wiadomości** (zaproszenie z kolejki,
`Date: Tue, 22 Sep 2026 09:45:01 +0000`, odebrane przez `mx.google.com`):

```
Authentication-Results: mx.google.com;
   dkim=pass header.i=@yaho.pl header.s=dkim
   spf=pass  (domain of alerty@yaho.pl designates 79.96.118.242 as permitted sender)
Return-Path:  <alerty@yaho.pl>       Delivered-To: <skrzynka klienta>
X-VADE-SPAMSTATE: clean              ← filtr dostawcy przekaźnika
multipart/alternative: część tekstowa i HTML obecne
```

Wiadomość **doszła do skrzynki odbiorczej**, SPF i DKIM przeszły. To zamyka pozycję
„SMTP przyjął ≠ wiadomość doszła" dla środowiska odniesienia — i odsłania trzy braki,
których DNS nie pokazywał:

| | Wymaganie | Stan zmierzony | Dlaczego to nie jest kosmetyka |
|---|---|---|---|
| 4 | **DMARC choćby `p=none`** | ⚠ **BRAK** — w nagłówku Google **nie ma wiersza `dmarc=`**, a jest wypisywany, gdy domena publikuje rekord | od 2024 Gmail i Yahoo wymagają go od **nadawców masowych**, a partia zaproszeń do stuosobowej firmy nią jest. Dziś nie szkodzi, bo SPF i DKIM przechodzą — to warunek **skali**, nie pierwszego wdrożenia |
| 5 | **`Message-ID` z prawdziwą domeną** | ⚠ **BRAK** — `<…@wezurlop>`, prawa strona nie jest nazwą domenową | PHPMailer bierze ją z nazwy hosta. Część filtrów to punktuje, część przekaźników przepisuje. **To jest po naszej stronie:** `Hostname` bierzemy z adresu instalacji (tego samego, z którego budujemy linki), nie z nazwy maszyny |
| 6 | **Nazwa nadawcy = nazwa firmy klienta** | ⚠ dziś `wezurlop <alerty@yaho.pl>` | pracownik dostaje wiadomość o **swoim** urlopie od nadawcy, o którym nigdy nie słyszał — wygląda jak phishing i tak zostanie potraktowana. Ta sama decyzja co pełna nazwa spółki w treści. **Też po naszej stronie:** ustawienie tenanta, domyślnie nazwa tenanta; adres zostaje ten z konfiguracji SMTP |

⚠ **Brak odbić.** `Return-Path` wskazuje skrzynkę, której nie czytamy: wiadomość odrzucona przez
serwer odbiorcy wraca tam i ginie, a kolejka melduje sukces. Znane ograniczenie (`docs/audyt.md`),
nie usterka wdrożenia — ale klient z jednym nieaktualnym adresem w kadrach zobaczy „wysłane"
i nie dostanie nic.

Czego **nie** wymagamy, choć bywa na listach konkurencji: `exec` i pokrewnych (zakazane),
`ext-sodium` (idzie przez `sodium_compat`), `gd` (kod QR generujemy jako SVG),
dostępu do `/tmp`, konkretnego serwera WWW, dedykowanego serwera.

## 2. Zalecane — podnosi bezpieczeństwo albo komfort, nie blokuje

| Zalecenie | Co daje | Gdy niedostępne |
|---|---|---|
| katalog domeny ustawiony na `public/` | najczystszy układ, reszta drzewa poza zasięgiem | wariant 2 + blokada `RewriteRule … [F]`, sprawdzona po HTTP |
| **dwa konta MySQL** (aplikacja bez DDL, migracje z DDL) | ogranicza skutek błędu w kodzie | tryb jednego konta z bezpiecznikiem DDL i obowiązkową kopią przed aktualizacją |
| cron **co 5 minut** | szybka poczta i eskalacje | wolniejsza eskalacja + domykanie kolejki po żądaniu dla zadań pilnych |
| TLS na połączeniu z bazą | dane kadrowe nie idą siecią jawnym tekstem | wymagane, gdy baza jest na osobnym hoście — patrz §4 |
| `intl`, `zip`, `opcache` | formatowanie, aktualizacje, wydajność | warianty zapasowe w kodzie |
| najnowsze PHP z dostępnym Loaderem | wsparcie bezpieczeństwa PHP | 8.3 działa i ma wsparcie do **31.12.2027** |

## 3. Czego nie przyjmujemy na słowo — sprawdza instalator

⚠ Zasada: **deklaracja nie jest dowodem, wynik pobrania po HTTP jest.** Powód nie jest
teoretyczny — u jednego dostawcy, na dwóch usługach, ten sam plik `.htaccess` zachowuje się
inaczej, a jedna z nich **podaje pliki z kropką z treścią**.

Instalator (krok 7) pobiera po HTTP `.env`, `composer.json`, plik z `vendor/` i ze `storage/`.
Trzy wyniki: **chronione** → dalej; **odsłonięte** → **błąd blokujący instalację**;
**nierozstrzygnięte** → wymaga świadomego potwierdzenia i zostaje w logu instalacji.

Poza tym instalator i strona `/diagnostyka` sprawdzają: obecność i wersję Loadera,
komplet rozszerzeń, przepisywanie adresów i `PATH_INFO`, dotarcie nagłówka `Authorization`
(oraz ścieżkę zapasową `X-Api-Key`), prawa zapisu, prawa `.env` (`0640`), szyfrowanie
połączenia z bazą, podział uprawnień kont bazy i dostępność SMTP.

## 4. Pytania do hostingodawcy przed wdrożeniem

Krótka lista dla osoby wdrażającej — pięć pytań, wszystkie wynikają z realnych pomiarów:

1. **Czy katalog domeny można wskazać na podkatalog** (`public/`)? Jeśli tak i aplikacja ma
   leżeć piętro wyżej — **separację serwisu (chroot) trzeba wyłączyć**, inaczej front controller
   nie zobaczy `vendor/`.
2. **Cron: jaki mechanizm i jaka najmniejsza częstotliwość?** Bywa, że nie ma crontaba,
   tylko plik o narzuconej nazwie uruchamiany przez HTTP — wtedy obowiązuje limit 30 s
   i plik trzeba zablokować przed dostępem z internetu.
3. **SMTP: adres, port, uwierzytelnianie i LIMITY WYSYŁKI** (na godzinę i dobę).
   Limit to jedyna z tych rzeczy, która objawia się dopiero przy kilkudziesięciu pracownikach —
   jako ciche odrzucanie poczty. ⚠ Do tego samego pytania dochodzi **SPF, DKIM i DMARC dla domeny
   nadawcy** (§1a): dostawca albo je prowadzi, albo trzeba dopisać rekordy w DNS klienta — i jedno,
   i drugie ustala się **przed** wdrożeniem, a nie po pierwszej partii zaproszeń w spamie.
4. **Baza: czy jest lokalna?** Jeśli stoi na osobnym hoście — czy port jest osiągalny
   z internetu, czy da się to ograniczyć, czy MySQL przyjmuje **TLS** i czy jest do niego **CA**.
5. **Czy do wybranej wersji PHP jest ionCube Loader i w jakiej wersji?** Po każdej zmianie
   wersji PHP w panelu trzeba to potwierdzić ponownie — Loader jest wiązany z wersją PHP.

Narzędzie: `tools/diagnostyka-hostingu.php` (jeden plik, uruchamiany przed sprzedażą,
kasowany po użyciu) odpowiada na wszystko poza tymi pięcioma pozycjami.

## 5. Zmierzone środowiska odniesienia

**home.pl, usługa na `IdeaWebServer/6.4.1`** — PHP 8.2.24, Loader 14.4, MySQL 8.0.33,
45 pozycji OK, 0 do zmiany. Ignoruje `RedirectMatch` i `Require` **bez błędu**,
`<FilesMatch>` nie działa wcale; blokada przez `[F]` działa. Pliki z kropką blokuje sam serwer.
⚠ **PHP wyłącznie do 8.2** — dokumentacja dostawcy mówi wprost, że 8.3 i 8.4 nie są na tym
typie serwera dostępne i wymagają zmiany usługi. Po decyzji z 19.09.2026 o podłodze 8.3
ta usługa **NIE kwalifikuje się**; klient musi poprosić home.pl o przeniesienie na Apache.

**home.pl, usługa „Apache" (SAPI `uwsgi`)** — PHP 8.2.29 w chwili pomiaru, wersja
przestawialna w panelu do 8.5; Loader 14.4, ta sama baza, 46 OK, 1 do zmiany.
Honoruje `Require` i `RedirectMatch`; **pliki z kropką podaje z treścią** — ochrona `.env`
stoi wyłącznie na naszych regułach. **Kwalifikuje się i jest u tego dostawcy jedynym
wariantem**: zachowuje się przewidywalnie i daje dostęp do PHP 8.3+.

⚠ Po zmianie wersji PHP w panelu **trzeba ponownie potwierdzić obecność Loadera** — jest
wiązany z wersją PHP (pytanie 5 w §4).

Wspólne dla obu: baza na `addconnect.home.pl:3380` (nazwa **publicznie rozwiązywalna**),
konto z grantem `@'%'`, brak `CREATE USER` i `GRANT OPTION`, `max_input_vars 1000`,
`max_execution_time 30 s`, `max_allowed_packet 8 MB`, komplet rozszerzeń łącznie z `sodium`.

## 6. Co zostaje otwarte — i dlaczego nie blokuje

| Pozycja | Stan | Dlaczego nie blokuje |
|---|---|---|
| TLS na połączeniu z bazą u home.pl | **niezmierzone** — jeden uruchomiony wiersz diagnostyki | mechanizm gotowy: DSN wymusza TLS przy zdalnej bazie; brak TLS zmienia komunikat wdrożeniowy, nie architekturę |
| częstotliwość crona na koncie klienta | dokumentacja mówi „co 5 minut", panel niepotwierdzony | rzadszy cron = wolniejsza eskalacja; poczta pilna ma drugi wyzwalacz |
| limity wysyłki SMTP | dostawca nie publikuje | kolejka z ponawianiem i logiem; limit ujawni się jako błąd zadania, nie jako zgubiona wiadomość |
| DMARC dla domeny nadawcy | **brak rekordu** — zmierzone na doręczonej wiadomości (§1a) | SPF i DKIM przechodzą, więc poczta dziś dochodzi; DMARC jest warunkiem **skali**, nie pierwszego wdrożenia |
| odbicia (`bounce`) | nie czytamy skrzynki `Return-Path` | kolejka melduje „przekaźnik przyjął"; zły adres w kadrach widać dopiero po tym, że pracownik się nie zalogował |
| wersja Encodera do kupienia | pytanie do ionCube | Loader 14.4 u dostawcy przesądza kierunek: Encoder 14, albo 15 z wyborem zakresu PHP — do potwierdzenia u producenta |

**Werdykt: temat hostingu zamknięty.** Wymagania są spisane, a cztery pozycje wyżej to lista
kontrolna wdrożenia, nie otwarte decyzje projektowe.

⚠ **Zmiana z 19.09.2026: podłoga PHP to 8.3, nie 8.2.** Decyzja produktowa, podjęta świadomie
i z ceną: usługi oparte o `IdeaWebServer` (u home.pl — PHP maksymalnie 8.2) wypadają z zakresu
wsparcia. U tego dostawcy wymagamy usługi **Apache**. W zamian podłoga ma wsparcie
bezpieczeństwa do 31.12.2027 zamiast do 31.12.2026, a zakres testowy zwęża się do 8.3–8.5.
⚠ Mechanizmów pisanych pod nieprzewidywalne serwery **nie usuwamy**: reguła „blokujemy
wyłącznie `RewriteRule … [F]`", weryfikacja ochrony katalogów pobraniem po HTTP, profil
`minimal` i cron przez HTTP zostają — dotyczą hostingów, których nie znamy, a nie jednego
typu serwera.
