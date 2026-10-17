# Rekordy wymiany connector → wezurlop

**Wersja 0.7, 19.09.2026.** (0.7: zakres osób — tylko zatrudnieni na umowę o pracę; znaczenie usunięć.) Kształt rekordów w paczkach `dane`, zbiór po zbiorze, z typem i obowiązkowością każdego pola. Koperty, operacje, zadania i wyniki — według `API-TENANTA.md` i `PRZYKLADY-API.md`.

Obowiązkowość wynika z danych: sprawdziliśmy ją na kopiach baz dwóch firm klienta odniesienia (ok. 16 tys. rekordów). Pole jest **wymagane**, gdy w poprawnym rekordzie zawsze ma wartość. **Opcjonalne**, gdy bywa `null` w rekordzie, który jest poprawny i trzeba go przyjąć.

---

## Zakres danych

Connector wysyła **wyłącznie pracowników aktualnie zatrudnionych na umowę o pracę** — i tylko ich zatrudnienia, limity i nieobecności. Zleceniobiorcy, osoby na umowie o dzieło, zwolnieni i kartoteki usunięte w programie kadrowym **nie są wysyłane w ogóle**.

- Pracownik **na wypowiedzeniu** (znana data zwolnienia w przyszłości) jest w zakresie do dnia zwolnienia włącznie — przychodzi z `zwolniony: true` i `dataZwolnienia`.
- **Nieobecności: od 1 stycznia poprzedniego roku.** Starsze nie są wysyłane.
- **Limity: rok bieżący** (zawsze, także `naliczony: false`), a poprzedni i następny — jeśli kadry je założyły.

**Znaczenie usunięć.** Rekord `{ "id": "…", "usuniety": true }` przychodzi, gdy rekord zniknął z zakresu. W praktyce:

| Usunięty rekord | Najczęstsza przyczyna |
|---|---|
| `pracownik` (razem z jego `zatrudnienie`, `limit`, `nieobecnosc`) | **pracownik przestał być zatrudniony na umowę o pracę** — minął dzień zwolnienia, zmiana na zlecenie albo usunięcie kartoteki w kadrach |
| `nieobecnosc` pracownika, który nadal jest w zakresie | kadry usunęły wpis w programie kadrowym |
| `nieobecnosc` / `limit` z najstarszego roku | przesunięcie okna dat w styczniu |

Usunięcie pracownika **nie oznacza, że kadry skasowały jego dane** — tylko że nie dotyczy go już system wniosków. Historię jego wniosków po Waszej stronie warto zachować.

---

## Zasady wspólne

| | |
|---|---|
| `nip`, `zbior` | niesie koperta paczki — w rekordach ich nie ma |
| `id` | **string, wymagane**, unikalne w obrębie `nip + zbior` |
| `suma` | **string, wymagane**, `"sha256:<64 znaki hex>"`; inna suma przy tym samym `id` = zmiana rekordu |
| pole opcjonalne bez wartości | przychodzi jako **`null`** (klucz jest zawsze obecny) |
| daty | string `"YYYY-MM-DD"`, bez godziny i strefy |
| znaczniki czasu | string ISO 8601 z przesunięciem, np. `"2026-09-19T16:25:56+02:00"` |
| liczby | **całkowite** — z jednym wyjątkiem: `zatrudnienie.etat` (dziesiętna) |
| identyfikatory w polach (`pracownikId`, `zatrudnienieId`, `kalendarzId`) | **string**, ta sama postać co `id` rekordu, na który wskazują |
| wartości logiczne | `true` / `false`; `null` tylko tam, gdzie tabela to dopuszcza |

**Usunięcie rekordu** — w tym samym zbiorze, bez `suma` i bez pozostałych pól:

```json
{ "id": "urlop:12315", "usuniety": true }
```

**Nigdy nie wysyłamy:** PESEL (w żadnej postaci), danych płacowych, kodów chorób, numerów zwolnień lekarskich, kwot zasiłków.

---

## `firma`

