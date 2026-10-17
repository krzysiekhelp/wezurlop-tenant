# API tenanta dla connectora — specyfikacja protokołu

Interfejs, który **wystawia tenant**, a connector jest jego klientem. Powód kierunku: connector
stoi w sieci klienta, tenant nie ma jak tam sięgnąć.

Dokument opisuje **protokół**: operacje, koperty, kursory, porcjowanie, błędy i bezpieczeństwo.
Kształt samych rekordów — pola, typy i obowiązkowość — jest w **`docs/ksztalt-danych-r2.md`**
(dokument autora connectora, wersja 0.6 z 19.09.2026), gdzie obowiązkowość zmierzono
na ok. 16 tys. rekordów z dwóch firm klienta odniesienia.

⚠ **Listę pól trzymamy jako konfigurację** (`config/sync-schema.php`), nie jako warunki
w klasach. Dopisanie pola ma być jedną linią w pliku, a nie zmianą w repozytorium i wydaniem.

Stan: 2026-09-20. Wersja protokołu: **1**.

---

## 1. Zasady, z których wynika reszta

1. **Cykl ma cztery fazy i zawsze tę kolejność**: pobranie → weryfikacja (po naszej stronie) →
   wydanie zadań → potwierdzenie wyników.
2. **Każda firma to niezależny strumień.** Wszystkie operacje niosą `nip`; kursory, porcje
   i przebiegi są per firma. Klucz rekordu to para `nip` + `id`.
3. **Limit 30 sekund na żądanie** obowiązuje po naszej stronie i jest twardy (§0 reguł).
   Stąd paczki po 500 rekordów i wznawianie od kursora.
4. **Każde żądanie jest idempotentne.** Powtórzenie tej samej paczki nie może niczego zdublować —
   ani rekordu, ani potwierdzenia, ani zadania.
5. **Nie ufamy treści z connectora** (§11 reguł): pola spoza listy są **ignorowane**, nie
   zapisywane; typy walidowane; długości ograniczone. Connector jest naszym partnerem,
   ale jego wyjście to wejście do aplikacji.

---

## 2. Transport i uwierzytelnienie

```
POST https://<domena-klienta>/api/v1/sync/<operacja>
Content-Type: application/json; charset=utf-8
Content-Encoding: gzip                 # opcjonalnie
Authorization: Bearer <klucz-instalacji>
X-Api-Key: <klucz-instalacji>          # ścieżka zapasowa, patrz niżej
X-Protokol: 1
X-Idempotency-Key: <uuid paczki>
X-Nip: 1234567009                      # NIP firmy — przy GET stan i zadania
```

⚠ **NIP firmy idzie w nagłówku `X-Nip`, nie w adresie** (od 17.10.2026). W operacjach `POST`
siedzi tam, gdzie siedział — w treści żądania. Zmiana dotyczy dwóch operacji `GET`
(`stan`, `zadania`), bo tylko tam NIP stał w adresie.
Powód jest zmierzony: **adresy trafiają do logu dostępu serwera WWW i do logów proxy po drodze**,
więc przy cyklu co godzinę log tenanta zapełniał się NIP-ami firm klienta w jawnej postaci.
To ta sama zasada, co dla kluczy — identyfikator w nagłówku, nigdy w adresie.

⚠ **Okres przejściowy: `?nip=` w adresie dalej działa.** Connector u klienta aktualizuje się
osobno od tenanta, więc tenant przyjmuje jedno i drugie. `?nip=` jest **przestarzałe** i zostanie
usunięte osobnym etapem, po przejściu connectorów.
⚠ **Oba naraz i różne → `400 NIP_NIESPOJNY`.** Tenant nie zgaduje, który numer jest prawdziwy:
rozbieżność znaczy, że po drugiej stronie coś się rozjechało, a wybranie jednego po cichu
dałoby synchronizację NIE TEJ firmy. Porównanie idzie **po normalizacji**, więc
`PL525-252-50-06` i `5252525006` to ten sam numer, a nie niespójność.
⚠ Protokół zostaje **`1`** — zmiana jest addytywna: stare żądanie działa bez zmian.
⚠ **Tenant liczy żądania ze starą drogą** w opisie przebiegu (`nipWAdresie` w `sync_run.stats`,
liczone przy `zadania`, czyli w każdym cyklu) i pokazuje je na ekranie przebiegu, gdy jest ich
więcej niż zero. Dzięki temu wyłączenie `?nip=` będzie decyzją na podstawie danych, a nie
domysłem „chyba wszyscy już przeszli". Connector nie musi nic z tym robić — to licznik po naszej
stronie.

⚠ **Nie ma jednego adresu dla wszystkich klientów.** Aplikacja jest instalowana u każdego klienta
osobno, więc adresem bazowym jest **domena tego klienta**, wpisywana w konfigurację connectora
przy wdrożeniu. Jeden connector = jeden klucz = jeden klient (ale dowolnie wiele firm i wiele
instalacji programu kadrowego po jego stronie).

**Wersja w adresie** (`/api/v1/`). Dokładanie pól nie jest zmianą wersji; `X-Protokol`
niezgodny z obsługiwanym → `409` z listą wersji.

**Klucz**: 64 znaki heksadecymalne, w bazie **jako hash** (§9 reguł), z listą dozwolonych NIP-ów.
Wymiana: nowy klucz wystawia administrator, stary działa **do chwili, w której nowy domknie
pierwszy przebieg** — wtedy dostaje `401` z kodem `KLUCZ_WYGASL`.

⚠ **Odpowiedź `401` niesie dodatkowe pole `powod`** (od 21.09.2026) — kody `KLUCZ_NIEPRAWIDLOWY`
i `KLUCZ_WYGASL` zostają bez zmian, bo connector już je obsługuje, a dokładanie pól nie jest zmianą
wersji protokołu. Powód mówi, CO się stało z kluczem:

