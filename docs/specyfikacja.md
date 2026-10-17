# wezurlop.pl — specyfikacja robocza i pytania otwarte

Stan na: 2026-09-15. Dokument roboczy — notatki z rozmowy + lista rzeczy do rozstrzygnięcia
przed wyborem technologii.

---

## 1. Co to jest

System do obsługi wniosków urlopowych, sprzedawany firmom, **instalowany u klienta**
i synchronizowany z jego programem kadrowym.

### Domeny

| Adres | Rola |
|---|---|
| `wezurlop.pl` | strona sprzedażowo-informacyjna |
| `panel.wezurlop.pl` | centralne zarządzanie: licencje, aktualizacje, klienci |
| `<tenant>.wezurlop.pl` | aplikacja kliencka — wymaga najwyższego poziomu zabezpieczeń |

### Aplikacja mobilna

- Android + iOS, nazwa w sklepach: wezurlop
- WebView opakowane w natywną powłokę
- Przy pierwszym uruchomieniu konfiguracja tenanta; zapisywana lokalnie
- Bez konfiguracji → instancja demo
- Tenant `testowa` → `testowa.wezurlop.pl` (DNS lub inny bezpieczny mechanizm) → serwer klienta
- Funkcje natywne: logowanie biometryczne, PIN, powiadomienia push, wgrywanie PDF i zdjęć
  (np. skan podpisanego wniosku)

### Model sprzedaży

- Licencja wieczysta „kupujesz raz, korzystasz cały czas" — dla mniejszych firm
  niechętnych subskrypcji
- Wariant subskrypcyjny równolegle
- Klient kupuje lub ma własny hosting; instalacja po naszej stronie
- Ochrona i kontrola przez **ionCube** — z `panel.wezurlop.pl` wydawanie aktualizacji,
  przedłużanie licencji

---

## 2. Zakres funkcjonalny

- Aplikacja mobilna (Android, iOS) + dostęp przez WWW
- Automatyczne pobieranie i zapisywanie urlopów do R2 Płatnik
- Obsługa wielofirmowości
- Powiadomienia push i e-mail o nowych zdarzeniach
- Hierarchia przełożony → pracownik
- Bieżący podgląd salda urlopowego
- Rezerwacja urlopu i akceptacja przez przełożonego
- Integracja z systemem logistyka
- Rodzaje urlopów i zwolnień (urlop wypoczynkowy, na żądanie, …)
- Automatyczne pobieranie zwolnień z ZUS do R2 Płatnik (gdy nie jest uruchomione po stronie R2)
- Wysyłka SMS z aplikacji
- Komunikaty wewnętrzne

---

## 3. Zasada działania

- Pracownik widzi saldo urlopu oraz ilość jeszcze niezaakceptowaną
- Wniosek: daty, opcjonalnie liczba godzin (dla poprawnego zapisu w R2)
- Przełożony ma **X czasu** na akceptację; po przekroczeniu sprawa idzie wyżej
- Przełożony na urlopie → sprawa idzie wyżej od razu
- Urlop na żądanie nie wymaga akceptacji
- Po akceptacji zapis automatyczny do R2 Płatnik
- Zmiany w R2 (nowy rok = nowa pula, urlop wpisany ręcznie) pojawiają się w aplikacji
- Zwolnienie w R2 automatycznie blokuje konto w aplikacji
- Nowy pracownik w R2 → pojawia się w systemie; administrator wskazuje przełożonego,
  podaje telefon i e-mail, generuje wiadomość powitalną
- Przełożony przegląda kalendarze urlopowe podwładnych, może wpisywać im urlopy *(do potwierdzenia)*
- Opcjonalne powiadomienia e-mail na każdym etapie
- Generowanie papierowej wersji wniosku do wydruku
- Komentarze pod wnioskiem — przełożony może dopytać przed akceptacją

---

## 4. Integracje

- Synchronizacja, nie bezpośredni dostęp do bazy programu kadrowego
- Pierwszy: **R2 Płatnik**. Dokumentacja i sposób realizacji po stronie zamawiającego —
  nie wymaga analizy
- **Connector** instalowany tam, gdzie stoi program kadrowy; łączy się do właściwego tenanta
  i synchronizuje informacje o urlopach
- W przyszłości kolejne programy kadrowe

---

## 5. Ustalenia

### Wielofirmowość i tożsamość

- Jeden tenant **może obsługiwać wiele firm** — biuro rachunkowe z wieloma firmami w programie
  kadrowym, albo grupa spółek, gdzie ten sam pracownik ma 1/2 etatu w każdej.