```json
{ "id": "1234567890", "suma": "sha256:…", "nazwa": "ALFA" }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | NIP, 10 cyfr bez kresek |
| `nazwa` | string | tak | nazwa firmy z programu kadrowego |

---

## `pracownik`

```json
{ "id": "207", "suma": "sha256:…",
  "osobaId": "hmac:3f9a…",
  "nazwisko": "TESTOWSKI", "imie": "JAN",
  "identyfikator": "397",
  "zwolniony": false, "dataZwolnienia": null,
  "skasowany": false,
  "umowy": { "oPrace": true, "zlecenie": false, "oDzielo": false } }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | kartoteka w firmie; ponowne zatrudnienie czasem tworzy nową |
| `osobaId` | string | **nie** | ten sam dla tej samej osoby w różnych firmach — do łączenia kont; nieodwracalny. `null` przy kartotece bez PESEL — w obecnych danych nie występuje wśród zatrudnionych, ale bywa u cudzoziemców |
| `nazwisko` | string | tak | |
| `imie` | string | tak | |
| `identyfikator` | string | **nie** | numer kadrowy — pusty w większości kartotek, tylko do wyświetlania |
| `zwolniony` | bool | tak | `true` tylko u osób na wypowiedzeniu (data zwolnienia jeszcze nie minęła) — po niej pracownik przychodzi jako usunięty |
| `dataZwolnienia` | date | **nie** | data ostatniego dnia pracy; `null` = brak wypowiedzenia |
| `skasowany` | bool | tak | w praktyce zawsze `false` — kartoteki usunięte w kadrach są poza zakresem |
| `umowy` | obiekt | tak | trzy pola bool, wszystkie wymagane: `oPrace` (zawsze `true` w zakresie), `zlecenie`, `oDzielo` — osoba może mieć równolegle inną umowę |

---

## `zatrudnienie`

Urlopy i limity wiszą pod zatrudnieniem, nie pod pracownikiem.

```json
{ "id": "207", "suma": "sha256:…",
  "pracownikId": "207",
  "aktualne": true, "domyslne": true,
  "etat": 0.75,
  "dataRozpoczecia": "2004-07-01", "dataZakonczenia": null,
  "kalendarzId": "2" }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | zmienia się przy ponownym zatrudnieniu — stare urlopy zostają pod starym |
| `pracownikId` | string | tak | → `pracownik.id` |
| `aktualne` | bool | tak | w zakresie zawsze `true` — wysyłamy tylko aktualne zatrudnienia |
| `domyslne` | bool | tak | pracownik może mieć kilka aktualnych zatrudnień; to wskazuje główne |
| `etat` | **liczba dziesiętna** | **nie** | `1`, `0.5`, `0.75`; ułamki okresowe zaokrąglone do 4 miejsc (`0.6667`). `null`, gdy program kadrowy nie ma umowy przypiętej do zatrudnienia — rzadkie, ale możliwe |
| `dataRozpoczecia` | date | **nie** | z umowy o pracę — `null` jak wyżej |
| `dataZakonczenia` | date | **nie** | koniec tego zatrudnienia; `null` = trwa albo brak umowy o pracę |
| `kalendarzId` | string | **nie** | → `kalendarz`. W danych **aktualne zatrudnienia mają go zawsze**; `null` tylko w zakończonych |

---

## `limit`

Jeden rekord na zatrudnienie, rok i rodzaj.

```json
{ "id": "207:2026:wypoczynkowy", "suma": "sha256:…",
  "zatrudnienieId": "207", "rok": 2026, "rodzaj": "wypoczynkowy",
  "naliczony": true,
  "wymiarMin": 12480, "zaleglyMin": 6120, "dostepneNaStartMin": 18600,
  "wykorzystaneMin": 7200, "korektyMin": 0, "pozostaloMin": 11400,
  "naZadanieWykorzystaneDni": 0 }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | `"<zatrudnienieId>:<rok>:<rodzaj>"` |
