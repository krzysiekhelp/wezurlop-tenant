# wezurlop — architektura systemu

Schemat blokowy całości i technologia dla każdego bloku. Stan: 15.09.2026 (wersja robocza,
do zatwierdzenia). Wersja graficzna ze schematem: artefakt **„Architektura wezurlop"**.

Dokument opisuje **warstwę techniczną**. Logika biznesowa — eskalacje, limity urlopu na żądanie,
zastępstwa, retencja załączników, zakres danych o zwolnieniach — jest następnym krokiem,
po zatwierdzeniu poniższego.

---

## Schemat ogólny

Siedem bloków:

```
[1] aplikacja mobilna  ──WebView──▶  [2] aplikacja tenanta (hosting klienta)
         │                                   │        ▲
         │ discovery, push                   │        │ REST API (tylko wychodzące)
         ▼                                   ▼        │
[3] panel centralny  ◀──licencje,       [7] broker   [4] connector (R2 Płatnik,
     (nasza infra)      aktualizacje,    logowania        sieć klienta)
                        telemetria       auth.wezurlop.pl

[5] instancja demo (nasz hosting)      [6] strona sprzedażowa (statyczna)
```

**Przez naszą infrastrukturę przechodzą tylko cztery ścieżki:** wskazanie adresu tenanta
(discovery), odnawianie licencji razem z aktualizacjami, przekazanie powiadomień push
oraz — gdy panel włączy to danemu tenantowi — logowanie przez Google i Apple w przeglądarce.

**Dane kadrowe nie przechodzą przez żadną z nich.** E-mail idzie z serwera klienta bezpośrednio,
push niesie wyłącznie identyfikator zdarzenia. Wyjątkiem jest broker logowania, przez który
przechodzą adresy e-mail osób logujących się — dlatego stoi osobno, a nie w panelu.

⚠ Obowiązujący opis tych ścieżek to **„przelotowe, nie magazynujące"**: dane istnieją na czas
jednego wywołania, w logach zostaje identyfikator tenanta, czas i wynik. Wcześniejsze zdanie
„panel widzi tylko telemetrię" zostało wycofane (znalezisko L5) — przez infrastrukturę
przechodzą tokeny urządzeń, treści powiadomień, numery telefonów i adresy e-mail.

---

## 1. Aplikacja mobilna

**Android + iOS, jedna aplikacja dla wszystkich tenantów.**

Natywna powłoka wokół WebView. Odpowiada za konfigurację tenanta, blokadę ekranu, logowanie
natywne, powiadomienia i dostęp do aparatu oraz plików. Cała logika biznesowa zostaje na serwerze.

| Technologia | Uzasadnienie |
|---|---|
| **Flutter 3 / Dart** | decyzja podjęta; jedna baza kodu na obie platformy, dojrzały ekosystem wtyczek natywnych |
| **`flutter_inappwebview`** | zamiast `webview_flutter` — daje allowlistę nawigacji, kontrolę zaufania certyfikatu i włączanie mostka JS tylko dla właściwego origin |
| **`flutter_secure_storage`** | adres tenanta, identyfikator urządzenia i klucze w Keychain/Keystore; `SharedPreferences` nie jest magazynem zabezpieczonym |
| **`local_auth`** | odcisk palca i Face ID, z fallbackiem na PIN |
| **`firebase_messaging` + `flutter_local_notifications`** | odbiór powiadomienia i wyświetlenie lokalnie po dociągnięciu treści z tenanta |
| **`google_sign_in`, `sign_in_with_apple`** | logowanie natywne; token tożsamości weryfikuje **serwer tenanta**, nie aplikacja |
| **`image_picker`, `file_picker`** | zdjęcie podpisanego wniosku, wgranie PDF-u |
| **ed25519 (`cryptography`)** | weryfikacja podpisu odpowiedzi discovery kluczem publicznym wkompilowanym w aplikację |

⚠ **Mostek JavaScript to granica bezpieczeństwa.** Adres serwera pochodzi z tego, co użytkownik
wpisze jako nazwę tenanta. Dopóki kanał natywny jest dostępny dla dowolnej załadowanej strony,
cudzy serwer może wywołać logowanie Google i przejąć token tożsamości. Kanał wolno włączać
wyłącznie dla origin zwróconego przez **podpisane** discovery.

