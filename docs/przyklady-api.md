# Przykłady żądań i odpowiedzi — API tenanta dla connectora

Komplet do zbudowania atrapy serwera. Każdy przykład to realna para żądanie/odpowiedź zgodna
z `API-TENANTA.md` (wersja protokołu 1). Wartości są przykładowe, kształt — wiążący.

Nagłówki wspólne dla wszystkich żądań, pomijane w przykładach dla czytelności:

```
Content-Type: application/json; charset=utf-8
Authorization: Bearer 9f3c…64znaki
X-Api-Key: 9f3c…64znaki
X-Protokol: 1
X-Idempotency-Key: 4c7a1f2e-…        # tylko przy POST
```

---

## 1. Przebieg udany, od początku do końca

### 1.0 `GET /api/v1/sync/stan` — test połączenia

```
GET /api/v1/sync/stan
X-Nip: 1234567009
```

```json
{
  "protokol": 1,
  "klucz": "aktywny",
  "firma": { "stan": "ok" },
  "ostatniPrzebieg": "2026-09-20T11:12:03Z"
}
```

⚠ **Ta operacja nie otwiera przebiegu.** `nip` można pominąć — wtedy `firma` i `ostatniPrzebieg`
są `null`, a odpowiedź mówi tylko o kluczu. `firma.stan` przyjmuje te same wartości co pole `stan`
w `403` (przykład 3.2): `ok`, `firma_nieznana`, `firma_zgloszona`, `firma_poza_zakresem`.

### 1.1 `POST /api/v1/sync/start`

```json
{
  "nip": "1234567009",
  "wersjaConnectora": "1.0.0",
  "wersjaR2": "10.10.33",
  "ostatniUdanyCykl": "2026-09-19T18:20:00+02:00",
  "stan": "ok",
  "stanOpis": null,
  "odciskTozsamosci": "a3f91c2e",
  "kursory": { "firma": "c-880", "pracownik": "c-881", "zatrudnienie": "c-880",
               "limit": "c-875", "nieobecnosc": "c-881", "slownik": "c-400",
               "kalendarz": "c-800" }
}
```

```json
{
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "zbiory": {
    "firma":        { "kursor": "c-880", "tryb": "zmiany" },
    "pracownik":    { "kursor": "c-881", "tryb": "zmiany" },
    "zatrudnienie": { "kursor": "c-880", "tryb": "zmiany" },
    "limit":        { "kursor": "c-875", "tryb": "zmiany" },
    "nieobecnosc":  { "kursor": "c-881", "tryb": "zmiany" },
    "slownik":      { "kursor": "c-400", "tryb": "zmiany" },
    "kalendarz":    { "kursor": "c-800", "tryb": "zmiany" }
  },
  "przegladKontrolny": false,
  "rozmiarPaczki": 500,
  "wycofane": []
}
```

### 1.2 `POST /api/v1/sync/dane` — nieobecności, w tym jedno usunięcie

```json
{
  "nip": "1234567009",
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "zbior": "nieobecnosc",
  "paczka": 1,
  "ostatnia": true,
  "rekordy": [
    { "id": "urlop:12315", "suma": "sha256:3b1f…",
      "zatrudnienieId": "207", "rodzaj": { "zrodlo": "urlop", "kod": 0 },
      "dataOd": "2026-10-05", "dataDo": "2026-10-09", "dni": 5, "minuty": 2400,
      "requestId": "wu-01J9ZC8QK4T7", "zrodloWpisu": "connector",
      "kontynuacja": null,
      "utworzono": "2026-09-19T10:09:56+02:00", "zmieniono": null },
    { "id": "zasilek:512", "suma": "sha256:9ac2…",
      "zatrudnienieId": "207", "rodzaj": { "zrodlo": "zasilek", "kod": 0 },
      "dataOd": "2026-09-14", "dataDo": "2026-09-18",
      "dni": 5, "minuty": null,
      "requestId": null, "zrodloWpisu": "kadry",
      "kontynuacja": false,
      "utworzono": null, "zmieniono": null },
    { "id": "kadrowe:17", "usuniety": true }
  ]
}
```

