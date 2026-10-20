# wezurlop — Etap 3: logika urlopowa

Dokument wykonawczy. Opisuje **wnioski, limity, akceptacje, eskalacje, kalendarz i granicę
z connectorem R2**. Buduje na szkielecie z Etapu 2 (`docs/szkielet-tenanta.md`) i nie zmienia
niczego, co tam zostało odebrane.

Wiążące reguły pisania kodu: `CLAUDE.md`. Kontekst produktowy: `docs/specyfikacja.md`,
`docs/architektura.md`, `docs/audyt.md`. Wymagania środowiska: `docs/wymagania-hostingu.md`.

Stan na: 2026-09-19.

---

## 0. Decyzje wiążące dla tego etapu

Ustalone w rozmowie 19.09.2026. Każda z nich ma konsekwencję w modelu danych, więc zmiana
po rozpoczęciu prac jest kosztowna.

| Decyzja | Ustalenie |
|---|---|
| Źródło prawdy o saldzie | **R2 Płatnik.** Aplikacja rezerwuje w granicach limitu pobranego z R2 i nie nalicza wymiaru urlopu sama |
| Kierunek synchronizacji | **Connector → aplikacja, zawsze.** Każdy cykl: najpierw pobranie stanu z R2, potem wysyłka zaakceptowanych wniosków |
| Konflikt ze stanem R2 | wniosek **nie jedzie do R2**, zmienia status na „do wyjaśnienia" i niesie komunikat |
| Rezerwacja limitu | przy **złożeniu** wniosku; pracownik widzi „dostępne" i osobno „oczekujące" |
| Typy w pierwszej wersji | **wypoczynkowy** i **na żądanie** |
| Urlop na żądanie | **ten sam przepływ i ta sama akceptacja** co wypoczynkowy; różni się wyłącznie progiem eskalacji |
| Ścieżka akceptacji | jeden przełożony + eskalacja w górę hierarchii; **nigdy** automatyczna akceptacja |
| Zastępstwa | **nie ma ich w tej wersji** — nieobecny przełożony oznacza eskalację w górę |
| Dni i godziny | dni wybierane z kalendarza, **opcjonalnie łączna liczba godzin** |
| Kalendarz | własny (dni tygodnia + święta), z opcją pobrania rozkładu z R2; obowiązuje liczba potwierdzona przez R2 |
| Wycofanie wniosku | samodzielnie do akceptacji; później jako **prośba o anulowanie** zatwierdzana przez przełożonego |
| Wniosek w imieniu pracownika | mogą **przełożony i kadry**, z adnotacją kto i za kogo, plus wpis w logu |
| Hierarchia | ustawiana w aplikacji przez administratora tenanta, z kontrolą cykli |
| Kalendarz zespołu | **sam fakt nieobecności**, bez rodzaju i bez przyczyny |
| Dokumenty | **wydruk PDF tak**; załączniki i retencja to osobny kamień milowy |

### Czego w tym etapie świadomie NIE ma

Zwolnienia lekarskie i import z ZUS, pozostałe typy urlopów (opiekuńczy, bezpłatny,
okolicznościowy, macierzyński), zastępstwa, załączniki, SMS, raporty analityczne,
plan urlopów na rok, komunikaty wewnętrzne, integracja z systemem logistyka.

---

## 1. Pojęcie, od którego wszystko zależy: rezerwacja to nie urlop

⚠ **Aplikacja nie udziela urlopu. Aplikacja prowadzi rezerwację, którą R2 potwierdza.**

Z tego jednego zdania wynika cała maszyna stanów. Wniosek ma **dwa różne „tak"**:

1. **akceptację przełożonego** — decyzję człowieka, że urlop się odbędzie,
2. **potwierdzenie z R2** — fakt, że urlop istnieje w systemie kadrowym.

Między nimi jest realny odstęp czasu (jeden cykl synchronizacji) i realna możliwość, że drugie
„tak" nie nadejdzie. Interfejs musi ten stan nazywać wprost („zaakceptowany, czeka na zapis
w kadrach"), a nie udawać, że sprawa jest zamknięta. Pracownik, który zobaczy zielony znaczek
i pojedzie, a potem okaże się, że w R2 nie było dni, ma problem, którego nie stworzył.

Ta sama zasada w drugą stronę: **wszystko, co przyszło z R2, wygrywa z tym, co mamy u siebie.**
Urlop wpisany ręcznie przez kadrową, korekta puli, nowy rok kalendarzowy — R2 ma rację,
my się dostosowujemy.

---

## 2. Cykl synchronizacji — kontrakt z connectorem

Connector stoi u klienta, przy programie kadrowym, i **to on nawiązuje połączenie do tenanta**
(tenant nie ma jak sięgnąć do sieci klienta). Jeden cykl ma trzy fazy, zawsze w tej kolejności:

```
1. POBRANIE      R2 → aplikacja
   pracownicy, firmy, wymiar etatu, limity urlopowe, urlopy już wpisane,
   nieobecności, zwolnienia (blokada konta), zmiany kadrowe

2. WERYFIKACJA   w aplikacji, na świeżych danych
   każdy wniosek w stanie „zaakceptowany" sprawdzany wobec tego, co właśnie przyszło:
   czy limit wystarcza, czy dni nie kolidują z już zapisaną nieobecnością,
   czy zatrudnienie nadal jest aktywne

3. WYSYŁKA       aplikacja → R2 (przez connector)
   tylko wnioski, które przeszły weryfikację

4. POTWIERDZENIE connector → aplikacja
   dla każdego zapisanego urlopu osobna informacja zwrotna: identyfikator wniosku,
   identyfikator zapisu w R2, potwierdzone dni i godziny, znacznik czasu.
   Dopiero to przenosi wniosek z „approved" do „confirmed"
```

⚠ **Faza 4 jest osobnym przesłaniem, nie odpowiedzią na wysyłkę.** Zapis w R2 i dotarcie
potwierdzenia do tenanta to dwa zdarzenia, które mogą się rozejść: połączenie potrafi paść
po zapisie, a przed potwierdzeniem. Wtedy w R2 urlop **jest**, a u nas wniosek dalej stoi
w `approved` — i następny cykl wysłałby go po raz drugi.

Stąd dwa wymagania, oba po stronie connectora:

- **connector prowadzi własny rejestr** `request_id → r2_record_id`, zapisywany **przed**
  zgłoszeniem sukcesu. Otrzymawszy ponownie wniosek, którego identyfikator już zna,
  **nie zapisuje go drugi raz** — odsyła istniejące potwierdzenie,
- **potwierdzenia są ponawiane** aż tenant je przyjmie; przyjęcie tego samego potwierdzenia
  dwa razy nie może niczego zepsuć (`confirmed` → `confirmed` to brak zmiany, nie błąd).

Potwierdzenie może przyjść w tym samym cyklu co wysyłka albo w następnym — interfejs nie może
zakładać, że przyjdzie od razu.

⚠ **Kolejność faz jest zabezpieczeniem, nie optymalizacją.** Wysyłka bez świeżego pobrania
generuje zapytania, o których z góry wiadomo, że się nie powiodą, a błąd wraca wtedy z R2
jako komunikat techniczny zamiast czytelnego konfliktu w aplikacji.

⚠ **Co ta kolejność eliminuje, a czego nie.** Eliminuje konflikty znane w chwili pobrania —
czyli zdecydowaną większość. Nie eliminuje dwóch rzeczy: kadrowa może wpisać coś w R2 **między**
fazą 1 a 3, a sam zapis może odmówić z przyczyn, których nie modelujemy (blokada pliku, reguła
walidacji R2, licencja). Dlatego ścieżka błędu zapisu istnieje, ale jest **wyjątkiem**,
a nie normalnym przebiegiem — i prowadzi do tego samego stanu „do wyjaśnienia".

### Wymagania wobec wymiany danych

- **Idempotencja.** Każdy wniosek niesie własny identyfikator; powtórne wysłanie tego samego
  wniosku nie może utworzyć drugiego urlopu w R2. Connector zwraca identyfikator zapisu z R2,
  który zapisujemy przy wniosku — to jest dowód potwierdzenia i klucz do ewentualnego wycofania.
- **Porcjowanie.** `max_execution_time` 30 s obowiązuje po obu stronach (§0 reguł).
  Każda faza przetwarza porcję i zapamiętuje miejsce przerwania. Cykl przerwany w połowie
  wznawia się, a nie zaczyna od początku.