Kontrakt mostka: `docs/mostek-natywny.md`.

---

## 2. Aplikacja tenanta

**`{tenant}.wezurlop.pl` albo własna domena klienta · hosting klienta · instalacja per klient.**

Serce systemu: wnioski, akceptacje, hierarchia, salda, kalendarze, załączniki, uwierzytelnianie
kont lokalnych z 2FA oraz weryfikacja logowania przez Google i Apple. Obsługuje web i mobile
tym samym backendem oraz wystawia REST API dla connectora. Jedna instalacja może obsługiwać
kilka firm.

| Technologia | Uzasadnienie |
|---|---|
| **PHP 8.2 + MySQL 8** | wersję PHP ostatecznie dyktuje posiadana wersja ionCube Encodera, nie odwrotnie |
| **Twig** | auto-escaping czyni XSS wyjątkiem zamiast domyślności; najważniejsza pojedyncza biblioteka w tym zestawie |
| **htmx** | serwer zwraca fragmenty HTML z Twiga, htmx podmienia kawałek strony — płynność w WebView bez drugiego API, bez stanu po stronie klienta i bez drugiej bazy kodu; błędy nadal lądują w logu serwera, nie na cudzym telefonie |
| **`nikic/fast-route`** | routing bez niespodzianek na nietypowych adresach |
| **`firebase/php-jwt`** | weryfikacja tokenów Google i Apple: podpis z JWKS dostawcy, kontrola `aud`, `iss`, `exp`, `email_verified`, `nonce`. Aplikacja token tylko przynosi — uznaje go dopiero tenant |
| **`robthree/twofactorauth` + `bacon/bacon-qr-code`** | TOTP i kody zapasowe, obowiązkowo dla administratora tenanta; **kod QR generowany lokalnie** — domyślne generatory w bibliotekach 2FA wysyłają sekret do zewnętrznego serwisu przez adres obrazka |
| **ionCube Encoder** | tylko `/src/License` i `/src/Core`; szablony Twig i zasoby front-endu pozostają jawne |
| **Phinx** | migracje; aktualizacja zdalna musi umieć podnieść schemat bez ręcznej ingerencji |
| **cron + tabela zadań** | na hostingu współdzielonym nie ma procesów w tle — eskalacje, alerty o milczącym connectorze i wysyłka e-maili idą przez zadania w MySQL odpalane cronem |
| **PHPMailer** | SMTP na skrzynce klienta, niezależnie od panelu |
| **mPDF** | papierowa wersja wniosku do wydruku; czysty PHP, poprawne polskie znaki, działa na hostingu współdzielonym |
| **instalator** | w paczce, uruchamiany raz: diagnostyka środowiska, zapis `.env`, migracje, rejestracja w panelu, pierwszy administrator przez jednorazowy link; **wymaga tokenu instalacyjnego z panelu**, po zakończeniu przestaje działać |

### Model danych: osoba ≠ zatrudnienie

Pracownik na pół etatu w dwóch spółkach tego samego tenanta to **jedna osoba i dwa zatrudnienia**.
Saldo, wniosek i przełożony wiszą przy **zatrudnieniu**; logowanie, telefon i urządzenie
przy **osobie**. Zwolnienie lekarskie dotyczy osoby, więc blokuje obie prace.

Szczegóły implementacyjne: `docs/szkielet-tenanta.md`, rozdział 5.

---

## 3. Panel centralny

**`panel.wezurlop.pl` · osobne konto hostingowe · dostęp tylko dla obsługi.**

Rejestr klientów i licencji, wydawanie wersji, discovery adresów tenantów, przekaźnik powiadomień
i SMS, telemetria. To tu włącza się tenantowi logowanie przez Google i Apple w przeglądarce.
Interfejs prosty, ale **to najcenniejszy cel ataku w całym systemie**.