```json
{ "przyjeto": 3, "kursor": "c-882", "pominietePola": 0 }
```

⚠ **Pola wymagane muszą być obecne** (w rekordzie `zasilek` to `zrodloWpisu` — w wersji 0.5
tych przykładów go brakowało, był to błąd). **Klucz pola opcjonalnego można pominąć** —
traktujemy go wtedy jak `null`. Oba rekordy wyżej pokazują pełną postać, z jawnymi `null`.

### 1.6 `POST /api/v1/sync/dane` — słownik i kalendarz (powiązanie z limitem, normy)

```json
{
  "nip": "1234567009",
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "zbior": "slownik",
  "paczka": 1,
  "ostatnia": true,
  "rekordy": [
    { "id": "urlop:0", "suma": "sha256:1a2b…",
      "zrodlo": "urlop", "kod": 0, "nazwa": "urlop wypoczynkowy",
      "tylkoOdczyt": false, "doWniosku": true, "nieobecnosc": true,
      "obciazaLimit": "wypoczynkowy", "obciazaLimitZrodlo": "dane" },
    { "id": "urlop:5", "suma": "sha256:2b3c…",
      "zrodlo": "urlop", "kod": 5, "nazwa": "urlop wyp.na żądanie",
      "tylkoOdczyt": false, "doWniosku": true, "nieobecnosc": true,
      "obciazaLimit": "wypoczynkowy", "obciazaLimitZrodlo": "domyslne" },
    { "id": "urlop:6", "suma": "sha256:3c4d…",
      "zrodlo": "urlop", "kod": 6, "nazwa": "ekwiwalent za urlop",
      "tylkoOdczyt": true, "doWniosku": false, "nieobecnosc": false,
      "obciazaLimit": "wypoczynkowy", "obciazaLimitZrodlo": "dane" },
    { "id": "kadrowe:17", "suma": "sha256:4d5e…",
      "zrodlo": "kadrowe", "kod": 17, "nazwa": "urlop bezpłatny",
      "tylkoOdczyt": true, "doWniosku": false, "nieobecnosc": true,
      "obciazaLimit": null, "obciazaLimitZrodlo": null }
  ]
}
```

```json
{ "przyjeto": 4, "kursor": "c-401", "pominietePola": 0 }
```

⚠ `urlop:6` to **ekwiwalent pieniężny** (`nieobecnosc: false`), a mimo to pomniejsza pulę —
dlatego o obciążeniu limitu decyduje `obciazaLimit`, nie `nieobecnosc`. Przy źródłach `kadrowe`
i `zasilek` `obciazaLimit` jest zawsze `null`.

```json
{
  "nip": "1234567009",
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "zbior": "kalendarz",
  "paczka": 1,
  "ostatnia": true,
  "rekordy": [
    { "id": "kal:2:2026", "suma": "sha256:5e6f…",
      "kalendarzId": "2", "nazwa": "Główny", "rok": 2026,
      "indywidualne": false,
      "dniNierobocze": ["2026-01-01", "2026-01-06"],
      "normaUrlopowaMin": 480, "normaDobowaMin": 480 }
  ]
}
```

⚠ `normaUrlopowaMin` jest **dzielnikiem minut na dni**. Brak pola oznacza po naszej stronie
wartość zapasową 480 min — działa, ale jest odnotowane w opisie przebiegu i na `/diagnostyka`.

### 1.3 `GET /api/v1/sync/zadania`

```
GET /api/v1/sync/zadania?przebiegId=8d41e0b2-…&limit=20
X-Nip: 1234567009
```

