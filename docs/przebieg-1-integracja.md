# Pierwszy przebieg integracji connector ↔ API — plan i kryteria

Dokument opisuje **pierwsze uruchomienie synchronizacji na prawdziwych danych R2**.
Cel jest diagnostyczny, nie prezentacyjny: chcemy złamać nasze założenia o danych kadrowych
zanim zbudujemy na nich logikę urlopową (tura B).

Miejsce: **instancja deweloperska `tenant.wezurlop.pl`**. Nie home.pl — tam idzie przebieg
drugi, jako test instalatora i diagnostyki zdalnej, dopiero gdy ten będzie zielony.

---

## 1. Kto wydaje klucz API

**Klucz generuje administrator tenanta** na ekranie `/admin/integracja`, w instalacji klienta.
Nie my, nie panel, nie instalator — i nie ma żadnej ścieżki, którą klucz mógłby powstać poza
tym ekranem.

Tak to działa i tak zostaje:

- klucz to **64 znaki heksadecymalne** z `random_bytes`, w bazie **wyłącznie jako hash**
  (SHA-256, porównanie przez `hash_equals`),
- **pokazywany dokładnie raz**, przy wygenerowaniu: odczyt jednorazowy, TTL 5 minut, po czym
  znika z wiersza sesji. Zgubiony klucz **nie jest do odzyskania** — generuje się nowy,
  a stary przestaje działać,
- klucz ma **listę dozwolonych NIP-ów**. Żądanie z NIP-em spoza listy dostaje `403` i zatrzymuje
  **tylko ten strumień**; zły albo wygasły klucz to `401`, który zatrzymuje **cały connector**,
- nowa firma wchodzi w zakres klucza wyłącznie przez `zgloszenie-firmy` **plus jawną decyzję
  administratora** na ekranie. Automatu nie ma,
- wygenerowanie, unieważnienie i zmiana zakresu idą do `event_log`. ⚠ Sam klucz **nigdy**
  nie trafia do logu, maila ani komunikatu błędu.

**W tym przebiegu** administratorem tenanta jesteś Ty. Klucz przekazujesz programiście
connectora **innym kanałem niż adres instancji** (menedżer haseł albo osobna wiadomość),
a po zakończeniu przebiegu go unieważniasz — testowy klucz nie ma prawa przeżyć testu.

## 2. Zakres — co sprawdzamy, a czego nie

| Sprawdzamy | Nie sprawdzamy |
|---|---|
| kierunek connector → aplikacja w całości | ekranów pracownika i przełożonego (tura B) |
| kierunek aplikacja → R2 na zadaniach **z przygotowanych wniosków** | składania i akceptacji wniosku przez człowieka |
| identyfikację osób, kartotek i zatrudnień na prawdziwych danych | salda, eskalacji, PDF |
| kursory, przegląd kontrolny, semantykę usunięć | wydajności przy skali większej niż dane klienta |

⚠ To **nie jest demo dla klienta**. Poza tabelami i stroną diagnostyczną nie ma czego pokazać.

## 3. Przygotowanie

**Po stronie Krzysztofa:**

1. Reguła zapory: dostęp do `tenant.wezurlop.pl` (443) **dla adresu IP programisty connectora**,
   nie dla świata.
2. Wygenerowanie klucza na `/admin/integracja`, przekazanie osobnym kanałem.
3. Zatwierdzenie NIP-u firmy testowej — celowo **dopiero po** pierwszej próbie connectora,
   żeby przejść ścieżkę `403 → zgloszenie-firmy → zatwierdzenie` na żywo.

**Po stronie agenta API:**

4. **Czysta baza**: migracje od zera, **bez seeda**. Dane z seeda mają fikcyjne NIP-y i osoby;
   zmieszane z prawdziwymi utrudnią ocenę wyniku.
5. ⚠ **Narzędzie `bin/przygotuj-wniosek.php`** — brakujący element. Ekranu składania wniosku
   jeszcze nie ma, a bez zaakceptowanego wniosku nie powstanie żadne zadanie, czyli drugi
   kierunek zostanie niesprawdzony. Narzędzie ma tworzyć wniosek w stanie `approved`
   dla **wskazanego zatrudnienia z danych już zsynchronizowanych** (parametry: id zatrudnienia,
   rodzaj, daty), przechodząc przez tę samą ścieżkę co aplikacja — nie `INSERT`-em do tabeli.
   Narzędzie leży w `dev/`, nie wchodzi do paczki klienta.
6. `APP_ENV=dev`, ale **`display_errors=Off`** i pełne logowanie z identyfikatorami zdarzeń —
   chcemy ćwiczyć diagnozę taką, jaka będzie u klienta.
7. Pusty `sync_run`, `sync_conflict`, `event_log` przed startem (punkt odniesienia).

**Po stronie programisty connectora:**

8. ⚠ **Baza R2 testowa, nie produkcyjna klienta.** Drugi kierunek zapisuje urlopy do programu
   kadrowego i pierwszy przebieg nie jest miejscem na naukę, co się stanie z prawdziwą kartoteką.
9. Adres instancji, klucz, jedna firma na start.

## 4. Scenariusze

Kolejność jest istotna — każdy następny korzysta ze stanu poprzedniego.

**T0 — uwierzytelnienie i wejście firmy do zakresu**