| `zatrudnienieId` | string | tak | |
| `rok` | int | tak | |
| `rodzaj` | string | tak | `wypoczynkowy` (obejmuje też na żądanie) albo `opieka_art188` |
| `naliczony` | bool | tak | `false` = kadry nie założyły jeszcze limitu na ten rok |
| `wymiarMin` | int | **nie** | `null` gdy `naliczony: false` |
| `zaleglyMin` | int | **nie** | jw. |
| `dostepneNaStartMin` | int | **nie** | jw.; = `wymiarMin + zaleglyMin` |
| `wykorzystaneMin` | int | **nie** | jw. |
| `korektyMin` | int | **nie** | jw.; ręczne korekty kadr, **może być ujemne** |
| `pozostaloMin` | int | **nie** | jw.; `dostepneNaStartMin − wykorzystaneMin + korektyMin`; **może być ujemne** (przekroczony limit) |
| `naZadanieWykorzystaneDni` | int | **nie** | tylko przy `wypoczynkowy`; przy `opieka_art188` — `null` |

Wszystkie wartości `…Min` w **minutach**. Prezentacja jak w programie kadrowym: dni = `min ÷ 480`, godziny = `(min mod 480) ÷ 60` — **zawsze 480, także przy niepełnym etacie**.

Kadry zakładają limity na nowy rok ręcznie, zwykle ok. 22 stycznia — do tego czasu `naliczony: false`. Limitu równego zero nie ma w danych.

---

## `nieobecnosc`

```json
{ "id": "urlop:12315", "suma": "sha256:…",
  "zatrudnienieId": "207",
  "rodzaj": { "zrodlo": "urlop", "kod": 0 },
  "dataOd": "2026-10-29", "dataDo": "2026-10-31",
  "dni": 2, "minuty": 960,
  "requestId": "wu-01J9ZC8QK4T7", "zrodloWpisu": "connector",
  "kontynuacja": null,
  "utworzono": "2026-09-19T10:09:56+02:00", "zmieniono": null }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | `"<zrodlo>:<numer>"` — `urlop:…`, `kadrowe:…`, `zasilek:…` |
| `zatrudnienieId` | string | tak | |
| `rodzaj` | obiekt | tak | `zrodlo` (string: `urlop` / `kadrowe` / `zasilek`) i `kod` (int) — oba wymagane; nazwa w `slownik` |
| `dataOd`, `dataDo` | date | tak | |
| `dni` | int | **nie** | `null` w pojedynczych starych wpisach |
| `minuty` | int | **nie** | **zawsze `null` przy `zasilek`**; przy pozostałych `null` w pojedynczych starych wpisach |
| `requestId` | string | **nie** | tylko wpis zapisany przez connector na zlecenie z wezurlop |
| `zrodloWpisu` | string | tak | `connector` albo `kadry` |
| `kontynuacja` | bool | **nie** | tylko `zasilek` (przy innych `null`): jedno zwolnienie to często kilka kolejnych rekordów — do prezentacji sklejać |
| `utworzono` | znacznik | **nie** | pusty przy starszych wpisach; informacyjnie |
| `zmieniono` | znacznik | **nie** | `null` = wpis nigdy nie edytowany; informacyjnie |

**`zasilek` — wyłącznie rodzaj i daty.** Rekomendacja: współpracownicy i przełożeni widzą „nieobecny", konkretny rodzaj tylko kadry — to dane o zdrowiu.

**Pomijać w kalendarzu:** `urlop` z kodem 6 i 8 (ekwiwalenty — rozliczenie płacowe, nie nieobecność).

---

## `slownik`

Nazwy rodzajów nieobecności — nie zaszywajcie kodów u siebie.

```json
{ "id": "urlop:5", "suma": "sha256:…",
  "zrodlo": "urlop", "kod": 5, "nazwa": "urlop wyp. na żądanie",
  "tylkoOdczyt": false, "doWniosku": true, "nieobecnosc": true }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | `"<zrodlo>:<kod>"` |