```json
{
  "zadania": [
    { "requestId": "wu-01J9ZD11M2A8", "operacja": "zapis",
      "zatrudnienieId": "207",
      "rodzaj": { "zrodlo": "urlop", "kod": 0 },
      "dataOd": "2026-10-29", "dataDo": "2026-11-03",
      "zapisy": [] },
    { "requestId": "wu-01J9ZD44P7C1", "operacja": "usuniecie",
      "zatrudnienieId": "311",
      "rodzaj": { "zrodlo": "urlop", "kod": 5 },
      "dataOd": "2026-09-22", "dataDo": "2026-09-22",
      "zapisy": [ { "id": "urlop:12280" } ] }
  ]
}
```

### 1.4 `POST /api/v1/sync/wyniki`

Pierwszy wniosek rozbity przez przełom miesiąca na dwa wpisy, drugi usunięty.

```json
{
  "nip": "1234567009",
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "wyniki": [
    { "requestId": "wu-01J9ZD11M2A8", "status": "zapisano", "kod": null, "komunikat": null,
      "zapisy": [
        { "id": "urlop:12330", "dataOd": "2026-10-29", "dataDo": "2026-10-31", "dni": 2, "minuty": 960 },
        { "id": "urlop:12331", "dataOd": "2026-11-02", "dataDo": "2026-11-03", "dni": 2, "minuty": 960 }
      ] },
    { "requestId": "wu-01J9ZD44P7C1", "status": "zapisano", "kod": null, "komunikat": null,
      "zapisy": [] }
  ]
}
```

```json
{ "przyjeto": 2 }
```

### 1.5 `POST /api/v1/sync/koniec`

```json
{
  "nip": "1234567009",
  "przebiegId": "8d41e0b2-3c77-4a51-9f02-77b1c6e4a910",
  "wyslano":   { "nieobecnosc": 2, "limit": 0 },
  "usuniete":  { "nieobecnosc": 1 },
  "odrzucone": [],
  "zadaniaPobrane": 2,
  "wynikiWyslane": 2,
  "pominietePola": 0,
  "czasMs": 1840
}
```

```json
{ "nastepnyInterwal": 60, "pelnyPrzegladWymagany": false }
```

---

## 2. Wyniki nietypowe

### 2.1 Zapisano z ostrzeżeniem (limit wypoczynkowy przekroczony)

```json
{ "requestId": "wu-01J9ZE07R3D2", "status": "zapisanoZOstrzezeniem",
  "kod": "OSTRZ_LIMIT_PRZEKROCZONY",
  "komunikat": "TESTOWSKI JAN: limit urlopu wypoczynkowego za rok 2026 został przekroczony",
  "zapisy": [ { "id": "urlop:12344", "dataOd": "2026-12-28", "dataDo": "2026-12-30",
                "dni": 3, "minuty": 1440 } ] }
```

Wniosek przechodzi w `confirmed` z flagą ostrzeżenia; alert do kadr.

### 2.2 Błąd trwały — limit na żądanie

```json
{ "requestId": "wu-01J9ZE55S8E3", "status": "blad",
  "kod": "R2_NA_ZADANIE_PRZEKROCZONY",
  "komunikat": "Limit urlopu na żądanie (max.4 dni) został przekroczony",
  "zapisy": [] }
```

Wniosek → `conflict`, dni wracają do puli przy najbliższym pobraniu limitu.

### 2.3 Błąd przejściowy

```json
{ "requestId": "wu-01J9ZE77T1F4", "status": "bladPrzejsciowy",
  "kod": "CONNECTOR_TIMEOUT", "komunikat": "Program kadrowy nie odpowiedział",
  "zapisy": [] }
```

Zadanie wraca do wydania w kolejnym cyklu, z licznikiem prób.

### 2.4 Zadanie wycofane, niewykonane

```json
{ "requestId": "wu-01J9ZE99U5G5", "status": "wycofano",
  "kod": null, "komunikat": null, "zapisy": [] }
```

### 2.5 `ZMIANA_CZESCIOWA` — stan po nieudanej zmianie (nieużywane w etapie 1)

```json
{ "requestId": "wu-01J9ZEBBV2H6", "status": "blad",
  "kod": "ZMIANA_CZESCIOWA",
  "komunikat": "Nowy zapis odrzucony, przywrócenie poprzednich wpisów nie powiodło się",
  "zapisy": [] }
```