- **Przebieg zapisany.** Tabela `sync_run`: kiedy, co pobrano, ile wysłano, ile konfliktów,
  wynik. Bez tego pierwsze pytanie klienta („czemu mój urlop nie doszedł?") nie ma odpowiedzi.
- **Uwierzytelnienie connectora** kluczem per instalacja, w nagłówku (`Authorization`,
  ze ścieżką zapasową `X-Api-Key` — §0 reguł). Klucz ograniczony do listy firm (znalezisko L4).
- **Odpowiedź connectora to dane niezaufane** (§13 reguł): walidujemy strukturę, nie zakładamy,
  że pola istnieją, nie wstawiamy treści do HTML z pominięciem Twiga.

### Ustalenia z autorem connectora (19.09.2026)

Zmierzone na kopii produkcyjnej R2 Płatnik 10.10.33, dwie firmy klienta. To już nie są założenia.

**Wykrywanie zmian: sumy kontrolne, nie kursor czasowy.** R2 ma znaczniki utworzenia
i modyfikacji, ale są niewiarygodne: rozdzielczość jednej sekundy, puste dla starych rekordów
(71 z 353 kartotek), **usunięcie nie zostawia żadnego śladu**, a dziennik zmian pomija operacje
wykonane przez API. Dlatego connector trzyma **lokalny stan (SQLite) z sumami kontrolnymi**
i wysyła różnice. Pełny przegląd wszystkich pracowników i ~8,5 tys. wpisów urlopowych z SHA-256
zajmuje **17 ms**, więc idzie w każdym cyklu — łącznie z wykrywaniem usunięć.
Tenant może **zażądać pełnego przeglądu** w dowolnym momencie (np. po odtworzeniu bazy z kopii).

**Trzy poziomy identyfikatorów, bo urlop nie wisi pod pracownikiem.**

| Poziom | Co to jest | U nas |
|---|---|---|
| **osoba** | `osobaId` = **HMAC z PESEL-u liczony po stronie connectora**, sekret nie opuszcza serwera klienta | `person.external_person_id` |
| **kartoteka** | kartoteka pracownika w jednej firmie; ponowne zatrudnienie **czasem tworzy nową** | pomocniczo, do diagnostyki |
| **zatrudnienie** | ⚠ **urlopy w R2 wiszą pod zatrudnieniem**, a jego identyfikator zmienia się przy ponownym zatrudnieniu | `employment.r2_employment_ref` |

⚠ **PESEL nie dociera do nas w żadnej postaci** — ani jawnie, ani jako skrót do odwrócenia.
Autor connectora słusznie zauważył, że samo SHA-256 z PESEL-u da się odwrócić przeglądem zupełnym,
bo przestrzeń numerów jest mała i ustrukturyzowana. HMAC z sekretem u klienta to rozwiązanie
lepsze od naszego pierwotnego i je przyjmujemy (`CLAUDE.md` §14).
⚠ **40 kartotek nie ma PESEL-u** — tych osób nie połączymy między firmami. Dostają osobne
rekordy `person`, a administrator widzi to jako zadanie do rozstrzygnięcia, nie jako błąd.

**Firmy mapujemy po NIP.** Identyfikator firmy z R2 nie jest unikalny — obie firmy klienta mają
`1`, bo to dwie osobne instalacje. `company.nip` jest kluczem mapowania.

**Zapis urlopu: wysyłamy same daty.** R2 sam wylicza dni i godziny z rozkładu pracy konkretnej
osoby i odsyła wynik. ⚠ **Godzin nie wolno liczyć jako dni × 8**: jeden dzień urlopu to 480 minut
przy pełnym etacie, 240 przy połowie, 360 przy trzech czwartych, a w systemie równoważnym
nawet 720.

⚠ **Jeden wniosek może dać kilka zapisów w R2.** Urlop na przełomie miesiąca R2 dzieli
automatycznie (29.10–03.11 → dwa wpisy po 2 dni). Potwierdzenie niesie więc **listę**
identyfikatorów z dniami i godzinami każdej części, a nie jeden `r2_record_id`.

**Kadrowa zmienia nasz wpis w R2 — dostajemy to jako zmianę, nie nowy rekord.** Sprawdzone
w programie i przez API: przy zmianie dat **identyfikator wpisu zostaje ten sam**, a R2 przelicza
dni i minuty. Aktualizujemy więc `days_confirmed`/`minutes_confirmed` przy istniejącym wniosku
i oznaczamy go jako **zmieniony w kadrach** (pracownik dostaje powiadomienie — inaczej pojedzie
w innym terminie, niż widzi w aplikacji). Usunięcie wpisu przez kadrową poznajemy po zniknięciu
identyfikatora i przenosi wniosek do `conflict`.
⚠ Nieprzetestowane po stronie R2: wydłużenie urlopu **przez przełom miesiąca** może dołożyć
osobny wpis dla nowego miesiąca. Nasz model listy zapisów to znosi, ale liczba dni musi być
sumowana ze wszystkich części, a nie brana z pierwszej.

**Trzy wyniki zapisu, nie dwa.**

| Wynik | Przykład | Co robimy |
|---|---|---|
| zapisano | — | `confirmed` |
| **zapisano z ostrzeżeniem** | „limit urlopu wypoczynkowego za rok … został przekroczony" | `confirmed` **z flagą i treścią ostrzeżenia**, alert do kadr |
| błąd | „Urlop pokrywa się z wcześniejszymi okresami"; „Użytkownik X jest zalogowany" | `conflict` albo `sync_failed` (drugi przypadek jest przejściowy — ponawiamy) |

⚠ Środkowy stan jest tym, o którym łatwo zapomnieć: **R2 zapisuje urlop przekraczający limit
i tylko ostrzega**. Czyli nasza blokada limitu jest **jedyną** twardą blokadą w całym łańcuchu
(rozdział 4). R2 odrzuca natomiast kolizję dat — to jest druga bariera po naszej.

Błędy R2 nie mają kodów, tylko tekst; connector nadaje własne kody i przekazuje oryginalny
komunikat, który trafia do wniosku jako powód.

**Idempotencja — trzy siatki.** Mapowanie `request_id → identyfikatory zapisów` w bazie connectora
(zapisywane przed zgłoszeniem sukcesu), `request_id` **w opisie wpisu urlopowego w R2**
(pozwala odtworzyć mapowanie po utracie bazy connectora) oraz odrzucenie przez R2 ponownego
zapisu na te same daty.

**Porcjowanie i tempo.** ⚠ **Zapis do R2 nie odbywa się w trakcie naszego żądania HTTP** —
connector pobiera zadania jednym wywołaniem, zapisuje je u siebie (1,4–2,2 s na urlop)
i potwierdza osobnym wywołaniem. Nasz limit 30 s nie ogranicza więc zapisu, tylko wielkość
paczek, które wydajemy i przyjmujemy.

- **paczka pobrania: 500 rekordów** (uzgodnione),
- **pierwsze pełne załadowanie klienta odniesienia: ~12 tys. rekordów, czyli ~25 paczek** —
  instalacja musi to przejść porcjami i wznowić po przerwaniu, bo to jest pierwszy moment,
  w którym wdrożenie może się wyłożyć,
- **sygnał „zwolnij": HTTP 429 lub 503 z `Retry-After`** (sekundy albo data); connector honoruje
  oba formaty, a bez nagłówka zwalnia wykładniczo,
- kilkanaście zatwierdzonych wniosków na cykl w zupełności wystarcza; przy zatwierdzeniu
  pięćdziesięciu naraz rozkłada się to na kilka cykli i interfejs kadr nie może obiecywać inaczej.

Protokół wymiany (operacje, koperty, kursory, kody błędów): **`docs/api-connector.md`**.

⚠ **Każda firma to niezależny strumień** — własny kursor, własne porcjowanie, własny przebieg
w `sync_run`. Nie ma jednego globalnego kursora dla tenanta: klient odniesienia ma dwie firmy
w dwóch osobnych instalacjach R2, a identyfikatory rekordów są unikalne tylko w obrębie jednej
bazy. Kluczem jednoznacznym jest **para `NIP` + identyfikator rekordu**.

**Skala u klienta odniesienia:** 45 i 53 zatrudnionych, 353 i 86 kartotek, 8 575 i 2 515 wpisów
urlopowych, 310–480 urlopów rocznie na firmę.

---

## 3. Maszyna stanów wniosku

Jeden byt: `leave_request`. Stany i przejścia — nic poza tą tabelą nie jest dozwolone.

| Stan | Co znaczy | Kto widzi jako „aktywne" |
|---|---|---|
| `draft` | zaczęty, niezłożony (opcjonalny — tylko gdy formularz wieloetapowy) | pracownik |
| `submitted` | złożony, czeka na decyzję; **dni już zarezerwowane** | pracownik, przełożony |
| `escalated` | przekroczony czas decyzji; przypisany wyżej | przełożony wyższego szczebla, kadry |
| `approved` | zaakceptowany przez człowieka, **oczekuje na zapis w kadrach** — tak brzmi też etykieta w interfejsie | pracownik, kadry |
| `confirmed` | potwierdzony przez R2 — urlop istnieje w systemie kadrowym | wszyscy |
| `rejected` | odrzucony przez przełożonego, z uzasadnieniem; **dni wracają do limitu** | pracownik |
| `cancelled` | wycofany przez pracownika przed akceptacją; **dni wracają** | pracownik |
| `cancel_requested` | prośba o anulowanie po akceptacji, czeka na decyzję | pracownik, przełożony |
| `cancelled_confirmed` | anulowanie zatwierdzone i (gdy trzeba) wycofane z R2; **dni wracają** | wszyscy |
| `conflict` | weryfikacja przed wysyłką wykryła kolizję ze stanem R2 — **nie wysłany** | kadry, przełożony, pracownik |
| `sync_failed` | zapis w R2 odmówił z przyczyn technicznych | kadry |

**Przejścia**

```
draft → submitted → approved → confirmed
            ↓           ↓  ↘
        rejected    conflict  sync_failed
            ↓           ↓          ↓
        (dni wracają)  → człowiek decyduje: popraw → approved, albo odrzuć → rejected

submitted → escalated → approved | rejected        (czas lub nieobecny przełożony)
submitted → cancelled                               (pracownik, samodzielnie)
approved | confirmed → cancel_requested → cancelled_confirmed | approved
```

⚠ **`conflict` i `sync_failed` nigdy nie rozwiązują się same.** Automat może ponawiać wyłącznie
`sync_failed` z przyczyny chwilowej (connector offline), i tylko ograniczoną liczbę razy.
Konflikt merytoryczny czeka na człowieka, bo jego przyczyna leży poza systemem.

⚠ **Zwolnienie stanu nie usuwa.** Wniosek nie jest kasowany na żadnym etapie — zmienia stan.
Historia zmian (kto, kiedy, z jakiego stanu na jaki, z jakim komentarzem) idzie do `event_log`
i jest częścią logu audytowego (znalezisko Z1).

---

## 3b. Tożsamość, konta i cykl życia pracownika

Etap 2 dał logowanie, zaproszenia i rozdział **osoba ≠ zatrudnienie ≠ konto**. Tu dochodzi to,
co z tym robi synchronizacja.

### Skąd biorą się ludzie w systemie

1. **Administrator tenanta** powstaje przy instalacji (jednorazowy link ustawiający hasło).
   Zakłada firmy i konfiguruje całość.
2. **Pracownicy przychodzą z R2** przy synchronizacji — jako *osoba* + *zatrudnienie* w firmie,
   **bez konta i bez możliwości logowania**.
3. **Administrator uzupełnia e-mail i telefon** i wysyła zaproszenie; dopiero aktywacja
   przez pracownika tworzy *konto*.
4. Administrator może **nadać rolę administratora** innym osobom.

⚠ **Pracownik bez adresu e-mail nie ma jak dostać zaproszenia**, a w firmach produkcyjnych
to nie jest przypadek brzegowy. Wariant zapasowy: administrator generuje **jednorazowy kod
aktywacyjny do wydania na papierze** (ważny krótko, jednorazowy, powiązany z zatrudnieniem),
a adres e-mail pracownik podaje sam przy aktywacji. Bez tego część załogi zostaje poza systemem
i wnioski wracają na papier.

### Dopasowanie osoby: PESEL jako klucz

⚠ **„Zaszyfrowany PESEL" nie zadziała jako identyfikator.** Szyfrowanie z losowym wektorem
daje za każdym razem inny wynik, więc po takim polu nie da się **wyszukać** — a dopasowanie
pracownika z R2 do osoby u nas polega właśnie na wyszukaniu. Dlatego rozdzielamy dwie funkcje:

| Pole | Co to jest | Do czego |
|---|---|---|
| `person.external_person_id` | **HMAC z PESEL-a policzony po stronie connectora** (`osobaId`), sekret zostaje u klienta | dopasowanie i wykrywanie duplikatow |
| PESEL jawny | **nie dociera do nas w zadnej postaci** | - |

WAZNE: wczesniejsza wersja tego rozdzialu mowila o `national_id_hmac` liczonym u nas kluczem
z `.env`. To ustalenie jest NIEAKTUALNE - po uwadze autora connectora skrot liczy strona
zrodlowa (rozdzial 2). Kolumna jest jedna: `external_person_id`; `national_id_hmac` nie powstaje.

Powód, dla którego nie trzymamy PESEL-u: do niczego w tej aplikacji nie jest potrzebny.
Nie drukujemy go, nie wysyłamy, nie pokazujemy. Potrzebujemy wyłącznie móc stwierdzić,
że pracownik X z firmy A i pracownik X z firmy B to ta sama osoba. Do tego wystarczy skrót,
a wyciek bazy nie daje wtedy listy PESEL-i całej załogi.

⚠ **Klucz HMAC musi leżeć poza bazą** (`.env`, §14 reguł). Sam skrót bez klucza jest do złamania
siłowo — przestrzeń PESEL-i jest mała i policzalna. Z kluczem w `.env` atakujący z samą kopią
bazy nie policzy niczego. Zmiana klucza wymaga przeliczenia wszystkich skrótów, więc jest
operacją serwisową, nie ustawieniem.

**Łączenie osób między firmami** (ten sam pracownik w dwóch spółkach): automatyczne, gdy zgadza
się skrót. ⚠ Ale gdy skrót się zgadza, a **imię i nazwisko nie** — nie łączymy, tylko zgłaszamy
administratorowi do potwierdzenia. Literówka w PESEL-u w R2 połączyłaby dwie różne osoby w jedno
konto, czyli dałaby jednemu pracownikowi wgląd w dane drugiego. To jest błąd, którego nie wolno
popełnić automatycznie.

### Hierarchia jest wewnątrz firmy

Struktura podległości jest **wielopoziomowa i budowana per firma**. ⚠ Przełożonym może być
wyłącznie osoba z **tej samej firmy** (`company_id` zgodne) — inaczej dane kadrowe jednej spółki
wyciekają do drugiej przez ścieżkę akceptacji. Eskalacja też zatrzymuje się na granicy firmy:
na szczycie idzie alert do kadr tej firmy, nie wyżej do innej spółki.

Osoba z zatrudnieniem w dwóch firmach ma **dwie niezależne pozycje w dwóch hierarchiach**
i może być przełożonym w jednej, a szeregowym pracownikiem w drugiej.

### Zwolnienie pracownika — blokada, nie usunięcie

R2 rozróżnia dwa niezależne stany: **„zwolniony"** (rozwiązany stosunek pracy)
i **„kartoteka skasowana"** (usunięta z R2). Traktujemy je osobno: pierwszy blokuje zatrudnienie,
drugi oznacza, że rekordu po prostu nie ma i znika z naszych list przy pełnym przeglądzie.

⚠ **Decyduje data, status jest informacją.** U klienta odniesienia **31 zwolnionych nie ma daty
zwolnienia nigdzie** (stare kartoteki), a przy wielu zatrudnieniach koniec każdego ma własną datę.
Reguła: jest data → blokujemy od niej; jest status bez daty → blokujemy **od chwili pobrania**
i oznaczamy zatrudnienie jako wymagające uzupełnienia daty przez kadry. Nie zgadujemy wstecz,
bo od daty zależy los wniosków na przyszłe terminy.

Skutek blokady:

- `employment.is_active = 0` z datą — **blokowane jest zatrudnienie, nie człowiek**,
- ⚠ **konto blokujemy dopiero, gdy nieaktywne są WSZYSTKIE zatrudnienia.** Zwolnienie w jednej
  spółce nie może odciąć dostępu do drugiej, w której ktoś dalej pracuje. To jest wprost skutek
  wielofirmowości i najłatwiejszy błąd do popełnienia w tym miejscu,
- blokada działa **natychmiast**: sesje tego konta są unieważniane przy najbliższym żądaniu
  (mechanizm z Etapu 2, `session.revoked_at`), a nie po wygaśnięciu ciasteczka,
- **dane zostają.** Wnioski, decyzje i historia są potrzebne kadrom i audytowi; usuwanie
  reguluje retencja (znalezisko Z2), nie zwolnienie,
- ⚠ **wnioski na termin po dacie zwolnienia** trafiają do `conflict` z czytelnym powodem.
  Nie znikają po cichu — ktoś musi je zamknąć świadomie (znalezisko Z3).

### Administratorzy: nigdy jeden

⚠ Administrator tenanta ma **obowiązkowe 2FA** (§9 reguł). Jeden administrator + zgubiony telefon
= tenant bez dostępu do konfiguracji, a my bez możliwości pomocy zdalnej. Dlatego:

- aplikacja **ostrzega widocznie**, dopóki administrator jest tylko jeden,
- **nie da się odebrać sobie ostatniej roli administratora** — to walidacja, nie podpowiedź,
- reset 2FA administratora przez innego administratora jest dozwolony i **zawsze zapisywany
  w logu zdarzeń** (§9 reguł).

## 4. Limit, saldo i wyścig o ostatnie dni

Znalezisko **L6** z audytu, w pełnej formie.

Pracownik widzi trzy liczby i muszą się zgadzać do zera:

| Liczba | Skąd |
|---|---|
| **Przysługuje** | z R2, ostatnie pobranie (osobno **zaległy z poprzedniego roku**) |
| **Wykorzystane** | z R2 (urlopy już zapisane, także wpisane ręcznie) |
| **Oczekujące** | nasze wnioski w stanach `submitted`, `escalated`, `approved` |
| **Dostępne** | przysługuje − wykorzystane − oczekujące |

⚠ **W styczniu limitu zwykle jeszcze nie ma i to jest stan normalny, nie błąd.** Kadry zakładają
limity ręcznie i hurtem — u klienta odniesienia 25 z 43 wierszy powstało **22 stycznia**,
a najwcześniejsze pojawiały się 10–11 stycznia. Przez pierwsze trzy tygodnie roku większość
pracowników nie ma naliczonej puli.

Connector odróżnia **„limit na ten rok nie istnieje"** (`naliczony: false`) od **„limit wynosi
zero"**, więc ekran salda ma trzy warianty, nie dwa: naliczony limit, **„limit na nowy rok nie
został jeszcze naliczony w kadrach" z pokazaną pozostałością z poprzedniego roku**, oraz zero.
Bez tego rozróżnienia styczeń to fala zgłoszeń, że aplikacja zjadła ludziom urlop.

⚠ **Wartością wiążącą są minuty**, nie dni. R2 podaje limit w minutach i to one idą do bazy;
dni są **prezentacją**.

⚠ **Dni pokazujemy jako `minuty ÷ 480`, także przy niepełnym etacie** — bo dokładnie tak robi
program kadrowy. To wygląda na błąd (przy pół etatu dzień urlopu zużywa 240 minut), ale liczba
na naszym ekranie ma się zgadzać z liczbą, którą kadrowa widzi u siebie. Własne, „poprawniejsze"
przeliczenie dałoby dwie prawdy o tej samej puli i telefon do kadr przy pierwszym pracowniku
na części etatu.

Do salda wchodzą też **ręczne korekty kadr** (`korektyMin`, dodatnie i ujemne) — pokazujemy je
jako osobną pozycję, a nie zlewamy z wymiarem, żeby dało się zrozumieć, skąd wzięła się różnica.

⚠ **To jest jedyna twarda blokada limitu w całym łańcuchu.** R2 zapisuje urlop przekraczający
pulę i tylko ostrzega (rozdział 2). Jeśli nasza rezerwacja przepuści przekroczenie, nikt go
już nie zatrzyma — kadrowa zobaczy je dopiero przy rozliczeniu roku.

**Urlop na żądanie zużywa pulę wypoczynkowego** (w R2 to kod 5 w tej samej karcie urlopowej).
⚠ **Limitu 4 dni R2 pilnuje twardo** — sprawdzone: wniosek na 5 dni jest odrzucany
(„Limit urlopu na żądanie (max.4 dni) został przekroczony"), czwarty dzień przechodzi
z ostrzeżeniem, piąty złożony osobno też jest odrzucany. Wychodzi asymetria, o której trzeba
pamiętać przy projektowaniu komunikatów:

| Limit | Zachowanie R2 | Kto jest barierą |
|---|---|---|
| podstawowy (wypoczynkowy) | zapisuje i **tylko ostrzega** | **my** |
| na żądanie (4 dni) | **odrzuca zapis** | R2 |

Własny licznik dni na żądanie (suma wpisów z kodem 5 w bieżącym roku) prowadzimy mimo to —
ale **wyłącznie po to, żeby powiedzieć pracownikowi „zostały Ci 2 dni na żądanie" przy składaniu
wniosku**, a nie po to, żeby być jedyną barierą. Lepiej zatrzymać wniosek u nas, niż pozwolić
mu przejść całą ścieżkę akceptacji i odbić się od R2.

⚠ **Złożenie wniosku musi sprawdzić limit i zarezerwować dni w jednej transakcji.**
Inaczej pracownik otwiera dwie karty i składa dwa wnioski na ostatnie pięć dni — oba widzą
dostępne pięć, oba przechodzą. Blokada wiersza zatrudnienia na czas transakcji
(`SELECT … FOR UPDATE`), sprawdzenie i zapis w środku, `commit` — zgodnie z §4 reguł.

⚠ **Kolizja dat z własnym wnioskiem** jest osobnym sprawdzeniem: ten sam pracownik nie może mieć
dwóch nieodrzuconych wniosków na ten sam dzień, nawet jeśli limit by na to pozwalał.

**Pobranie z R2 może obniżyć limit poniżej tego, co już zarezerwowaliśmy** (kadrowa wpisała urlop
ręcznie). Wtedy nie cofamy niczego automatycznie: wnioski, które przestały się mieścić, trafiają
do `conflict` w kolejności od najpóźniej złożonego, a kadry dostają alert. Decyzja należy
do człowieka, bo może się okazać, że to wpis w R2 jest pomyłką.

---

## 5. Kalendarz: ile dni zużywa wniosek

Własny kalendarz, z opcją pobrania rozkładu z R2 (`CALENDAR_SOURCE` w `tenant_setting`).

- **Dni tygodnia** z ustawień firmy (domyślnie poniedziałek–piątek).
- **Święta państwowe** z wbudowanego kalendarza; święta ruchome liczone algorytmicznie
  (Wielkanoc i zależne od niej). Tablica na sztywno wpisanych dat zestarzeje się po cichu.
- **Wyjątki firmowe** (dzień wolny za święto w sobotę, przerwa świąteczna) w ustawieniach tenanta.
- **Rozkład z R2**, gdy connector go udostępnia — wtedy jest nadrzędny wobec naszego kalendarza.

⚠ **To, co pokazujemy przed synchronizacją, jest prognozą.** Liczba dni i godzin, która
obowiązuje, to ta potwierdzona przez R2 przy przejściu do `confirmed`. Przy pracy zmianowej,
równoważnym czasie pracy i sobotach roboczych obie liczby potrafią się różnić — interfejs
pokazuje wtedy wartość z R2 i adnotację, że wniosek został zapisany z inną liczbą dni.
Zapisujemy **obie** (`days_requested`, `days_confirmed`), bo rozbieżność jest informacją,
a nie błędem do zatarcia.

**Godziny: nie pytamy o nie we wniosku.** ⚠ Zmiana wobec pierwotnego ustalenia, wymuszona
odpowiedzią autora connectora: **przy zapisie wystarczą daty**, a R2 sam wylicza dni i godziny
z rozkładu pracy konkretnej osoby i odsyła je w potwierdzeniu. Gdybyśmy pytali pracownika
o godziny, zbieralibyśmy daną, której i tak nie wyślemy i która w połowie przypadków nie zgodzi
się z wynikiem R2.

Godziny są więc **wynikiem**, nie wejściem: pokazujemy je po potwierdzeniu, obok liczby dni.
Urlop na **część dnia** R2 podobno obsługuje, ale nie zostało to sprawdzone — dlatego w pierwszej
wersji wniosek obejmuje pełne dni, a urlop godzinowy wraca jako temat po weryfikacji (pozycja C5).

Rozkład czasu pracy z R2 zgodził się z naszym liczeniem na **717 z 719** urlopów z lat 2025–2026;
dwa rozjazdy to indywidualne harmonogramy nadpisujące kalendarz. Potwierdza to regułę:
nasza liczba jest prognozą, wiążąca jest ta z potwierdzenia.

---

## 5b. Nieobecności zewnętrzne — to, czego nie rezerwujemy

Z R2 przychodzą nieobecności, których w aplikacji **nikt nie wnioskuje**: zwolnienia lekarskie,
urlopy wpisane ręcznie przez kadry, opieka, urlopy bezpłatne, macierzyńskie i wszystko inne,
co kadrowa prowadzi w programie. Muszą być widoczne w kalendarzu pracownika — inaczej aplikacja
pokazuje nieprawdziwy obraz miesiąca i ludzie przestają jej ufać.

To **osobny byt, nie typ wniosku**: `external_absence`.

| Cecha | Wniosek (`leave_request`) | Nieobecność zewnętrzna (`external_absence`) |
|---|---|---|
| Kto tworzy | pracownik lub uprawniony | **wyłącznie synchronizacja z R2** |
| Maszyna stanów | jedenaście stanów | **żadna** — jest albo jej nie ma |
| Akceptacja | tak | nie dotyczy |
| Edycja w aplikacji | tak | ⚠ **niemożliwa, tylko do odczytu** |
| Znika, gdy | anulowano | zniknęła z R2 (albo przy pełnym przeglądzie) |

⚠ **Nie da się ich edytować ani usunąć w aplikacji.** Jedyną drogą zmiany jest R2 — i to jest
zgodne z zasadą z rozdziału 1: co przyszło z R2, wygrywa. Interfejs ma to pokazywać wprost
(„wpisane w systemie kadrowym"), a nie dawać przycisków, które nic nie robią.

### Zakres danych i znaczenie usunięć (`ksztalt-danych-r2.md` 0.7)

⚠ **Connector przysyła wyłącznie osoby aktualnie zatrudnione na umowę o pracę**, ich aktualne
zatrudnienia, limity i nieobecności **od 1 stycznia poprzedniego roku**. Zleceniobiorcy, umowy
o dzieło, zwolnieni po dacie zwolnienia i kartoteki skasowane **nie przychodzą w ogóle**.
Skala u klienta odniesienia: 36 i 53 osoby, pierwsze pełne ładowanie ok. 850 i 1 200 rekordów.

⚠⚠ **`usuniety: true` znaczy „wypadł z zakresu", a NIE „skasowano dane".** To jest najważniejsza
różnica w całej wymianie i najłatwiejsza do przeoczenia. Reguły po naszej stronie:

| Co znika | Co robimy | Czego NIE robimy |
|---|---|---|
| `pracownik` z jego zatrudnieniami i limitami | zatrudnienie → **nieaktywne** (koniec umowy o pracę albo zmiana na zlecenie); konto blokujemy dopiero, gdy wszystkie zatrudnienia są nieaktywne | **nie kasujemy** osoby, wniosków, decyzji ani historii |
| `nieobecnosc` pracownika, który nadal jest w zakresie | traktujemy jak dziś: powiązany wniosek → `conflict` z powodem „wpis usunięty w kadrach" | — |
| `nieobecnosc` albo `limit` **z najstarszego roku** | oznaczamy jako **poza oknem synchronizacji**, zostawiamy w historii | ⚠ **nie tworzymy konfliktów** |
| `nieobecnosc` **zatrudnienia już nieaktywnego** | zapisujemy zdarzenie w `sync_run`, wpis znika z kalendarza | ⚠ **nie tworzymy konfliktu i nie ruszamy historii wniosku** |

⚠ **Trzeci wiersz to porządki po zwolnionym pracowniku, nie zniknięcie urlopu.** Kadry
domykają kartotekę po odejściu pracownika i kasują wpisy, których nie chcą w rozliczeniu —
nikt już tego nie wyjaśni ani nie przywróci, bo umowa się skończyła. Konflikt trafiłby wtedy
do kadr jako zadanie bez rozwiązania, a przy końcu roku (zwolnienia grupowe, koniec sezonu)
byłaby to lawina. ⚠ Wniosek zostaje w historii w stanie, w jakim był — **nie cofamy go
do `conflict` ani nie zmieniamy `confirmed` na nic innego**: to, że urlop był i został
udzielony, jest faktem kadrowym niezależnym od tego, czy wpis nadal jest w programie.

⚠ **Styczniowe przesunięcie okna to pułapka o zdefiniowanej dacie.** 1 stycznia najstarszy rok
wypada z zakresu i przychodzi jako seria usunięć. Potraktowane dosłownie dałyby setki wniosków
w stanie „do wyjaśnienia" pierwszego dnia roboczego roku. Dlatego usunięcie nieobecności
**z roku spoza okna** jest zwykłym wypadnięciem z zakresu, nie konfliktem — sprawdzamy rok
rekordu, zanim cokolwiek oznaczymy.

**Pracownik na wypowiedzeniu** przychodzi normalnie, z `zwolniony: true` i `dataZwolnienia`
(ostatni dzień pracy) — aż do tej daty. Pokazujemy to przy pracowniku, blokujemy wnioski
na terminy po tej dacie, a po niej przestaje przychodzić i zatrudnienie staje się nieaktywne.

⚠ `skasowany` jest zawsze `false`, `aktualne` zawsze `true`, `umowy.oPrace` zawsze `true`
w tym zakresie. Nie budujemy na nich logiki („skoro zawsze true, to nie sprawdzajmy"),
ale też nie odrzucamy rekordów, gdy kiedyś przyjdzie inna wartość.

⚠ `osobaId` bywa `null` **także u osób aktualnie zatrudnionych** — u cudzoziemców bez PESEL-u
(w obecnych danych klienta nie występuje). Takiej osoby **nie łączymy** między firmami:
powstają dwa osobne rekordy `person` i wpis dla administratora. Ekranu ręcznego łączenia
nie budujemy w etapie 1.

### Co wynika z kształtu rekordów

Pięć rzeczy, które zmieniają zachowanie aplikacji, a nie tylko walidację:

- ⚠ **Urlop przysługuje przy umowie o pracę.** Rekord `pracownik` niesie `umowy`
  (`oPrace`, `zlecenie`, `oDzielo`). Zatrudnienie bez `oPrace` **nie ma prawa do wniosku
  urlopowego** — formularz jest dla niego niedostępny, z czytelnym powodem, a nie pustym saldem.
- ⚠ **Formularz jest sterowany słownikiem, nie kodem.** `slownik` niesie `doWniosku`,
  `tylkoOdczyt` i `nieobecnosc`. Lista typów we wniosku to te z `doWniosku: true`
  (w etapie 1: `urlop` 0 i 5), a z kalendarza wypadają pozycje z `nieobecnosc: false`
  (ekwiwalenty, `urlop` 6 i 8). **Nie zaszywamy kodów w kodzie.**
- ⚠ **Saldo bywa ujemne** (`pozostaloMin` po przekroczeniu limitu, `korektyMin` in minus).
  Ekran ma pokazać „przekroczono o X", a nie zero ani błąd.
- ⚠ **Zwolnienia trzeba sklejać.** Jedno L4 to często kilka kolejnych rekordów `zasilek`
  z `kontynuacja: true`; w kalendarzu pokazujemy je jako **jedną nieobecność**, nie serię.
  Przy `zasilek` pole `minuty` jest **zawsze `null`** — nie liczymy z nich czasu.
- ⚠ **Kalendarz z R2 bywa orientacyjny.** Rekord niesie `indywidualne: true`, gdy pracownik
  może mieć własny harmonogram — wtedy nasze wyliczenie dni jest prognozą tym bardziej
  niepewną i interfejs ma to mówić. Wiążące pozostają dni i minuty z potwierdzenia zapisu.

`limit` przychodzi per zatrudnienie, rok i **rodzaj** (`wypoczynkowy` — obejmujący także dni
na żądanie — albo `opieka_art188`), z osobnym `zaleglyMin` i licznikiem
`naZadanieWykorzystaneDni`.

### Skąd się biorą i jak je rozpoznajemy

R2 trzyma nieobecności w **trzech osobnych tabelach, każda z własnym słownikiem**, a ⚠ **kody
się między nimi nakładają** (kod 0 to raz urlop wypoczynkowy, raz bezpłatny, raz chorobowe).
Dlatego typ jest u nas parą **`source` + `code`**, nigdy samym kodem.

| Źródło | Co zawiera | Kierunek |
|---|---|---|
| `karta_urlopowa` | wypoczynkowy (0), okolicznościowy (1), opieka art. 188 (2), dodatkowy (3), nieobecność płatna (4), **na żądanie (5)**, szkoleniowy (7) | **jedyna tabela, do której zapisujemy** |
| `nieobecnosci_kadrowe` | bezpłatny (0), wychowawczy (1), służba wojskowa (2), usprawiedliwiona (3), nieusprawiedliwiona (4) | tylko odczyt |
| `karta_zasiłkowa` | 🏥 chorobowe, wypadkowe, rehabilitacyjne, opiekuńcze, macierzyński, ojcowski, rodzicielski i pozostałe zasiłki ZUS | tylko odczyt |

⚠ **Rodzaje z karty zasiłkowej pochodzą z ZUS (w większości z e-ZLA) i nie mogą pojawić się
w formularzu wniosku** — nikt ich nie wnioskuje w aplikacji. Formularz zna wyłącznie kody
0 i 5 z karty urlopowej.

⚠ **Ekwiwalent za urlop (kody 6 i 8 w karcie urlopowej) to nie jest nieobecność**, tylko
rozliczenie płacowe. Nie pokazujemy go w kalendarzu i nie liczymy jako dni wolnych — inaczej
w kalendarzu pojawią się „urlopy", w których pracownik normalnie pracował.

Słowniki przychodzą z bazy klienta razem z danymi. ⚠ **Nie zaszywamy nazw w kodzie** — u innego
klienta albo w nowszej wersji R2 lista może wyglądać inaczej.

### Wpływ na rezerwacje

- **dzień zajęty nieobecnością zewnętrzną jest niedostępny** przy składaniu wniosku —
  walidacja przy złożeniu, a potem jeszcze raz w fazie weryfikacji (rozdział 2), bo nieobecność
  mogła dojść po złożeniu,
- ⚠ **zwolnienie lekarskie przerywa zaplanowany urlop** (znalezisko L3). Gdy nieobecność
  zewnętrzna pokryje dni wniosku w stanie `approved` lub `confirmed`, wniosek trafia
  do `conflict` z czytelnym powodem, a kadry dostają alert. **Nie rozliczamy tego sami** —
  ile dni wraca do puli, rozstrzyga R2 i przyjdzie w następnym pobraniu,
- zwolnienie lekarskie dotyczy **osoby**, nie jednego zatrudnienia: blokuje dni we wszystkich
  firmach, w których ta osoba pracuje (specyfikacja §5). ⚠ Ale **nie blokuje logowania** —
  chory pracownik ma prawo zobaczyć swój kalendarz i saldo.

### Widoczność — to są dane o zdrowiu

⚠ **Nie importujemy kodów chorobowych ani żadnej informacji diagnostycznej.** Nawet jeśli R2
je udostępnia. Do niczego w tej aplikacji nie są potrzebne, a ich przechowywanie zmienia
kategorię całej bazy.

| Kto | Co widzi przy nieobecności zewnętrznej |
|---|---|
| pracownik | swoją nieobecność z rodzajem („zwolnienie lekarskie", „urlop wpisany przez kadry") i datami |
| przełożony | **fakt nieobecności podwładnego i jej rodzaj ogólny**, bez żadnych szczegółów medycznych |
| kadry | to samo co przełożony, w obrębie swoich firm |
| pozostali pracownicy | **wyłącznie fakt nieobecności**, bez rodzaju (reguła z rozdziału 8) |

Rodzaj nieobecności w kalendarzu zespołu pozostaje ukryty właśnie dlatego, że pojawiają się
tu zwolnienia — decyzja z rozdziału 8 zapadła z myślą o tym momencie.

## 6. Hierarchia, akceptacja i eskalacja

**Hierarchia** ustawiana w aplikacji: każde zatrudnienie ma wskazanego przełożonego
(`employment.supervisor_employment_id`). R2 nie prowadzi hierarchii akceptacji, więc jest to
nasz byt. Administrator tenanta wskazuje przełożonego przy zatrudnieniu — także przy pracowniku,
który właśnie pojawił się z R2 (specyfikacja §3).

⚠ **Kontrola cykli przy zapisie** (znalezisko L2): A → B → A zawiesiłoby eskalację w nieskończonej
pętli. Sprawdzenie idzie w górę łańcucha przy każdej zmianie przełożonego, z twardym limitem
głębokości; wykryty cykl to błąd walidacji, nie ostrzeżenie.

**Akceptacja**: przełożony akceptuje albo odrzuca z uzasadnieniem. Odrzucenie bez uzasadnienia
jest niemożliwe — pracownik ma prawo wiedzieć dlaczego, a kadrowa musi to widzieć w historii.
Pod wnioskiem są komentarze (specyfikacja §3), żeby dało się dopytać przed decyzją.

**Eskalacja** — zadanie cykliczne, nie akcja użytkownika:

- próg czasu **per typ nieobecności**, w `tenant_setting`: wypoczynkowy domyślnie 48 godzin
  roboczych, na żądanie kilka godzin. Powód różnicy: wniosek na żądanie dotyczy dzisiaj,
  więc dwa dni na decyzję są bezużyteczne,
- **przełożony nieobecny** (ma potwierdzony urlop obejmujący dziś) → eskalacja natychmiast,
  bez czekania na próg,
- **na szczycie hierarchii** wniosek nie idzie już nigdzie: zostaje oznaczony jako zalegający,
  a alert trafia do kadr i administratora tenanta,
- ⚠ **nigdy nie ma automatycznej akceptacji.** System nie udziela urlopu za człowieka i nie
  zapisuje go w R2 bez czyjejkolwiek decyzji.

⚠ Rzadki cron oznacza **wolniejszą eskalację, nigdy zgubioną** (§0 reguł). Zadanie liczy progi
od znaczników czasu w bazie, a nie od tego, kiedy akurat się uruchomiło.

---

## 7. Wniosek w imieniu pracownika

⚠ **To jest osobne uprawnienie, nadawane jawnie — nie wynika z bycia przełożonym.**
`leave.create_for_subordinate` i `leave.create_for_any` są **domyślnie wyłączone**; przypisuje
je administrator tenanta roli albo konkretnej osobie. Przełożony bez tego uprawnienia akceptuje
i odrzuca, ale nie wnioskuje za nikogo. Powód jest prosty: wpisanie komuś urlopu to rozporządzenie
jego dniami, a nie decyzja o cudzym wniosku — i nie każdy klient chce, żeby mógł to robić
każdy kierownik.

Uprawnieni (domyślnie nikt, w praktyce kadry i wybrani przełożeni) mogą złożyć wniosek
za pracownika. Zasady:

- wniosek zapisuje **kto go złożył i za kogo** (`created_by_account_id` ≠ `employment_id`),
  a pracownik dostaje powiadomienie — nie może się dowiedzieć przypadkiem,
- wniosek złożony przez przełożonego dla swojego podwładnego jest **od razu zaakceptowany**
  (ta sama osoba i tak by go akceptowała), ale przechodzi normalną drogę do R2,
- każde takie złożenie to wpis w `event_log` z adnotacją — to jest działanie w cudzym imieniu
  na danych kadrowych i musi zostawiać ślad,
- ⚠ uprawnienie sprawdzane **zapytaniem o relację**, nie warunkiem w szablonie: przełożony może
  wnioskować wyłącznie za swoich podwładnych (`WHERE supervisor_employment_id = :me`) — to jest
  modelowy IDOR (§10 reguł).

---

## 8. Widoczność danych

Aplikacja przetwarza dane kadrowe, więc widoczność jest decyzją projektową, nie skutkiem ubocznym.

| Kto | Co widzi |
|---|---|
| Pracownik | swoje wnioski w całości; kalendarz zespołu — **sam fakt nieobecności** (imię, zakres dat), bez rodzaju i bez przyczyny |
| Przełożony | wnioski swoich podwładnych w całości, ich salda, pełny kalendarz zespołu |
| Kadry | wszystko w obrębie firm, do których mają uprawnienie |
| Administrator tenanta | konfigurację i hierarchię; dostęp do danych urlopowych **tylko jeśli ma rolę kadrową** |

⚠ Rodzaj nieobecności nie jest informacją dla kolegi z zespołu. Przy zwolnieniach (kolejna wersja)
byłaby to wprost dana o zdrowiu — mechanizm ma być gotowy już teraz, żeby nie trzeba go było
odkręcać przy pierwszym typie wrażliwym.

---

## 8b. Widoczność kalendarza — reguły dostępu (Z53, 19–20.10.2026)

Rozdział 8 mówi, **kto widzi co z urzędu**. Ten mówi, jak administrator firmy **dokłada wgląd
poza hierarchią** — i zastępuje mechanizm, który stał tu do 19.10.2026 (trójstanowy przełącznik
„brak / ogólny / pełny" w panelu osoby, oparty na uprawnieniach `calendar.view_all`
i `calendar.view_all_details`).

### Dlaczego tamten kształt był zły

⚠ **„Kto widzi kogo" jest RELACJĄ, a panel jednej osoby pokazuje tylko jej połowę.** Z ekranu
konta nie dawało się odpowiedzieć na pytanie „kto widzi kalendarz tej osoby", bo odpowiedź leżała
rozsypana po panelach wszystkich pozostałych kont. Drugi powód: poziom był **jeden na cały
tenant** — nadany raz, działał we wszystkich firmach, w których konto miało cokolwiek do
oglądania, a granica firmy jest w tym produkcie granicą danych kadrowych.

### Model

Reguła należy do **firmy** i ma trzy człony:

| człon | wartości |
|---|---|
| KTO WIDZI | `supervisor_all` (reguła domyślna firmy) · `supervisor` (jeden przełożony) · `account` (konto) · `group` (grupa) |
| CZYJE NIEOBECNOŚCI | `subordinates` (gałąź pod widzem) · `company` (cała firma) · `groups` (wskazane grupy) · `persons` (wskazane osoby) |
| ZAKRES | `bez_szczegolow` · `ze_szczegolami` |

- ⚠ **Przełożony i wskazana osoba to ZATRUDNIENIE, nie osoba.** Jedna osoba miewa etat w dwóch
  firmach, a reguła należy do jednej — porównanie po `person_id` dawałoby regule z ALFY wpływ
  na tę samą osobę w BECIE, czyli przeciek przez granicę firmy.
- ⚠ **Jedna lista celu, nie dwie.** Grupy i osoby jadą tym samym polem, bo reguła ma dokładnie
  jeden rodzaj celu (`target_kind`); dwa pola oznaczałyby stan, w którym oba są pełne,
  i pytanie, które z nich obowiązuje.
- ⚠ **Podpis reguły** (`UNIQUE (company_id, signature)`) pilnuje, żeby tej samej reguły nie dało
  się nadać dwa razy. Podpis reguły domyślnej **nie zawiera zakresu** — jest jedna i zmienia się
  jej zakres, a nie zakłada drugą.

### Reguła domyślna firmy

„Wszyscy przełożeni › Swoi podwładni" **istnieje zawsze** i nie daje się usunąć. Dopóki nikt jej
nie ruszył, wiersza w tabeli nie ma, a obowiązuje `bez_szczegolow` — i **stosuje to jedno miejsce
w kodzie** (`CalendarVisibility::zakresPrzelozonych()`), a nie `DEFAULT` kolumny i kod naraz.
Inaczej administrator widziałby na liście jeden zakres, a pracownik dostawał drugi.

⚠ Reguła domyślna obejmuje widza **tylko wtedy, gdy ktoś pod nim dziś stoi** — inaczej „każdy
przełożony" znaczyłoby „każdy".

### Wygrywa SZERSZY dostęp, nigdy węższy

Kilka reguł dotyczących tej samej pary daje **maksimum** zakresów. Odwrotne rozstrzygnięcie
znaczyłoby, że nadanie dostępu jednej osobie **odbiera** dostęp innej — a tego nikt nie przewidzi
przy wypełnianiu okna. Ta sama zasada rozstrzyga sprzeczne zaznaczenie w kroku 2 okna
(„cała firma" kontra wskazane osoby): wygrywa zbiór szerszy, bo i tak obejmuje tamte osoby.

### Szczegółowość jest OSOBNA DLA KAŻDEGO WIERSZA

⚠ **To odwołuje wcześniejsze uzasadnienie** (jednolita szczegółowość całej siatki kalendarza,
łącznie z własnym wierszem). Tamto brzmiało: ekran służy do ZESTAWIANIA, więc wiersz o innej
szczegółowości czyta się jak inny **rodzaj** nieobecności, a nie jak inny dostęp. Przestało
obowiązywać z powodu rzeczowego, nie estetycznego: **reguła może obejmować jedną grupę, a nie
całą firmę**, więc jednolity ekran musiałby równać albo w dół (zabierać dostęp, który ktoś nadał),
albo w górę (dawać dostęp, którego nikt nie nadał). Ryzyko „inny wiersz = inny rodzaj" zdejmuje
legenda, która nazywa szary pasek wprost („nieobecny").

### `bez_szczegolow` to NIE jest dawny „rodzaj ogólny"

Dawny poziom 1 pokazywał „urlop" albo „zwolnienie" — a **„zwolnienie" przy cudzym nazwisku jest
daną o zdrowiu** tak samo jak nazwa ze słownika, tylko mniej dokładną. Został sam FAKT
nieobecności: jedno słowo „nieobecny" dla wszystkich rodzajów, jeden token wypełnienia.

⚠ **Rodzaj nie opuszcza serwera w żadnej postaci** — ani tekstem, ani klasą, ani w `title`,
`aria-label` czy `data-*`. Ukrycie stylem się nie liczy. Rozstrzyga o tym **jedna funkcja**
(`KalendarzPracownika::pasek()`), bo decyzja postawiona w szablonie znaczyłaby, że pierwszy nowy
widok pokaże nazwy rodzajów bez pytania.

### Wszystko liczy się W CHWILI ODCZYTU

- gałąź hierarchii i skład grup — więc podwładny dopisany jutro jest objęty regułą jutro,
  bez dotykania reguły,
- ⚠ **czynność zatrudnienia** — zwolniona osoba przestaje być widoczna natychmiast, i pilnuje
  tego `CalendarVisibility::zakresy()`, czyli punkt egzekwowania, a nie wywołujący. Zmierzone
  20.10.2026: siatka zespołu nie pokazuje zwolnionych sama z siebie, więc wyglądało to na
  działające — ale ta sama funkcja zapytana wprost o zwolnione zatrudnienie odpowiadała
  `ze_szczegolami`, czyli każdy nowy widok z własną listą osób dostałby dostęp, którego nikt
  nie nadał.

⚠ **Reguł po zwolnieniu NIE SPRZĄTAMY** (decyzja Krzysztofa 19.10.2026, zmiana wcześniejszego
polecenia). Zwolnienie przychodzi z synchronizacji i jest już zdarzeniem w dzienniku; drugi wpis
o skutku ubocznym niczego nie dodaje, a reguła skasowana po cichu zabiera administratorowi
możliwość zobaczenia, co w niej było. Zamiast tego wiersz reguły pokazuje nazwisko **przekreślone**
(ta sama konwencja, co na `/admin/konta`), a reguła bez ani jednej czynnej osoby dostaje szarą
plakietkę „nieaktywna — brak czynnych osób" i **zostaje**. Kasuje ją administrator, świadomie.
Ponowne zatrudnienie **nie przywraca** dostępu: przy pierwszym zapisie reguły zwolnieni z niej
wypadają, a dziennik odnotowuje ich jako wartości sprzed zmiany.

### Usunięcie grupy

Grupa po stronie „kto widzi" kasuje regułę w całości; po stronie celu wypada z listy, a reguła
bez ani jednej grupy znika razem z nią. ⚠ **Robi to kod, nie kaskada bazy** — kaskada zrobiłaby
to samo po cichu, a administrator dowiedziałby się o utracie dostępu od pracownika, nie
z dziennika (`calendar_access.auto_changed`).

### Dziennik

Zdarzenia `calendar_access.granted | changed | revoked | auto_changed | migrated` należą do
istniejącej rodziny filtra „konta i uprawnienia" — nie zakładamy ósmego filtra dla pięciu kodów.

⚠ **Treścią wpisu są IDENTYFIKATORY, nie nazwiska** — odstępstwo od tego, co robi dziennik grup.
Wpis mówi, **kto czyje nieobecności może oglądać**, czyli opisuje dostęp do danych o zdrowiu:
para nazwisk w treści byłaby tą informacją zapisaną drugi raz, w miejscu, z którego się jej nie
kasuje. Nazwy składa ekran dziennika przy odczycie.

### Migracja i uprawnienie

Stare nadania `calendar_viewer` → `bez_szczegolow`, `calendar_viewer_full` → `ze_szczegolami`,
z celem „cała firma"; nadanie obejmujące cały tenant (`company_id = 0`) rozwija się na każdą firmę,
w której konto ma rolę pracownika. ⚠ Role zostają w tabeli (migracja ma się cofać), ale nic już
z nich nie wynika; `calendar_viewer_full` traci `requires_2fa`, bo nie jest już uprawnieniem
administracyjnym. Ekranem rządzi nowe uprawnienie **`calendar.visibility_manage`** (rola
`tenant_admin`), a przełącznik w panelu osoby zastępuje odnośnik do `/admin/widocznosc`.

---

## 9. Powiadomienia

Przez kolejkę `job` i cron (§0 reguł) — **nigdy w żądaniu użytkownika**.

| Zdarzenie | Do kogo | Kanał |
|---|---|---|
| złożenie wniosku | przełożony | push + e-mail |
| decyzja (akceptacja/odrzucenie) | pracownik | push + e-mail |
| potwierdzenie zapisu w R2 | pracownik | push (e-mail konfigurowalnie) |
| eskalacja | nowy decydent + kadry | e-mail |
| konflikt i błąd zapisu | kadry | e-mail |
| prośba o anulowanie | przełożony | push + e-mail |

Treść powiadomienia zawiera **zakres dat i typ**, nigdy uzasadnienia ani komentarzy —
powiadomienie idzie na urządzenie, które może zobaczyć ktoś inny.

⚠ **Push też idzie przez kolejkę, nie z żądania.** Wywołanie do przekaźnika trwa zwykle ułamek
sekundy — ale tylko wtedy, gdy przekaźnik odpowiada. Przy akceptacji zbiorczej (kierownik
zatwierdza dwadzieścia wniosków) to dwadzieścia wywołań w jednym żądaniu, przy limicie 30 s.
Żądanie zapisuje zadania i kończy się; wysyła runner. Mechanizm i jego ograniczenia na hostingu
współdzielonym: `docs/profile-hostingu.md`, sekcja o workerze i kolejce.

Zadania mają **priorytet**: linki z terminem ważności (zaproszenie, reset) przed powiadomieniami
o wnioskach, te przed sprzątaniem. Przy nawale powiadomień runner rozkłada je na kilka przebiegów.

---

## 10. Wydruk wniosku

PDF generowany na żądanie, z danymi wniosku i miejscem na podpisy. Bez załączników w tej wersji.

- generowany **po stronie serwera**, z tego samego stanu co ekran,
- dostęp sprawdzany jak każdy inny zasób (właściciel albo uprawnienie), bo to dokument
  z danymi osobowymi,
- nazwa pliku bez danych osobowych w ścieżce.

---

## 11. Model danych (zarys migracji)

Nazewnictwo angielskie (ustalenie z Etapu 2). Tabele nowe w tym etapie:

| Tabela | Rola |
|---|---|
| `leave_type` | typ nieobecności: ⚠ **klucz złożony `source` + `code`** (kody powtarzają się między tabelami R2), nazwa ze słownika klienta, czy wnioskowalny, czy liczy się do limitu, próg eskalacji |
| `leave_request` | wniosek: `employment_id`, typ, `date_from`, `date_to`, `days_requested`, `days_confirmed`, `minutes_confirmed`, stan, `created_by_account_id`, flaga i treść **ostrzeżenia z R2**, znaczniki czasu stanów |
| `leave_request_r2_record` | ⚠ **lista** zapisów w R2 dla jednego wniosku (przełom miesiąca dzieli urlop): identyfikator zapisu, daty, dni, minuty |
| `leave_request_day` | rozbicie na dni (do kalendarza i do wykrywania kolizji bez liczenia zakresów w locie) |
| `leave_decision` | decyzje: kto, kiedy, jaka, uzasadnienie; pełna historia, nie ostatni stan |
| `leave_comment` | komentarze pod wnioskiem |
| `leave_balance` | saldo z R2 per zatrudnienie i rok, ⚠ **w minutach jako wartości wiążącej**: przysługuje, zaległy z poprzedniego roku, wykorzystane, pozostałe, znacznik pobrania |
| `external_absence` | nieobecności z R2, których nie wnioskujemy: `person_id`/`employment_id`, typ ogólny, daty, `r2_record_id`, znacznik pobrania. **Tylko do odczytu**, bez kodów chorobowych |
| `company_calendar` | dni robocze, święta i wyjątki firmowe |
| `sync_run` | przebiegi synchronizacji: fazy, liczby, wynik, miejsce przerwania |
| `sync_conflict` | konflikty wykryte w fazie weryfikacji, z powodem i wnioskiem, którego dotyczą |

Zmiany w tabelach z Etapu 2: `employment` dostaje `supervisor_employment_id`
i **`r2_employment_ref`** (nie „employee" — ⚠ urlopy w R2 wiszą pod zatrudnieniem, a jego
identyfikator zmienia się przy ponownym zatrudnieniu); `person` dostaje **`external_person_id`**
(HMAC z PESEL-u policzony po stronie connectora — my nie widzimy PESEL-u w żadnej postaci),
`NULL` dopuszczalny dla osób bez PESEL-u w R2; `company` dostaje **`nip`** jako klucz mapowania
(identyfikator firmy z R2 nie jest unikalny między instalacjami).

⚠ `UNIQUE` tam, gdzie ma pilnować jednoznaczności, **nie może stać na kolumnie z `NULL`**
(§4 reguł) — dotyczy mapowań na R2.

⚠ Strefa kodowana: reguły liczenia limitu i decyzji o dopuszczalności wniosku należą do
`/src/Core` (§15 reguł). Kalendarz, repozytoria i kontrolery zostają jawne. Granicę ustalamy
**przed** pisaniem klas, nie po.

---

## 12. Kamienie milowe

| # | Zakres | Ukończone, gdy |
|---|---|---|
| **E1** | migracje, typy nieobecności, kalendarz firmowy, seed | `migrate` i `rollback` przechodzą; kalendarz liczy dni robocze dla roku z świętami ruchomymi |
| **E2** | złożenie wniosku z rezerwacją limitu w transakcji, lista wniosków, wycofanie przed akceptacją | dwa równoległe wnioski na te same ostatnie dni — drugi odrzucony; salda zgadzają się do zera |
| **E3** | akceptacja, odrzucenie z uzasadnieniem, komentarze, powiadomienia przez kolejkę | pełna ścieżka pracownik → przełożony → pracownik, z powiadomieniami w `var/mail` |
| **E4** | hierarchia z kontrolą cykli, eskalacja cronem, alert na szczycie | cykl w hierarchii odrzucony przy zapisie; wniosek eskaluje po progu i przy nieobecnym przełożonym |
| **E5** | granica connectora: przyjęcie danych z R2, faza weryfikacji, stany `conflict` i `sync_failed` | podrzucony ręcznie konflikt zatrzymuje wniosek przed wysyłką i pokazuje powód |
| **E6** | wysyłka do R2, **faza potwierdzenia zwrotnego**, `r2_record_id`, idempotencja | przerwanie połączenia po zapisie, a przed potwierdzeniem, nie tworzy duplikatu w następnym cyklu; `sync_run` opisuje przebieg |
| **E7** | anulowanie po akceptacji, wycofanie zapisu z R2 | prośba → decyzja → wycofanie; dni wracają dokładnie raz |
| **E8** | kalendarz zespołu z widocznością, **nieobecności zewnętrzne w kalendarzu pracownika**, wydruk PDF, ekrany kadr | pracownik nie widzi rodzaju nieobecności kolegi w żadnym widoku ani w odpowiedzi endpointu; dzień zajęty zwolnieniem nie da się zarezerwować |

E5 i E6 wymagają działającego connectora — do tego czasu granica jest testowana **atrapą**
odtwarzającą kontrakt z rozdziału 2.

---

## 13. Scenariusze odbioru (wyciąg)

1. Dwa wnioski złożone równocześnie na ostatnie dostępne dni — tylko jeden przechodzi.
2. Odrzucenie wniosku zwraca dni do limitu; ponowne złożenie na te same daty jest możliwe.
3. Wniosek pracownika A nie jest widoczny ani edytowalny dla pracownika B — także przez
   bezpośrednie wywołanie endpointu z cudzym identyfikatorem.
4. Przełożony nie może zaakceptować wniosku osoby spoza swoich podwładnych.
5. Wniosek zalegający ponad próg eskaluje wyżej; na szczycie hierarchii nie akceptuje się sam.
6. Pobranie z R2 z obniżonym limitem przenosi nadmiarowe wnioski do `conflict`, nie kasuje ich.
7. Powtórzony cykl synchronizacji nie tworzy duplikatu w R2 — także wtedy, gdy poprzedni cykl
   przerwano **po** zapisie w R2, a **przed** dotarciem potwierdzenia do tenanta.
7b. Przyjęcie tego samego potwierdzenia dwa razy nie zmienia stanu wniosku ani sald.
8. Wniosek w stanie `approved` pokazuje się pracownikowi jako „czeka na zapis w kadrach",
   a nie jako zakończony.
9. Kalendarz zespołu nie ujawnia rodzaju nieobecności — ani w HTML, ani w odpowiedzi fragmentu htmx.
9b. Zwolnienie lekarskie pobrane z R2 pojawia się w kalendarzu pracownika, blokuje rezerwację
   tych dni i **przenosi kolidujący wniosek do `conflict`**, nie kasując go. Nieobecności
   zewnętrznej nie da się w aplikacji zmienić ani usunąć żadną ścieżką.
10. Praca bez JavaScriptu: złożenie i akceptacja wniosku działają (warunek z Etapu 2).

---

## 14. Otwarte — do rozstrzygnięcia w trakcie

| Id | Pytanie | Kiedy |
|---|---|---|
| ~~C1~~ | ~~identyfikacja pracownika, mapowanie firm~~ — **rozstrzygnięte 19.09**: osoba/kartoteka/zatrudnienie, `osobaId` jako HMAC u klienta, firmy po NIP | — |
| ~~C2~~ | ~~czy da się usunąć zapisany urlop~~ — **rozstrzygnięte**: usunięcie i zmiana działają, R2 przelicza dni sam | — |
| ~~C4~~ | ~~zakres typów nieobecności~~ — **rozstrzygnięte**: trzy tabele, pełne słowniki, z karty zasiłkowej wyłącznie rodzaj i daty | — |
| **C5** | **urlop na część dnia** (godzinowy) — nieprzetestowany po stronie R2 | po pierwszym wdrożeniu |
| **C6** | **zapis do tabeli nieobecności kadrowych** — nietestowany; przesądza, czy kiedyś obsłużymy bezpłatny i opiekuńczy jako wniosek | przy rozszerzaniu typów |
| **C7** | zmiana urlopu **rozliczonego już na liście płac** — zachowanie R2 nieznane; prawdopodobnie sprawa dla kadr, nie dla automatu | przed E7 |
| ~~C8~~ | ~~osoby bez PESEL-u~~ — **rozstrzygnięte**: wszystkie 41 takich kartotek to kartoteki **skasowane**, wśród aktywnych pracowników nie ma ani jednej. Ekranu ręcznego łączenia **nie budujemy**; przypadek cudzoziemca bez PESEL-u obsłuży administrator, gdy się pojawi | — |
| **C9** | wydłużenie urlopu przez kadry **przez przełom miesiąca** — czy R2 dokłada osobny wpis | przed E7 |
| **C10** | zwolnienie z **datą przyszłą** (okres wypowiedzenia) — czy status zmienia się od razu; w danych klienta brak takiego przypadku | przy E4 |
| **C3** | częstotliwość cyklu synchronizacji a oczekiwania klienta | przy pierwszym wdrożeniu |
| **Z1** | pełny log audytowy — zakres zdarzeń i czas przechowywania | równolegle z E3 |
| **Z2** | retencja danych urlopowych i usuwanie | przed pierwszym wdrożeniem produkcyjnym |
| **Z3** | procedura odejścia pracownika (wnioski, salda, dostęp) | przy E4 |
| **Z4** | załączniki: szyfrowanie, dostęp, retencja | kolejna wersja |
| **R1** | raporty i eksport dla kadr — zakres | po E8 |
| — | `max_input_vars = 1000` przy formularzach zbiorczych | przy ekranach kadr |