| `zrodlo` | string | tak | `urlop` / `kadrowe` / `zasilek` |
| `kod` | int | tak | |
| `nazwa` | string | tak | |
| `tylkoOdczyt` | bool | tak | `true` dla całego `zasilek` i `kadrowe` — pracownik tego nie wnioskuje |
| `doWniosku` | bool | tak | etap 1: tylko `urlop` 0 (wypoczynkowy) i 5 (na żądanie) |
| `nieobecnosc` | bool | tak | `false` dla ekwiwalentów (`urlop` 6 i 8) |

---

## `kalendarz`

Do **orientacyjnego** liczenia dni przy składaniu wniosku. Wiążące dni i minuty przychodzą w wyniku zapisu.

```json
{ "id": "2:2026", "suma": "sha256:…",
  "kalendarzId": "2", "nazwa": "Główny", "rok": 2026,
  "indywidualne": true,
  "dniNierobocze": ["2026-01-01", "2026-01-03", "…"] }
```

| Pole | Typ | Wymagane | Znaczenie |
|---|---|---|---|
| `id` | string | tak | `"<kalendarzId>:<rok>"` |
| `kalendarzId` | string | tak | |
| `nazwa` | string | tak | |
| `rok` | int | tak | |
| `indywidualne` | bool | tak | `true` = pracownik może mieć własny harmonogram, więc wyliczenie orientacyjne czasem się rozjedzie |
| `dniNierobocze` | tablica dat | tak | niedziele, święta i dni wolne; może być pusta |

Nie liczcie minut jako dni × 480 — zależą od etatu i harmonogramu.

---

## Kody wyników zapisu

Decyduje `status`, `kod` jest do wyświetlenia. Lista może się wydłużyć — nieznany kod przy znanym statusie jest poprawny.

| `kod` | Znaczenie | `status` |
|---|---|---|
| `OSTRZ_PRZELOM_MIESIACA` | urlop rozbity na części per miesiąc | `zapisanoZOstrzezeniem` |
| `OSTRZ_LIMIT_PRZEKROCZONY` | przekroczony limit wypoczynkowy — **program kadrowy go nie blokuje** | `zapisanoZOstrzezeniem` |
| `OSTRZ_NA_ZADANIE_WYKORZYSTANY` | wykorzystany 4. dzień na żądanie | `zapisanoZOstrzezeniem` |
| `OSTRZ_INNE` | inne ostrzeżenie programu kadrowego | `zapisanoZOstrzezeniem` |
| `R2_KOLIZJA` | daty pokrywają się z istniejącą nieobecnością | `blad` |
| `R2_NA_ZADANIE_PRZEKROCZONY` | ponad 4 dni na żądanie w roku — odrzucone | `blad` |
| `R2_PRACOWNIK_NIEZNALEZIONY`, `R2_ZATRUDNIENIE_NIEZNALEZIONE` | pracownik / zatrudnienie nie istnieje albo nie jest aktualne | `blad` |
| `R2_WPIS_NIE_DO_USUNIECIA` | wskazany wpis nie jest okresem urlopu | `blad` |
| `R2_NIEDOSTEPNE` | zapis chwilowo niemożliwy | `bladPrzejsciowy` |
| `R2_KONFIGURACJA` | problem instalacji u klienta — wymaga administratora | `blad` |
| `R2_NIEZNANY` | inny komunikat programu kadrowego | `blad` |
| `ZADANIE_NIEPOPRAWNE` | zadanie niezgodne z protokołem (np. `requestId`, daty) | `blad` |
| `CONNECTOR_TIMEOUT` | zapis przerwany po limicie czasu | `bladPrzejsciowy` |
| `CONNECTOR_AWARIA` | awaria connectora w trakcie zadania | `bladPrzejsciowy` |
| `OPERACJA_NIEOBSLUGIWANA` | operacja, której connector nie wykonuje (`zmiana` w etapie 1) | `blad` |
| `ZMIANA_CZESCIOWA` | tylko `zmiana` — nieużywana w etapie 1 | `blad` |

`komunikat` — oryginalny tekst programu kadrowego; może zawierać imię i nazwisko pracownika.