Obsługiwane jako najwyższy priorytet: `conflict`, alert do kadr, jednoznaczny komunikat
u pracownika. W etapie 1 operacja `zmiana` nie jest wydawana, więc ten wynik nie powinien wystąpić.

---

## 3. Błędy protokołu

### 3.1 `422` — paczka odrzucona w całości

```json
{ "blad": {
    "kod": "REKORDY_NIEPOPRAWNE",
    "komunikat": "Paczka zawiera rekordy niezgodne ze schematem",
    "zdarzenieId": "a1b2c3d4",
    "pozycje": [
      { "id": "urlop:12315", "kod": "POLE_WYMAGANE", "pole": "dataDo" },
      { "id": "zasilek:512", "kod": "ZLY_TYP", "pole": "dni" }
    ] } }
```

### 3.2 `403` — firma niezatwierdzona

```json
{ "blad": { "kod": "FIRMA_NIEZATWIERDZONA",
            "komunikat": "Firma nie jest jeszcze zgłoszona — wyślij zgloszenie-firmy",
            "zdarzenieId": "b2c3d4e5",
            "stan": "firma_nieznana" } }
```

Zatrzymuje **tylko ten strumień**. Pozostałe firmy pracują dalej.

⚠ Pole `stan` przyjmuje `firma_nieznana` (wyślij `zgloszenie-firmy`), `firma_zgloszona`
(administrator ma ją zatwierdzić w aplikacji) albo `firma_poza_zakresem` (administrator ma dopiąć
ją do tego klucza). Nazwy firmy w odpowiedzi nie ma — jest w aplikacji, po stronie administratora.
`komunikat` odpowiada stanowi i jest pokazywany człowiekowi przez connector.

### 3.3 `POST /api/v1/sync/zgloszenie-firmy`

```json
{ "nip": "9876543210", "nazwa": "Firma B sp. z o.o." }
```

```json
{ "status": "oczekuje" }
```

Kod `202`. Powtórzone zgłoszenie tego samego NIP-u nie tworzy duplikatu, ale odpowiedź
mówi, co się z nim stało:

| Kod | Treść | Znaczenie |
|---|---|---|
| `202` | `{ "status": "oczekuje" }` | czeka na decyzję administratora |
| `200` | `{ "status": "zatwierdzona" }` | NIP jest już w zakresie klucza — wróć do `start` |
| `200` | `{ "status": "odrzucona" }` | administrator odrzucił — przestań zgłaszać ten NIP |

### 3.8 `503` — przerwa techniczna (baza zajęta)

```
HTTP/1.1 503 Service Unavailable
Retry-After: 30
```

```json
{ "blad": { "kod": "PRZERWA_TECHNICZNA", "komunikat": "Baza zajęta, powtórz paczkę",
            "zdarzenieId": "aa11bb22" } }
```

Cała paczka odrzucona w transakcji — nic nie zostało zapisane połowicznie. Powtórz to samo
żądanie po czasie z `Retry-After`.

### 3.10 `403` — zmieniony odcisk tożsamości

```json
{
  "blad": {
    "kod": "TOZSAMOSC_ZMIENIONA",
    "komunikat": "Connector zgłasza inną tożsamość osób niż dotychczas — strumień tej firmy zatrzymany do decyzji administratora",
    "zdarzenieId": "a1b2c3d4",
    "odcisk": "b7d0e1f2"
  }
}
```

⚠ Odpowiedź na `start`, **zanim** pójdzie jakakolwiek paczka: żaden rekord tej firmy nie zostaje
przyjęty. Pozostałe firmy tego klucza pracują normalnie. Odblokowanie: administrator akceptuje nowy
odcisk w aplikacji albo connector wraca z poprzednim sekretem.

### 3.9 `422` — NIP o błędnej sumie kontrolnej

```json
{ "blad": { "kod": "NIP_NIEPOPRAWNY", "komunikat": "Numer NIP jest niepoprawny",
            "zdarzenieId": "bb22cc33" } }
```