| Technologia | Uzasadnienie |
|---|---|
| **PHP 8.2 + MySQL** | ten sam stack co tenant: jeden zestaw reguł bezpieczeństwa, jedno utrzymanie. Bez ionCube — kod nie opuszcza naszego serwera |
| **hosting, nie VPS** | wszystko, czego panel potrzebuje, jest na poziomie aplikacji: 2FA to kod PHP, ograniczenie po IP to `.htaccess`, klucz operacyjny to plik poza webrootem, klucz wydawniczy nigdy nie trafia na serwer. **Niepilnowany VPS jest groźniejszy niż profesjonalnie utrzymywany hosting** — przy jednoosobowym zespole łatanie systemu nie będzie się działo. VPS wraca do gry przy skali |
| **Ed25519, dwa klucze** | patrz niżej |
| **`google/auth`** | token OAuth2 dla FCM HTTP v1; poświadczenie konta serwisowego Firebase to jedyne miejsce, gdzie istnieje uprawnienie do wysyłki push |
| **TOTP (RobThree)** | drugi składnik logowania do panelu — bez tego jedno hasło otwiera wszystkie instalacje u wszystkich klientów |
| **bramka SMS przez HTTPS** | wybór dostawcy otwarty; panel egzekwuje regułę „SMS tylko w subskrypcji" |
| **allowlista IP** | interfejs administracyjny dostępny z naszych adresów (`.htaccess`); publicznie wystawione zostaje wyłącznie API dla tenantów |
| **limity w aplikacji** | na hostingu współdzielonym nie ma fail2ban — ograniczanie prób (logowania, kody 2FA, discovery, API connectora) musi siedzieć w kodzie, liczniki w bazie |

### Dwa klucze podpisujące

- **Wydawniczy** — paczki aktualizacji i pliki discovery. Klucz prywatny **nigdy nie dotyka
  serwera**: budujesz i podpisujesz u siebie, do panelu wgrywasz gotowy, podpisany artefakt.