| `powod` | Kiedy | Co zrobić u klienta |
|---|---|---|
| `uniewazniony_przez_administratora` | administrator unieważnił klucz z ekranu integracji | poprosić o wydanie nowego klucza |
| `zastapiony_nowym_kluczem` | nowy klucz przejął synchronizację (domknął pierwszy przebieg) — kod odpowiedzi `KLUCZ_WYGASL` | wpisać nowy klucz w konfiguracji connectora |

Bez tego pola administrator klienta widzi „klucz wygasł” także wtedy, gdy klucz unieważniono ręcznie
— czyli komunikat nieprawdziwy w jednym z dwóch przypadków.

⚠ **Przejęcie następuje w chwili, gdy nowy klucz domknie pierwszy przebieg** (`koniec` z wynikiem
pozytywnym) — wtedy stary klucz jest unieważniany automatycznie, z wpisem w dzienniku zdarzeń.
**Nie ma żadnego terminu ani licznika czasu** (zmiana z 20.09.2026; wcześniej było okno 24 h,
a jeszcze wcześniej 30 dni).

Powód usunięcia licznika: miał on dokładnie jeden efekt praktyczny — **zamieniał nieudaną wymianę
w awarię**. Gdy nowy klucz działa, licznik nie ma nic do roboty, bo stary ginie sam przy pierwszym
przebiegu. Gdy nowy klucz nie został wklejony (literówka, nikt nie wszedł na maszynę, serwer padł),
licznik po dobie zabijał **działający** stary klucz i zatrzymywał synchronizację kadr, mimo że
wszystko przedtem działało. Przed „zapomnianą starą maszyną" broni automatyczne unieważnienie
w chwili przejęcia, a nie zegar.

Stąd po naszej stronie są **dwie operacje, nie trzy**:

- **wydanie nowego klucza** — stary działa dalej, bez terminu; nowy przejmuje przy pierwszym
  zakończonym przebiegu. Firma ma najwyżej jeden klucz `wydany, jeszcze nieużyty`; wydanie
  kolejnego unieważnia poprzedni nieużyty,
- **unieważnienie** — natychmiastowe, bez związku z wydawaniem nowego. ⚠ To jest **jedyne**
  narzędzie na wyciek klucza i na wycofanie maszyny z użycia; nakładka w żadnej postaci nie jest
  do tego właściwa, bo wyciekły klucz ma zginąć od razu.

Klucz `wydany, jeszcze nieużyty` dłużej niż próg jest **stanem wymagającym uwagi** na ekranie
integracji i w diagnostyce — nie powodem do zabicia czegokolwiek.

⚠ **Klucz jest poświadczeniem, nie właścicielem danych** (sprawdzone 20.09.2026). Stan synchronizacji
— kursory, rejestr rekordów, konflikty, zadania i ich rezerwacje — należy do **firmy**, nie do klucza;
`sync_run` odnotowuje klucz wyłącznie diagnostycznie. Stąd trzy konsekwencje:

- **wymiana klucza przy tej samej firmie NIE powoduje ponownego ładowania** — nowy klucz z tymi samymi
  kursorami dostaje `tryb: "zmiany"` i zero rekordów,
- **nowy klucz wymaga tylko zaznaczenia firmy w zakresie** (jedno kliknięcie na `/admin/integracja`),
  a nie nowego zgłoszenia firmy,
- ⚠ **jedna firma może być w zakresie tylko jednego aktywnego klucza.** Dwa connectory na jednym
  strumieniu to skaczące kursory i zadania pobrane dwa razy, więc aplikacja odmawia dopięcia firmy
  do drugiego klucza. Wyjątkiem jest sama wymiana: klucz w 30-dniowej nakładce to ta sama instalacja.

⚠ **Zadanie wydane starym kluczem i niepotwierdzone** wraca do wydania po upływie rezerwacji
(15 minut) z **tym samym `requestId`** — to `requestId`, a nie klucz, chroni przed podwójnym zapisem
w programie kadrowym.

**Kompresja**: `gzip` przyjmowany, ale rozpakowana treść ograniczona do **8 MB**
(ochrona przed bombą kompresyjną) — powyżej `413`. Treść przed kompresją: maks. **2 MB**.

- **Klucz per instalacja connectora**, wydany przy wdrożeniu, **ograniczony do listy firm**
  (znalezisko L4). Żądanie z `nip` spoza listy → `403`, bez wskazówki, czy firma istnieje.
- ⚠ **Klucz w obu nagłówkach naraz.** Część hostingów wycina `Authorization` pod CGI/FastCGI
  (§0 reguł). Serwer przyjmuje `Authorization`, a gdy go brak — `X-Api-Key`. Connector wysyła oba.
- **HTTPS z weryfikacją certyfikatu**, bezwarunkowo, po obu stronach.
- Kodowanie **UTF-8**. Daty nieobecności jako `YYYY-MM-DD` (bez godziny). Znaczniki zdarzeń
  w **ISO 8601 z przesunięciem strefy**.
- `X-Protokol` niezgodny z obsługiwanym → `409` z listą wspieranych wersji. ⚠ **Brak tego
  nagłówka nie jest błędem** — wersja jest w adresie (`/api/v1/`), a `X-Protokol` jest
  informacją; brak traktujemy jak `1`. Wersję podbijamy wyłącznie przy zmianie niezgodnej
  wstecz; dokładanie pól nie jest taką zmianą.

---

## 3. Operacje

### 3.0 `GET /api/v1/sync/stan` — test połączenia

Odpowiada na pytanie „czy klucz działa i co z moją firmą”. To **inne pytanie niż `start`**,
dlatego ma własną, wąską operację.

```
GET /api/v1/sync/stan
X-Nip: 1234567009                      # opcjonalny; `?nip=1234567009` w adresie — przestarzałe
```

```json
{ "protokol": 1,
  "klucz": "aktywny",
  "firma": { "stan": "ok" },
  "ostatniPrzebieg": "2026-09-20T11:12:03Z" }
```