⚠ **NIP-y w tych przykładach mają poprawną sumę kontrolną** (`1234567009` dla Firmy A,
`9876543210` dla Firmy B, `5252525006` jako trzeci do testów wielofirmowych). Zmienił się
**tylko numer Firmy A**: do wersji 0.7 było tam `1234567890`, które nasz serwer odrzuci
z `NIP_NIEPOPRAWNY`. `9876543210` sumę kontrolną ma poprawną i zostaje bez zmian.

### 3.4 `401` — klucz nie działa, z podanym powodem

```json
{ "blad": { "kod": "KLUCZ_WYGASL",
            "komunikat": "Klucz został zastąpiony nowym, który przejął synchronizację — wpisz nowy klucz w konfiguracji connectora",
            "zdarzenieId": "c3d4e5f6",
            "powod": "zastapiony_nowym_kluczem" } }
```

Zatrzymuje **cały connector**.

⚠ **Pole `powod` doszło 21.09.2026; kody odpowiedzi zostały bez zmian.** Dokładanie pola nie jest
zmianą wersji protokołu — connector, który go nie zna, ma je zignorować. Powód istnieje po to,
żeby administrator u klienta zobaczył prawdziwą przyczynę, a nie „klucz wygasł” przy kluczu
unieważnionym ręcznie:

```json
{ "blad": { "kod": "KLUCZ_NIEPRAWIDLOWY",
            "komunikat": "Klucz unieważniony przez administratora — poproś o wydanie nowego",
            "zdarzenieId": "c3d4e5f7",
            "powod": "uniewazniony_przez_administratora" } }
```

Klucz **nieznany** (literówka, klucz z innej instalacji) zostaje bez `powod` — nie mamy czego podać.

### 3.5 `429` — za szybko

```
HTTP/1.1 429 Too Many Requests
Retry-After: 45
```

```json
{ "blad": { "kod": "ZA_SZYBKO", "komunikat": "Zwolnij", "zdarzenieId": "d4e5f6a7" } }
```

### 3.6 `413` — paczka za duża

```json
{ "blad": { "kod": "PACZKA_ZA_DUZA", "komunikat": "Przekroczony limit",
            "zdarzenieId": "e5f6a7b8", "maks": { "rekordy": 500, "bajty": 2097152 } } }
```

### 3.7 `409` — niezgodna wersja protokołu

```json
{ "blad": { "kod": "WERSJA_PROTOKOLU", "komunikat": "Nieobsługiwana wersja",
            "zdarzenieId": "f6a7b8c9", "obslugiwane": [1] } }
```

---

## 4. Pełne ładowanie i przegląd kontrolny

### 4.1 `start` żądający pełnego ładowania

```json
{
  "przebiegId": "…",
  "zbiory": {
    "firma":       { "kursor": null, "tryb": "pelny" },
    "pracownik":   { "kursor": null, "tryb": "pelny" },
    "nieobecnosc": { "kursor": null, "tryb": "pelny" }
  },
  "przegladKontrolny": false,
  "rozmiarPaczki": 500,
  "wycofane": []
}
```

### 4.2 `POST /api/v1/sync/przeglad`

```json
{
  "nip": "1234567009", "przebiegId": "…", "zbior": "nieobecnosc",
  "paczka": 2, "ostatnia": true,
  "pozycje": [
    { "id": "urlop:12315", "suma": "sha256:3b1f…" },
    { "id": "urlop:12330", "suma": "sha256:77de…" }
  ]
}
```

```json
{ "przyjeto": 412, "kursor": "c-883" }
```

(Przyklad skrocony: pokazane sa dwie pozycje z czterystu dwunastu. `przyjeto` to zawsze liczba
pozycji w tej paczce, tak samo jak w `dane`.)

⚠ Usunięcia domykamy **dopiero po paczce z `ostatnia: true`**. Przegląd przerwany w połowie
nie kasuje u nas niczego.