| Krok | Oczekiwane |
|---|---|
| `start` ze złym kluczem | `401 KLUCZ_NIEPOPRAWNY`, connector zatrzymuje wszystko |
| `start` z dobrym kluczem, NIP niezatwierdzony | `403 FIRMA_NIEZATWIERDZONA`, **bez `przebiegId`** |
| `zgloszenie-firmy` | `202 { "status": "oczekuje" }`, zgłoszenie widoczne u administratora |
| powtórzone `zgloszenie-firmy` | to samo, **bez duplikatu** |
| po zatwierdzeniu przez administratora | `200 { "status": "zatwierdzona" }`, `start` przechodzi |
| NIP z błędną sumą kontrolną | `422 NIP_NIEPOPRAWNY` |

**T1 — pełne ładowanie.** Wszystkie zbiory w `tryb: "pelny"`. Sprawdzamy: liczby rekordów
zgodne z tym, co connector widzi w R2; czas każdej paczki **poniżej 30 s z zapasem**;
`pominietePola` = 0 albo wyjaśnione; zero wpisów w `sync_conflict`.

**T2 — cykl bez zmian.** Natychmiast drugi przebieg. Oczekiwane: zbiory w `tryb: "zmiany"`,
zero rekordów, kursory bez ruchu, brak konfliktów. ⚠ Jakikolwiek rekord tutaj znaczy, że jedna
ze stron uznaje za zmianę coś, co zmianą nie jest — i jest to znalezisko, nie szum.

**T3 — zmiany w R2.** Kadry robią w programie kadrowym trzy rzeczy: dodają nieobecność,
zmieniają etat istniejącemu pracownikowi, dopisują nowego pracownika. Oczekiwane: przychodzą
**wyłącznie te trzy rekordy**, trafiają na właściwe osoby, kalendarz pokazuje nieobecność.

**T4 — usunięcie i przegląd kontrolny.** Kadry kasują jedną nieobecność. Przebieg
z `przegladKontrolny: true`. Oczekiwane: usunięcie wykryte **dopiero po paczce z `ostatnia: true`**,
zapis w `sync_run`. ⚠ Powtórzyć wariant z przerwanym przeglądem (connector ubity w połowie):
**nic nie może zostać skasowane**.

**T5 — zadanie zapisu.** Narzędziem z punktu 5 przygotowujemy wniosek `approved` dla realnego
zatrudnienia, na termin **przechodzący przez przełom miesiąca**. Oczekiwane: connector pobiera
zadanie z `zadania`, zapisuje w R2, odsyła `wyniki` ze statusem `zapisano` i **dwoma wpisami
w `zapisy`**; wniosek → `confirmed`, dni i minuty policzone z sumy `zapisy`, a nie z żądania.

**T6 — zadanie usunięcia.** Anulowanie wniosku z T5. Oczekiwane: zadanie `usuniecie`
ze wskazaniem obu wpisów, wynik `zapisano`, wpisy znikają z R2 i z kalendarza.

**T7 — wyniki nietypowe.** Dwa przypadki wymuszone na danych: przekroczony limit urlopu
na żądanie (R2 **blokuje**) → `blad` z `R2_NA_ZADANIE_PRZEKROCZONY` → wniosek w `conflict`;
przekroczony limit wypoczynkowy (R2 **nie blokuje**) → `zapisanoZOstrzezeniem` → `confirmed`
z flagą i alertem do kadr.

**T8 — odporność.** Connector ubity w połowie paczki i uruchomiony ponownie: powtórka
z tym samym `X-Idempotency-Key` **nie tworzy duplikatów**. Wymuszone przekroczenie tempa:
`429` z `Retry-After`, po którym connector zwalnia i kończy przebieg normalnie.

**T9 — dwie firmy.** Drugi NIP w zakresie klucza. ⚠ Jeśli w danych klienta ta sama osoba
występuje w obu firmach, to jest najważniejszy test całej identyfikacji: **jedna osoba,
dwa zatrudnienia**, ten sam `osobaId`, dane nie przeciekają między strumieniami.

## 5. Co ma zostać zmierzone i zapisane

Agent API po przebiegu raportuje liczby, nie wrażenia: rekordy per zbiór, czas najdłuższej
paczki i całego cyklu, liczba żądań, `pominietePola`, liczniki `503` i `429`, wpisy
w `sync_conflict` z powodami, rekordy oczekujące (wskazujące na coś, czego jeszcze nie ma),
oraz **każdy przypadek, w którym prawdziwe dane nie pasują do `KSZTALT-DANYCH-R2.md`**.

Ta ostatnia pozycja jest właściwym celem przebiegu. Wszystko inne już raz przeszło na symulatorze.

## 6. Kryteria zaliczenia

1. T0–T4 przechodzą bez poprawek w kodzie **albo** poprawki są opisane i powtórzony przebieg
   jest czysty.
2. Żadna paczka nie zbliża się do 30 s bliżej niż na połowę budżetu.
3. `sync_conflict` zawiera **wyłącznie** konflikty wymuszone w T7.
4. Żaden PESEL, klucz ani token nie pojawia się w logu, odpowiedzi ani na ekranie.
5. Rozbieżności między prawdziwymi danymi R2 a dokumentem kształtu danych są **spisane**,
   nawet te, które niczego nie zepsuły.

## 7. Zasada na czas przebiegu

⚠ **Protokołu nie zmieniamy w locie.** Znalezisko idzie na listę, decyzję podejmuję ja,
a zmiana wchodzi jednym commitem do kodu, `API-TENANTA.md` i OpenAPI naraz — tak jak dotąd.
Doraźna poprawka „żeby przebieg przeszedł" jest tym, co potem wychodzi u klienta jako
zachowanie, którego nikt nie umie wyjaśnić.

Po przebiegu: klucz unieważniony, reguła zapory zdjęta, baza dev wyczyszczona, raport
w `docs/audyt.md`.