- Konsekwencja: **osoba ≠ zatrudnienie**.
  - *Osoba* — jedna na tenanta: logowanie, telefon, e-mail, biometria, urządzenie
  - *Zatrudnienie* — osobne per spółka: wymiar etatu, pula urlopu, przełożony, powiązanie z R2
- Saldo, wniosek, akceptacja i eskalacja wiszą przy **zatrudnieniu**.
  Zwolnienie lekarskie dotyczy **osoby** — blokuje wszystkie zatrudnienia.
- Jedno logowanie może dawać dostęp do kilku firm; konfigurowalne per tenant.
- Connector musi mapować firmę z R2 na `company_id` u nas.

### Hosting i środowisko

- Prosty hosting współdzielony w typie home.pl: **jest ionCube Loader, jest cron, PHP i MySQL**.
- Brak procesów w tle → eskalacje, alerty i wysyłki przez **tabelę zadań + cron**.

#### Fakty z `phpinfo()` na home.pl (sprawdzone 16.09.2026)

| | Wartość | Konsekwencja |
|---|---|---|
| PHP | **8.1.30** | ⚠ wersja **po końcu wsparcia** — przełączyć konto na 8.2+ w panelu hostingu |
| ionCube Loader | **14.4.1** | obecny; sprawdzić zgodność z wersją Encodera przed zakupem |
| Serwer WWW | **IdeaWebServer / UltraCGI**, nie Apache | ⚠ największe ryzyko: obsługa `.htaccess` i przepisywania adresów może się różnić |
| `max_execution_time` | **30 s** | ⚠ synchronizacja, migracje i zadania cron **muszą być dzielone na porcje** |
| `memory_limit` | 512 M | wystarczające |
| `upload_max_filesize` | 64 M | wystarczające na skany i PDF-y |
| `disable_functions` | puste | brak niespodzianek |
| Przed serwerem | **Cloudflare** | ⚠ `REMOTE_ADDR` to adres proxy — limity „per IP" wymagają prawdziwego adresu (§9 reguł) |
| Rozszerzenia | komplet: `sodium`, `intl`, `zip`, `gd`, `bcmath`, `exif`, `opcache` z JIT | u tego hostingu bogato; u innych klientów zakładamy minimum |