- ⚠ **Nie otwiera przebiegu, nie rusza kursorów i nie wchodzi do statystyki przebiegów.**
  Test połączenia robiony przez `start` wyglądał w historii jak cykl, który nic nie zrobił.
- NIP jest **opcjonalny**: bez niego wraca sam stan klucza (`firma` i `ostatniPrzebieg` to `null`),
  więc connector może sprawdzić poświadczenia, zanim pozna NIP firmy. Przysyła się go w nagłówku
  `X-Nip`; `?nip=` w adresie działa przez okres przejściowy (§1).
- `firma.stan`: `ok` | `firma_nieznana` | `firma_zgloszona` | `firma_poza_zakresem` — te same
  wartości co pole `stan` w `403 FIRMA_NIEZATWIERDZONA` (§3.1). **Nazwy firmy odpowiedź nie zawiera.**
- Zły albo wygasły klucz → `401`, tak samo jak w pozostałych operacjach. Zła suma kontrolna NIP-u → `422`.
- `ostatniPrzebieg` to czas domknięcia ostatniego cyklu tej firmy (UTC) albo `null`.

### 3.1 `POST /api/v1/sync/start` — otwarcie przebiegu

Connector melduje gotowość, tenant odpowiada, czego chce w tym cyklu.

```json
// żądanie
{ "nip": "1234567009",
  "wersjaConnectora": "1.4.2", "wersjaR2": "10.10.33",
  "ostatniUdanyCykl": "2026-09-19T18:20:00+02:00",
  "stan": "ok",                                     // ok | zapisWstrzymany | odczytWstrzymany
  "stanOpis": null,                                 // maks. 200 znaków, bez danych osobowych
  "odciskTozsamosci": "a3f91c2e",                   // opcjonalne, 8 znaków szesnastkowych
  "kursory": { "nieobecnosc": "c-881", "limit": "c-875" } }

// odpowiedź
{
  "przebiegId": "4f2c…",
  "zbiory": {
    "firma":       { "kursor": "c-881", "tryb": "zmiany" },
    "pracownik":   { "kursor": "c-881", "tryb": "zmiany" },
    "zatrudnienie":{ "kursor": "c-880", "tryb": "zmiany" },
    "limit":       { "kursor": "c-875", "tryb": "zmiany" },
    "nieobecnosc": { "kursor": "c-881", "tryb": "zmiany" },
    "slownik":     { "kursor": null,    "tryb": "pelny"  },
    "kalendarz":   { "kursor": "c-800", "tryb": "zmiany" }
  },
  "przegladKontrolny": true,
  "rozmiarPaczki": 500,
  "wycofane": ["wu-7a10…"]
}
```

`wycofane` — zadania, których **nie należy wykonywać**, jeśli jeszcze nie trafiły do programu
kadrowego (pracownik zdążył anulować wniosek). Gdy zapis już się odbył, connector odsyła
normalny wynik, a my dokładamy zadanie usunięcia.

Żądanie `start` niesie też **stan connectora**, który pokazujemy administratorowi na stronie
diagnostycznej: wersja connectora, wersja programu kadrowego, czas ostatniego udanego cyklu
i stan per firma. Osobnego wywołania na to nie ma — jedno mniej na cykl.

⚠ **Nieznana firma — osobna, wąska operacja.** `start` z niezatwierdzonym NIP-em zwraca
`403 FIRMA_NIEZATWIERDZONA` **bez `przebiegId`**. Zgłoszenie idzie wtedy przez:

```
POST /api/v1/sync/zgloszenie-firmy
{ "nip": "1234567009", "nazwa": "Firma A sp. z o.o." }
→ 202 { "status": "oczekuje" }
```

Operacja przyjmuje **wyłącznie te dwa pola**, ma własny limit częstotliwości, nie tworzy
przebiegu i nie przyjmuje żadnych danych osobowych. Administrator widzi zgłoszenie i decyduje;
po zatwierdzeniu NIP wchodzi do zakresu klucza, a connector dostaje normalny przebieg
z `tryb: "pelny"`. Firm nie zakładamy automatycznie: z firmą wiąże się hierarchia, uprawnienia
i licencja, a connector z literówką w NIP-ie wlałby do nowego bytu dane kadrowe prawdziwych ludzi.

⚠ **Odpowiedź niesie jeden z trzech stanów** — powtórzone zgłoszenie tego samego NIP-u nigdy
nie tworzy duplikatu, ale mówi, co się z nim stało:

| Kod | `status` | Znaczenie | Co robi connector |
|---|---|---|---|
| `202` | `oczekuje` | zgłoszenie czeka na decyzję administratora | nie ponawia częściej niż raz na cykl; firma pozostaje wstrzymana |
| `200` | `zatwierdzona` | firma **istnieje u nas** i jest w zakresie klucza | wraca do `start` **w tym samym cyklu** |
| `200` | `odrzucona` | administrator odrzucił zgłoszenie | **przestaje zgłaszać ten NIP**; wznowienie wymaga decyzji administratora |

Bez `zatwierdzona` connector po zatwierdzeniu firmy dostawałby w kółko „oczekuje" i czekał
na kolejny cykl mimo że wszystko jest gotowe; bez `odrzucona` zgłaszałby w nieskończoność NIP,
którego administrator świadomie nie chce.