- **Operacyjny** — odpowiedzi licencyjne, podpisywane w locie, więc leży na panelu.
- **Rotacja:** operacyjny jest poświadczany wydawniczym (mały podpisany plik „ten klucz jest
  ważny do dnia X"). W instalacjach zaszyty jest tylko klucz publiczny wydawniczy, który się
  nie zmienia — wymiana operacyjnego nie wymaga ruszania niczego u klientów.
- **Promień rażenia:** włamanie do panelu daje najwyżej fałszywe odpowiedzi licencyjne;
  nie pozwala podpisać aktualizacji ani przekierować aplikacji.
- *Otwarte:* gdzie fizycznie stoi maszyna podpisująca, kto ma dostęp, gdzie zaszyfrowana kopia
  zapasowa klucza wydawniczego. **Utrata bez kopii = żadna instalacja nie przyjmie już aktualizacji.**

⚠ **Przekaźnik push musi być bezstanowy.** Tokeny urządzeń trzyma tenant — to jego użytkownicy.
Panel dostaje token i minimalną treść tylko na czas jednego wywołania, loguje id tenanta, czas
i wynik, i nic poza tym. Inaczej stajemy się administratorem bazy urządzeń wszystkich klientów,
ze wszystkim, co z tego wynika przy RODO.

---

## 4. Connector

**Windows, sieć lokalna klienta, obok R2 Płatnika · wyłącznie połączenia wychodzące.**

Synchronizacja z programem kadrowym: wysyła urlopy i zmiany kadrowe do tenanta, pobiera
zaakceptowane wnioski i zapisuje je w R2. Konfigurowalna częstotliwość i okno godzinowe.

| Technologia | Uzasadnienie |
|---|---|
| **C# / .NET 8** | rekomendacja: usługa Windows z instalatorem MSI, naturalny dostęp do sterowników i bibliotek R2, oczekiwany przez informatyków u klienta. **Ostateczny wybór przesądzi to, czy R2 udostępnia API programistyczne, czy tylko bazę** (znalezisko A6) |
| **Go (alternatywa)** | jeden plik `.exe` bez runtime; kusi przy małych klientach, słabszy przy integracji z komponentami Windows |
| **SQLite** | lokalna kolejka na czas braku łączności — po odzyskaniu połączenia dosyła zaległości, zamiast je gubić |
| **DPAPI / Credential Manager** | klucz API do tenanta zaszyfrowany kluczem maszyny, nie w pliku konfiguracyjnym obok programu |

⚠ **REST API tenanta to najatrakcyjniejszy cel w instalacji klienta.** Ma dostęp do danych
wszystkich pracowników naraz i działa bez udziału człowieka. Potrzebuje własnego reżimu: osobna
ścieżka, klucz per instalacja z możliwością unieważnienia, limit zapytań, zapis każdego wywołania
i twarde ograniczenie do operacji z zakresu synchronizacji.

---

## 5. Instancja demonstracyjna

**`demo.wezurlop.pl` · nasz hosting · domyślny cel aplikacji bez konfiguracji.**

Zwykły tenant na tym samym kodzie, z fikcyjnymi danymi.

- **ten sam kod co tenant** — demo, które różni się od produktu, przestaje być demem
  po pierwszej aktualizacji
- **cykliczny reset danych** — wejść może każdy z internetu, więc dane muszą wracać do stanu
  wyjściowego i nie mogą być prawdziwe
- **wyłączone SMS i connector** — żadnych wysyłek na zewnątrz z instancji otwartej dla wszystkich

---

## 6. Strona sprzedażowa

**`wezurlop.pl` · osobny hosting · bez połączenia z resztą systemu.**

- **statyczny HTML + CSS** — brak backendu i bazy to brak powierzchni ataku. Strona marketingowa
  na CMS-ie jest najczęstszym wejściem do infrastruktury firmy
- **formularz przez usługę zewnętrzną** — jeśli ma być kontakt, niech wysyłką zajmie się ktoś inny

---

## 7. Broker logowania

**`auth.wezurlop.pl` · osobna usługa · włączany per tenant z panelu.**

Jedyny zarejestrowany u Google i Apple adres powrotny. Przyjmuje powrót po zalogowaniu
w przeglądarce i wydaje tenantowi jednorazowy, podpisany bilet z tożsamością. Na telefonie
nie bierze udziału w niczym — logowanie natywne idzie prosto do tenanta.

| Decyzja | Uzasadnienie |
|---|---|
| **osobny host, nie panel** | panel trzyma klucz podpisujący aktualizacje i poświadczenie FCM; `client_secret` Google i Apple nie powinien leżeć obok nich, a przez brokera przechodzą adresy e-mail. Broker może paść bez wpływu na licencje i aktualizacje |
| **PHP + cURL** | cały mechanizm to cztery przekierowania `header('Location')` i trzy wywołania cURL. Żadnego SDK, żadnego JS — działa przy CSP bez `unsafe-inline` i z wyłączonym JS |
| **adres powrotny z rejestru** | broker bierze adres tenanta z własnej bazy, po nazwie tenanta — **nigdy z parametru żądania**. Inaczej powstaje open redirect na zaufanej domenie i można nim zbierać cudze bilety. To najczęstszy błąd w tym wzorcu |
| **bilet jednorazowy, 60 s** | przez przeglądarkę idzie wyłącznie nieprzezroczysty identyfikator; dane tożsamości tenant odbiera osobnym wywołaniem serwer–serwer, uwierzytelnionym swoim kluczem API — przechwycony bilet jest bez niego bezużyteczny |
| **podpis Ed25519** | odpowiedź z danymi tożsamości podpisana, żeby tenant nie musiał ufać samemu TLS-owi; bilet związany z konkretnym tenantem |
| **pamięć na 60 sekund** | po wydaniu danych broker kasuje tożsamość; zostaje identyfikator biletu, nazwa tenanta, czas i wynik — bez adresu e-mail |

⚠ **Tożsamością jest `sub`, nie adres e-mail.** Adres pracownika zmienia się po ślubie albo przy
zmianie domeny firmy, a `sub` od dostawcy nie zmienia się nigdy i nie jest przydzielany ponownie.
Konto wiązane po adresie oznacza duplikaty przy zmianie adresu — i cudze konto dla nowej osoby,
która dostała adres po zwolnionym pracowniku. Wiązanie po adresie **tylko raz**, przy pierwszym
logowaniu z zaproszenia.

Panel zasila broker: broker trzyma rejestr tenantów lokalnie (istnienie, prawo do usługi, adres
powrotny), panel wypycha zmiany, broker działa mimo awarii panelu (znalezisko A2).
⚠ Zmiana adresu tenanta musi trafić do brokera natychmiast — inaczej po przeprowadzce klienta
logowanie odsyła pod stary adres.

---

## Discovery — zamiast CNAME

Aplikacja mobilna musi z nazwy tenanta wyprowadzić adres jego serwera. **CNAME tego nie załatwia:**
wejście na `firmax.wezurlop.pl` wymaga certyfikatu na **tę** nazwę, a klient ma certyfikat
na swoją domenę — efektem jest błąd certyfikatu, a nie ekran „brak połączenia".

Rekomendacja (A3, **czeka na decyzję**): jeden podpisany plik na tenanta,
`https://d.wezurlop.pl/t/firmax.json` o treści
`{"url":"https://urlopy.firmax.pl","active":true,"sig":"…"}`, na hoście serwującym wyłącznie
pliki statyczne. Panel generuje i podpisuje przy zakładaniu klienta albo zmianie adresu.
**Osobny plik na tenanta, nie jedna lista** — wspólna lista to wykaz klientów dla każdego.
Plik statyczny daje dodatkowo pole `active` i natychmiastową zmianę adresu, bez czekania na DNS.

---

## Wersjonowanie kontraktów (A1)

Aplikacja niesie swoją wersję; tenant sprawdza ją przy uruchomieniu i w razie potrzeby pokazuje
komunikat o konieczności aktualizacji. Paczka aktualizacji tenanta zawiera sekcję z wymaganą
wersją aplikacji mobilnej. Connector przedstawia się wersją przy połączeniu do REST API —
za stary dostaje blokadę.

Drugi kierunek (nowa aplikacja, stary tenant) załatwia sama konstrukcja: nowe funkcje jadą
ze strony renderowanej w WebView, więc stary tenant pokazuje po prostu stary interfejs.
Prawdziwym kontraktem jest **mostek natywny** — stąd reguła „tylko do dopisywania"
(`docs/mostek-natywny.md`).

---

## Decyzje jeszcze otwarte

| Temat | Na czym polega |
|---|---|
| **PIN — telefon czy serwer** | cztery cyfry to 10 000 kombinacji; weryfikacja lokalna ma sens tylko wtedy, gdy PIN odblokowuje klucz w Keystore z systemowym limitem prób |
| **Imię i nazwisko od Apple** | Apple przekazuje je **tylko przy pierwszej w życiu autoryzacji, metodą POST**, i nigdy tego nie powtórzy — broker musi przyjmować POST na adresie powrotnym i przekazać te pola w pierwszym bilecie |
| **Apple Hide My Email** | podstawia adres w `privaterelay.appleid.com`, co koliduje z dopasowaniem do adresu z zaproszenia; bez świadomej obsługi część użytkowników nie zarejestruje się i nie dowie dlaczego |
| **Wygaśnięcie prawa do brokera** | gdy panel odbierze tenantowi logowanie zewnętrzne, pracownicy logujący się wyłącznie przez Google tracą jedyną drogę wejścia — wygaszanie musi uruchamiać ścieżkę „ustaw hasło" |
| **Nonce przy logowaniu zewnętrznym** | jedna aplikacja to jeden `client_id` dla wszystkich tenantów, więc token wydany dla jednego przejdzie weryfikację u każdego innego; tenant musi wydać jednorazowy `nonce` i sprawdzić go w tokenie |
| **Zakres wymuszenia 2FA** | administrator obowiązkowo, przełożeni i kadry konfigurowalnie, pracownicy domyślnie nie; do ustalenia, czy SMS jako drugi składnik ma być w ogóle oferowany |
| **Wersja PHP pod Encoder** | do sprawdzenia w posiadanej licencji ionCube; ta liczba staje się wymaganiem instalacyjnym dla każdego klienta |
| **Pinning certyfikatu w WebView** | wykonalne w `flutter_inappwebview`, ale wymaga przekazywania odcisku certyfikatu klienta w discovery — klienci odnawiają certyfikaty |
| **Konflikty synchronizacji** | źródło prawdy to R2, ale reguły rozstrzygania i idempotencja zapisów wracają przy projektowaniu integracji |
| **jQuery czy vanilla JS** | **rozstrzygnięte przy szkielecie: vanilla JS**, bez gotowych komponentów ciągnących jQuery |

Pełna lista znalezisk i ich przypisanie do bloków: `docs/audyt.md`.