home.pl pozwala przełączyć konto na **Apache**, co ułatwia testy — ale **kod nie ma prawa tego
zakładać**. Aplikacja idzie na cudze hostingi, więc uniwersalność jest wymaganiem produktowym,
nie preferencją. Zasady spisane w §0 reguł („Uniwersalność hostingu"):

- brak funkcji dostępnych tylko pod mod_php; nagłówki z `$_SERVER`
- nagłówek `Authorization` bywa wycinany pod CGI → reguła w `.htaccess` **plus** zapasowy `X-Api-Key`
- router odporny: `REQUEST_URI`, a przy braku przepisywania `PATH_INFO`
- paczka instalowalna zarówno z katalogiem domeny na `public/`, jak i z aplikacją powyżej webrootu
- kolacja `utf8mb4_unicode_ci` (przenośna między MySQL i MariaDB)
- wszystko dzielone na porcje mieszczące się w 30 sekundach
- poczta przez SMTP, nigdy `mail()`

**Do zweryfikowania w pierwszym tygodniu**, przy wdrożeniu pustego tenanta:

1. czy `.htaccess` z przepisywaniem wszystkich żądań na `public/index.php` działa —
   **i czy działa także bez przełączania na Apache**, bo u klienta może być inaczej
2. czy da się ustawić katalog domeny na `public/`
3. czy nagłówek `Authorization` dociera do PHP
4. jaka jest najczęstsza dopuszczalna częstotliwość crona i czy CLI ma inny limit czasu niż 30 s

### Adresacja i TLS

- Klient ma **własną domenę i własny certyfikat**. Nie ma wildcardu `*.wezurlop.pl` u klientów.
- Aplikacja mobilna nie łączy się przez naszą subdomenę na ślepo — pyta panel o adres tenanta
  (**discovery**), a odpowiedź jest **podpisana Ed25519**; klucz publiczny wkompilowany w aplikację.
- W odpowiedzi discovery może jechać też odcisk certyfikatu tenanta (pinning sterowany serwerem,
  nie zaszyty w aplikacji — klienci odnawiają certyfikaty).
- W aplikacji: twarda allowlista nawigacji WebView, mostek JS aktywny tylko dla właściwego origin,
  `device_id` z `Random.secure()` w secure storage.

### Panel centralny

- Kierunek: **tenant dzwoni do panelu**, cyklicznie, po odświeżenie licencji. Panel wie, kto zapłacił.
- Panel widzi **tylko telemetrię** — nie dane klienta.
- Po wygaśnięciu licencji: **aplikacja zablokowana z komunikatem**.
- Aktualizacje: wgrywamy paczkę w panelu i wskazujemy, który tenant może zaktualizować.
  Administrator tenanta robi to **ręcznie** — przycisk backup + update. Paczka zawiera też
  skrypt migracji bazy.
- Odpowiedź licencyjna musi być **podpisana** (inaczej podstawiony DNS przedłuża licencję)
  i musi istnieć **okres karencji** — awaria panelu nie może wyłączyć wszystkich klientów naraz.
- DNS **nie jest** dźwignią egzekwowania licencji — klienci mają własne domeny.
  Licencja w kodzie chronionym ionCube jest jedynym mechanizmem.
- Panel jest też **pośrednikiem bramki SMS**; SMS i push zależne od licencji (subskrypcja).
- Proces aktualizacji w szczegółach — do dopracowania po zbudowaniu projektu.
- ⚠ Panel trzyma klucz podpisujący aktualizacje, poświadczenie FCM i decyzję o ważności licencji
  wszystkich klientów. To **najcenniejszy cel ataku w całym systemie**, mimo prostego interfejsu.

### Connector

- Połączenie **wyłącznie connector → REST API tenanta** (connector jest za NAT-em).
- Uwierzytelnianie kluczem API, per instalacja.
- Zakres: wysyła bieżące urlopy i zmiany kadrowe (nowy / zwolniony), pobiera nowe urlopy
  i zapisuje w R2 Płatniku.
- Częstotliwość i okno godzinowe konfigurowane w connectorze.
- Brak połączenia przez X (konfigurowane po stronie tenanta) → alert e-mail.
- W konfiguracji każdego tenanta: **skrzynka pocztowa na alerty** i **konfigurowany serwer REST API**.
- Pełna logika synchronizacji i konflikty — osobny etap.

### Logowanie i rejestracja

- Konta **lokalne, Google i Apple** — wszystkie trzy.
- Model zaproszeniowy: administrator tenanta widzi użytkowników pobranych przez connector,
  może też dodać konto niepowiązane z connectorem. Wpisuje e-mail i telefon, wysyła zaproszenie
  (mail / SMS). **Bez zaproszenia rejestracja nie przechodzi.** Konto Gmail lub Apple ID upraszcza
  rejestrację i logowanie.
- ⚠ Apple „Hide My Email" podstawia adres w `privaterelay.appleid.com` — przy wymogu zgodności
  z adresem z zaproszenia część użytkowników odbije się bez zrozumiałego powodu.
- ⚠ Normalizacja adresów Gmail (kropki, aliasy z plusem).
- ⚠ Jeden `client_id` dla wszystkich tenantów → token wydany dla jednego przejdzie weryfikację
  u każdego innego. Konieczne **nonce**: tenant wydaje jednorazowy, aplikacja przekazuje go
  do żądania logowania, tenant sprawdza w tokenie.
### Logowanie zewnętrzne w przeglądarce — `auth.wezurlop.pl` (ROZSTRZYGNIĘTE)

Problem: logowanie zewnętrzne w przeglądarce wymaga zarejestrowanego `redirect_uri`,
a każdy klient ma własną domenę. Natywnie na telefonie problemu nie ma.

**Decyzja: osobny broker `auth.wezurlop.pl`, włączany per tenant z panelu centralnego.**
Osobny host, nie panel — bo panel trzyma klucz podpisujący aktualizacje i poświadczenie FCM,
a przez brokera przechodzą adresy e-mail.

Przepływ (web):

| # | Kto wykonuje i czym | Co leci | Widoczne w URL |
|---|---|---|---|
| 1 | PHP tenanta → `header('Location')` | `tenant`, `state_A`, dostawca | tak |
| 2 | przeglądarka → GET na `auth` | to samo | tak |
| 3 | PHP `auth` → `header('Location')` | `client_id`, `redirect_uri`, `state_B`, `nonce` | tak |
| 4 | przeglądarka → GET na Google/Apple; użytkownik się loguje | — | tak |
| 5 | Google → 302 na `auth` (Apple: POST `form_post`) | kod autoryzacyjny + `state_B` | tak |
| 6 | PHP `auth` → **cURL POST** na endpoint tokenu | kod + `client_id` + `client_secret` | nie |
| 7 | dostawca → odpowiedź JSON | `id_token` z `sub`, `email`, nazwiskiem | nie |
| 8 | PHP `auth` → **cURL GET** JWKS (cache) | klucze publiczne do weryfikacji podpisu | nie |
| 9 | PHP `auth` → `header('Location')` na tenanta | **sam bilet** + `state_A` | tak |
| 10 | PHP tenanta → **cURL POST** `auth/redeem`, klucz API w nagłówku | bilet + klucz | nie |
| 11 | PHP `auth` → odpowiedź JSON | podpisane dane tożsamości | nie |
| 12 | PHP tenanta → `session_regenerate_id`, `setcookie`, `header('Location')` | sesja | nie |

Zabezpieczenia obowiązkowe:

- **Adres powrotny broker bierze z własnego rejestru**, nigdy z parametru żądania — inaczej
  open redirect na zaufanej domenie i zbieranie cudzych biletów.
- **Bilet związany z tenantem**, jednorazowy, 60 s. Tenant A nie zrealizuje biletu tenanta B.
- **`state` na obu odcinkach** (tenant↔broker, broker↔dostawca).
- Odpowiedź `redeem` **podpisana Ed25519** — tenant nie ufa samemu TLS-owi.
- `access_token` i `refresh_token` od dostawcy **wyrzucane** — tenant nie woła API Google.
- Broker kasuje tożsamość po wydaniu; zostaje id biletu, tenant, czas, wynik — bez adresu e-mail.
- **Tożsamością jest `sub`, nie e-mail.** Wiązanie po adresie tylko raz, przy pierwszym logowaniu
  z zaproszenia; dalej wyszukiwanie po parze `provider` + `sub`.

Cały mechanizm to cztery `header('Location')` i trzy wywołania cURL. Zero JavaScriptu —
działa przy CSP bez `unsafe-inline`.

Otwarte: imię i nazwisko od Apple tylko przy pierwszej autoryzacji (POST, bez powtórki);
ścieżka „ustaw hasło" przy odebraniu tenantowi prawa do brokera.

### 2FA dla kont lokalnych tenanta

- **TOTP** (aplikacja uwierzytelniająca) jako podstawa. SMS tylko jako alternatywa w subskrypcji —
  kosztuje, idzie przez panel, podatny na przejęcie numeru.
- **Kod QR generowany lokalnie.** Domyślne generatory w bibliotekach 2FA wysyłają sekret
  do zewnętrznego serwisu przez URL obrazka — to kompromitacja drugiego składnika.
- Sekret TOTP **szyfrowany w bazie**; 8 jednorazowych kodów zapasowych, hashowanych, pokazanych raz.
- Obowiązkowo dla **administratora tenanta**. Konfigurowalnie per rola dla przełożonych i kadr.
  Domyślnie wyłączone dla pracowników.
- Sesja po samym haśle jest **częściowo uwierzytelniona** — nie daje dostępu do niczego poza
  ekranem 2FA, ma własny limit czasu, a `session_regenerate_id` wykonuje się **drugi raz**
  po przejściu 2FA.
- Obowiązkowy limit prób (sześć cyfr to milion kombinacji), jednorazowość kodu w oknie czasowym,
  tolerancja zegara ±1 okno.
- Reset 2FA przez administratora dozwolony (zgubiony telefon), ale zapisywany w logu zdarzeń.
- Logowanie przez Google i Apple ma własny drugi składnik po stronie dostawcy — nie dokładamy TOTP.

### Powiadomienia push

- **Jedna aplikacja dla wszystkich tenantów** → jeden projekt Firebase → jedno poświadczenie
  konta serwisowego, które musi być u nas.
- Sprostowanie: w aplikacji **nie ma klucza do wysyłania pushy**. `google-services.json` zawiera
  dane publiczne. Wysyłka wymaga poświadczenia konta serwisowego (FCM HTTP v1).
- Architektura: **tenant → panel (przekaźnik) → FCM**.
  - Tokeny urządzeń trzyma **tenant**, nie panel
  - Panel jest **bezstanowy**: nie zapisuje tokenów ani treści, loguje id tenanta, czas, wynik
  - Treść pusha: typ zdarzenia + identyfikator, **bez danych osobowych**; szczegóły aplikacja
    dociąga z tenanta po zalogowanej sesji
  - Klucz przekaźnika per instalacja, odwoływalny, z limitem zapytań
  - Nieważne tokeny FCM wracają do tenanta do skasowania
  - E-mail musi działać **niezależnie od panelu**, bezpośrednio z tenanta
- Odrzucone: osobny projekt Firebase per klient — wymagałoby rejestrowania bundle ID i klucza APNs
  z naszego konta Apple Developer przy każdym kliencie.

### Aplikacja mobilna

- **Flutter + WebView.** Materiał referencyjny: `main.dart` z innej aplikacji.
- Z referencji przenosimy: blokada po bezczynności z biometrią i fallbackiem na PIN,
  push niosący tylko identyfikator, obsługa `<input type=file>` przez aparat i picker,
  watchdog i ekran błędu przy braku sieci.
- **PIN — do przemyślenia**: lokalnie czy serwerowo. Cztery cyfry to 10 000 kombinacji;
  weryfikacja lokalna ma sens tylko gdy PIN odblokowuje klucz w Keystore z limitem prób.
- Blokada PIN/biometria to **wygoda, nie kontrola dostępu** — bramą pozostaje sesja serwerowa.
- Konfiguracja tenanta przy starcie; jeden tenant naraz.

### Pozostałe

- **demo.wezurlop.pl** — nasz tenant, domyślne środowisko aplikacji bez konfiguracji,
  do testów i prezentacji. Wymaga cyklicznego resetu danych i wyłączonych wysyłek.
- **panel** — dla obsługi, nie dla klienta; prosty interfejs, ale wystawione API do komunikacji
  z tenantami.
- **RODO** — do opracowania po zamknięciu założeń projektu.

---

### ionCube — koszty i wymagana edycja

- **Loader** (u klienta) — **darmowy**. To warunek wykonalności całego modelu on-premise.
- **Encoder** (u nas) — płatny, licencja wieczysta. Cennik Encoder 15, USD bez VAT:
  - **Basic $249** — samo kodowanie, bez funkcji licencyjnych
  - **Pro $369** — licencje z datą ważności, wiązane z domeną/IP
  - **Cerberus $429** — dodatkowo wiązanie z adresem MAC
  - Pro CI $476 / Cerberus CI $549 (2 licencje, tylko Linux/FreeBSD, pod automatyczny build)
  - Upgrade **Basic → Pro $120**, Basic → Cerberus $180, Pro → Cerberus $60
  - GUI na Windows/macOS +$40 (wersja konsolowa w cenie); cena obejmuje wszystkie platformy
  - Wsparcie 3–6 miesięcy zależnie od edycji; większe wersje Encodera to płatny upgrade
- 💡 **$249 + $120 = $369 = cena Pro kupionego od razu.** Za odłożenie decyzji nie płacimy nic —
  to przesądza o starcie od Basica.
- **Decyzja: startujemy od Basica**, moduł licencyjny piszemy sami w strefie kodowanej.
  - Siła licencjonowania bierze się z **podpisu Ed25519 na odpowiedzi panelu**, nie z ionCube —
    nikt nie wystawi sobie licencji bez klucza prywatnego, niezależnie od edycji Encodera.
    ionCube chroni tylko kod, który ten podpis sprawdza.
  - Różnica Pro vs Basic to **próg wysiłku, nie absolutna odporność**: dekodowanie przełamuje oba
    warianty, bo po zdekodowaniu aplikacja działa jako zwykły PHP i Loader nie bierze już udziału.
    Pro blokuje leniwy atak (kopiowanie katalogu na inny serwer bez dotykania kodu) — to samo
    da się osiągnąć własnym wiązaniem instalacji z domeną i odciskiem bazy.
  - Realne zagrożenie: klient kopiujący instalację na drugą spółkę albo przestający płacić
    po roku — na to własne sprawdzenie wystarcza.
  - **Przejście na Pro później nie wymaga przepisywania aplikacji**, o ile reszta kodu zna
    wyłącznie `LicenseGate` (§15 reguł). Warto wrócić, gdy pojawią się klienci subskrypcyjni
    i pierwszy przestanie płacić, nie przestając używać.
- ⚠ Skoro ochrona zależy od własnego kodu, reguły z §15 obowiązują bez taryfy ulgowej:
  żadnej metody `isValid(): bool`, stan licencji **dostarcza parametry potrzebne do działania**,
  sprawdzenie w kilku miejscach, podpis weryfikowany kluczem publicznym w strefie kodowanej,
  wiązanie z instalacją, wykrywanie cofnięcia zegara.
- **Online Encoder** — wariant bez zakupu Encodera, rozliczany kredytami ($0.10/kredyt,
  minimum $10). Sensowny na próby, nie na produkcję.
- **Trial Encodera** — 14 dni od pobrania, pełne funkcje wszystkich edycji (w tym licencje Pro),
  ale **pliki zakodowane trialem przestają działać po 36 godzinach** i EULA zabrania użycia
  komercyjnego. ⚠ Zegar rusza przy pobraniu — nie pobierać, dopóki nie ma działającego tenanta
  z modułem licencyjnym i gotowego procesu budowania paczki, inaczej trial idzie na pusty szkielet.
  Wszystko wokół ionCube (podział stref, build, migracje, podpisy, pobieranie licencji)
  testuje się na zwykłym PHP.
- **Online Licensing** — osobna subskrypcja, cena niepublikowana. To **portal do wystawiania
  licencji**, nie serwer walidacji — nie zastępuje naszego panelu.
- Do sprawdzenia: czy przejście na nowszą wersję Encodera (pod nowsze PHP) jest płatnym upgradem.
- ⚠ **Wersje Loadera się rozjeżdżają:** maszyna deweloperska ma **15.5.0**, home.pl ma **14.4.1**.
  Loadery są zgodne wstecz (nowszy uruchomi starsze pliki), ale nie w drugą stronę — plik
  zakodowany Encoderem 15 może wymagać Loadera 15. **Sprawdzić przed zakupem Encodera:**
  jakiej minimalnej wersji Loadera wymagają pliki z Encodera 15 i czy home.pl aktualizuje Loadery.
  Wyszłoby to inaczej dopiero przy pierwszym wdrożeniu paczki u klienta.

### Podział strefy kodowanej a pobieranie licencji

- **Poza strefą kodowaną:** kod dzwoniący do panelu po odnowienie licencji i zapisujący
  podpisaną odpowiedź. Musi działać zawsze — również przy wygasłej licencji, inaczej aplikacja
  nie ma jak poprosić o nową.
- **W strefie kodowanej:** logika biznesowa, weryfikacja podpisu i egzekwowanie zakresu licencji.
- Jawność pobieracza niczego nie osłabia: on tylko przenosi podpisaną odpowiedź z punktu A
  do punktu B. Nie decyduje o ważności i nie da się przez niego nic podrobić bez klucza prywatnego.
  Protokół nie jest sekretem.
- *(Gdyby kiedyś doszła edycja Pro i pliki licencyjne ionCube, ta granica staje się twarda:
  zakodowane pliki bez ważnej licencji w ogóle się nie uruchomią — tym bardziej pobieracz
  musi zostać poza strefą.)*

### Licencja — jak działa przy Basicu

Wszystko egzekwuje **własny kod w strefie kodowanej**, na podstawie podpisanej odpowiedzi panelu:

| | Wieczysta | Subskrypcja |
|---|---|---|
| Stan z panelu | bez daty wygaśnięcia, węższy zakres funkcji | z datą, odnawiana cyklicznie |
| Aktualizacje | przez rok od zakupu | przez cały okres subskrypcji |
| Limit userów | — | tak, wpływa na cenę |

⚠ Wieczysta bez daty wygaśnięcia jest **nieodwoływalna** — tak ma być przy „kup raz, korzystaj
cały czas", ale nie ma drogi powrotnej. Alternatywa: licencja długa, ale skończona, odnawiana
automatycznie tak długo, jak klient nie łamie umowy.

⚠ Wiązanie z domeną kusi, ale klient prędzej czy później zmieni hosting. Przy modelu wieczystym
(brak bieżącego kontaktu) skończy się telefonem „przestało działać po przeprowadzce" —
potrzebna prosta ścieżka przepięcia instalacji.

*(Warstwa z plikami licencyjnymi ionCube — dostępna dopiero w edycji Pro — wchodzi w grę
później, jeśli okaże się potrzebna; patrz decyzja wyżej.)*

### Model rozliczeń (doprecyzowany)

- **Zawsze osobna instalacja** — u klienta albo u nas, ale odseparowana od innych.
- Różnica wyłącznie w rozliczeniu: wieczysta (większa kwota z góry, aktualizacje przez rok,
  **węższy zakres funkcji**) albo subskrypcja miesięczna zależna od liczby użytkowników.
- **Usługa instalacji płatna w obu wariantach.**

### Instalator tenanta

Osobny komponent dostarczany razem z paczką tenanta, uruchamiany raz, przy wdrożeniu u klienta.
Zamyka lukę L1 (instalacja i provisioning).

**Co robi**

1. **Diagnostyka środowiska** — wersja PHP, obecność ionCube Loadera, wymagane rozszerzenia
   (`pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `iconv`, `xmlwriter`), prawa zapisu
   do `/storage` i `/var` — **`sodium` nie jest wymagane**, kryptografia idzie przez
   `paragonie/sodium_compat`,
   ustawienie DocumentRoot. Czytelny raport zamiast białej strony.
2. **Zebranie danych** — dostęp do bazy, nazwa i klucz tenanta wygenerowany w panelu,
   adres bazowy, e-mail administratora, konfiguracja SMTP i skrzynki na alerty.
3. **Zapis `.env`** poza webrootem, uruchomienie migracji (phinx).
4. **Rejestracja w panelu** — pierwsze pobranie licencji, potwierdzenie, że klucz działa.
5. **Utworzenie pierwszego administratora** przez **jednorazowy link ustawiający hasło**,
   wysłany na adres klienta. Nigdy hasło wpisywane w instalatorze — instalujący nie ma znać
   hasła administratora klienta.
6. **Samodezaktywacja** po zakończeniu.

**Wymagania bezpieczeństwa**

- ⚠ **Token instalacyjny z panelu wymagany do uruchomienia.** Bez tego istnieje realny wyścig:
  pliki są już na serwerze, domena działa, a instalacja jeszcze się nie odbyła — kto pierwszy
  wejdzie, ten zostaje administratorem i wskazuje własną bazę. Token wydaje panel przy zakładaniu
  klienta, jednorazowy, z krótkim terminem ważności.
- ⚠ **Po instalacji instalator musi przestać działać.** Blokada zapisywana **poza webrootem**
  i sprawdzana w pierwszej linii; docelowo skasowanie katalogu instalatora. Pozostawiony,
  działający instalator pozwala wskazać własną bazę i przejąć instalację.
- Instalator **nigdy nie wyświetla** wpisanych danych dostępowych ani klucza API. Żadnego `phpinfo`.
- Własna ochrona CSRF na krokach instalatora — sesje aplikacji jeszcze nie istnieją.
- Instalacja zapisywana w logu po stronie panelu: kto, kiedy, jaki tenant, jaki adres.

**Poza strefą kodowaną.** Musi działać, zanim licencja w ogóle istnieje, i musi być diagnozowalny
u klienta. Dzieli z aktualizatorem diagnostykę środowiska i uruchamianie migracji — ale instalator
znika po wdrożeniu, a aktualizator zostaje.

### Konfiguracja tenanta w aplikacji

- Aplikacja mobilna: **kod QR** zamiast wpisywania nazwy tenanta przy pierwszym uruchomieniu.
- Przeglądarka: zaproszenie zawiera adres tenanta.

---

## 6. Nadal otwarte

- PIN: telefon czy serwer
- jQuery czy vanilla JS (rozstrzygnie użycie gotowych komponentów tabel/selektów)
- wersja PHP podyktowana przez posiadaną wersję ionCube Encodera
- pinning certyfikatu w WebView — mechanizm dostarczania odcisku
- obsługa Apple Hide My Email przy rejestracji zaproszeniowej
- konflikty i idempotencja synchronizacji (etap integracji)
- logika biznesowa: eskalacje, limit urlopu na żądanie, zastępstwa, wycofanie wniosku,
  wpisywanie urlopu przez przełożonego
- retencja i szyfrowanie załączników, zakres danych o zwolnieniach
- backup i odtwarzanie, dostawca SMS, skala klientów
- „integracja z systemem logistyka" — na koniec

---

## 7. Kolejność prac

### Etap 1 — środowisko deweloperskie

- Maszyna wirtualna, **AlmaLinux**, SELinux wyłączony (maszyna deweloperska, nie produkcja)
- Apache, PHP w wersji docelowej, MySQL, ionCube Loader
- Subdomeny: `tenant.wezurlop.pl`, `panel.wezurlop.pl`, `auth.wezurlop.pl`
- Dostęp ograniczony do adresu IP właściciela; Let's Encrypt odnawiany ręcznie
- Vhosty kierowane na `public/` wewnątrz repozytoriów git
- Osobne bazy i użytkownicy MySQL z ograniczonymi uprawnieniami (§4 reguł)
- ⚠ HSTS na dev: `max-age` krótki albo wyłączony, **nigdy `preload`** (prawdziwe nazwy domen)
- Claude Code uruchamiany na tej maszynie przez SSH

### Etap 2 — szkielet tenanta

📄 **Dokument wykonawczy: `docs/szkielet-tenanta.md`** (projekt: `claude/wezurlop-szkielet-tenanta.md`,
16.09.2026) — struktura, bootstrap, routing, middleware, migracje, logowanie i 2FA, widok,
mostek natywny, licencja, instalator, 8 kamieni milowych i 27 scenariuszy odbioru.

**Decyzje podjęte przy jego pisaniu:**

| Decyzja | Ustalenie |
|---|---|
| Język interfejsu | tylko polski, bez warstwy tłumaczeń |
| Widok mobilny | osobny zestaw szablonów i layout, **wspólne kontrolery**, prefiks tras `/m/` |
| Role | w tym etapie tylko `tenant_admin` i `employee` — mechanizm uprawnień działa, sekcje biznesowe dochodzą w Etapie 3 |
| Nazewnictwo | angielski w kodzie i bazie, polski w interfejsie i adresach URL |
| Kolejka zadań | tabela `job` + runner CLI **już w tym etapie** — wysyłka poczty nie może iść w żądaniu (limit 30 s) |
| Wariant mobilny | **bez instalatora i bez diagnostyki** — te trasy nie mają odpowiednika mobilnego |

**Paczki graficzne (4):** `wezurlop-web-panel`, `wezurlop-mobile-webview` (ekrany aplikacyjne),
`wezurlop-web-security`, `wezurlop-mobile-security` (ekrany systemowe). Brakuje jeszcze dwóch
ekranów: „konto bez aktywnego zatrudnienia" (web + mobile) i strony diagnostycznej (web).
Do przerobienia przed użyciem: 68 atrybutów `style=`, 21 użyć `innerHTML`, 15 użyć `localStorage`,
wpisane dane demo w formularzach logowania.

⚠ **Do zatwierdzenia przed rozpoczęciem kodowania:** uzupełnienie `CLAUDE.md` §0 o
`phpmailer/phpmailer` oraz rozszerzenia `ext-iconv` i `ext-xmlwriter` (twarde wymagania
`bacon/bacon-qr-code`, potrzebnego do lokalnego generowania QR dla 2FA).

Zakres (**bez logiki biznesowej urlopów**):

- bootstrap, routing, łańcuch middleware (CSRF, sesja, uprawnienia)
- logowanie, reset hasła, **rejestracja zaproszeniowa**, 2FA dla kont lokalnych
- podział **osoba / zatrudnienie** w migracjach — wisi na nim model kont, więc nie może czekać
- konfiguracja Twiga i htmx, layout bazowy, katalog fragmentów
- obsługa błędów i własna strona błędu
- nawigacja i warstwa graficzna: web oraz widok mobilny
- `LicenseGate` jako zaślepka + podział katalogów na strefę kodowaną i jawną od pierwszego pliku
- instalator tenanta
- struktura zasobów CSS/JS
- kontrakt mostka natywnego dla Fluttera (reguła „tylko do dopisywania")
- `.htaccess` blokujący pliki z kropką + reguła przywracająca nagłówek `Authorization`

**Czwarty vhost na maszynie deweloperskiej — „gorszy hosting".** Wskazuje celowo na **korzeń
aplikacji zamiast na `public/`**, odtwarzając wdrożenie u klienta bez możliwości przestawienia
katalogu domeny. Bez niego obie ścieżki zapasowe z reguł uniwersalności nigdy nie zostaną
wykonane aż do pierwszego wdrożenia:

- czy blokowanie plików z kropką faktycznie chroni `.env`
- czy routing przez `PATH_INFO` działa, gdy nie ma przepisywania adresów

### Etap 3 — logika biznesowa

Wnioski, akceptacje, eskalacje, kalendarze, salda, integracja z R2. Tu wracają odłożone
znaleziska: L2 (cykle w hierarchii), L3 (zwolnienie przerywa urlop), L6 (saldo z oczekującymi),
Z1 (log audytowy w pełnej formie), Z2 (retencja), Z3 (odejście pracownika), Z4 (załączniki).

### Równolegle, jak najwcześniej

- **Zapis urlopu do R2** na instalacji testowej — jedyne realne ryzyko techniczne
- **Reakcja App Store** na aplikację opakowującą stronę
- **Wdrożenie prawie pustego tenanta na home.pl** — DocumentRoot, cron, limity, Loader

---

## 8. Architektura

Schemat blokowy i propozycja technologii dla każdego bloku: artefakt **„Architektura wezurlop"**
(opublikowany 15.09.2026).