⚠ **`zatwierdzona` zależy od ISTNIENIA firmy u nas, nie od samej listy NIP-ów przy kluczu**
(rozstrzygnięcie 20.09.2026, po pierwszym podłączeniu na żywo). Firma wchodzi do systemu wyłącznie
przez zatwierdzenie zgłoszenia, a zakres klucza administrator ustawia, **wybierając firmy już
istniejące** — nie wpisuje NIP-ów ręcznie. Wcześniej dało się rozjechać te dwa źródła prawdy:
`start` odpowiadał `403` („nie ma takiej firmy"), a `zgloszenie-firmy` w tej samej chwili
`zatwierdzona` („jest w zakresie klucza") — i connector krążył między nimi bez śladu w zgłoszeniach.
⚠ **Każda ścieżka poza `zatwierdzona` zostawia wiersz zgłoszenia**, także wtedy, gdy firma u nas
jest, ale poza zakresem tego klucza.

⚠ **NIP sprawdzamy sumą kontrolną, nie tylko formatem.** Przed sprawdzeniem normalizujemy
wartość: usuwamy myślniki, spacje i prefiks `PL`. Dziesięć cyfr o błędnej sumie kontrolnej to
**`422 NIP_NIEPOPRAWNY`** — nigdy `403`. Różnica jest praktyczna: `403` wysyła connector
do `zgloszenie-firmy`, gdzie literówka utknęłaby jako zgłoszenie do ręcznego zatwierdzenia,
a administrator zobaczyłby prośbę o założenie firmy o nieistniejącym numerze.

- `tryb: "zmiany"` — connector wysyła tylko rekordy zmienione względem swojego stanu.
- `tryb: "pelny"` — pełna zawartość zbioru (słowniki, pierwsze ładowanie).
- `przegladKontrolny: true` — dodatkowo **przegląd samych identyfikatorów i sum**
  (patrz 3.3); tak wykrywamy usunięcia.
- `przebiegId` wiąże wszystkie żądania tego cyklu i jest kluczem w `sync_run`.

⚠ **Życie `przebiegId`** (rozstrzygnięte z autorem connectora 20.09.2026):

| Zdarzenie | Skutek |
|---|---|
| `koniec` | przebieg zamknięty |
| **30 minut bez żadnego żądania w tym przebiegu** | przebieg wygasa (`sync_run.status = expired`) |
| nowy `start` tej samej firmy | poprzedni unieważniony — **jeden otwarty przebieg na firmę** |
| utrata stanu po naszej stronie | przebieg nie istnieje |

Każde żądanie z martwym `przebiegId` dostaje **`409 PRZEBIEG_NIEZNANY`** z polem `powod`
(`wygasl` | `zamkniety` | `zastapiony` | `nieznany`); connector otwiera nowy `start`.
⚠ `powod` jest informacją do diagnozy, nie sterowaniem — connector reaguje tak samo niezależnie
od wartości, ale przy zgłoszeniu od klienta różnica między „wygasł po 30 minutach” i „zastąpiony
nowym `start`” to różnica między diagnozą a zgadywaniem.

```json
{ "blad": { "kod": "PRZEBIEG_NIEZNANY", "komunikat": "Nieznany albo zamknięty przebieg — otwórz nowy",
            "zdarzenieId": "c7d8e9f0", "powod": "wygasl" } }
``` Czas życia jest konfigurowalny (`tenant_setting.sync_run_ttl_minutes`, domyślnie 30),
a liczy się **bezczynność w przebiegu**, nie jego długość — każde żądanie odsuwa wygaśnięcie.

⚠ **Kursory nie giną razem z przebiegiem.** Leżą w `sync_cursor` i obowiązuje ostatni potwierdzony,
więc nowy przebieg podejmuje pracę od tego samego miejsca. Inaczej każda przerwana synchronizacja
kończyłaby się pełnym ładowaniem.

**`odciskTozsamosci` — bezpiecznik przed kompletem duplikatów osób**

⚠ Sekret, z którego connector liczy `osobaId`, mieszka w **jego** konfiguracji, a przenosiny same
z siebie go nie zachowują. Gdy przepadnie, ci sami ludzie przychodzą z nowymi identyfikatorami
i zakładamy drugi komplet osób — a dowiadujemy się o tym po fakcie. `start` jest jedynym miejscem,
w którym da się to złapać **przed** szkodą.

- Pole jest **opcjonalne**: 8 znaków szesnastkowych, bez żadnej informacji o osobach.
  Brak pola = starszy connector; przyjmujemy bez zastrzeżeń i nie zapisujemy nic.
- Pierwszy odcisk dla firmy zapamiętujemy **cicho** — nie ma z czym porównywać.
- Ten sam odcisk: nic się nie dzieje.
- ⚠ **Inny odcisk: `403 TOZSAMOSC_ZMIENIONA`** i strumień **tej** firmy stoi, zanim przyjdzie
  pierwsza paczka. Pozostałe firmy pracują dalej. Odblokowanie wymaga **jawnej decyzji
  administratora** w aplikacji („Zaakceptuj nowy odcisk”), z wpisem w dzienniku zdarzeń.
- Wartość niezgodna z formatem (`^[0-9a-f]{8}$`) → `400 ZADANIE_NIEPOPRAWNE`.

```json
{ "blad": { "kod": "TOZSAMOSC_ZMIENIONA", "komunikat": "…", "zdarzenieId": "a1b2c3d4",
            "odcisk": "b7d0e1f2" } }
```

### 3.2 `POST /api/v1/sync/dane` — faza 1, paczka rekordów

```json
{
  "nip": "1234567009",
  "przebiegId": "4f2c…",
  "zbior": "nieobecnosc",
  "paczka": 3,
  "ostatnia": false,
  "rekordy": [ { "id": "…", "suma": "sha256:…", "…": "…" } ]
}
```

Odpowiedź:

```json
{ "przyjeto": 500, "kursor": "c-882", "pominietePola": 0 }
```

⚠ **Nie ma pola `odrzucono`.** Paczka jest jedną transakcją, więc albo wchodzi cała (`200`),
albo nie wchodzi wcale (`422` z listą pozycji — patrz §4). Pole, które zawsze byłoby zerem,
tylko myli. `pominietePola` liczy pola spoza uzgodnionej listy: ignorujemy je, ale chcemy
widzieć, że przychodzą.

**Usunięcie rekordu** wysyłane jest w tym samym zbiorze, bez `suma`:

```json
{ "id": "urlop:12315", "usuniety": true }
```

⚠⚠ **`usuniety: true` znaczy „wypadł z zakresu synchronizacji", a nie „skasowano dane".**
Connector przysyła wyłącznie osoby aktualnie zatrudnione na umowę o pracę i nieobecności
od 1 stycznia poprzedniego roku, więc rekord znika także wtedy, gdy pracownik przeszedł
na zlecenie, minął jego dzień zwolnienia albo **rok wypadł z okna dat**.
Konsekwencje po naszej stronie — rozdział 5b `docs/etap3-logika-urlopowa.md`; w skrócie:
zatrudnienie oznaczamy jako nieaktywne, **niczego nie kasujemy**, a usunięcie nieobecności
z roku spoza okna **nie jest konfliktem** (inaczej 1 stycznia dostajemy setki wniosków
„do wyjaśnienia").

- **Maks. 500 rekordów** w paczce; większa → `413` z `maks` w treści.
- Paczka przetwarzana **w jednej transakcji** (§4 reguł). Albo wchodzi cała, albo żadna.
- `kursor` zwracany po przyjęciu paczki to **potwierdzenie**, nie znacznik czasu — connector
  wznawia od ostatniego potwierdzonego. Powtórzenie paczki z tym samym `X-Idempotency-Key`
  zwraca tę samą odpowiedź bez ponownego zapisu.
- Rekord z nieznanym `id` = nowy, ze znanym `id` i inną `suma` = **zmiana istniejącego**,
  nigdy duplikat (ustalenie: kadrowa edytująca nasz wpis nie tworzy nowego rekordu).
**Reguły walidacji rekordów** (pełna lista pól: `docs/ksztalt-danych-r2.md`):

- ⚠ **Pole wymagane musi być obecne i mieć wartość** — brak klucza albo `null` to `422`.
  **Pole opcjonalne: brak klucza traktujemy jak `null`.** Connector deklaruje, że zawsze wysyła
  komplet kluczy, i dobrze — ale odrzucanie całej paczki za nieobecny klucz opcjonalny nic nie
  chroni: obcięty albo źle zbudowany komunikat wychwytuje brak pola **wymaganego**, a nie brak
  `zmieniono: null`.
- ⚠ **Liczby całkowite mogą być ujemne** — `korektyMin` (ręczna korekta kadr) i `pozostaloMin`
  (przekroczony limit). Walidacja nie może wymagać wartości ≥ 0.
- ⚠ **Jedyna liczba dziesiętna to `zatrudnienie.etat`** (`0.5`, `0.75`, `0.6667`), i bywa `null`
  przy zleceniach i umowach o dzieło — **także w zatrudnieniach aktualnych**.
- Wszystkie identyfikatory, także wskazujące (`pracownikId`, `zatrudnienieId`, `kalendarzId`),
  są **stringami**.
- ⚠ **Pola spoza uzgodnionej listy są ignorowane** i zliczane w `sync_run` jako „pominięte".
  Dotyczy to zwłaszcza karty zasiłkowej: przyjmujemy **rodzaj i daty**, nic więcej.
  Gdyby kiedykolwiek przyszły kody chorobowe, nie zostaną zapisane (§14 reguł).

**Powiązanie rodzaju z limitem** (`slownik.obciazaLimit`, `slownik.obciazaLimitZrodlo`):

- `obciazaLimit` to wartość taka jak `rodzaj` rekordu `limit` (u klienta odniesienia:
  `wypoczynkowy`, `opieka_art188`) albo `null`. To ona — a nie nasze domysły — decyduje,
  czy rodzaj pomniejsza pulę.
- ⚠ **Dla źródeł `kadrowe` i `zasilek` zawsze `null`.** Niepustą wartość przy tych źródłach
  odrzucamy i odnotowujemy w opisie przebiegu; zgadywanie po stronie tenanta byłoby gorsze
  niż brak danych.
- ⚠ **`urlop:6` to ekwiwalent pieniężny, nie nieobecność — a pulę zmniejsza.** Dlatego
  „obciąża limit" wynika z `obciazaLimit`, a nie z pola `nieobecnosc`.
- `obciazaLimitZrodlo`: `dane` — powiązanie policzone z sald tej firmy; `domyslne` — rodzaj
  nieużywany u klienta, powiązanie wynika z zachowania programu kadrowego.
  ⚠ **`domyslne` nie blokuje wniosku** — wniosek przechodzi, ale z ostrzeżeniem. Nie odmawiamy
  pracownikowi urlopu na podstawie domysłu o konfiguracji jego programu kadrowego.
- Brak obu pól (starszy connector) = brak powiązania: rodzaj nie obciąża limitu.

**Normy kalendarza** (`kalendarz.normaUrlopowaMin`, `kalendarz.normaDobowaMin`):

- ⚠ **`normaUrlopowaMin` jest dzielnikiem minut na dni.** Wcześniej dzieliliśmy przez stałą 480
  wpisaną w kod — u klienta z dniówką 12 h myliłaby się liczba dni w **każdym** wniosku.
- Brak pola = wartość zapasowa 480 min, ale **widoczna**: licznik `kalendarzeBezNormy`
  w opisie przebiegu, stała pozycja na stronie `/diagnostyka` i ostrzeżenie przy wniosku.
- `normaDobowaMin` przyjmujemy informacyjnie. `indywidualne` również — **żadnej logiki na tej fladze**,
  dopóki producent programu kadrowego nie opisze jej znaczenia.

**Długość nieobecności przy źródle `kadrowe`:**

- ⚠ **`dni: 0` i `minuty: 0` to reguła, nie brak danych** (84 wpisy w dwóch firmach, bez wyjątku:
  urlop bezpłatny, wychowawczy, nieusprawiedliwiona, służba wojskowa). Program kadrowy nie wylicza
  długości dla tego źródła.
- Termin blokujemy **datami** `dataOd`–`dataDo` (kalendarz, kolizja z wnioskiem), a do rozliczeń
  taka nieobecność wnosi **zero**. Zapisujemy zero jawnie, żeby nikt nie wziął `null` za „nie wiemy”.

### 3.3 `POST /api/v1/sync/przeglad` — wykrywanie usunięć

Paczki złożone **wyłącznie** z `id` i `suma`, dla całego zbioru.

```json
{
  "nip": "1234567009", "przebiegId": "4f2c…", "zbior": "nieobecnosc",
  "paczka": 1, "ostatnia": true,
  "pozycje": [ { "id": "…", "suma": "sha256:…" } ]
}
```

Odpowiedź jak przy `dane`: `{ "przyjeto": 412, "kursor": "c-883" }`.

⚠ **Usunięcia domykamy dopiero, gdy przegląd jest kompletny.** Tenant oznacza brakujące rekordy
jako usunięte **wyłącznie po otrzymaniu paczki z `ostatnia: true`** i tylko dla tego `przebiegId`.
Przerwany przegląd nie kasuje niczego — inaczej zerwane łącze w połowie transmisji wyczyściłoby
klientowi kalendarz.

### 3.4 `GET /api/v1/sync/zadania` — faza 3, co zapisać w kadrach

```
GET /api/v1/sync/zadania?przebiegId=4f2c…&limit=20
X-Nip: 1234567009                      # `&nip=1234567009` w adresie — przestarzałe
```

```json
{
  "zadania": [
    {
      "requestId": "wu-9f31…",
      "operacja": "zapis",              // zapis | zmiana | usuniecie
      "zatrudnienieId": "…",            // identyfikator z R2, nie nasz
      "rodzaj": { "zrodlo": "urlop", "kod": 0 },
      "dataOd": "2026-10-29",
      "dataDo": "2026-11-03",
      "zapisy": []                      // przy zmianie/usunięciu: identyfikatory z R2
    }
  ]
}
```

- `limit` domyślnie **20**, maksymalnie **100**.
- ⚠ **Wydane zadanie jest rezerwowane na 15 minut** i nie wróci wcześniej; po upływie wraca
  do puli. To druga warstwa obok rejestru `requestId` po stronie connectora — obie są potrzebne.
- **Zadania dotyczące tego samego wpisu wydajemy sekwencyjnie**: dopóki nie ma wyniku,
  nie wydamy następnego dla tego wniosku. Zadania różnych pracowników są niezależne.
- `requestId`: maks. **32 znaki ASCII** z zakresu `[A-Za-z0-9-]` (ograniczenie programu kadrowego).
- Wydajemy **tylko wnioski, które przeszły weryfikację** na danych z tego cyklu.
- Zadanie wydane, ale niepotwierdzone, **wraca w kolejnym cyklu** — z tym samym `requestId`.
  Ochroną przed podwójnym zapisem jest rejestr po stronie connectora i `requestId` zapisywany
  w opisie wpisu w R2.
- Nie wysyłamy dni ani godzin: liczy je program kadrowy (ustalenie z 19.09).

### 3.5 `POST /api/v1/sync/wyniki` — faza 4, potwierdzenie

```json
{
  "nip": "1234567009", "przebiegId": "4f2c…",
  "wyniki": [
    {
      "requestId": "wu-9f31…",
      "status": "zapisanoZOstrzezeniem",
      "kod": "OSTRZ_NA_ZADANIE_WYKORZYSTANY",
      "komunikat": "…",
      "zapisy": [
        { "id": "…", "dataOd": "2026-10-29", "dataDo": "2026-10-31", "dni": 2, "minuty": 960 },
        { "id": "…", "dataOd": "2026-11-02", "dataDo": "2026-11-03", "dni": 2, "minuty": 960 }
      ]
    }
  ]
}
```

| `status` | Co robimy |
|---|---|
| `zapisano` | wniosek → `confirmed`, dni i minuty z sumy wszystkich `zapisy` |
| `zapisanoZOstrzezeniem` | `confirmed` + flaga i treść ostrzeżenia, alert do kadr |
| `blad` | `conflict` — czeka na człowieka, z kodem i komunikatem |
| `bladPrzejsciowy` | `sync_failed` — zadanie wraca do wydania w kolejnym cyklu, z licznikiem prób |
| `wycofano` | zadanie było na liście `wycofane` i nie zostało wykonane — zamykamy je bez zmiany stanu wniosku |

⚠ **`zapisy` to zawsze faktyczny stan w programie kadrowym po wykonaniu zadania**, niezależnie
od `status`. Reguła autora connectora, przyjęta bez zmian, bo zdejmuje z nas zgadywanie
po kodach błędów:

| Sytuacja | `status` | `zapisy` |
|---|---|---|
| zapis udany | `zapisano` / `zapisanoZOstrzezeniem` | wpisy, które powstały |
| zapis odrzucony, **bo termin jest zajęty** | `blad` | wpisy, które blokują termin (`id`, `dataOd`, `dataDo`) |
| zapis odrzucony z innego powodu, stan nietknięty | `blad` | `[]` |
| `usuniecie` (także wpisu, który kadry już usunęły) | `zapisano` | `[]` |
| zmiana nieudana, stare wpisy przywrócone | `blad` | przywrócone wpisy, **z nowymi `id`** |
| zmiana nieudana i przywrócenie nieudane | `blad`, `kod: ZMIANA_CZESCIOWA` | `[]` |

⚠ **`ZMIANA_CZESCIOWA` to najgroźniejszy wynik w całym protokole**: wniosek nie ma już żadnego
wpisu w kadrach, a pracownik mógł już wyjechać. Traktujemy go osobno — `conflict`, alert do kadr
o najwyższym priorytecie i jednoznaczny komunikat u pracownika („urlop nie jest zapisany
w kadrach, skontaktuj się z działem kadr"), nigdy ciche przejście do `sync_failed`.

- ⚠ **`zapisy` przy `status: "blad"` nie są realizacją wniosku** (rozstrzygnięcie 20.09.2026):
  nie liczymy z nich dni ani minut i nie wiążemy ich z wnioskiem. Trafiają do treści konfliktu
  (`sync_conflict.detail.blokujaceZapisy`) jako informacja „to blokuje termin" dla człowieka,
  który będzie to rozplątywał.
- ⚠ **`zapis` i `usuniecie` tego samego wniosku nigdy nie wychodzą w jednym cyklu.** Zadanie `zapis`
  pobrane i niepotwierdzone, a wniosek w międzyczasie anulowany → `requestId` trafia na listę
  **`wycofane`** w najbliższym `start`, a wynik `wycofano` zamyka sprawę; zadania `usuniecie`
  wtedy nie tworzymy. `usuniecie` powstaje dopiero po potwierdzonym zapisie, ze wskazaniem wpisów.
- ⚠ **`zapisy` to lista.** Urlop na przełomie miesiąca program kadrowy dzieli na osobne wpisy;
  liczbę dni sumujemy ze wszystkich części, nigdy nie bierzemy z pierwszej.
- Potwierdzenie przyjęte drugi raz **nie zmienia stanu** i zwraca `ok` — connector ponawia,
  aż dostanie odpowiedź.
- ⚠ **Decyduje `status`, `kod` jest do wyświetlenia.** Nieznany kod przy znanym statusie nie
  zmienia zachowania aplikacji — pokazujemy go kadrom i zapisujemy. Dzięki temu autor connectora
  dokłada kody bez uzgadniania wersji protokołu.

### 3.6 `POST /api/v1/sync/koniec` — zamknięcie przebiegu

```json
// żądanie
{ "nip": "1234567009", "przebiegId": "4f2c…",
  "wyslano":   { "nieobecnosc": 3, "limit": 2 },
  "usuniete":  { "nieobecnosc": 1 },
  "odrzucone": [ { "zbior": "nieobecnosc", "id": "urlop:12315", "zdarzenieId": "a1b2c3d4" } ],
  "zadaniaPobrane": 2, "wynikiWyslane": 2,
  "pominietePola": 0,
  "czasMs": 1840 }

// odpowiedź
{ "nastepnyInterwal": 60, "pelnyPrzegladWymagany": false }
```

Podsumowanie trafia do `sync_run`. `pelnyPrzegladWymagany: true` zwracamy po odtworzeniu bazy
z kopii i gdy przegląd kontrolny wykazał rozjazd. `nastepnyInterwal` w sekundach — krócej,
gdy czekają zadania, dłużej w nocy.

---

## 4. Sterowanie tempem i błędy

| Kod | Kiedy | Co robi connector |
|---|---|---|
| `200` | przyjęte | dalej |
| `401` | zły albo wygasły klucz | **zatrzymuje cały connector** i alarmuje administratora |
| `403` | firma niezatwierdzona albo spoza zakresu klucza (z polem `stan`) | **zatrzymuje tylko tę firmę**; pozostałe strumienie pracują dalej |
| `409` | niezgodna wersja protokołu albo obcy `przebiegId` | otwiera nowy przebieg |
| `413` | paczka za duża | dzieli i ponawia |
| `422` | rekord niezgodny ze schematem | **paczka odrzucona w całości**; connector usuwa wskazane pozycje, ponawia resztę z nowym `X-Idempotency-Key`, odrzucone raportuje w `koniec` |
| `429` / `503` | za szybko albo przerwa techniczna | honoruje `Retry-After` (sekundy albo data), bez nagłówka zwalnia wykładniczo |
| `5xx` | błąd po naszej stronie | ponawia z rosnącym odstępem |

⚠ Odpowiedź błędna **nie zawiera szczegółów technicznych** (§14 reguł) — jest kod, identyfikator
zdarzenia i komunikat ogólny. Szczegóły zostają w logu tenanta.

```json
{ "blad": { "kod": "FIRMA_NIEZATWIERDZONA", "komunikat": "…", "zdarzenieId": "a1b2c3d4",
            "stan": "firma_zgloszona" } }
```

⚠ **`403` niesie `stan`**, bo to connector pokazuje ten komunikat człowiekowi przy wdrożeniu:

| `stan` | Znaczenie | Co ma zrobić człowiek |
|---|---|---|
| `firma_nieznana` | nie ma jej u nas i nie była zgłoszona | connector wysyła `zgloszenie-firmy` |
| `firma_zgloszona` | zgłoszenie czeka na decyzję | administrator zatwierdza firmę w aplikacji |
| `firma_poza_zakresem` | firma jest u nas, ale nie w zakresie tego klucza | administrator dopina firmę do klucza |

To jedyne miejsce, gdzie rozluźniamy zasadę „nie ujawniamy, czy zasób istnieje": po drugiej stronie
stoi **uwierzytelniony** connector tego klienta, a w odpowiedzi jest sam stan — **nigdy nazwa firmy**.
NIP trafia wyłącznie do logu tenanta, razem ze `stan`.

Podział, którym kieruje się connector: **trwałe** (`400`, `401`, `403`, `409`, `413`, `422`) —
nie ponawiać bez zmiany; **przejściowe** (`408`, `429`, `500`, `502`, `503`, `504`) — ponawiać.

⚠ **`503 PRZERWA_TECHNICZNA` z `Retry-After: 30`** to nie awaria, tylko zajęta baza: paczka
trafiła na blokadę wiersza i czekanie na nią nie zmieściłoby się w limicie czasu wykonania
u klienta (30 s). Przerywamy **całą paczkę w transakcji** — nic nie zostaje zapisane połowicznie —
i prosimy o powtórzenie tego samego żądania. Powtórka jest bezpieczna: paczki są idempotentne
treściowo (`id` + `suma`, `requestId`).

```
HTTP/1.1 503 Service Unavailable
Retry-After: 30
```
```json
{ "blad": { "kod": "PRZERWA_TECHNICZNA", "komunikat": "Baza zajęta, powtórz paczkę",
            "zdarzenieId": "aa11bb22" } }
```

**Tempo cyklu.** Odpowiedź na `koniec` niesie `nastepnyInterwal` w sekundach — krócej, gdy czekają
zadania, dłużej w nocy. `Retry-After` obowiązuje natychmiast i ma pierwszeństwo.

⚠ **`nastepnyInterwal` to prośba o ZWOLNIENIE, nie o przyspieszenie** (rozstrzygnięcie 20.09.2026).
Connector honoruje go tylko wtedy, gdy wydłuża cykl; jego własny odstęp ustawiony u klienta jest
nadrzędny i **nie schodzi poniżej 5 minut**. Wartość niższa niż odstęp connectora nie skróci cyklu,
więc po naszej stronie nie wolno na niej budować żadnej obietnicy czasu: komunikaty mówią
„w ciągu kilku minut", nigdy „natychmiast", i żaden ekran nie odświeża się w pętli w oczekiwaniu
na potwierdzenie z kadr (docs/etap3-logika-urlopowa.md).

---

## 5. Co ten protokół wymusza po stronie tenanta

- **Endpointy API to pełnoprawne trasy** w `config/routes.php`, z własnym middleware
  uwierzytelniającym klucz instalacji — **nie** przez sesję i nie przez CSRF (to nie jest
  przeglądarka), ale z tą samą zasadą „domyślnie odmawiaj" (§10 reguł).
- **Osobny limit częstotliwości** dla tego klucza, niezależny od limitów logowania.
- **Wszystko w `sync_run`** — bez tego pierwsze pytanie klienta („czemu mój urlop nie doszedł")
  nie ma odpowiedzi.
- **Paczka = jedna transakcja**, z blokadą wiersza zatrudnienia tam, gdzie zmieniamy saldo (§4 reguł).
- Przetworzenie 500 rekordów musi zmieścić się w 30 s z zapasem — przy tej wielkości danych
  (12 tys. rekordów przy pierwszym ładowaniu, potem pojedyncze sztuki) to nie jest ryzyko,
  ale mierzymy to w testach odbioru.

---

## 5b. Rozstrzygnięcia z 19.09 (uwagi autora connectora)

- **Kursor jest nieprzezroczystym tokenem potwierdzenia**; connector go nie interpretuje,
  ale **odsyła w `start`** w polu `kursory`. Rozjazd między jego a naszym kursorem to dla nas
  sygnał do zażądania `tryb: "pelny"` — inaczej o utracie danych dowiedzielibyśmy się z braków.
- **`422` dotyczy całej paczki**, z listą pozycji w treści (§4). Nieznane pole **nie jest błędem**
  (ignorujemy i liczymy); brak pola wymaganego albo zły typ — jest.
- **Identyfikatory zawsze jako string**, unikalne w obrębie `nip` + `zbior`; `suma` z prefiksem
  `sha256:`. Ta sama postać w `zadania.zatrudnienieId` i `wyniki.zapisy[].id`.
- **`rodzaj.zrodlo`**: `"urlop"`, `"kadrowe"`, `"zasilek"` — nazewnictwo ze słownika connectora,
  nie nazwy tabel programu kadrowego.
- ⚠ **`operacja: "zmiana"` nie jest używana w etapie 1.** Zostaje w protokole (semantyka:
  usunięcie dotychczasowych `zapisy` + nowy zapis, pod tym samym `requestId`), ale nasz przepływ
  jej nie wywoła: zmiana terminu po akceptacji to **anulowanie plus nowy wniosek**, czyli
  `usuniecie` i później `zapis`. Powód jest wprost z ryzyka opisanego przez autora connectora —
  program kadrowy nie ma transakcji obejmującej usunięcie i zapis, więc `zmiana` potrafi zostawić
  wniosek bez żadnego wpisu (`ZMIANA_CZESCIOWA`). Dwa osobne zadania tego nie robią: po usunięciu
  stan jest znany, a nowy wniosek przechodzi normalną weryfikację.
- ⚠ **Flagą „uwzględnij na kolejnej liście wynagrodzeń" nie sterujemy przez API w ogóle** —
  ani teraz, ani jako pole zarezerwowane. Nie ma `wyplataNaKolejnejLiscie` i nie ma
  `operacja: "ustawWyplate"`. To ustawienie programu kadrowego i decyzja płacowa kadr;
  zapis urlopu zostawia je nietknięte.
- **Zadanie z listy `wycofane`, którego connector nie wykonał, wraca jako wynik
  `status: "wycofano"`** (bez `zapisy`) — żeby nie wisiało u nas do wygaśnięcia rezerwacji.
- **Jeden wspólny cykl dla wszystkich firm**, obsługiwanych kolejno; interwał = najkrótszy
  z otrzymanych. Limit „nie częściej niż co 30 s" dotyczy **całego connectora**, bo ogranicza
  nas hosting klienta, wspólny dla wszystkich jego firm.
- **`X-Idempotency-Key` tylko przy `POST`.**

## 6. Czego w tym API nie ma

- **Dystrybucji biblioteki API programu kadrowego.** Weryfikację pliku robi connector,
  a hosting pliku i manifestu zostaje po stronie autora connectora. Wracamy do tematu tylko
  wtedy, gdyby miało stanąć u nas — wtedy dwa osobne endpointy poza tym protokołem
  (pobranie po wersji i manifest `wersja → SHA-256`), a wcześniej odpowiedź producenta
  o prawie do redystrybucji.
- **Odczytu z programu kadrowego** — to jest wyłącznie sprawa connectora i nie wpływa na interfejs.
- **Danych wrażliwych ponad rodzaj i daty** — karta zasiłkowa nie przekazuje niczego więcej,
  a my i tak nie zapisujemy pól spoza listy.
