# System wizualny wezurlop — dokument obowiązujący

**Wersja:** 1.20.0, 22.10.2026 · **Właściciel dokumentu:** nadzór · **Status:** zatwierdzony przez Krzysztofa

⚠ **Od 17.10.2026 ten dokument mieszka w repozytorium tenanta** (`tenant/docs/system-wizualny.md`),
a nie w `wezurlop-talk`. Powód jest prosty: wszystkie cztery arkusze powołują się na ścieżkę
`docs/system-wizualny.md`, której w repozytorium kodu nie było — dokument obowiązujący stał poza
zasięgiem tego, co obowiązywał. **Każda zmiana wyglądu dopisuje się tutaj w tym samym commicie
co kod.** Kopia w `wezurlop-talk` jest od tej daty archiwalna i nie wolno jej już zmieniać.
⚠ Przykładowe nazwy firm i osób w tym dokumencie są **zastępcze** (`ALFA`, `BETA`, `KOWALSKI`,
`JAN`) — prawdziwe dane klienta nie wchodzą do repozytorium w żadnej postaci, także w przykładzie.

> **Zmiana 1.19.5 → 1.20.0: `/dane` — lista firm jako czytelna tabela** (zlecenie Z76,
> zgłoszenie Krzysztofa „dużo informacji i nic nie widać", prototyp zaakceptowany).
> ⚠ Numer rośnie na drugim członie, bo zmienia się UKŁAD karty, nie jej szczegóły.
> - **Było**: wiersz był rzędem pudełek `flex`, w którym każda firma powtarzała etykiety
>   „Zatrudnienia" i „Ostatnia synchronizacja" (przy dwóch firmach dwa razy, przy pięćdziesięciu
>   — pięćdziesiąt), NIP był plakietką wśród plakietek stanu, a nazwa szła **pełną nazwą prawną
>   z R2** (zmierzone: 46 znaków wersalikami wobec 6-znakowej nazwy wyświetlanej). Dane były,
>   ale nic nie stało w jednej osi, więc nie dawały się zestawić.
> - **Jest**: tabela w ramce (`1px --c-line`, promień 10 px) z JEDNYM wierszem nagłówków kolumn
>   na `--c-bg` (11 px, wersaliki, odstęp `.05em`, `--c-text-3`): FIRMA · STATUS · ZATRUDNIENIA
>   (do prawej) · OSTATNIA SYNCHRONIZACJA · akcja. Etykiety z każdego wiersza zniknęły.
> - **Wiersz**: siatka `minmax(0,1fr) 170px 130px 190px 130px`, odstęp 24 px, padding 14/16,
>   kreska `--c-line`, tło `--c-bg` po najechaniu. Ikona 36×36 na `--c-bg`, nazwa 15 px/600
>   z wielokropkiem, pod nią „NIP …" 12 px `--c-text-3` cyframi tabelarycznymi (NIP przestał
>   być plakietką), liczba zatrudnień 20 px/600 do prawej, czas 14 px `--c-text-2`, „Pracownicy ›"
>   jako mały przycisk drugorzędny do prawej. Cały wiersz pozostaje jednym odnośnikiem.
> - **Karta ma tytuł** „Firmy z programu kadrowego" (18 px/600) i jedno zdanie pod nim.
>   ⚠ To NIE łamie reguły z 11.10: kryterium `nazwa_ekranu_w_belce` mierzy **powtórzenie nazwy
>   ekranu** w nagłówkach (`h2`/`h3` o tekście równym `h1`), a „Firmy z programu kadrowego" nie
>   jest nazwą ekranu („Dane z kadr") — jest nazwą sekcji, jak „Dane konta" na „Moim koncie".
>   Poprzedni komentarz w szablonie ujmował regułę za szeroko i właśnie dlatego karta stała bez
>   tytułu, a dwa zdania opisu musiały go udawać.
> - ⚠ **Nazwa wyświetlana na tym ekranie, nazwa prawna w podpowiedzi** (Z76 pkt 4). Dotąd
>   `NazwaSpolki` wykluczała `/dane` wprost — „tam nazwa z R2 jest treścią, nie etykietą".
>   Powód nie zniknął, ale role się rozdzieliły: kluczem porównania z kadrami jest **NIP**, który
>   stoi w wierszu jawnie, a dosłowne brzmienie nazwy niesie `title`. Docblock `NazwaSpolki`
>   został poprawiony, żeby nie mówił nieprawdy.
> - **Wąsko przez `@container karta`, nie `@media`**: decyduje szerokość KARTY, bo ta sama lista
>   może stanąć w węższej kolumnie przy szerokim oknie. Poniżej 760 px wiersz przechodzi
>   w kafelek (nazwa i NIP, pod nimi plakietki, potem jedno zdanie „39 zatrudnień · przed chwilą"),
>   nagłówki kolumn się chowają; poniżej 420 px przycisk bierze całą szerokość. ⚠ Lista musiała
>   trafić do `card__body`, bo `@container` **nie pyta o samego siebie** — bez tego pojemnika
>   nie byłoby o co pytać.
> - Zmierzone: 1920 → kolumny `760 170 130 190 130`, wiersz 71 px, ikona 36×36, liczba 20 px/600
>   przy x=1324, przycisk 102×32 przy prawej krawędzi; 1366 → `254 170 130 190 130`, wiersz 71 px;
>   375 → nagłówki ukryte, liczba i czas ukryte, zdanie skrótu widoczne, przycisk 259 px na całą
>   szerokość, wiersz 171 px. **Zero przewijania strony i kontenerów na wszystkich trzech.**
>   Wielokropek sprawdzony podstawieniem 71-znakowej nazwy: ucina się, a siatka i szerokość
>   wiersza nie drgnęły.

> **Zmiana 1.19.4 → 1.19.5: w „Moim koncie" nagłówki oddzielone od treści** (zlecenie Z75,
> zgłoszenie Krzysztofa „treść się zlewa", prototyp zaakceptowany).
> - **Nagłówek bloku firmy** w karcie „Dostęp": tło `--c-bg`, kreska `1px --c-line`, nazwa firmy
>   15 px (waga 600 zostaje). Przed poprawką miał tło przezroczyste — tę samą biel co wiersze
>   źródeł pod nim — i kreskę `--c-line-soft`, czyli zmierzone `rgb(240,242,245)`, przy 1 px
>   praktycznie niewidoczną. Czytał się jak pierwszy wiersz listy, nie jak nagłówek.
>   ⚠ Promień górnych rogów jest o **1 px mniejszy** niż bloku (`calc(--radius-card - 1px)`):
>   blok ma ramkę 1 px, więc tło liczone pełnym promieniem wystawałoby za jej łuk. Widać to
>   wyłącznie w narożniku i wyłącznie przy szarym tle, czyli dokładnie w sytuacji, którą ta
>   zmiana wprowadza.
> - **Pola w karcie „Dane konta" są kafelkami**: tło `--c-bg`, ramka `1px --c-line`, promień
>   10 px, padding 12/14 px, odstęp siatki 16 px bez zmian. Wartość 15 px, waga **600**,
>   `--c-text`; etykieta z odstępem liter `.04em` (miara względna, nie `.4px`) i 2 px pod nią.
>   Przed poprawką etykieta, wartość i opis miały podobną wagę, więc cztery pola w jednym
>   wierszu siatki zlewały się w blok tekstu.
> - ⚠ **Brak danej nie udaje danej**: „nie podano" i „—" wracają do wagi 400 w `--c-text-3`.
>   Przy wadze 600 w kolorze głównym „nie podano" czytałoby się jak wpisana treść.
> - Zmierzone po zmianie (1920 / 375 / `/m/` 375): nagłówek `rgb(245,246,248)` z kreską
>   `rgb(230,232,236)` i promieniem 9 px; kafelek `rgb(245,246,248)`, ramka `rgb(230,232,236)`,
>   promień 10 px, padding `12px 14px`; wartość 15 px waga 600 w `rgb(21,24,29)`; brak danej
>   waga 400 w `rgb(107,114,128)`; etykieta 11 px z odstępem 0,44 px. Zero przewijania strony
>   i kontenerów przy 375 px.
> - ⚠ **Przy okazji naprawiony cichy błąd z Z69**: wartość pola miała `color: var(--c-text-1)`,
>   a tokena `--c-text-1` **nie ma** w `00-tokens.css`. Nieznany token nie jest dla przeglądarki
>   błędem — unieważnia całą deklarację po cichu, więc kolor brał się z dziedziczenia (zmierzone
>   `rgb(42,47,57)`, czyli `--c-text-2`). **Nauka: nieznana nazwa tokena nie zapala się nigdzie
>   — ani w przeglądarce, ani w `check-rules`.**

> **Zmiana 1.19.3 → 1.19.4: pole telefonu to JEDEN komponent w trzech miejscach**
> (zlecenie Z73, decyzja Krzysztofa po Z72).
> - Panel osoby (`/admin/konta`) i formularz nowego konta administracyjnego dostały **to samo
>   pole** co „Moje konto": prefiks `+48`, reguła dziewięciu cyfr, normalizacja w `Support\Telefon`.
>   Do Z73 stał tam zwykły `.input` na 32 znaki z własną, luźniejszą białą listą znaków, więc
>   w jednej kolumnie bazy mogły stać dwa kształty numeru. **Numerów zagranicznych świadomie nie
>   obsługujemy.**
> - **Znaczniki w jednym pliku** (`partials/pole-telefonu.twig`), a nie w trzech: dwie kopie
>   zdążyły się już rozjechać między Z69 i Z72 i kosztowało to osobne zlecenie.
> - **Jedna decyzja po stronie serwera** (`Telefon::dlaZapisu()`): pusty numer znaczy USUNIĘCIE,
>   poprawny — jedną postać `+48XXXXXXXXX`, niepoprawny — odmowę z jednym zdaniem. Trzy kopie
>   tej reguły w kontrolerach zniknęły.
> - Zmierzone po zmianie (jedna ramka, prefiks i wnętrze tej samej wysokości):
>   panel 740×40 / 487×40 / 275×40 przy 1920 / 1366 / 375 px, prefiks zawsze 52×38, wnętrze
>   686 / 433 / 221 × 38; formularz nowego konta 1004×40.
> - Zapis sprawdzony przez przeglądarkę, cztery przypadki: numer zagraniczny wysłany **z ręki**
>   (bo pole go nie przyjmie — moduł zjada znaki spoza cyfr) → odmowa i baza bez zmian;
>   osiem cyfr wpisanych w pole → odmowa; poprawny numer → w bazie `+48…`; wyczyszczone pole
>   → usunięcie.
> - ⚠ **Numery zapisane wcześniej w innym kształcie zostają nietknięte** (zmierzone: **dwa**
>   wiersze w bazie roboczej, oba dałyby się znormalizować). Pole pokazuje taki numer bez zmian
>   i bez obcinania, a poprawki wymaga dopiero ZAPIS — wtedy serwer odmawia i mówi, czego
>   oczekuje. Migracji danych nie robimy bez decyzji.

> **Zmiana 1.19.2 → 1.19.3: nagłówek „Mojego konta" w jednym wierszu, telefon jako JEDNO pole**
> (zlecenie Z72 — zgłoszenie Krzysztofa po Z69, zmierzone w Chrome przy 1568 px).
> - **Nagłówek**: miał **408 px wysokości** (zmierzone na 1920, 1366 i 375), bo `.konto-glowa__body`
>   nie nadpisywał `flex-direction: column` z `.card__body`. W układzie kolumnowym `flex-basis`
>   dotyczy WYSOKOŚCI, więc `.konto-glowa__dane { flex: 1 1 240px }` rozciągał kartę do 240 px
>   w pionie, a inicjały, nazwa i „Wyloguj" stały jedno pod drugim. Po dopisaniu
>   `flex-direction: row`: **104 px** przy 1920 i 1366, jeden wiersz, „Wyloguj" przy prawej
>   krawędzi. Na telefonie (≤ 560 px) wiersz zawija się jak dotąd — 251 px, bez przewijania strony.
>   ⚠ Nauka: komponent dokładany do `.card__body` **dziedziczy jego kierunek** i musi go nadpisać,
>   jeśli chce wiersza. Sprawdzone: żadna inna karta tego nie dotyczy — `.konto-glowa__body` jest
>   jedynym miejscem łączącym `card__body` z własną klasą ciała.
> - **Pole telefonu**: prefiks i wnętrze były dwiema kontrolkami obok siebie, każda z własną ramką
>   i promieniem 8 px (zmierzone: pojemnik 410 px, prefiks 49×38, input 185×38 — czyli pole nie
>   wypełniało okna). Teraz ramkę nosi POJEMNIK (`--c-line-strong`, promień 10 px,
>   `overflow: hidden`), prefiks ma tło `--c-bg` i kreskę z prawej, a wnętrze `flex: 1`, 17 px,
>   odstęp liter .04em i podpowiedź „600 100 200". Zmierzone po poprawce: 410×40, prefiks 52×38,
>   wnętrze 356×38, obie części tej samej wysokości i bez własnych ramek.
> - **Obwódka akcentu na CAŁYM polu**, nie na wnętrzu — przy fokusie i przy poprawnym numerze
>   (`box-shadow 0 0 0 3px --c-accent-soft`, ramka `--c-accent-text`). Trzy stany zmierzone:
>   w spoczynku `rgb(212,216,223)` bez obwódki, z fokusem i przy dziewięciu cyfrach
>   `rgb(14,110,60)` z obwódką `rgb(232,244,236)`. ⚠ Wewnętrzny `outline` z `10-base.css`
>   wyłączony **tylko w tym polu**: wskaźnik fokusu nie znika, przenosi się na pojemnik,
>   który jest większy i lepiej widoczny.
> - ⚠ **Przy okazji znaleziony brak z Z69**: `modules/konto.js` ustawiał klasę `field__hint--ok`,
>   której arkusz NIE ZNAŁ — podpowiedź zmieniała tekst na „numer wygląda poprawnie", ale kolorem
>   dalej mówiła „to opis pola". Klasa dopisana (barwa akcentu, półgruby krój).
> - ⚠ **Pole w panelu osoby (`/admin/konta`) to NIE ten komponent** i zostaje bez zmian: tam stoi
>   zwykły `.input` o długości 32 znaków, bez prefiksu, bo administrator wpisuje numer w dowolnym
>   kształcie (także zagraniczny). Ujednolicenie oznaczałoby narzucenie mu reguły dziewięciu cyfr
>   krajowych — to zmiana zachowania, nie wyglądu, więc czeka na decyzję.

> **Zmiana 1.19.1 → 1.19.2: trzecie zdanie o zasięgu zespołu, i dowód reguły „Zespół"
> w obie strony** (zlecenie Z71, 21.10.2026).
> - **Zdanie**: przełożony z trzema podwładnymi i bez żadnej reguły czytał „Widzisz swoją gałąź
>   **i osoby z reguł dostępu**: 4 osoby" — dopisek mówił o kimś, kogo w siatce nie ma, i kazałby
>   szukać czwartej grupy osób na próżno. Zdania są teraz trzy: bez podwładnych „Widzisz N osób
>   z nadanych reguł dostępu…", zespół równy gałęzi „Widzisz swoją gałąź: N osób…", zespół
>   szerszy od gałęzi „Widzisz swoją gałąź i osoby z reguł dostępu: N…".
> - **Dowód dodatni**: kryterium `przelacznik_zespolu` mierzy teraz także stan, w którym pozycja
>   „Zespół" MUSI być — na własnej strukturze próbnej (`dev/probka-zespolu.php`): przełożony
>   z kontem i trzech podwładnych, zakładani na czas pomiaru i kasowani po nim. Zmierzone:
>   pozycja obecna, widok pokazuje **dokładnie 4 wiersze** (gałąź = przełożony + trzy osoby),
>   zdanie mówi „Widzisz swoją gałąź", liczniki sześciu tabel wracają do stanu z przed pomiaru.
> - ⚠ **Dlaczego własna struktura, a nie istniejący przełożony**: jedyny przełożony z niepustą
>   gałęzią w bazie roboczej to **prawdziwa osoba z prawdziwymi podwładnymi**, a trzymanie hasła
>   do jej konta w pliku deweloperskim byłoby otwarciem dostępu do prawdziwych danych kadrowych
>   (Krzysztof odmówił wprost, 21.10.2026). Hasło próbki jest losowe, jednorazowe i nie trafia
>   ani do pliku kont, ani do raportu.
> - ⚠ **Podwładni próbki nie mają kont** — i to jest decyzja, nie skrót: widok zespołu buduje się
>   z ZATRUDNIEŃ, więc konta nie zmieniłyby żadnej mierzonej liczby, a zmieniłyby zajętość
>   **miejsc licencyjnych** na instancji, która jest używana.
> - ⚠ **Dwie usterki na jedno kryterium**, bo ma ono twierdzenia o przeciwnych stanach: jedna
>   dokłada pozycję „Zespół" tam, gdzie dublowałaby „Firmę", druga ją usuwa przy niepustej
>   gałęzi. Jedna złamałaby tylko połowę kryterium, a druga połowa byłaby nieudowodniona.
> - ⚠ „Zespół" i „Firma" obok siebie u tego konta nie wystąpią i nie jest to brak pomiaru: widok
>   firmy wymaga wglądu w całą firmę, a wtedy zespół równa się firmie i reguła z 1.19.1 słusznie
>   chowa pozycję. Obie naraz wymagają reguły węższej niż cała firma — osobny stan, osobny pomiar.

> **Zmiana 1.19.0 → 1.19.1: pozycja „Zespół" tylko wtedy, gdy pokazuje coś innego niż „Firma"**
> (zlecenie Z70, zgłoszenie Krzysztofa z 21.10.2026: „u administratora oba widoki pokazują to samo").
> - **Było**: pozycję „Zespół" włączał warunek `galaz > 1`, a `galaz` od Z53 liczy **gałąź RAZEM
>   z osobami z reguł**. Konto z regułą „cała firma" miało więc „gałąź" 39 osób, choć nie prowadzi
>   nikogo — przełącznik oferował dwa przyciski pokazujące ten sam zbiór, a pod kalendarzem stało
>   zdanie „Widzisz swoją gałąź: 39 osób z 39 **w spółce** Piaskarz": nieprawdziwe dwa razy
>   (przypisywało podwładnych i używało zakazanego słowa).
> - **Jest**: rozstrzyga `ZasiegPodgladu::zespolMaSens()` — pozycja istnieje, gdy zespół ma więcej
>   niż jedną osobę **i** nie jest tym samym zbiorem co cała firma; gdy „Firmy" nie ma, „Zespół"
>   zostaje najszerszym widokiem konta. ⚠ Ten drugi warunek jest konieczny, a nie ostrożnościowy:
>   konto bez etatu z regułą na grupy ma „Zespół" jako JEDYNY widok (Z53 §2), więc warunek
>   „tylko dla osoby z podwładnymi" odebrałby mu ekran.
> - Jedna funkcja rozstrzyga i o przełączniku, i o widoku z adresu, więc nie ma stanu osiągalnego
>   przez `?widok=zespol`, którego przełącznik nie oferuje. Przy jednym widoku przełącznika nie ma
>   wcale (reguła bez zmian).
> - **Zdania mówią o źródle**: bez podwładnych „Widzisz N osób **z nadanych reguł dostępu**…",
>   z gałęzią „Widzisz swoją gałąź i osoby z reguł dostępu: N…". Dla konta z etatem, którego
>   wgląd obejmuje całą firmę, doszedł czwarty powód odmowy widoku: „Twój wgląd obejmuje całą
>   firmę, więc widok »Zespół« pokazałby te same osoby — pokazuję widok firmy" (dotąd mówiło
>   „Nie masz podwładnych, więc pokazuję Twój kalendarz", co myliło przyczynę i skutek).
> - ⚠ **Słowo „spółka" zniknęło z trzech napisów w PHP**, nie tylko z tego zdania: odmowa
>   nadania dostępu („brak aktywnego zatrudnienia w tej **firmie**") i pomoc narzędzia
>   konsolowego. Napisy w szablonach były czyste.
> - ⚠ **Przyczyna, dla której kryterium `bez_slowa_spolka` tego nie widziało, była w kryterium,
>   nie w zasięgu pomiaru**: szukało rdzenia `spółk`, a w zdaniu stało „w spółce" — w celowniku
>   i miejscowniku `k` przechodzi w `c`. Komentarz obok twierdził, że rdzeń łapie „spółce".
>   Rdzeń to teraz `spó[łl][kc]`, a po stronie Pythona jedna funkcja `ma_slowo_spolka()` z czterema
>   rdzeniami (z ogonkami i bez). **Nauka ogólniejsza: deklarowany zakres wyrażenia trzeba
>   sprawdzić przykładem KAŻDEJ wymienionej odmiany — komentarz nie jest testem.**
> - Dowody: kryterium bramki `przelacznik_zespolu` (cztery twierdzenia: brak pozycji, adres jej
>   nie przywraca, zdanie bez „spółki", zdanie bez „gałęzi") plus `tests/przelacznik-widoku.php`
>   — siedem kombinacji reguły bez bazy i bez przeglądarki, w tym ta, która pękła w produkcie.

> **Zmiana 1.18.48 → 1.19.0: „Moje konto" od nowa — dane, dostęp i bezpieczeństwo na jednym
> ekranie** (zlecenie Z69, projekt zaakceptowany przez Krzysztofa 21.10.2026). ⚠ Numer wersji
> rośnie na drugim członie, bo to nowy EKRAN, a nie poprawka istniejącego.
> - **Było**: lista czterech odnośników („Weryfikacja dwuetapowa", „Twoje urządzenia", „Zmień
>   firmę", „Ustawienia tenanta") i plakietka stanu 2FA. Każda rzecz o koncie mieszkała na innym
>   ekranie, a trzy z nich były osiągalne tylko stąd.
> - **Jest**: pięć kart jedna pod drugą — nagłówek konta (inicjały 56 px, nazwa, e-mail,
>   plakietka typu konta, „Wyloguj"), cztery kafle stanu (typ konta, 2FA, ostatnie logowanie,
>   urządzenia), „Dane konta", „Dostęp" i „Bezpieczeństwo". Odnośnik „Ustawienia tenanta" znika
>   (ustawienia mają własną pozycję w nawigacji), a `/ustawienia/2fa` i `/ustawienia/urzadzenia`
>   **przekierowują tutaj**.
> - **Telefon ustawia sam pracownik** (decyzja Krzysztofa): wartość z ołówkiem w karcie „Dane
>   konta", okno z nieruchomym prefiksem `+48` i formatem 3-3-3. Reguła jest wąska i jedna
>   (`Support\Telefon`): dziewięć cyfr krajowych, jedna postać w bazie (`+48600100200`).
>   W panelu osoby administrator zmienia numer dalej, a podpis pola mówi teraz, że pracownik
>   może go ustawić sam — inaczej administrator nadpisywałby cudzy wpis, nie wiedząc o tym.
> - **Karta „Dostęp" pokazuje blok TYLKO dla firmy z wglądem w cudze nieobecności** (dopisek
>   Krzysztofa). Bez żadnego wglądu karta mówi jedno zdanie: „W kalendarzu widzisz tylko swoje
>   nieobecności." Wiersze źródeł renderuje **wspólny partial** z kartą „Widoczność" w panelu
>   osoby (`partials/zrodlo-wgladu.twig`) — jedna odpowiedź na dwóch ekranach, jeden słownik.
> - **Bezpieczeństwo**: hasło z datą ostatniej zmiany (`account.password_changed_at`, przy pustej
>   „nie zmieniano od utworzenia konta"), 2FA z liczbą kodów zapasowych, lista urządzeń rozwijana
>   natywnym `<details>` i „Wyloguj z pozostałych urządzeń". Przyciski w tej karcie mają JEDNĄ
>   szerokość (120 px), bo stoją w kolumnie jeden pod drugim.
> - ⚠ **Kodów zapasowych jest OSIEM, nie dziesięć** (rozstrzygnięcie Krzysztofa po pytaniu
>   z raportu Z68): ósemka stoi w `CLAUDE.md` §9 i w stałej `BackupCodes::COUNT`, a ekran liczbę
>   bierze z tej stałej — nie z napisu.
> - ⚠ **Okna otwiera SERWER adresem** (`?okno=telefon`, `?okno=haslo`, `?okno=2fa`,
>   `?okno=2fa-wylacz`, `?okno=kody`), tak jak okno nazw firm: cały ekran działa bez JavaScriptu,
>   a odmowa wraca **pełną stroną z otwartym oknem i komunikatem**. `modules/konto.js` dokłada
>   wyłącznie wygodę (format 3-3-3, podpowiedź „numer wygląda poprawnie", zgodność haseł,
>   odblokowanie „Gotowe", kopiowanie) i każda z tych rzeczy ma działającą wersję bez niego.
> - ⚠ **Okno włączania 2FA przy wymogu dla administratora NIE MA „Anuluj" ani krzyżyka**
>   (rozstrzygnięcie zarządcy), a jedyną drogą wyjścia jest „Wyloguj" — żeby nikt nie utknął
>   w oknie bez żadnego przycisku. Oba przekierowania wymogu prowadzą teraz na „Moje konto".
> - ⚠ **Pole kodu to JEDNO pole w wyglądzie okienek, a nie sześć osobnych**: sześć pól bez
>   JavaScriptu wymaga kliknięcia w każde, a wklejenie kodu z menedżera haseł trafia tylko
>   w pierwsze. Kratki robi arkusz (`letter-spacing`), wartość jest jedna.
> - ⚠ **Wyłączenie 2FA wymaga HASŁA i kodu** (`CLAUDE.md` §9), choć szkic zlecenia rysował samo
>   pole kodu. Drugą drogą jest kod zapasowy (odnośnik przełączający, bez JavaScriptu).
> - Telefon 375 px: przyciski w karcie bezpieczeństwa schodzą pod opis i rozciągają się na całą
>   szerokość, odstęp w polu kodu maleje z 12 do 8 px.

> **Zmiana 1.18.47 → 1.18.48: kolumna dnia wolnego jest CIĄGŁA także w wierszu z nieobecnością**
> (zlecenie Z68, decyzja Krzysztofa z 21.10.2026 po prototypie w przeglądarce: „Czarnecki
> 16–23.02 ma tak jak powinno być").
> - **Było**: pasek nieobecności leży w JEDNEJ komórce rozciągniętej na kilka kolumn
>   (`grid-column: span N`), więc całe jej tło brało się z **pierwszego dnia** zakresu. Usterka
>   miała dwie twarze: nieobecność zaczęta w dzień roboczy dawała całe tło białe i **kolumna
>   weekendu urywała się w połowie wiersza** (zgłoszony przypadek: 16–23.02, przerwa na 21–22),
>   a nieobecność zaczęta w weekend wygaszała pod paskiem **także dni robocze** (zwolnienie
>   1–28.02 miało całą komórkę w barwie dnia wolnego).
> - **Jest**: komórka z paskiem wielodniowym rysuje tło dzień po dniu — dni wolne barwą kolumny
>   dnia wolnego, dni robocze barwą zwykłej komórki. Widać to **nad paskiem i pod nim** (pasek ma
>   `inset: 6px 2px`), więc kolumna weekendu biegnie bez przerwy z góry na dół.
> - ⚠ **Kolory i kolumny bez zmian.** `.team-grid__cell--free` zostaje na `--c-surface-hover`,
>   paleta pasków bez zmian, bez rozjaśnień na weekendzie. Ciemniejszy wariant kolumny
>   (Z67 pkt 1) jest **porzucony** — miał na sobie pasek zasiłku z kontrastem **1,041** przy
>   podłodze 1,05.
> - ⚠ **Rozwiązanie: warstwa pod-kratek, nie gradient.** Prototyp liczył pozycje kolumn
>   w JavaScripcie i wstawiał gradient atrybutem `style` — oba te środki są u nas zakazane
>   (CLAUDE.md §6). Gradient z klasy też nie przechodzi, bo wzór dni wolnych zależy od rozkładu
>   czasu pracy osoby i od dnia tygodnia, w który wypada początek zakresu: klasa musiałaby kodować
>   wszystkie kombinacje. Serwer podaje listę `dni` (po jednym `wolny` na kolumnę), szablon
>   renderuje `.team-grid__dni` z `.team-grid__dzien` na dzień, a arkusz dzieli je `flex: 1 1 0`.
>   **Arkusz nie musi znać liczby dni**: kolumny są równe i bez `gap`, a niedobór to dokładnie
>   1 px prawej krawędzi rozłożony na N dni, więc każda granica pod-kratki wypada **wewnątrz
>   pasma kreski** między kolumnami.
> - ⚠ Warstwa **nie ma** `pointer-events: none` i to jest decyzja: nie obsługuje żadnych zdarzeń,
>   a wyłączona z testu trafienia staje się niewidoczna dla `elementFromPoint` — czyli dla
>   kryterium, które sprawdza, co widzi człowiek, i dla całej rodziny sprawdzeń osiągalności.
>   Pasek jest malowany wyżej i pozostaje trafialny.
> - **Dni wolne z rozkładu osoby**, nie tylko weekendy: lista `dni` bierze `wolny` z tych samych
>   nagłówków, którymi wygaszają się kolumny bez nieobecności.
> - Dotyczy **osi czasu** (Zespół, Firma) w powłoce web i `/m/` — jeden plik
>   (`partials/os-czasu.twig`) obsługuje obie. ⚠ **Siatki Miesiąca i Roku to NIE dotyczy**
>   (sprawdzone): tam każdy dzień jest osobną kratką, a dzień z nieobecnością bierze barwę rodzaju
>   na całą kratkę — nie ma paska, nad którym i pod którym mogłoby coś przeświecać, więc nie ma
>   czego scalać ani przerywać.
> - ⚠ **Przy tej samej turze poprawione zdanie pod kalendarzem Firma** (dopisek do Z68).
>   Stało tam „To nadana reguła dostępu, **nie domyślny stan konta**" — a od Z55 (20.10.2026)
>   dla administratora pełny wgląd **jest** stanem domyślnym, bo daje go reguła „Administratorzy"
>   zakładana migracją w każdej firmie. Zdanie zaprzeczało więc temu, co człowiek widzi na ekranie,
>   i to przy danych o zdrowiu. Teraz mówi, SKĄD ten wgląd się bierze, a źródła podaje
>   `CalendarVisibility::zrodlaSzczegolu()` — ta sama funkcja i ten sam słownik, którym mówi karta
>   „Widoczność" w panelu osoby. Zmierzone na obu kontach administracyjnych i obu firmach:
>   „z uprawnień administratora · reguła domyślna »Administratorzy«".

> - Kryterium: `paski_nieobecnosci` ma twierdzenie `tlo_dni_pod_paskiem` — mierzone **kolorem
>   w punkcie** nad paskiem i pod nim, porównywanym z barwami zmierzonymi na komórkach bez paska
>   (kryterium nie zna żadnego kodu koloru). Zmierzone po poprawce: **111 próbek, 32 dni wolne,
>   zero niezgodnych**; progi kontrastu bez zmian (dzień wolny 1,063 do wiersza, paski 1,19–1,21).

> **Zmiana 1.18.46 → 1.18.47: powłoka `/m/` jest pod aplikację mobilną, a nie pod telefon
> w przeglądarce** (rozstrzygnięcie Krzysztofa 20.10.2026, pozycja 74 z `do-sprawdzenia.md`;
> **sama decyzja — zero zmian w kodzie**).
> - `/m/` to powłoka przygotowywana pod **webview aplikacji mobilnej** (Android, iPhone)
>   w kolejnych etapach. Nie jest „wersją dla telefonu".
> - Telefon w **zwykłej przeglądarce** dostaje responsywną wersję webową i **żadnego
>   przekierowania na `/m/`** — ani z `User-Agent`, ani ze znacznika klienta.
> - ⚠ **Obie powłoki muszą działać przy 375 px**, tak jak dziś mierzy bramka ekranowa. To jest
>   praktyczny skutek decyzji: skoro telefon zostaje na powłoce web, wąskiego pomiaru nie wolno
>   zawęzić do `/m/` ani uznać 375 px za problem wyłącznie powłoki mobilnej.

> **Zmiana 1.18.45 → 1.18.46: próbka „dzień wolny" w legendzie ma token kratki, nie kursora**
> (zlecenie Z66, decyzja Krzysztofa z 20.10.2026 — pozycja 90 z `do-sprawdzenia.md`).
> - **Było**: `.legend__mark--free` brało `--c-surface-hover`, czyli token „wiersz tabeli pod
>   kursorem" (#F7F8FA), a kratka dnia wolnego w siatce miesiąca i roku ma `--c-neutral-soft`
>   (#F0F2F5). Legenda mówiła więc o innym kolorze niż ten, który opisuje.
> - **Jest**: próbka bierze `--c-neutral-soft`. Zmierzone na koncie z rozkładem czasu pracy,
>   w obu powłokach i na trzech skalach (Miesiąc, Rok, widok firmy): przed — legenda
>   `rgb(247,248,250)` wobec kratki `rgb(240,242,245)`; po — **oba `rgb(240,242,245)`, sześć
>   pomiarów na sześć zgodnych**.
> - ⚠ **Zostaje jedno rozejście, zgłoszone osobno**: kolumna dnia wolnego w OSI CZASU
>   (`.team-grid__cell--free`) dalej ma `--c-surface-hover` (#F7F8FA), a jej nagłówek
>   `.team-grid__head--free` jeszcze inny odcień (#FCFCFD). Po tej zmianie legenda zgadza się
>   z siatką, a rozmija z osią czasu — czyli ta sama rzecz („dzień wolny") ma w produkcie trzy
>   odcienie. Nie zmieniam osi bez zlecenia; liczby w raporcie Z66.
> - ⚠ **Próba wyrównania osi ZMIERZONA I WSTRZYMANA (Z67, 20.10.2026).** Decyzja Krzysztofa
>   brzmiała „wyrównaj kolumnę do `--c-neutral-soft`" i została wykonana w kodzie, zmierzona
>   i **cofnięta**, bo łamie kryterium `paski_nieobecnosci`: pasek zasiłku chorobowego ma na
>   ciemniejszej kolumnie **1,041 kontrastu przy podłodze 1,05** (urlop 1,062 i rodzaje kadrowe
>   1,075 przechodzą). Wygaszenie weekendu wraca przy tym z 1,063 do 1,122, czyli oddaje zysk
>   z 1.14. Znacznik „dziś" wytrzymuje bez zmian (7,3–7,9 — nie zależy od tła kratki).
>   ⚠ Najtańsze wyjście, gdy decyzja ma zostać: przyciemnić wypełnienie zasiłku o **dwa punkty
>   na kanał** (`rgb(253,235,210)` → `rgb(251,233,208)`), co daje 1,06 na dniu wolnym, 1,189 do
>   wiersza i 4,99 kontrastu tekstu — wszystkie progi z zapasem. To jednak zmiana palety rodzajów
>   ze wzoru Krzysztofa, więc czeka na jego słowo, a nie na moje.
> - ⚠ Próbka zostaje **prostokątem z obrysem**, nie pigułką: dzień wolny nie jest
>   nieobecnością, a kształt próbki to jedyne miejsce, w którym legenda o tym mówi.

> **Zmiana 1.18.44 → 1.18.45: przy wąskim oknie nazwa ekranu wygrywa z szerokością znacznika**
> (pomiar odbiorowy Z63 część B, 20.10.2026).
> - ⚠ **Usterka**: przy oknie 375 px nazwa ekranu w belce miała **43 px przy 140 px potrzebnych**
>   — „Widoczność kalendarza" czytało się jako „Widoczn…", na **12 z 14 zmierzonych ekranów**.
>   Przyczyną nie był brak miejsca w belce, tylko **stała szerokość znacznika spółki**
>   (`width: 14rem` = 224 px, reguła z 1.18.30 „żeby belka nie drgała przy przełączeniu spółki")
>   — znacznik brał 224 z 375 px i nie oddawał ich nikomu.
> - **Poniżej 560 px** znacznik spółki kurczy się do treści (`width: auto`), prawa strona belki
>   też (`flex: 0 1 auto`), a napis z imieniem i nazwiskiem w menu konta ustępuje miejsca
>   (zostaje sam inicjał w kółku; pełne dane są w rozwiniętym menu). Zmierzone po zmianie:
>   nazwa ekranu **140 px na 140 potrzebnych, zero ucięć na 14 ekranach**; znacznik spółki
>   109 px, dalej z wielokropkiem i `title`.
> - ⚠ **Reguła „belka nie drga" zostaje na szerokim oknie i tam jest nadal słuszna.** Przy 1366
>   i 1920 px nic się nie zmienia (zmierzone: zero ucięć, nazwisko w belce widoczne). Na wąskim
>   oknie drganie znacznika jest mniej warte niż wiedza, na którym ekranie się stoi.
> - ⚠ Samo schowanie nazwiska **nic nie dało** i to jest nauka z tego pomiaru: belka dzieli wolne
>   miejsce równo między lewą i prawą stronę (`flex: 1 1 0` po obu), więc prawa trzymała połowę
>   nawet pusta. Zmierzone po pierwszej próbie: `h1` dalej 44 px. Dopiero zwężenie znacznika
>   oddało miejsce nazwie.

> **Zmiana 1.18.43 → 1.18.44: sprawdzanie crona jako żywy wiersz z odliczaniem**
> (zlecenie Z65, projekt zatwierdzony przez Krzysztofa 20.10.2026).
> - **Wiersz zmienia się W MIEJSCU, bez okna i bez przeładowania**: pytanie „czy cron działa"
>   ma odpowiedź tam, gdzie je zadano. Trzy stany, bo trzy różne rzeczy są do powiedzenia —
>   **CZEKA** (ikona zegara w kółku `--c-accent-soft`, „Sprawdzam crona…", pasek postępu 6 px
>   na całą szerokość wiersza i licznik mm:ss po prawej), **DZIAŁA** (ramka i tło akcentu,
>   kółko z ptaszkiem, „Cron działa", pod spodem godzina odhaczenia i czas oczekiwania,
>   przycisk „Sprawdź ponownie" w wariancie `ghost`) oraz **NIE ODPOWIEDZIAŁ** (barwy
>   ostrzeżenia z tokenów `--c-warn-*`, trójkąt, nazwany skutek „poczta, przypomnienia
>   i sprzątanie stoją" i przycisk `primary` „Sprawdź jeszcze raz").
> - ⚠ **Licznik ma token `--t-stat` (24 px), nie 22 px z prototypu.** 22 px nie jest tokenem,
>   a licznik jest **daną** — tym samym, czym liczba w kaflu pulpitu. Cyfry tabelarne, żeby
>   „4:09" nie przeskakiwało w „3:59" o pół znaku.
> - **Koniec odliczania liczy SERWER**: pierwszy spodziewany przebieg po postawieniu sondy plus
>   zapas z jednej stałej (`CronStan::ZAPAS_SONDY_S` = 60 s). Skrypt dostaje dwa znaczniki czasu
>   w atrybutach `data-sonda-start` i `data-sonda-koniec` i tylko tyka — gdyby liczył termin sam,
>   dwie przeglądarki z różnie ustawionym zegarem pokazałyby dwa wyroki o jednym cronie.
> - ⚠ **Odpytywanie gaśnie samo**: atrybuty `hx-get` i `hx-trigger="every 5s"` renderują się
>   tylko w stanie nieostatecznym, a odpowiedź podmienia pojemnik razem z nimi. Nie ma żadnego
>   „wyłącz po wyniku" do przeoczenia, bo nie ma czego wyłączać.
> - **Szerokość paska idzie zmienną CSS** (`--sonda-postep`) ustawianą skryptem, nie atrybutem
>   `style` w szablonie: wartość podaje skrypt, regułę trzyma arkusz (CLAUDE.md §6).
> - **Bez JavaScriptu** wiersz pokazuje ten sam stan CZEKA ze statycznym „do GG:MM:SS"
>   i odnośnikiem „Odśwież wynik"; odnośnik chowa reguła przy `html.js`, a nie dokładanie go
>   skryptem — przeglądarka bez skryptów musi dostać tę drogę z serwera.
> - **Kafle „Ostatni przebieg" i „Najbliższy spodziewany" odświeżają się razem z wynikiem**,
>   bo stoją w tym samym fragmencie: po odhaczeniu sondy znacznik przebiegu jest już nowy.
> - **Telefon (375 px)**: wiersz w dwóch liniach — ikona, tytuł i licznik w pierwszej, pasek
>   i zdanie pod spodem, przyciski stanów końcowych na całą szerokość. Przycisk 32 px obok dwóch
>   linijek tekstu w kolumnie 343 px zabierał tekstowi tyle, że zdanie łamało się na pięć wierszy.

> **Zmiana 1.18.42 → 1.18.43: wiersz zaproszenia w panelu osoby ma trzy stany**
> (zlecenie Z64, zgłoszenie Krzysztofa z 20.10.2026).
> - **Trzy stany jednego wiersza** („Konto" → „Wyślij zaproszenie"), bo trzy są różne sytuacje:
>   bez adresu — przycisk **wyłączony** i zdanie „Najpierw wpisz adres e-mail w karcie Dane
>   konta."; po wpisaniu poprawnego adresu — **„Zapisz i wyślij"** i zdanie „zapisze adres
>   i wyśle zaproszenie"; adres zapisany i nic nie zmienione — zwykłe **„Wyślij"**.
> - ⚠ **Wyłączony przycisk, nie ukryty** (§3.1): droga istnieje i będzie wolna, gdy adres się
>   pojawi. Powód stoi w **widocznym zdaniu** obok, nie w `title` — `title` nie istnieje na
>   telefonie i nie wchodzi na zrzut ekranu.
> - **Szerokość przycisku jest stała**: zmierzone 96 px w każdym z trzech stanów, więc wiersz
>   nie drga przy zmianie napisu.
> - ⚠ **`disabled` ustawia wyłącznie skrypt.** Serwer renderuje przycisk CZYNNY, bo bez
>   JavaScriptu to on rozstrzyga: zapisuje adres i wysyła zaproszenie albo odmawia komunikatem
>   przy karcie „Dane konta". Wyłączony w szablonie zabrałby tę drogę przeglądarce bez skryptów.
> - **Jeden przycisk w formularzu panelu**, nie drugi formularz obok — zaproszenie jedzie tym
>   samym żądaniem, co „Zapisz zmiany" (reguła Z61 „jeden zapis w panelu" zostaje bez zmian:
>   zapis jest jeden, korzystają z niego dwie czynności).
> - **Komunikat po wysłaniu jest jeden w obu ścieżkach**: „Zapisane. Zaproszenie przyjęte
>   do wysyłki." plus termin z faktycznej częstotliwości crona (zmierzone: „wyjdzie w ciągu
>   około 5 minut") — ze skryptem w odpowiedzi zapisu, bez skryptu po przekierowaniu
>   na `?ok=zaproszono`.
> - ⚠ **Odmowa bez JavaScriptu jest EKRANEM, nie fragmentem** — `panelZBledem()` renderowało
>   zawsze fragment panelu, więc żądanie bez htmx dostawało goły HTML bez powłoki i arkusza
>   (zmierzone: 7,5 kB wobec 17,5 kB ekranu). Naprawione w tym samym zleceniu.
> - **Pole wyboru przy osobie bez adresu na liście zostaje czynne**: wiersz ma pole adresu
>   z podpowiedzią „dodaj adres e-mail", więc powód pominięcia jest widoczny na miejscu,
>   a okno wyniku partii nazywa pominiętych i powód („brak adresu e-mail").

> **Zmiana 1.18.41 → 1.18.42: temat zaproszenia zaczyna się od nazwy pracodawcy**
> (zlecenie Z62, decyzja Krzysztofa z 20.10.2026).
> - ⚠ **To przywrócenie reguły, którą odwołało Z60** — z datą i powodem, bo odwołanie też stoi
>   w tym dokumencie (wpis 1.18.39 → 1.18.40). Powód przywrócenia: zaproszenie jest **jedyną
>   wiadomością, którą człowiek czyta PRZED pierwszym logowaniem**. Nazwy naszego produktu wtedy
>   nie zna, więc „wezurlop · Zaproszenie…" wygląda w skrzynce na reklamę albo phishing; nazwę
>   swojego pracodawcy zna. Pozostałe dziesięć rodzajów wiadomości zostaje przy prefiksie,
>   bo tamte czyta ktoś, kto aplikację już widział.
> - **Wzór tematu**: „<nazwa firmy> · Zaproszenie do aplikacji urlopowej — ustaw hasło".
>   Nazwa to **nazwa wyświetlana** (`NazwaSpolki`, ta z aplikacji), nie pełna nazwa z R2 —
>   w temacie widać około czterdziestu znaków, więc „ALFA" mówi tam więcej niż
>   „ALFA sp. z o.o. w restrukturyzacji". ⚠ **W treści i w stopce zostaje pełna nazwa z umowy**:
>   dwie różne nazwy w jednej wiadomości i to jest celowe (temat rozpoznaje, treść przedstawia).
> - **Firma, której dotyczy zaproszenie**: ta z **etatem i dostępem**; przy kilku — pierwsza
>   alfabetycznie po nazwie wyświetlanej, bez wyliczania pozostałych. Zapytanie jest to samo,
>   którego używa przełącznik spółki (`EmploymentRepository::accessibleForAccount()`) — drugie
>   z tym samym warunkiem rozjechałoby się przy pierwszej zmianie reguły dostępu.
> - **Konto administracyjne bez etatu**: „wezurlop · Zaproszenie do aplikacji — ustaw hasło".
>   ⚠ Taki adresat **nie ma pracodawcy w R2**, a zgadnięcie firmy („pewnie ta jedna") podpisałoby
>   wiadomość cudzym nazwiskiem.
> - ⚠ **Wyjątek jest jawny, nie obejściem**: szablon `zaproszenie` stoi w
>   `MailRenderer::PELNY_TEMAT` i tylko dla niego render nie dokłada prefiksu; temat buduje
>   jedna metoda (`AccountMailService::tematZaproszenia()`), z której korzystają też podgląd
>   i test. Układ wiadomości, nagłówek i stopka — bez zmian.
> - **Zmierzone** na prawdziwej drodze (`deliver()` z transportem zbierającym, dwie osoby próbne
>   skasowane po pomiarze): konto z etatem i dostępem w obu firmach tenanta dostało temat
>   zaczynający się od nazwy wyświetlanej **pierwszej alfabetycznie** (59 znaków, ogon
>   „ · Zaproszenie do aplikacji urlopowej — ustaw hasło"), a w treści pełną nazwę z R2; konto
>   bez etatu — „wezurlop · Zaproszenie do aplikacji — ustaw hasło". Test `tests/poczta.php`:
>   200 sprawdzeń (było 171), kryteria łamane po jednym (4, 4 i 5 czerwonych).
> - ⚠ **Rozjazd temat/treść przy koncie bez etatu w tenancie jednofirmowym zostaje**
>   (rozstrzygnięcie 20.10.2026): temat mówi „wezurlop · …", a treść i stopka nadal pokazują
>   nazwę tej jednej firmy ze ścieżki zapasowej z Z60. Powód: **treść przedstawia instalację,
>   a temat nie udaje pracodawcy** — adresat bez etatu nie jest niczyim pracownikiem, więc
>   podpisanie TEMATU cudzą firmą byłoby fałszem, a wymienienie jej w treści jest informacją,
>   czyja to instalacja. To nie jest niespójność do „naprawienia" w żadną stronę.

> **Zmiana 1.18.40 → 1.18.41: jeden zapis w panelu osoby, kolumna dostępu tylko pokazuje**
> (zlecenie Z61, 20.10.2026).
> - **Stopka panelu zapisuje OBIE części jednym przyciskiem i jednym żądaniem**: dane konta
>   (adres, telefon) i dostęp do firm. Przycisk „Zapisz dostęp" z karty firm zniknął — dwa
>   przyciski zapisu w jednym panelu kazały zgadywać, który zapisuje co.
>   ⚠ **Bez JavaScriptu też jednym żądaniem**: pola wyboru firm należą do formularza panelu
>   przez atrybut `form="osoba-dane"`, a to atrybut HTML, nie skrypt. Zmierzone: jeden `POST`
>   na `/zapisz` z JS i bez.
>   ⚠ **Kolejność jest treścią**: najpierw walidacja danych konta (jedyna część, którą da się
>   odrzucić bez zapisu czegokolwiek), potem dostęp w transakcji, na końcu zapis danych konta.
>   Zmierzone: przy złym numerze telefonu stan przed i po jest identyczny — ani telefon,
>   ani dostęp się nie zapisały.
>   ⚠ **Ukryty znacznik `firmy_w_formularzu`** renderuje się razem z kartą firm i mówi serwerowi,
>   że kontrolka była na ekranie. Bez niego zapis z panelu BEZ tej karty (konto administracyjne,
>   osoba bez konta) wyglądałby w żądaniu identycznie jak odznaczenie wszystkich firm — czyli
>   zapis telefonu odbierałby dostęp.
> - **Komunikat stoi przy swojej karcie**: błąd danych konta w karcie „Dane konta", odmowa
>   dostępu (licencja, brak etatu) w karcie firm, odmowa blokady w karcie „Konto". Jeden wspólny
>   komunikat u góry panelu kazał zgadywać, które pole poprawić — a przy dwóch częściach zapisu
>   w jednym żądaniu to zgadywanie się podwaja.
> - **Stopka aktywna po zmianie w KTÓREJKOLWIEK karcie**: `zapisz.js` czyta `form.elements`,
>   nie potomków formularza — pola firm stoją fizycznie poza nim. Liczy też pola wyboru
>   (`checked` vs `defaultChecked`). Zmierzone: przycisk nieaktywny na starcie, aktywny po
>   przestawieniu firmy.
> - **Kolumna „Dostęp do firm" na liście POKAZUJE, nie edytuje**: skrót „N z M firm" otwiera panel
>   osoby na karcie firm (ze skryptem szuflada i rozwinięta karta, bez skryptu pełny ekran
>   z kotwicą `#karta-firm`). ⚠ Wybrany drugi z dwóch dopuszczonych wariantów: pole firm ma listę
>   220 px i stopkę licencji, a kolumna 24% szerokości tabeli — rozwinięte w wierszu albo
>   rozpychałoby wiersz na pół ekranu, albo byłoby drugą, węższą kontrolką o tym samym znaczeniu.
> - **Usunięte razem z kontrolką**: `fragments/access-panel.twig`, `modules/dropdown-dostep.js`,
>   obie trasy `/admin/konta/osoba/{id}/dostep`, nagłówek `X-Wezurlop-Odmowa` z mapą kodów
>   i cała rodzina CSS `.dropdown*` (60 linii). Administrator tenanta ma w tej kolumnie napis
>   „wszystkie firmy" bez strzałki — wygląda jak pozostałe wiersze, ale nie obiecuje kliknięcia.

> **Zmiana 1.18.39 → 1.18.40: jeden wygląd WSZYSTKICH wiadomości** (zlecenie Z60, 20.10.2026).
> - ⚠ **Stan przed zmianą, policzony:** jedenaście rodzajów wiadomości, z czego **trzy** miały
>   wspólny układ i wersję HTML (zaproszenie, reset hasła, alert o logowaniach), a **osiem**
>   było napisami sklejanymi w PHP — bez HTML-a, bez wspólnej stopki, z tematami w dwóch
>   wzorach („wezurlop: …" i bez prefiksu). Alert wygląda inaczej niż zaproszenie, bo nikt tego
>   nie zobaczy dwa razy — wiadomość czyta się raz, w dniu wysyłki.
> - **Jeden układ dla wszystkich** (`templates/mail/layout.twig` + `text/layout.twig`): nagłówek
>   z nazwą produktu w barwach marki (tekst, zero obrazków), tytuł, treść, **najwyżej jedno
>   działanie** i stopka: nazwa firmy, zdanie „Wiadomość wysłana automatycznie z aplikacji
>   wezurlop — nie odpowiadaj na nią." oraz **data i godzina w strefie instalacji**.
>   ⚠ Znacznik czasu jest nowy i jest treścią: alert bywa czytany po dwóch dniach, a bez daty
>   nie mówi, czy opisuje stan z dziś, czy z zeszłego tygodnia.
> - ⚠ **Przycisk akcji przeniósł się do UKŁADU.** Każda wiadomość ma najwyżej jedno działanie,
>   a adres pod przyciskiem musi stać zawsze — przycisk bez adresu jest bezużyteczny w czytniku
>   tekstowym i przy zablokowanych stylach. Szablon treści podaje `akcja` (napis + link) albo
>   `null` i nie powtarza konstrukcji przycisku.
> - **Dwa nowe szablony zamiast ośmiu** (`alert` i `proba`): treść alertów się różni, ale KSZTAŁT
>   nie — zdanie o stanie, dane w parach, „co sprawdzić po kolei". Osiem osobnych układów
>   znaczyłoby osiem miejsc do rozjechania się.
> - ⚠ **Barwy poczty mają jedno miejsce w kodzie** (`Service\Mail\BarwyPoczty`): wartości
>   przepisane z `00-tokens.css`, nazwy kluczy po roli („akcja", nie „zielony"). `var(--…)`
>   w poczcie nie działa, więc wartości muszą być wpisane — ale nie rozsypane po szablonach.
>   Test pilnuje, że nie rozjechały się z tokenami.
> - **Tematy w jednym wzorze**: prefiks „wezurlop · " dokłada `MailRenderer`, nie nadawcy.
>   ⚠ To **odwoływało** rozstrzygnięcie, które zabraniało nazwy produktu na początku tematu
>   zaproszenia („pracownik ma poznać wiadomość od pracodawcy") — zarządca wymienił zaproszenie
>   wprost w nowym wzorze, a nazwa pracodawcy stoi w treści i w stopce każdej wiadomości.
>   ⚠⚠ **Odwołanie samo odwołane 20.10.2026** decyzją Krzysztofa (Z62): zaproszenie wróciło do
>   tematu z nazwą pracodawcy, pozostałe wiadomości zostały przy prefiksie — patrz 1.18.42.
> - **Wiadomość próbna mówi, co sprawdza i którą drogą poszła** („Serwer poczty działa",
>   host i port w danych; przy zrzucie do pliku — „zrzut do pliku w var/mail"). ⚠ Bez loginu
>   i hasła SMTP: host i port są konfiguracją instalacji, hasło jest sekretem (CLAUDE.md §14).
>   Ten sam szablon obsługuje próbę z ekranu poczty, próbę skrzynki alertowej i **wiadomość
>   testową instalatora** — pierwsza wiadomość, jaką klient widzi z instalacji, nie może być
>   jedyną bez układu.
> - **Podgląd: wariant A** (`dev/probka-poczty.php`) — renderuje każdy rodzaj na danych
>   fikcyjnych do katalogu tymczasowego poza repozytorium. Trasa podglądu, nawet schowana za
>   `APP_ENV=dev`, byłaby kolejnym wejściem renderującym treść, którego trzeba pilnować.
> - **Test** `tests/poczta.php`: 171 sprawdzeń — każdy rodzaj renderowany prawdziwym renderem
>   ze `strict_variables`, wspólny nagłówek i stopka w obu wersjach, znacznik czasu, szerokość
>   600 px, zero obrazków, zero `class=`, zero słowa „spółk", przycisk i adres jako tekst,
>   zgodność barw z tokenami.

> **Zmiana 1.18.38 → 1.18.39: `/admin/konta` — dwie zakładki, konta administracyjne wychodzą
> z ukrycia** (zlecenie Z59, 20.10.2026).
> - ⚠ **Powód jest usterkowy, nie estetyczny.** Konta administratorów bez etatu nie było na
>   liście ani w wyszukiwarce (zmierzone przez zarządcę: szukanie po nazwisku dawało „Nikogo
>   nie znaleziono"), więc nie dało się ich zablokować, zresetować 2FA ani zakończyć im sesji.
>   Mechanizm: lista zawęża się filtrem firmy z belki, a filtr wymaga ETATU w tej firmie.
> - **Nagłówek karty to jeden wiersz**: po lewej przełącznik segmentowy „Pracownicy z kadr N" /
>   „Konta administracyjne M" z ikoną i licznikiem w pigułce, po prawej działania zakładki.
>   Znak karty i zdanie „Wszystkie osoby z programu kadrowego…" zniknęły — zdanie opisywało
>   JEDNĄ z dwóch list, a opis zbioru niesie teraz zakładka razem z licznikiem.
>   ⚠ Zakładki są **odnośnikami `GET`** (`?rodzaj=…`), nie przyciskami ze skryptem: działają bez
>   JavaScriptu, dają się wkleić i wrócić do nich „wstecz". Zmierzone: ten sam wynik z JS i bez.
>   ⚠ Licznik liczy ZBIÓR zakładki, nie wynik szukania — inaczej po wpisaniu nazwiska zakładka
>   mówiłaby „Pracownicy 1" o liście, do której się przełącza (ta sama zasada, co w kaflach).
> - **„Nowe konto administracyjne" stoi tylko na swojej zakładce**: na liście pracowników był
>   przyciskiem do czynności, której ta lista nie dotyczy.
> - **Tabela kont administracyjnych ma własne kolumny**: Osoba (kółko z inicjałami + nazwisko),
>   E-mail, Rola (klucz + „Administrator"), 2FA, Logowanie, Konto (ta sama plakietka stanu).
>   ⚠ Kolumna „Logowanie" mówi „dziś, 09:41" przy dzisiejszym logowaniu i samą datę przy
>   starszym (`Support\Strefa::logowanie()`): pytanie o takie konto brzmi „czy ktoś go dziś
>   używał", więc dziś rozróżnia godzina, a wcześniej sama data.
>   Pod tabelą szara notka o tym, skąd te konta się biorą i czego nie wolno (ostatniego
>   aktywnego administratora nie da się zablokować).
> - **Panel osoby dla konta administracyjnego**: karty „Dane z kadr" NIE MA (nie ma czego z kadr
>   pokazać — warunek jest parą: rola na cały tenant i zero etatów), karta trzecia nazywa się
>   „Rola" i jest tylko do odczytu („Administrator · wszystkie firmy"), karta widoczności mówi
>   „z uprawnień administratora · reguła domyślna »Administratorzy«".
>   ⚠ **Odmowa blokady jest ZDANIEM, nie wyłączonym przyciskiem**: „ostatni aktywny administrator"
>   i „własne konto" nie otworzą się same, a `title` nie istnieje na telefonie i nie wchodzi
>   na zrzut ekranu. Wyłączony przycisk zostaje tam, gdzie droga naprawdę się otworzy
>   (ponowienie zaproszenia).
> - **Kafel „Aktywnych" mówi, czego NIE liczy**: „konta w użyciu · bez N administracyjnych".
>   Kafle opisują osoby w zakresie firmy z belki, czyli po zatrudnieniu — konta administracyjne
>   nie wchodzą do żadnej z tych liczb, więc dopisek „w tym N administratorów" byłby nieprawdą.
> - **Formularz nowego konta administracyjnego**: jedno objaśnienie zamiast trzech, rola jako
>   NAPIS (wyłączony `<select>` z jedną pozycją wyglądał jak wybór, który ktoś komuś zabrał),
>   „firma" zamiast „spółki", a zdanie o obowiązkowym 2FA **tylko przy `ADMIN_2FA_REQUIRED=1`**
>   (zmierzone na tej instalacji z wyłączonym wymogiem: zdania nie ma). Po utworzeniu powrót
>   na zakładkę kont administracyjnych — bez tego nowe konto nie było widoczne na liście,
>   na którą wracał formularz.
> - **Telefon**: przełącznik na całą szerokość, zakładki po równo (142 px przy 375 px), pole
>   szukania pod nim, a wiersz tabeli kont administracyjnych staje się blokiem trzech linii
>   (nazwisko ze stanem, adres, rola · 2FA · logowanie). ⚠ Wiersz jest zawijającym się flexem,
>   nie siatką: w siatce każda z trzech krótkich danych zajmowała własny rząd — zmierzone
>   273 px wysokości wiersza przed poprawką, 121 px po.

> **Zmiana 1.18.37 → 1.18.38: panel osoby w `/admin/konta` — pięć kart zamiast pięciu sekcji**
> (zlecenie Z58, 20.10.2026; prototyp zatwierdzony przez Krzysztofa).
> - **Szuflada zostaje** (600 px, prawa krawędź) i **klasy `drawer__*` też**: na nich stoi
>   zmierzony układ przewijania (nagłówek i stopka nieruchome, treść przewijana, `min-height: 0`
>   na każdym ogniwie). Nowy wygląd dokłada rodzina `op-*`, a nie drugi mechanizm.
> - **Nagłówek**: kółko 44 px z inicjałami (największy wariant w aplikacji), imię i nazwisko
>   `--t-h2`, pod nim „‹firma› · z kadr" i **plakietka stanu konta z kropką** — ta sama, co
>   w kolumnie listy. ⚠ Plakietka stanu wyjechała do wspólnego `partials/stan-konta.twig`:
>   ten sam warunek stał w dwóch szablonach, a stan konta jest daną audytową.
> - **Treść na szarym tle (`--c-bg`), każda sekcja osobną białą kartą** z nagłówkiem: znak 32 px
>   na tle akcentu, tytuł 15 px/600 i **jedno zdanie o tym, czego karta dotyczy**. ⚠ Akapit
>   „Te dane przychodzą z programu kadrowego…" zniknął: w treści karty czytał się jak ostrzeżenie
>   o awarii, a jako podtytuł mówi to samo trzema słowami („zmienia się w programie kadrowym (R2)").
> - **Dane z kadr: blok na każdą firmę, rozdzielone kreską.** Osoba z dwoma etatami ma dwa
>   wymiary, dwie daty i dwa limity — jedna wspólna siatka zlewała je w listę ośmiu wartości
>   bez przypisania do firmy.
> - **Dostęp do firm: POLE WYBORU zamiast plakietek i warstwy.** Zamknięte 42 px, z ikoną
>   budynku, wybranymi firmami po przecinku („dwie, potem i N innych", przy wszystkich
>   „Wszystkie firmy", przy żadnej szare „brak dostępu") i licznikiem „N z M".
>   ⚠ **Rozwija się `<details>`, nie skryptem**: lista otwiera się W MIEJSCU, przesuwa resztę
>   karty w dół i nie ma prawa wyjść poza panel — zmierzone po zmianie: 0 px poza panelem,
>   lista 220 px z przewijaniem, okno nieprzewijalne. Dawna kontrolka była warstwą `.dropdown`
>   pozycjonowaną skryptem nad tabelą: w panelu szerokim na 600 px nie miała gdzie się zmieścić,
>   a **bez JavaScriptu nie otwierała się wcale**.
>   ⚠ Wyszukiwarka i „Zaznacz wszystkie" przychodzą z HTML-a `hidden` i odsłania je
>   `modules/firmy-pole.js` — bez niego byłyby atrapami. Zaznaczanie i zapis działają bez skryptu.
>   ⚠ Zdanie „Rola i firma idą w parze — w bazie to jeden wiersz" zniknęło: opisywało model
>   bazy, a nie decyzję administratora.
> - **Widoczność kalendarza: karta POGLĄDOWA** (nowa). Lista źródeł wglądu — ikona rodzaju celu,
>   cel („Swoi podwładni", „Cała firma", „Grupy: …", nazwiska), pod nim **skąd** („z hierarchii ·
>   reguła domyślna „Każdy przełożony" · 4 osoby", „reguła osobista · 53 osoby", „przez grupę …",
>   „z uprawnień administratora · reguła domyślna „Administratorzy"") i plakietka zakresu jak
>   na liście reguł. Pod listą zdanie o pierwszeństwie szerszego zakresu; przy braku źródeł
>   „Widzi tylko siebie."; przy osobie bez konta „Widoczność dotyczy konta — ta osoba go jeszcze
>   nie ma.". ⚠ **Liczy to ta sama funkcja, która egzekwuje dostęp** — panel pokazujący coś
>   innego niż kalendarz byłby gorszy niż panel milczący. ⚠ Bez form osobowych („jako przełożona"):
>   rodzaju gramatycznego osoby nie znamy i nie zgadujemy go z imienia — nazwa reguły mówi to samo.
>   Stary odnośnik „Uprawnienia › Widoczność kalendarza" zniknął, bo zastępuje go ta karta.
> - **Konto: lista akcji w ramce**, wiersz = ikona 32 px, tytuł, opis i przycisk po prawej.
>   ⚠ **Wszystkie przyciski akcji równej szerokości** (96 px, na telefonie 84 px, napis
>   wyśrodkowany): kolumna przycisków o szerokości napisu czytała się jak cztery różne rodzaje
>   czynności. ⚠ **Akcje bez sensu dla stanu są NIEWIDOCZNE, nie wyłączone** — odwrotnie niż
>   dotąd: „Zakończ sesje" przy osobie bez konta nie jest drogą czasowo niedostępną, tylko
>   czynnością, która w tym stanie nie istnieje. Wyłączony przycisk zostaje tam, gdzie droga
>   ISTNIEJE i będzie wolna (ponowienie zaproszenia, blokada ostatniego administratora).
> - **Stopka: tylko „Zapisz zmiany"**, nieaktywne dopóki nic się nie zmieni. „Zamknij" zniknęło —
>   panel zamyka × w nagłówku, tak jak wszystkie okna; drugi przycisk zamykający był trzecim
>   wyjściem z tego samego panelu.
> - **Telefon**: karty i siatki schodzą do jednej kolumny, przyciski zostają równe (84 px).
>   Zmierzone przy 375 px: pięć kart, zero przewijania w bok na stronie i w każdej karcie.
> - ⚠ **Dopisek do U6 z Z57** (polecenie zarządcy): na `/admin/integracja` nazwę WYŚWIETLANĄ
>   firmy pokazują teraz także nagłówek zatrzymanego strumienia, podpowiedź plakietki zakresu
>   klucza i okno zakresu — NIP obok zostaje jako tożsamość z R2.

> **Zmiana 1.18.36 → 1.18.37: osiem usterek z odbioru — okno reguły, dziennik, kafel 2FA,
> stopka listy, odstęp ikon, wiadomość próbna** (zlecenie Z57, 20.10.2026).
> - ⚠ **Okno reguły dostępu przewijało się całe** (U1): zmierzone `scrollHeight` 1480 px przy
>   `clientHeight` 701 px, okno otwarte z `scrollTop` 491 — czyli bez nagłówka i bez kafelków
>   kroków, ze stopką na środku. Przyczyną nie był ani htmx, ani `scrollIntoView`: **pola wyboru
>   pozycji listy są `.visually-hidden`, czyli `position: absolute`**, a przewijane pudełko listy
>   nie było pozycjonowane — więc pięćdziesiąt pól „uciekało" z niego i rozpychało okno modalne.
>   Pudełko dostało `position: relative`. Po poprawce **każdy krok i każda zakładka**: 701/701,
>   `scrollTop` 0. ⚠ Zasada ogólna: **pudełko z `overflow` przycina tylko to, czego blokiem
>   zawierającym jest ono samo** — element `absolute` wymaga od niego `position`.
> - **Stopka listy w oknie reguły mówi prawdę od razu** (U4): po kliknięciu osoby stała tam
>   nadal liczba całej firmy („wybrano: cała firma · 52 osoby") — czyli napis przeczył
>   zaznaczeniu, które ten sam ekran właśnie pokazał. Teraz `modules/widocznosc.js` przepisuje
>   ten jeden napis („wybrano 1 osoba", „wybrano 2 · 34 osoby"), a **słowa przychodzą z serwera**
>   (`data-formy-osoby` z `Support\Odmiana`) — w przeglądarce zostaje sam wybór formy po liczbie.
>   Bez JavaScriptu napis renderuje serwer, jak dotąd.
> - **Ołówek i kosz w wierszu reguły miały odstęp 0 px** (U8, zmierzone 1763–1801 i 1801–1839).
>   Przyczyna: tory siatki wiersza były wpisane jako `32px`, a kontrolka ma `--control-h` = 38 px,
>   więc przycisk wystawał z toru i zjadał odstęp. Tory biorą teraz szerokość z `--control-h`,
>   a ujemny margines pary („6 px między ikonami") obowiązuje **tylko kosza stojącego za
>   ołówkiem** — bo przy samotnym koszu w wierszu kolejki poczty ściągał go do plakietki,
>   z którą pary nie tworzy. Po poprawce 6 px, tak jak na `/admin/grupy`, a ołówek we wszystkich
>   wierszach stoi w jednej kolumnie (x = 1751).
> - **Kafel „Weryfikacja 2-etapowa" czyta konfigurację** (U3): twierdził „wymagana" na sztywno,
>   gdy `/diagnostyka` w tej samej instalacji mówiła „WYŁĄCZONE (ADMIN_2FA_REQUIRED=0)". Teraz
>   przy 0 jest „wyłączona" w barwie odchylenia, z podpisem `ADMIN_2FA_REQUIRED=0`, i to samo
>   w wierszu karty niżej. Z dwóch ekranów o stanie zabezpieczenia nie wybiera się już,
>   który kłamie.
> - **Zdanie wiodące `/admin/widocznosc`** (U5) nie mówi już „Przełożeni widzą swoich podwładnych
>   **zawsze**" — po Z53 i Z55 nie jest to prawda: zakres przełożonych zmienia się ołówkiem
>   w pierwszym wierszu, a administratorzy mają drugą regułę domyślną.
> - **`/admin/integracja` bez ścieżki w belce** (U6): jest pozycją menu, więc ma jeden tytuł,
>   jak `/admin/konta`. Stało tam „Administracja /" — nazwa grupy menu w roli ekranu nadrzędnego,
>   czyli ścieżka, której pierwszy człon nigdzie nie prowadzi. Podstrony (`/admin/podlaczenie`,
>   potwierdzenia) ścieżkę mają i zostają bez zmian.
> - **Tabela „Ostatnie przebiegi" pokazuje nazwę wyświetlaną firmy** (U6), nie pełną nazwę
>   prawną z R2 (zmierzone: 51 znaków zamiast 6). Tożsamość firmy niesie na tym ekranie NIP.
> - **Wiadomość próbna: widać wynik i nie wyjdzie dwa razy** (U7). Trzy rzeczy naraz:
>   * ⚠ **komunikat był niewidoczny, nie brakujący** — okno otwarte przez `showModal()` siedzi
>     w warstwie wierzchniej razem ze swoim przygaszeniem, a stos komunikatów stał w zwykłym
>     przepływie, więc toast rysował się **pod** tłem okna. Żaden `z-index` tego nie zmienia.
>     `modules/flash.js` przenosi teraz pojemnik do otwartego okna modalnego; zmierzone po
>     poprawce: `elementFromPoint` w środku toastu trafia w sam toast.
>   * **przycisk gaśnie na czas żądania** i mówi „Wysyłam…" (`data-zajety`, obsługa w `ui.js`).
>     ⚠ Wyłączenie idzie przez `setTimeout(…, 0)`, bo przeglądarka buduje listę pól **po**
>     zdarzeniu `submit`: wyłączony na miejscu przycisk zgubiłby swoje `name`/`value`, czyli
>     zamieniłby „Wyślij próbę" w zwykły zapis adresu. Zmierzone: w żądaniu dalej jest `proba=1`.
>   * **zdanie o wyniku mówi, którą drogą wiadomość poszła** — „zapisana do pliku w var/mail"
>     na środowisku bez SMTP, „Wysłano na …" przy SMTP. Jedno źródło dla obu ekranów.
> - **Dziennik zdarzeń mówi o regułach zdaniem, nie kodami** (U2): było
>   „account:4 → persons · ze_szczegolami", jest „nadał(a) dostęp do kalendarza: <nazwisko> ›
>   <nazwiska> · ze szczegółami". Nazwy składa ekran przy odczycie, w treści wpisu zostają
>   identyfikatory (Z53 §5). ⚠ Znak **„›", nie „→"**: strzałki nie ma w kroju pisma i rysuje się
>   jako pusty prostokąt. Reguły stałe firmy mają własne zdanie („zmienił(a) zakres reguły
>   Administratorzy: ze szczegółami › bez szczegółów"), bo da się w nich zmienić wyłącznie zakres.

> **Zmiana 1.18.35 → 1.18.36: ekrany sprzed zalogowania — spokojne zdanie zamiast strony
> „formularz wygasł"** (zlecenie Z56, 20.10.2026).
> - **Cztery ekrany** (logowanie, prośba o reset, nowe hasło z linku, zaproszenie) mają teraz
>   slot komunikatu **z rodzajem podanym przez serwer** (`notice_rodzaj`). Powód: ten sam slot
>   niesie dwie różne rzeczy — potwierdzenie czynności („Wylogowano.", zielone) i spokojne
>   wyjaśnienie, że karta stała otwarta („Strona była otwarta długo — zaloguj się jeszcze raz.",
>   `info`). Jeden kolor dla obu znaczyłby „udało się" tam, gdzie nic się nie wydarzyło.
> - ⚠ **Nie czerwono i nie „błąd".** Nic się nie zepsuło i człowiek nie zrobił nic źle — pomylona
>   jest tylko długość przerwy. Zmierzone po zmianie: w odpowiedzi nie ma `notice--danger`,
>   kod 200 (nie strona 419), adres zostaje w polu, hasło nie wraca.
> - **Formularz wraca z wpisanym adresem** (albo z tokenem linku przy ustawianiu hasła) i ze
>   świeżym tokenem. Pole hasła jest puste — i to jest decyzja o bezpieczeństwie, nie o wygodzie.
> - **Nowy, niewidoczny znacznik** `partials/odnowienie-tokenu.twig` (`<div hidden data-token-*>`)
>   wyłącznie na tych czterech ekranach; `modules/token.js` czyta z niego adres endpointu i próg.
>   ⚠ Znacznik jest **jedynym** wyzwalaczem tego modułu: dołożony gdziekolwiek indziej robiłby
>   z tego odnawianie sesji zalogowanej w tle.

> **Zmiana 1.18.34 → 1.18.35: `/admin/widocznosc` — drugi wiersz stały „Administratorzy",
> token CSRF schodzi z adresu** (zlecenie Z55, 20.10.2026).
> - **Drugi wiersz stały, pod „Każdym przełożonym"**: ikona klucza, „Administratorzy" / „N kont
>   z prawem zarządzania widocznością · reguła domyślna firmy" › „Cała firma" › zakres. Ołówek
>   bez kosza, tak samo i z tego samego powodu, co u przełożonych. ⚠ Klucz, nie oko: oko jest
>   znakiem całego ekranu, więc w wierszu nie odróżniałoby niczego.
> - ⚠ **Administrator widzi domyślnie wszystko, ze szczegółami** (decyzja Krzysztofa, odwołanie
>   rozstrzygnięcia z 23.09) — i jest to **reguła, nie wyjątek w kodzie**, bo klient z zewnętrznym
>   informatykiem ma móc ten wgląd zabrać jednym ołówkiem. Uzasadnienie w pełnej postaci:
>   `docs/etap3-logika-urlopowa.md` §8b.
> - **W oknie tej reguły kroki 1 i 2 są WYŁĄCZONE**, a nie ukryte: nie ma w nich czego wybrać
>   (podmiotem są wszystkie konta z uprawnieniem, celem cała firma), a kliknięcie prowadziłoby
>   na listę, z której „administratorów" wybrać się nie da — czyli w ślepą uliczkę. Kafelki
>   zostają przygaszone, żeby okno dalej mówiło, ile kroków ma normalnie.
> - **Wiersz kandydata mówi, skąd konto ma dostęp**: „bez etatu w tej firmie" albo „dostęp do
>   wszystkich firm · bez etatu". Druga linia jest **daną**, nie ozdobą — od niej zależy, czy
>   regułę wolno zapisać, a administrator ma to wiedzieć przed kliknięciem, a nie z odmowy.
> - ⚠ **Token CSRF zszedł z ukrytego pola na przycisk zapisu.** To jeden formularz, który
>   `POST`-em zapisuje, a `GET`-ami przełącza kroki i szuka — ukryte pole szło z każdym z nich,
>   więc token lądował w pasku adresu, w historii i w logach serwera. `name`/`value` przycisku
>   przeglądarka wysyła **tylko wtedy, gdy to on wysłał formularz**. Zmierzone po zmianie: po
>   „Dalej" i po Enter w szukaniu w adresie nie ma `_csrf`, a zapis przechodzi z JavaScriptem
>   i bez niego; żądanie bez tokenu dalej dostaje 419.
> - ⚠ **Naprawiona rozbieżność znacznika firmy i treści kalendarza.** Kalendarz budował własną
>   białą listę firm (etaty + firmy z regułami), więc po dołożeniu reguły „Administratorzy" konto
>   bez etatu miało w belce jedną firmę, a w siatce ludzi z drugiej — zmierzone: znacznik
>   `data-spolka="3"`, a na ekranie 53 osoby z firmy 4. Lista firm do oglądania powstaje teraz
>   w jednym miejscu (`KontekstSpolki::spolki()`), tym samym, z którego bierze ją znacznik.

> **Zmiana 1.18.33 → 1.18.34: Enter w polu szukania szuka, a nie idzie dalej; bramka pilnuje
> nawiasów w arkuszach** (zlecenie Z54, 20.10.2026).
> - ⚠ **Enter w polu szukania w oknie reguły przerzucał okno do następnego kroku** (zmierzone:
>   krok 1 → krok 2). To nie była usterka tego ekranu, tylko reguła HTML: przeglądarka na Enter
>   klika **pierwszy przycisk wysyłający w kolejności drzewa**, a był nim „Dalej". Jedynym
>   sposobem, żeby to zmienić bez JavaScriptu, jest postawić w formularzu własny przycisk
>   wysyłający **przed** tamtym — niewidoczny, poza kolejnością tabulacji, z **tym samym numerem
>   kroku**. Enter robi więc dokładnie to, co pisanie w polu: szuka. Zasada ogólna: **formularz,
>   w którym pierwszy `submit` znaczy co innego niż Enter w jego polu tekstowym, jest źle
>   złożony** — i widać to dopiero wtedy, gdy ktoś naciśnie Enter.
> - ⚠ Przy okazji poprawka drogi **bez JavaScriptu**: podmiot reguły jedzie teraz w ukrytych polach
>   także w kroku 1. Bez nich żądanie z Enter składało się z samych pól formularza (rodzaj podmiotu
>   niesie zakładka w adresie), więc serwer wracał do wartości domyślnej i pokazywał listę
>   przełożonych zamiast kont — zmierzone: zero pozycji. Zaznaczona pozycja listy i tak wygrywa
>   z tymi polami, bo niesie rodzaj i numer razem.
> - **Bramka `check-rules` sprawdza teraz parowanie nawiasów klamrowych w KAŻDYM arkuszu**, także
>   w `00-tokens.css` (wyjątek dotyczy wartości kolorów, nie składni). Dwa komunikaty: „nadmiarowy
>   `}`" — bo przeglądarka odrzuci po nim **następną regułę**, i „niezamknięty blok" — bo wciągnie
>   do siebie wszystko, co po nim stoi. Komentarze i napisy w cudzysłowach schodzą przed liczeniem,
>   więc `content: "}"` nie udaje nawiasu bloku. Oba przypadki sprawdzone na celowo zepsutej kopii
>   arkusza; na obecnym stanie repozytorium kryterium jest zielone.

> **Zmiana 1.18.32 → 1.18.33: `/admin/widocznosc` — nowy ekran reguł dostępu do kalendarza,
> szczegółowość osobna dla każdego wiersza** (zlecenie Z53, 19–20.10.2026).
> - **Nowy ekran**: kafle stanu, wiersz reguły domyślnej („Każdy przełożony › Swoi podwładni",
>   bez kosza — miejsce po nim zostaje **puste, bez ramki**, bo kwadrat w obrysie czytał się jak
>   przycisk wyłączony, czyli „tu da się coś zrobić, ale nie teraz"), pod nim reguły dodatkowe.
>   Wiersz ma trzy człony relacji (KTO WIDZI › CZYJE NIEOBECNOŚCI › ZAKRES); na telefonie
>   rozkłada się na trzy linie, a strzałka znika, bo kierunek niesie kolejność.
> - **Okno reguły: trzy kroki w jednym formularzu.** Kafelki kroków stoją **w nagłówku, pod
>   tytułem** — 16 px odstępu, bez kreski między tytułem a kafelkami, kreska dopiero pod nimi;
>   postawione nad treścią były przycinane dolną krawędzią nagłówka i wyglądały na urwane.
>   Ciało okna ma **stałą wysokość 477 px** (nazwany wyjątek jak `.assign__list`, §12), więc okno
>   nie skacze między krokami; przewija się wyłącznie lista pozycji. ⚠ Na telefonie ciało okna
>   **przewija się** (`overflow-y: auto`): przy 375 × 760 px zostaje na nie 400 px, a krok 3 ma
>   485 px treści — zmierzone, czyli 85 px podglądu znikało pod krawędzią.
> - **Listy wyboru** (kroki 1 i 2) to jedna kontrolka: wyszukiwarka z lupą **w polu**, ramka
>   z przewijaną listą, stopka z licznikiem **wewnątrz ramki** (licznik mówi o jej zawartości,
>   więc postawiony poza nią czytał się jak podpis całego kroku). Doczytywanie po 20 pozycji
>   wyzwala **ostatni wiersz** (`intersect once`), a nie przycisk; przycisk zostaje
>   w `<noscript>`. ⚠ `revealed` nie działa: lista przewija się we własnym pudełku, więc
>   zdarzenie przewijania strony do niej nie dociera (zmierzone: po czterech przewinięciach
>   lista nadal miała trzy pozycje z dziewięciu).
> - **Pierwsza pozycja listy, oddzielona kreską**, to wybór innego rodzaju niż nazwisko:
>   „Wszyscy przełożeni" (ikona drzewa na zielonym tle) w kroku 1 i „Cała firma" (ikona budynku)
>   w kroku 2. Postawione nad wyszukiwarką wyglądały jak „zaznacz wszystko", czyli jak coś
>   zupełnie innego. ⚠ Zieleń znaczy tu **akcję** („to obejmuje każdego"), nie stan dobry (§2).
> - ⚠ **Zaznaczona „Cała firma" przygasza wiersze osób i zapala na nich ptaszki** (`opacity: .5`,
>   nadal klikalne): puste znaczniki obok zaznaczonej „całej firmy" mówiłyby coś przeciwnego do
>   prawdy. Wyszukiwarka jest wtedy **wyłączona**, a nie ukryta — pole, które znika i wraca,
>   przestawiałoby wysokość ramki przy każdym kliknięciu. Przy wpisanym tekście pozycja
>   „Cała firma" znika z listy.
> - ⚠ **Zaznaczenie idzie za POLEM, nie za klasą z serwera** — `:has(:checked)` w arkuszu,
>   w kartach zakresu i w wierszach list. Zmierzone 19.10.2026: klik ustawiał `input.checked`,
>   ale tło i kółko zostawały tam, gdzie postawiła je odpowiedź serwera, więc do następnego
>   żądania człowiek widział zaznaczone co innego, niż naprawdę wybrał. Przeglądarka bez `:has()`
>   dostaje widoczne pola wyboru (`@supports not selector(:has(*))`).
> - **Krok 3 pokazuje SKUTEK, nie przykład**: pięć dni w tych samych tokenach, których użyje
>   kalendarz, z numerem dnia w lewym górnym rogu i nazwą u dołu (z wielokropkiem), pustym dniem
>   w środku. Oba warianty stoją w znaczniku, arkusz pokazuje ten, którego pole jest zaznaczone —
>   tekstu nie da się przełączyć samym stylem, a tekst w CSS to tekst poza Twigiem.
> - ⚠ **Dwie liczby z prototypu zostały zastąpione wartościami ze skali** (rozstrzygnięcie
>   zarządcy 20.10.2026): odstęp kafelków 8 px (`--s-2`) zamiast 6 px, napis 11 px (`--t-label`)
>   zamiast 10,5 px. Skala odstępów dopuszcza **tylko** swoje wartości, a dopisywanie tokenu dla
>   jednego podglądu kosztowałoby więcej niż dwa piksele różnicy.
> - **Kolumna CZYJE przy regule na wskazane osoby**: ikona osoby i nazwiska po przecinku
>   (do trzech w całości, przy większej liczbie dwa plus „i N innych"), pełna lista w `title`.
>   Zwolnieni mają nazwisko **przekreślone** — ta sama definicja wyglądu, co na `/admin/konta`
>   (Z32), dopisana do istniejącej reguły, a nie powtórzona. Reguła bez ani jednej czynnej osoby
>   dostaje szarą plakietkę „nieaktywna — brak czynnych osób" i **zostaje na ekranie**; ołówek
>   niesie wtedy wyłącznie osoby czynne.
> - **Kalendarz**: pasek bez szczegółów ma własny token wypełnienia (`.team-grid__bar--bez`)
>   i napis „nieobecny”, a legenda nazywa go wprost (`.legend__mark--bez`). Szczegółowość bywa
>   **różna w różnych wierszach tej samej siatki** — uzasadnienie jednolitości zostało odwołane
>   wprost w `docs/etap3-logika-urlopowa.md` §8b.
> - **Panel osoby traci trójstanowy przełącznik widoczności**; w jego miejsce stoi odnośnik
>   do `/admin/widocznosc`, bo „kto widzi kogo" jest relacją, a panel jednej osoby pokazuje
>   tylko jej połowę.
> - ⚠ **Dwie pułapki arkusza i szablonu, zmierzone przy tym zleceniu** — obie dają kod, który
>   wygląda dobrze i nie działa:
>   1. **nadmiarowy `}` kasuje NASTĘPNĄ regułę**, a nie sam nawias (przeglądarka czyta go jako
>      początek preludium kolejnej reguły i odrzuca ją w całości; tak samo znika `@media`
>      postawiony po nim). Stąd osobna naprawa w `main`: jeden taki nawias wyłączał cały blok
>      `@media (pointer: coarse)`, czyli wysokość celów dotykowych i 16 px w polach, na każdym
>      ekranie telefonu i tabletu;
>   2. **komentarz `{#- … -#}` między atrybutami skleja je**, bo myślniki kasują odstęp.
>      Przy atrybucie z wartością w cudzysłowie to tylko błąd składni, po którym parser wraca
>      do rozpoznawania nazw; przy atrybucie **bez wartości** (`disabled`) powstaje jedna nazwa
>      `disabledhx-get` i atrybut nie działa. Komentarz między atrybutami pisze się bez myślników.

> **Zmiana 1.18.31 → 1.18.32: `/admin/grupy` — cały stan ekranu odświeża się po zmianie składu,
> karta traci własny tytuł** (zlecenie Z52 z dopiskiem, 19.10.2026).
> - **Zgłoszenie:** po dodaniu albo usunięciu osób w oknie członków karta „Osoby bez grupy"
>   nie zmieniała się do przeładowania strony. Przyczyna była głębsza niż jedna karta: odświeżanie
>   opisał **JavaScript** (`modules/grupy.js` wołał własne `htmx.ajax` z wyborem `#grupy-lista`),
>   czyli **drugie miejsce**, w którym zapisano, co zależy od składu grup — i to miejsce znało
>   wyłącznie listę grup.
> - **Teraz mówi to szablon, przy elementach, których dotyczy.** Serwer odsyła po zapisie
>   `HX-Trigger: grupy-zmienione` („co się stało"), a pasek kafli nasłuchuje tego zdarzenia
>   i jednym `GET`-em na adres ekranu pobiera cztery obszary: kafle, listę grup, plakietkę
>   i treść karty „Osoby bez grupy" oraz okna grup (`hx-select-oob`). ⚠ **Jedno liczenie**:
>   wszystkie liczby powstają tą samą drogą, co przy pierwszym wejściu — w przeglądarce
>   nie jest przeliczana żadna.
> - ⚠ **Wymieniana jest PLAKIETKA i TREŚĆ, nie cały `<details>`**: pudełko niesie stan `open`,
>   którego serwer nie zna, więc podmiana całości zwijałaby rozwiniętą listę przy każdej zmianie.
> - ⚠ **Okna grup odświeżają się razem z wierszami** (`#grupy-okna`) — to naprawa usterki:
>   `<dialog id="grupa-usun-N">` powstaje wyłącznie dla grupy PUSTEJ, a wspólna obsługa okien
>   przepuszcza wysłanie formularza, gdy okna o danym identyfikatorze nie ma. Po opróżnieniu grupy
>   odświeżony kosz wskazywał więc okno, którego na stronie nie było, i **jedno kliknięcie kasowało
>   grupę bez potwierdzenia**. `grupa-okno` i `grupa-nowa` stoją poza tym pojemnikiem, bo pierwsze
>   bywa otwarte w chwili odświeżenia.
> - **Karta listy traci własny tytuł** (dopisek do zlecenia). Dawny nagłówek brzmiał „Grupy
>   w firmie X" — pierwsze słowo powtarzało nazwę ekranu z belki (kryterium bramki
>   `nazwa_ekranu_w_belce`), a reszta nazwę firmy ze znacznika obok. Zostaje znak i zdanie
>   wiodące 14 px (`.card__opis--lead`) ze zdaniem 12 px pod nim — ta sama konstrukcja,
>   co na `/admin/konta` i `/dane`.
> - **Nazwa firmy schodzi też z podpisu kafla** („utworzonych w tej firmie") **i z podtytułów
>   okien** grupy. Zostaje w nagłówku kolumny „Pozostali w firmie X" w oknie członków, bo tam
>   przeciwstawia dwa zbiory, a nie powtarza znacznik.
> - ⚠ Bez JavaScriptu nic się nie zmienia: okna grupy nie ma, zapisy to `POST` z przekierowaniem,
>   czyli strona i tak przychodzi od nowa.

> **Zmiana 1.18.30 → 1.18.31: `/dane/pracownik/{id}/kalendarz` — ten sam ekran, co „Tylko ja ·
> Miesiąc", z danymi wskazanej osoby** (zlecenie Z51, 19.10.2026).
> - ⚠ **Zero nowych klas i zero nowego CSS.** Ten wpis jest tu dlatego, że **zniknął** jeden układ,
>   a nie dlatego, że przybył drugi: kalendarz pracownika miał własną siatkę (`table.month-grid`
>   z nazwami nieobecności wpisanymi w komórkę) i własną tabelę wpisów pod nią. Pracownik oglądał
>   swój miesiąc w jednym kształcie, a kadry **ten sam miesiąc tej samej osoby** w zupełnie innym —
>   więc każda poprawka wyglądu nieobecności wchodziła w jedno miejsce z dwóch.
> - **Różnicą jest OSOBA TRZECIA, nie układ.** Siatka, wykaz wpisów obok niej, limit pięciu pozycji
>   i odnośnik „wszystkie (N)" idą z `partials/miesiac-ja.twig`; nagłówek kolumny wpisów, zdanie
>   dla zera wpisów i adres pełnego wykazu stały się jego **parametrami**, bo to jedyne, co się
>   między ekranami różni („NIEOBECNOŚCI W MIESIĄCU" zamiast „TWOJE NIEOBECNOŚCI").
> - **Blok nagłówka: nazwa miesiąca i legenda w pierwszym wierszu, strzałki okresu w drugim**, a po
>   prawej — tam, gdzie na `/kalendarz` stoi przełącznik widoku — jeden kwadrat `.kal-btn` „Karta"
>   (`aria-label` „Karta pracownika"). ⚠ **Podtytułu nie ma**: zdanie o pochodzeniu danych zeszło
>   do bloku objaśnień, razem z pozostałymi zdaniami o tym samym. `.page__head` z tego ekranu znikło.
> - ⚠ **Osobny plik nagłówka (`partials/naglowek-kalendarza-osoby.twig`), a nie parametr
>   `naglowek-kalendarza.twig`.** Tamten buduje adresy z `widok`, `skala` i `?osoby=`, liczy pozycje
>   przełącznika widoku i dołącza kontrolkę wyboru osób — pięć rzeczy, których ten ekran nie ma.
>   Współdzielone jest to, co naprawdę wspólne: **klasy i legenda**, czyli ani jednej nowej reguły.
> - ⚠ **Objaśnienia w TRZECIEJ OSOBIE**: ekran oglądają kadry, więc „Twojego rozkładu czasu pracy"
>   byłoby nieprawdą. Rozkładów bywa **kilka** — 6 z 88 osób w danych roboczych ma etat w dwóch
>   firmach — i wtedy zdanie wymienia wszystkie, bo wygaszone kratki są ich sumą.
> - ⚠ **Jedna siatka dla jednej osoby, także przy kilku etatach** (`KalendarzPracownika::widokMiesiaca()`).
>   `widokJa()` jest od tej pory jednolinijkowym wywołaniem tej metody dla jednego zatrudnienia —
>   drugi kształt miesiąca w tym systemie już był i właśnie go kasujemy.

> **Zmiana 1.18.29 → 1.18.30: `/diagnostyka` — wynik w kaflach, pozycje nie-OK zebrane w jednej
> karcie, grupy jako `<details>`** (zlecenie Z50, 19.10.2026).
> - **`.page__head` i linia plakietek „OK / Ostrzeżenia / Błędy" znikają.** Zdanie o instalatorze
>   stoi w nagłówku karty „Wszystkie sprawdzenia", a liczby — w kaflach. Dawna linia była czwartym
>   miejscem, w którym trzeba je było czytać.
> - **Cztery kafle**: Wynik („N błędy" / „N ostrzeżenia" / „wszystko w porządku"; barwa idzie za
>   NAJWYŻSZYM stanem, bo jeden błąd przesłania dziesięć ostrzeżeń), Sprawdzenia OK („N" i „z M
>   sprawdzeń"), Błędy („blokuje / blokują działanie"), Źródło („na żywo"). ⚠ Odmiana liczebnika
>   zostaje w PHP (`Support/Odmiana::bledy()`, `ostrzezenia()`, `sprawdzenDopelniacz()`) — „13 błędy"
>   w kaflu, który ma powiedzieć „napraw to", czyta się jak usterka ekranu, a wtedy czytelnik
>   podważa też samą liczbę.
> - ⚠ **Jedno liczenie, nie dwa.** Kafle, plakietki grup i karta „Do sprawdzenia" pokazują te same
>   liczby w trzech miejscach, więc sumy całej strony powstają PO pętli, z liczników grup. Licznik
>   prowadzony obok pętli rozjeżdża się przy pierwszym warunku dopisanym tylko w jednym miejscu.
> - **Karta „Do sprawdzenia" zbiera pozycje nie-OK z CAŁEJ strony** — błędy przed ostrzeżeniami,
>   w każdym z tych bloków kolejność grup ze strony. Jest tylko wtedy, gdy jest co zebrać. Znak
>   nagłówka bierze barwę najwyższego stanu (bursztyn, przy błędzie czerwień). Pozycja niesie
>   nazwę grupy pod nazwą sprawdzenia, bo jest wyrwana z kontekstu.
> - **Grupy to natywne `<details>`** (`.diag-grupa`), więc rozwijają się bez JavaScriptu.
>   Podsumowanie: ikona grupy 18 px, nazwa, plakietka „N/M OK" (szara) albo „K do sprawdzenia ·
>   N/M OK" (bursztynowa, przy błędzie czerwona), strzałka obracana o 180°. **Grupa z czymkolwiek
>   do sprawdzenia jest otwarta, czysta — zwinięta.**
> - ⚠ **Kółko stanu 22 px, a OK jest OBOJĘTNE** (`--c-neutral-soft` / `--c-text-3` z ptaszkiem),
>   nie zielone: zieleń w tym systemie znaczy akcję, a pięćdziesiąt zielonych kółek to ściana,
>   w której nie widać czterech bursztynowych. ⚠ Ptaszek jest IKONĄ, nie znakiem „✓": znak brałby
>   krój z otoczenia i zależał od podzbioru czcionki (patrz strzałka „→" w 1.18.26).
> - ⚠ **Stan niesie też słowo, nie tylko barwę** (`visually-hidden` w kółku: „W porządku",
>   „Do sprawdzenia", „Błąd") — kolor nie jest treścią.
> - **Reset instalacji (tylko dev)**: ramka-ostrzeżenie „Operacja jest nieodwracalna…" ZNIKA
>   z wnętrza karty — to samo mówią znak w barwie błędu i podpis „Tylko w trybie deweloperskim.
>   Operacja nieodwracalna.", a pasek powtarzał je trzecim głosem. Przycisk „Wyczyść instalację…"
>   dostaje `.btn--ryzyko` (geometria `.btn--ghost`, barwy `--c-danger`) i **okno potwierdzenia**;
>   pełna czerwień `.btn--danger` zostaje dla przycisku W OKNIE, czyli tam, gdzie decyzja zapada.
> - ⚠ **Tabela `.diag-group` ZOSTAJE** w `partials/diagnostics-table.twig`: obsługuje krok 1 i 7
>   INSTALATORA, których to zlecenie nie dotyczy. Nowa rodzina ma własny przedrostek `diag-wiersz`,
>   zamiast przerabiać tamtą.
> - **Telefon (≤ 640 px)**: wiersz schodzi do trzech kolumn (kółko, nazwa, wartość), objaśnienie
>   wchodzi pod nazwę. Cztery kolumny przy 375 px dawałyby wartości ~60 px, czyli nagłówek HTTP
>   po jednym znaku w linii.

> **Zmiana 1.18.28 → 1.18.29: `/licencja` — stan w kaflach, szczegóły w wierszach**
> (zlecenie Z48, 19.10.2026).
> - **`.page__head` znika** („Stan licencji tej instalacji."): to samo zdanie stoi w nagłówku karty,
>   której dotyczy, a cztery dane, po które się tu wchodzi, są w pasku kafli.
> - **Cztery kafle**: Stan licencji (krótka nazwa z kropką 10 px), Ważna do, Aktualizacje do,
>   Konta. ⚠ Kropka ma `--duza` (10 px zamiast 8), bo stoi SAMA przy krótkim słowie, a nie
>   w wierszu obok innych danych — przy 8 px ginęła między ikoną głowy a napisem.
>   ⚠ **Barwa kropki idzie za STANEM, nie za „dobrze / źle"**: tryb deweloperski nie jest ani
>   sukcesem, ani awarią, więc dostaje błękit „dziś" z kalendarza (`--c-dzis-text` na
>   `--c-dzis-bg`) — ten sam, którym oznaczamy stan przejściowy. Zieleń zostaje dla licencji,
>   która naprawdę obowiązuje.
> - **„Aktualizacje do" bez daty mówi „brak daty", nie „—"**: upływ tej daty nie ogranicza
>   działania aplikacji, blokuje tylko instalację nowszych wersji, a myślnik czytałby się
>   jak brak uprawnienia. Po terminie wartość idzie na `--c-warn-text`.
> - **Kafel „Konta" bierze liczby z tego samego wywołania, co kafel „Licencje" na `/admin/konta`**
>   (`AccessService::licenceUsage()`) — bez drugiego liczenia. Dwa ekrany o jednej licencji muszą
>   mówić to samo. W trybie ograniczonym limit wynosi 0, więc kafel pokazuje przekroczenie
>   i to jest prawda: nowych dostępów nie wolno wtedy nadać.
> - **Szczegóły w wierszach `.licencja-wiersz`** (kolumna etykiet 220 px, separator `--c-line-soft`),
>   a nie w `.defs`: tamte układają pary w siatce, która przy dwóch wierszach zostawia pustą drugą
>   kolumnę. Kod stanu w ramce `.kod` — wartość do przepisania do zgłoszenia, nie zdanie.
>   ⚠ `.kod` **nie wprowadza drugiego kroju**: `.mono` i `<code>` w tym systemie znaczą „cyfry
>   o stałej szerokości", a nie inny font (§4) — ramka i tło robią z kodu wartość do skopiowania.
> - ⚠ **Wiersza „Rodzaj instalacji" NIE MA, bo licencja go nie niesie**: podpisany ładunek ma
>   `license_id`, `installation` (nazwę hosta — jej ekran nie pokazuje, §15), `issued_at`,
>   `expires_at`, `updates_until`, `limits` i `features`. Wiersz z wymyśloną wartością byłby
>   gorszy niż jego brak. „Ograniczenia" pokazują limity i funkcje, gdy licencja jakieś niesie.
> - ⚠ **Zdanie karty mówi, kiedy stan powstaje, i mówi prawdę**: „Odczytane przy tym żądaniu —
>   odśwież stronę, żeby sprawdzić ponownie". Prototyp proponował „odczytany przy starcie
>   aplikacji", ale `LicenseGate` czyta plik przy pierwszym pytaniu w ŻĄDANIU i pamięta wynik
>   do jego końca; startu, który by to przeżył, ta aplikacja nie ma.
> - **Notka o wgraniu licencji tylko tam, gdzie licencji nie ma** (tryb deweloperski albo
>   instalacja bez pliku, `L100`): przy licencji wygasłej albo odrzuconej zdanie „po wgraniu
>   pojawią się dane" byłoby nieprawdą — dane już są, tylko licencja nie obowiązuje.
>   ⚠ Notka nie mówi, KTO wgrywa plik: zlecenie zakładało instalatora, a `docs/specyfikacja.md`
>   mówi „tenant dzwoni do panelu cyklicznie po odświeżenie licencji" — żadna z tych dróg nie jest
>   jeszcze napisana w kodzie.
> - **Zmierzone wszystkie siedem stanów, które zna kod licencji** (zaślepka
>   `dev/probka-licencji.php` podpisuje licencję własnym kluczem i oddaje stan zastany co do bajtu):
>   `L001` deweloperska (błękit), `L000` ważna (zieleń), `L200` wygasła-karencja (bursztyn),
>   `L108` po karencji, `L105` licencja innej instalacji, `L103` zły podpis, `L101` nieczytelny
>   klucz publiczny — wszystkie cztery ostatnie jako „nieważna" (czerwień).
> - **Telefon (375 px)**: kafle po dwa, etykieta nad wartością (kolumna 220 px zostawiałaby
>   wartości 100 px na identyfikator licencji). 0 przewijania w poziomie.

> **Zmiana 1.18.27 → 1.18.28: karta pracownika — limity z paskiem zamiast tabeli, stan bez
> plakietki, rodzaje nieobecności w barwach kalendarza** (zlecenie Z46, 19.10.2026).
> - **`.page__head` znika**: firma, numer kadrowy i data zatrudnienia stoją w karcie
>   „Zatrudnienie", więc nagłówek powtarzał ją słowo w słowo. Przycisk „Pokaż w kalendarzu ›"
>   zszedł do tej karty — dotyczy zatrudnienia, nie ekranu. Karta `.card.stats` ustąpiła paskowi
>   czterech kafli (Pozostało urlopu, Wykorzystane, Nieobecności w roku, Etat).
> - ⚠ **„Pozostało urlopu" BEZ zieleni**: to dana, a nie dobry stan (§3.1). Barwę dostaje dopiero
>   odchylenie — przy ujemnym saldzie kafel idzie na `--c-warn-text`. Bez limitu w kadrach:
>   „—" i podpis „brak limitu w kadrach", a nie „0 dni" (zero znaczyłoby „limit jest i wynosi zero").
>   Przy dwóch zatrudnieniach kafel **sumuje** limity tego samego rodzaju, bo odpowiada na pytanie
>   „ile mam urlopu", a nie „ile w tej jednej firmie" — rozbicie stoi niżej, na karcie limitów.
> - **Stan zatrudnienia BEZ plakietki** (`.stan-kropka`): plakietka robiła ze stanu drugą daną tej
>   samej wagi, co firma i etat. Zostaje słowo z kropką 8 px — ta sama sygnatura, co „połączony"
>   na ekranie integracji. ⚠ Kropka zakończonego zatrudnienia jest **obojętna i bez obwódki**:
>   zakończone zatrudnienie nie jest błędem ani ostrzeżeniem, tylko stanem przeszłym.
> - **Limity bez tabeli** (`.limit-wiersz`): sześć kolumn liczbowych kazało czytać trzy liczby
>   i policzyć w głowie, jak się mają do siebie. Teraz nazwa po polsku (`Support\RodzajeLimitow`
>   — do dziś ekran pokazywał surowy kod z R2, `wypoczynkowy`), rok, saldo „pozostało **X** z Y",
>   pasek 10 px i pod nim „wykorzystane X (N%)" oraz „na start Y".
>   ⚠ **Pasek to natywny `<progress>` z wartością w atrybucie** — bez `style=` w szablonie (§6),
>   bo szerokość wypełnienia jest daną, nie wyglądem. `max` i `value` biorą **surowe minuty**,
>   a nie zaokrąglony procent: zaokrąglenie rysowałoby pasek odrobinę inny niż liczby obok niego.
>   Wypełnienie w barwie paska urlopu z kalendarza, prawa krawędź 2 px `--c-accent` — bez niej
>   koniec wypełnienia gubił się w tle przy niskim wykorzystaniu.
>   ⚠ **Przekroczenie to `remaining_min < 0`, nie `used > start`**: R2 przysyła też korekty, więc
>   te dwa warunki potrafią się rozjechać, a prawdę niesie pole wyliczone przez kadry. Wtedy pasek
>   jest pełny (wartość > maksimum rysuje się tak sama), a saldo idzie na `--c-danger-text`.
> - **Nieobecności**: tabela bez zebry, rodzaj jako plakietka w **barwach pasków kalendarza**
>   (`urlop` / `zasilek` / `kadrowe`), żeby ta sama nieobecność miała ten sam kolor na obu
>   ekranach; daty `DD.MM.RRRR`, dni cyframi tabelarycznymi, źródło 12 px `--c-text-4`.
>   ⚠ Modyfikator plakietki z jawnego warunku, nigdy sklejany z danych (§4b).
>   ⚠ **Fragment karty importuje ikony u siebie**: `{% import %}` jest lokalny dla szablonu,
>   a ten fragment bywa renderowany sam (odpowiedź htmx po przełączeniu roku) — bez tej linii
>   przełączenie roku kończyłoby się błędem i kartą, która znika po kliknięciu.
> - **Telefon (375 px)**: kafle po dwa, wiersz limitu zawija się nad paskiem (pasek 293 px),
>   nieobecności jako bloki — plakietka rodzaju na całą szerokość, pod nią daty i dni w dwóch
>   kolumnach z nazwą kolumny nad wartością. 0 przewijania w poziomie przy 1920, 1366 i 375.
> - ⚠ **Kafel nieobecności niesie liczbę WYCOFANYCH, gdy jakieś są** („wszystkie rodzaje ·
>   wycofanych: 8") — i to nie jest ozdoba podpisu. Przy przepisywaniu karty na kafle dopisek
>   wypadł z pierwszej wersji, a **bramka to złapała**: zostawało „0 dni" przy ośmiu plakietkach
>   „wycofana" w tabeli pod spodem, czyli dokładnie ta cisza, przez którą 10.10.2026 lista firmy
>   i karta osoby pokazywały dwie różne liczby. Kryterium `prawda_nieobecnosci` dostało nowy
>   selektor (`.kafel` zamiast `.stats__item`), bo mierzy to samo twierdzenie w innym elemencie.
> - **Zmierzone stany**: osoba bez limitu i z zakończonym zatrudnieniem (kafel „—", pusta karta
>   limitów, szara kropka bez obwódki), osoba z drugim rodzajem limitu („Opieka nad dzieckiem
>   (art. 188 k.p.)" osobnym wierszem), osoba z dwoma zatrudnieniami (dwa wiersze z nazwą firmy,
>   kafel równy ich sumie), limit przekroczony (pasek pełny, saldo czerwone, „33 dni (106%)").
>   Przekroczenia nie dało się pokazać na zastanych danych — ani jedna osoba nie ma ujemnego salda
>   w najnowszym roku — więc powstała próbka `dev/probka-limitu.php`, która rusza dwie liczby
>   jednego wiersza i przywraca je co do minuty. Przełączanie lat (2026 → 2025 → wszystkie → 2026)
>   zachowuje znak karty, zdanie, plakietki i format dat.

> **Zmiana 1.18.26 → 1.18.27: `/dane/firma/{id}` — jedna lista sortowania zamiast dwóch,
> stan zatrudnienia w nazwisku zamiast w kolumnie** (zlecenie Z45, 19.10.2026).
> - **`.page__head` znika** razem z przyciskiem „Wszystkie firmy" (powrót daje okruszek w belce)
>   i z napisem „Wynik: X z Y" — tę samą liczbę mówi stopka („Pokazano 1–25 z 37"), a dwa liczniki
>   jednej rzeczy rozjeżdżają się przy pierwszym nowym warunku. NIP zszedł do kafla, gdzie jest
>   daną, a nie dopiskiem do nazwy firmy.
> - **Trzy kafle `.kafle--auto`**: Zatrudnienia („z programu kadrowego"), NIP („firma z R2
>   Płatnik"), Tryb („tylko odczyt"). ⚠ Liczba w pierwszym kaflu to ta sama liczba, co „z N"
>   w stopce — obie z jednego zapytania o liczność.
> - **Filtry wchodzą do nagłówka karty** (`.card__head--lista`, jak na `/admin/konta`):
>   wyszukiwarka 240 px z lupą i bez widocznej etykiety, lista porządku 240 px z ikoną, „Pokaż".
>   ⚠ Pole jest węższe niż na liście osób (240 wobec 300 px), bo obok stoją jeszcze dwie
>   kontrolki — trzy po 300 px schodziły do drugiego rzędu już przy 1440 px.
> - ⚠ **JEDNA lista sortowania zamiast dwóch** („Sortuj" + „Kierunek"): sześć pozycji, które
>   mówią, co się stanie z danymi („Zatrudnieni najpóźniej"), a nie po czym i w którą stronę.
>   Dwie listy kazały wybierać dwa razy, żeby dostać jedną odpowiedź. Wartość **rozkłada serwer**
>   na tę samą parę (kolumna + kierunek), która jedzie do białej listy kolumn w repozytorium —
>   nazwy kolumny nie da się sparametryzować i to się nie zmienia (CLAUDE.md §4).
>   ⚠ **Stare adresy `?sortuj=nazwisko&kierunek=desc` dalej działają** i wybierają właściwą
>   pozycję listy: prowadzą do nich zakładki i zrzuty ekranu, a martwy adres, który po cichu
>   pokazuje inną kolejność, jest gorszy niż jedna gałąź warunku.
> - **Tabela bez zebry** (`.table--pracownicy`): pasy w co drugim wierszu rysowały siatkę, której
>   te dane nie potrzebują — wiersz rozpoznaje się po nazwisku, nie po pozycji. Zostaje separator
>   `--c-line-soft` i podświetlenie pod kursorem (dawała je zebra, więc trzeba było dołożyć).
>   Nagłówki 11 px wersalikami w jednej linii i cyfry tabelaryczne są w regule bazowej `.table`.
> - **Kolumna „Stan" znika.** Zakończone zatrudnienie niesie **przekreślone nazwisko** (Z32):
>   stan zatrudnienia jest cechą TEJ SAMEJ danej, więc nie zabiera kolumny na całą szerokość
>   tabeli. Przekreślenie jest wyglądem, więc obok stoi tekst ukryty wizualnie, a znak niesie
>   `title` z datą, jeśli kadry ją podały. ⚠ Kolumna miała dokładnie jedną wartość („zakończone"),
>   co sprawdzono przed usunięciem.
>   ⚠ **Ta sama pułapka specyficzności, co w Z32, trafiła tu drugi raz**: `.table--pracownicy
>   .table__name` maluje nazwisko na `--c-text` i stoi niżej w pliku, więc przy równej
>   szczegółowości wygrywało kolejnością — zmierzone na gotowym ekranie: kreska była, a napis
>   dalej czarny, czyli „skreślenie ręką". Selektor wyciszenia wymienia teraz obie tabele.
> - **Etat ma dwie formy, bo decyduje pozycja**: w kolumnie liczbowej sama liczba („0,50"),
>   a pełny opis („1/2 etatu") w `title` — ta sama zasada, co w `Odmiana::osoby()`
>   i `osobyDopelniacz()`. „0,50 (1/2 etatu)" w komórce rozpychało kolumnę i czytało się
>   jak dwie dane.
> - **Daty kalendarzowe jako `DD.MM.RRRR`** (`data_dnia`, `Support\DataDnia`): data zatrudnienia
>   nie ma godziny ani strefy, więc NIE przechodzi przez przeliczanie stref — to dałoby
>   przesunięcie o jeden dzień przy zmianie czasu.
> - **Telefon (375 px)**: kafle po dwa, filtry jeden pod drugim na całą szerokość (293 px),
>   wiersz jako blok — nazwisko z inicjałami w pierwszej linii, reszta w dwóch kolumnach
>   z nazwą kolumny nad wartością (`::before` z `data-kolumna`, bo `<thead>` w tym układzie
>   nie istnieje). 0 przewijania w poziomie przy 1920, 1366 i 375.
> - **Zmierzona zgodność z poprzednim układem**: każda z sześciu pozycji listy daje dokładnie tę
>   samą kolejność identyfikatorów, co odpowiadający jej stary adres (6/6), pełna lista „Nazwisko
>   Z–A" jest odwróconą „A–Z" (37 pozycji), stronicowanie niesie porządek i frazę, a wartość
>   spoza białej listy wraca do domyślnej.
>
> ⚠ Przy okazji: kolumna w wierszu firmy na `/dane` nazywa się **„Zatrudnienia"**, tak samo jak
> kafel nad listą — to ta sama liczba, a dwie nazwy jednej liczby każą pytać, która jest która.
> Trzy stany zgłoszenia (zatwierdzona, zgłoszona-czeka, odrzucona) zostały zmierzone na ekranie
> nową próbką `dev/probka-zgloszenia.php`, która zapamiętuje stan zastany i oddaje go co do znaku;
> do Z45 dwóch z nich nie dało się zobaczyć ani razu, bo na bazie roboczej wszystkie firmy
> są zatwierdzone.

> **Zmiana 1.18.25 → 1.18.26: adres skrzynki na alerty da się ustawić w aplikacji**
> (zlecenie Z49, 19.10.2026).
> ⚠ **Do 19.10.2026 tego adresu nie dało się zmienić z żadnego ekranu** — zapisywał go wyłącznie
> instalator, a na instalacji odniesienia był PUSTY. Zależą od niego trzy alerty, których nikt
> inny nie zauważy: cisza connectora, klucz wydany i nieużyty, podejrzenie ataku na wiele kont.
> Ekran pokazywał „nie ustawiono" i nie prowadził nigdzie — czyli nazywał stan, którego nie dawał
> naprawić. Ta zmiana wyszła z PROBLEMU zgłoszonego przy odbiorze Z43, nie z prototypu.
> - **Wiersz w karcie „Powiadomienia"**: `.settings-list--akcje` — ta sama konstrukcja, co wiersze
>   „Nazwy wyświetlane firm" i „Poczta wychodząca" (1.18.7), czyli cały wiersz jest odnośnikiem,
>   a przycisk jego częścią. Stan w pierwszej linii (adres albo plakietka „nie ustawiono"
>   bursztynowa), zdanie pod nim, przycisk **„Ustaw" przy pustym i „Zmień" przy ustawionym** —
>   napis mówi, co się stanie. To jedyna z trzech małych kart z działaniem; dwie pozostałe niosą
>   stany, których na tym ekranie się nie zmienia, i zostają parami `.ustaw-para`.
> - **Okno `.modal--alerty`** (520 px, na telefonie margines `--s-4`): pole adresu na całą
>   szerokość z `autofocus`, szara notka „Tu trafiają alerty systemowe: cisza connectora, nieużyty
>   klucz integracji, podejrzenie ataku na konta." i stopka o **dwóch stronach** — po lewej
>   „Wyślij próbę" (sprawdzenie), po prawej „Anuluj" i „Zapisz" (decyzje). Próba wśród decyzji
>   czytałaby się jak trzecia decyzja, a nie zmienia ani jednej wartości w bazie.
> - ⚠ **Okno osadzone w stronie, nie dociągane htmx-em** — ta sama decyzja i ten sam powód, co
>   w oknie nazw firm (Z29): działa **bez JavaScriptu**, bo przycisk jest zwykłym odnośnikiem
>   na `/ustawienia?okno=alerty`, a serwer renderuje wtedy `<dialog open>`. Wartość `okno`
>   pochodzi z białej listy dwóch nazw, nie z tekstu żądania.
> - ⚠ **Jeden formularz, dwa przyciski, jedna trasa.** Próba dotyczy adresu WPISANEGO w pole,
>   a nie zapisanego, więc musi przyjść tym samym żądaniem co jego treść; rozstrzyga nazwa
>   wciśniętego przycisku. Druga trasa znaczyłaby drugi odczyt tego samego pola i drugie miejsce
>   na walidację.
> - ⚠ **Wynik próby rozchodzi się dwiema drogami, bo czyta go człowiek w dwóch różnych stanach**:
>   sukces idzie do toastu (nic nie trzeba z nim zrobić), a **błąd zostaje przy polu** z czerwoną
>   ramką i `aria-invalid` (1.18.23), bo komunikat serwera SMTP trzeba przepisać do zgłoszenia,
>   a toast znika po pięciu sekundach. Przy sukcesie okno **zostaje otwarte z wpisanym adresem**:
>   przekierowanie zgubiłoby to, co człowiek właśnie sprawdził, a adresu nie wolno odesłać
>   w adresie strony, bo trafiłby do logów serwera (§14).
> - ⚠ **Pusty zapis wymaga potwierdzenia**, bo wyłącza alerty dla całej instalacji: pierwsze
>   żądanie wraca z oknem, zdaniem „Bez adresu alerty nie będą wysyłane." i polem wyboru. Rysuje
>   je SERWER i tylko wtedy, gdy adres naprawdę był ustawiony — pole pojawiające się po wpisaniu
>   wymagałoby skryptu, a to okno ma działać bez niego.
> - **Cztery komunikaty na jedno działanie**, bo cztery różne rzeczy mogą się stać: zapisano,
>   wyczyszczono, bez zmian, wysłano próbę. ⚠ Czwarty („bez zmian") dochodzi z pomiaru: zapis tego
>   samego adresu pokazywał „wyczyszczona" przy pustym polu i pustym adresie, czyli opisywał
>   zmianę, której nie było.
> - **Podpisy kafli prowadzą do okna**: na `/ustawienia` „ustaw w Powiadomieniach", na
>   `/ustawienia/poczta` „ustaw/zmień w Ustawieniach › Powiadomienia". ⚠ Na `/ustawienia/poczta`
>   stało dotąd „adres z instalatora" — i to był jedyny prawdziwy podpis, jaki dało się wtedy
>   napisać, bo innej drogi nie było.
>   ⚠ **Szewron „›" (U+203A), nigdy strzałka „→" (U+2192)**: podzbiór IBM Plex Sans, który
>   hostujemy, tej strzałki nie ma (`10-base.css` wymienia U+2191 i U+2193), więc w jej miejscu
>   stoi **pusty prostokąt**. Pierwsza wersja tych dwóch podpisów miała strzałkę i tak właśnie
>   się rysowała — zmierzone na zrzucie, nie wyczytane. ⚠ `document.fonts.check()` odpowiedziało
>   na to pytanie `true` i **było to nieprawdą**: sprawdza, czy którykolwiek dostępny krój
>   deklaruje znak, a nie czy rysuje się glif. Dowodem jest zrzut ekranu.
> - **Dziennik**: `tenant.alert_email_changed` z wartością PRZED i PO (adres alertowy to
>   konfiguracja tenanta, nie dana osobowa — bez poprzedniej wartości nie da się odtworzyć,
>   na co alerty szły wcześniej). Przy wyczyszczeniu zdanie mówi wprost „bez adresu, alerty
>   nie będą wysyłane". Wysłana próba zostawia `mail.sent` / `mail.failed` z Z43, tym samym
>   dekoratorem transportu.
> - **Zmierzone na żywo**: okno 520 px i pole 470 × 38 px przy 1920, okno 341 px i pole 291 px
>   przy 375 (0 przewijania w poziomie); serwer odmawia sam przy pominiętym sprawdzeniu
>   przeglądarki (`noValidate`) — błędny format, spacja w środku, 300 znaków; normalizacja
>   `  ALERTY@Skrzynka.TEST  ` → `alerty@skrzynka.test`; wyłączenie dopiero po potwierdzeniu.
> - **Alerty naprawdę wychodzą** (baza testowa, wymuszone progi, transport = zrzut do pliku):
>   cisza connectora, klucz nieużyty i cisza firmy dały trzy zadania w kolejce i trzy wpisy
>   `mail.sent` z rodzajem, odbiorcą i numerem zadania. Stan bazy testowej przywrócony.
>
> ⚠ Przy okazji, po odbiorze Z44: kafel na `/dane` nazywa się **„Zatrudnienia"**, nie
> „Pracownicy" — ta liczba sumuje czynne zatrudnienia, więc osoba zatrudniona w dwóch firmach
> jest w niej dwa razy. Etykieta „Pracownicy" obiecywała liczbę ludzi.

> **Zmiana 1.18.24 → 1.18.25: `/dane` zamienia tabelę firm na listę wierszy, a stan instalacji
> na pasek kafli** (zlecenie Z44, 19.10.2026).
> - **`.page__head` znika.** Zdanie o ekranie zeszło do nagłówka karty, której dotyczy,
>   a „Stan synchronizacji ›" stanął przy liście, do której się odnosi.
> - **Cztery kafle `.kafle--auto`**: Firmy („z programu kadrowego"), Pracownicy („we wszystkich
>   firmach"), Ostatnia synchronizacja (czas względny, „connector R2 Płatnik"), Tryb („tylko
>   odczyt", „poprawki robi się w kadrach"). ⚠ Liczby **wyliczone z tej samej tablicy, co wiersze
>   niżej**, nie drugim zapytaniem: kafel „Pracownicy" jest sumą liczb, które widać pod nim —
>   dwa liczniki tej samej rzeczy rozjeżdżają się na pierwszym brakującym warunku, a wtedy ekran
>   sam sobie przeczy. ⚠ „Tylko odczyt" **nie dostaje barwy ostrzeżenia**: tak ten ekran ma
>   działać, a nie tak się zepsuł (§3.1 — kolorujemy to, co wymaga reakcji).
> - **Karta bez tytułu** (`.card__head--znak` ze znakiem `dane`, zdanie 14 px `.card__opis--lead`
>   i 12 px `.card__opis` pod nim): ekran nazywa się „Dane z kadr" i ta nazwa stoi w belce jako
>   jedyny `h1` — tytuł karty byłby drugim tytułem tego samego ekranu (reguła z 11.10, kryterium
>   bramki `nazwa_ekranu_w_belce`). Ta sama konstrukcja, co na `/admin/konta`.
> - **Tabela sześciu kolumn ustępuje liście `.dane-firmy` / `.dane-firma`.** Wiersz niesie kwadrat
>   38 px w barwie obojętnej, nazwę 14 px/600 w `--c-text` (nie w zieleni odnośnika), plakietki
>   (NIP, stan zgłoszenia z kropką, nazwa klucza z ikoną), dwie dane w stałych kolumnach
>   (96 px i 168 px — `auto` kazałoby liczbie skakać z wiersza na wiersz) i „Pracownicy ›".
>   ⚠ Powód zmiany jest zmierzony, nie estetyczny: tabela przy 1440 px wychodziła 10 px za kartę,
>   a kolumna „Klucz connectora" z napisem „— brak aktywnego klucza" była najszersza na ekranie
>   i mówiła najmniej. Jako plakietka ten sam fakt zajmuje tyle, ile ma treści.
> - ⚠ **Cały wiersz jest jednym odnośnikiem, a przycisk jego CZĘŚCIĄ** — `<span class="btn">`
>   wewnątrz `<a>` z `pointer-events: none`, nie zagnieżdżony przycisk (niedozwolony w HTML,
>   a przeglądarka rozstrzyga to po swojemu). Oba cele prowadzą pod ten sam adres, więc drugi cel
>   kliknięcia niczego nie dodaje. Najechanie podświetla wiersz `--c-surface-hover` i nadaje
>   przyciskowi `--c-accent-soft` z ramką `--c-accent` — ta sama reguła, co
>   w `.settings-list--akcje` na `/ustawienia`.
> - ⚠ **Kropka stanu bierze barwę z napisu** (`.chip__kropka`, `currentColor`), więc trzy stany
>   zgłoszenia nie dokładają ani jednego nowego tokenu: zatwierdzona `.chip--accent`, oczekuje
>   `.chip--warn`, odrzucona `.chip--danger`. Modyfikator wybiera **jawny warunek z białej listy**,
>   nigdy sklejenie z danych (`chip--{{ status }}`, §4b).
> - ⚠ **Ikona w plakietce ma własny `margin-right`**, nie `gap`: `.chip` jest `inline-flex`, więc
>   odstęp między dwoma dziećmi znika — ta sama pułapka, co przy kropce stanu i przy plakietce
>   wysyłki na `/ustawienia/poczta`.
> - **Telefon (375 px)**: kafle po 2 w rzędzie, wiersz jako blok — kwadrat i nazwa, plakietki,
>   pod nimi dwie dane obok siebie, przycisk na całą szerokość. Zmierzone przy 1920, 1366 i 375:
>   0 przewijania w poziomie, 0 błędów JS, odnośniki prowadzą tam, gdzie przed zmianą
>   (`/dane/firma/{id}`, `/dane/synchronizacja`).

> **Zmiana 1.18.23 → 1.18.24: nowy układ `/ustawienia/poczta`, kasowanie zadań z kolejki,
> wpis w dzienniku o KAŻDEJ wysłanej i niewysłanej wiadomości, krótsze podpisy kolumn klucza**
> (zlecenie Z43 z dopiskami, 19.10.2026).
>
> **(1) Ekran poczty.** `.page__head` i karta „Stan" znikają; cztery dane ze „Stanu" stoją
> w pasku `.kafle kafle--auto` (Wysyłka, Ostatnia udana wysyłka, Kolejka, Skrzynka na alerty).
> Kolejność kart idzie za kolejnością pytań: **Serwer poczty → Wiadomość próbna → Kolejka
> wysyłki** (do Z43 kolejka stała pierwsza, przed konfiguracją). Każda karta ma nagłówek
> `.card__head--znak` ze znakiem (`serwer`, nowa ikona `samolocik`, `odswiezenie`) i zdanie
> `.card__opis` pod tytułem.
> - **Formularz w trzech kolumnach** — `.poczta-forma` (`auto-fit`, podłoga 240 px) z sekcjami
>   `.poczta-sekcja` i etykietami `.poczta-sekcja__etykieta` (11 px wersaliki, `--c-text-4`):
>   POŁĄCZENIE (serwer, port, szyfrowanie), LOGOWANIE (użytkownik, hasło), NADAWCA (adres, nazwa).
>   ⚠ Nie `.form-grid` (1,6fr/1fr): tamta siatka układa pola PARAMI, a te siedem pól to trzy
>   niezależne decyzje. Zmierzone przy 1920: trzy kolumny po 487 px; przy 1366: po 319 px.
> - **Ostrzeżenie „Nadawca nazywa się wezurlop…" znika z ekranu w całości.** ⚠ Pierwsza wersja
>   przeniosła je pod pole jako podpowiedź w barwie ostrzeżenia (`.field__hint--uwaga`) — zlecenie
>   kazało je USUNĄĆ, a nie przenieść, i tak zostało rozstrzygnięte przy odbiorze (19.10). Pod polem
>   stoi sama podpowiedź o tym, czym to pole jest; nazwę jedynej spółki proponuje `placeholder`.
>   Klasa `.field__hint--uwaga` skasowana razem z jedynym użyciem.
> - **Wiadomość próbna bez osobnej stopki**: `.poczta-proba` — pole 360 px, przycisk `.btn--akcja`
>   z ikoną i notka „Jedna wiadomość, limit czasu 10 s." w jednym rzędzie (zmierzone przy 1920:
>   jedna linia, 61 px; przy 375 px trzy rzędy jeden pod drugim). Karta jest jednym działaniem,
>   więc stopka karty byłaby drugim miejscem na tę samą decyzję.
> - **Kolejka**: cztery szare kafelki `.ustaw-dane` / `.ustaw-dana` (ten sam komponent, co dane
>   crona na `/ustawienia`) i lista `.poczta-kolejka` z etykietą „W KOLEJCE". Wiersz niesie
>   **nazwę zadania po polsku**, kod typu `mono` 11 px `--c-text-4`, plakietkę liczby i kosz
>   `.ikona-akcja--usun .ikona-akcja--sm` (32 px, hover w barwie błędu).
>   ⚠ Kod typu zostaje obok nazwy z tego samego powodu, co kod zdarzenia w dzienniku: nazwa
>   po polsku jest tłumaczeniem, a przy zgłoszeniu „nic nie wychodzi" zestawia się `mail.invitation`.
> - **Telefon (375 px)**: kafle po 2 w rzędzie, kolumny formularza jedna pod drugą, rząd
>   wiadomości próbnej zawinięty. Zmierzone: 0 przewijania w poziomie przy 1920, 1366 i 375.
>
> **(2) Kasowanie zadań z kolejki.** Kosz usuwa **wszystkie czekające zadania tego typu**
> po potwierdzeniu w oknie w stylu „Usuń grupę"; `POST /ustawienia/poczta/kolejka/usun` z CSRF
> i uprawnieniem `admin.settings`, wpis `maintenance.job_deleted` w dzienniku.
> ⚠ **To jest odstępstwo od brzmienia zlecenia („pojedyncze zadanie") i ma jeden powód:** kolejka
> pokazuje LICZBY i typy, nigdy adresatów (inaczej byłaby listą adresów pracowników dostępną
> każdemu z `admin.settings`), więc dwa zadania tego samego typu są na tym ekranie
> **nierozróżnialne** — przycisk wybierający jedno z dwóch identycznych wierszy obiecywałby
> decyzję, której ekran nie umie pokazać. Okno mówi liczbę wprost, dziennik ją zapisuje.
> ⚠ **O tym, czego usunąć NIE WOLNO, decyduje jedno pytanie: czy to zadanie wróci.**
> Źródłem jest `Support\ZadaniaKolejki` — to samo, z którego serwer odmawia, bo wyłączony
> przycisk nie jest zabezpieczeniem (CLAUDE.md §10). Trzy odpowiedzi:
> zadanie cykliczne (`JobRunner::CYKLICZNE`) wraca w następnym przebiegu crona → **kosz aktywny**;
> wiadomość zamówiona przez człowieka (zaproszenie, link do hasła) da się zamówić ponownie
> tym samym przyciskiem → **kosz aktywny**; **alert nie wróci nigdy** (powstał z epizodu, który
> minął, a przy ciszy connectora slot alertu jest zajęty na całą długość progu) → **kosz wyłączony**
> z powodem w `title`. Sonda crona też ma kosz wyłączony: jej usunięcie zostawiłoby znacznik
> „sonda wysłana" bez wyniku, czyli ekran ustawień **oskarżałby crona klienta** o to, że nie
> odpowiada. Nieznany typ zadania: kosz wyłączony (domyślnie odmawiaj).
> Zadanie zajęte przez przebieg crona (`locked_until`) zostaje — zmierzone cztery odmowy serwera:
> alert, zadanie zablokowane, typ nieznany i typ, którego w kolejce już nie ma.
>
> **(3) Dziennik: wysłana i niewysłana poczta.** Nowa para kodów `mail.sent` / `mail.failed`
> (rodzaj wiadomości, odbiorca, identyfikator zadania, przy błędzie krótki powód bez sekretów).
> ⚠ Do 19.10.2026 **wysłana poczta nie zostawiała ani jednego wpisu**: były wpisy o ZAMÓWIENIU
> wiadomości (`account.invited`, `auth.password_reset_requested`), a między nimi a wysyłką stoi
> kolejka, cron i serwer SMTP klienta. Przy zgłoszeniu „nie dostałem zaproszenia" dziennik
> odpowiadał „zaproszono", a pytanie było inne. Wpis robi dekorator transportu
> (`Service\Mail\TransportZDziennikiem`), bo **odbiorcę zna wyłącznie wiadomość** — nie typ
> zadania. Wysyłka próbna idzie tą samą parą kodów i niesie adresata; `tenant.mail_test_sent`
> i `tenant.mail_test_failed` **nie powstają od tej daty** (zdania zostają w katalogu dla wpisów
> wcześniejszych). W filtrze dziennika poczta ma **własny rodzaj** („poczta", prefiks `mail.`),
> wyjęty ze zbiorczej „integracji i systemu" — prefiks stoi w dokładnie jednym rodzaju.
>
> **(4) Krótsze podpisy kolumn klucza i JEDNO rozdanie zamiast trzech** (dopisek Krzysztofa).
> „Firmy w zakresie" → **„Firmy"**, „Ostatnie użycie" → **„Użycie"**. Skrócenie zdjęło 135 px
> szerokości minimalnej nagłówka (suma minimów 634 → **499 px**) i pozwoliło zwinąć trzy rozdania
> kolumn z 1.18.21 w jedno: **17/10/11/10/24/28**. Zmierzone przed → po:
> 1920 px jedna linia przycisków i wiersz 78 px (bez zmian), 1440 px dwie linie i 105 px
> (bez zmian), **1366 px trzy linie i 145 px → dwie linie i 105 px**. Próg zawijania podpisów
> zjechał z 1365 na **1277 px** i wylicza się z najdłuższego podpisu („Utworzony" 106 px przy 11 %
> kolumny → 964 px tabeli → okno 1278 px). Sprawdzone w całym zakresie układu tabeli
> (641–1920 px): **żaden podpis nie wychodzi za swoją kolumnę i nigdzie nie ma przewijania
> w poziomie**.
>
> ⚠ **Czego ekran NIE dostał** (dopisek do Z43): odnośnik „Wyczyść zapisane hasło" i karta
> „Hasło SMTP" **zniknęły razem z obsługą po stronie serwera**. Droga do czyszczenia hasła
> nie miała własnej trasy — jechała ukrytym polem `wyczysc_haslo=1` w formularzu zapisu,
> a jedyny inny wywołujący `MailSettings::zapisz()` (instalator) podawał tam zawsze `false`.
> Parametr `$wyczyscHaslo` zniknął z sygnatury. **Pusty formularz nadal NIE kasuje hasła**, a
> skrzynkę bez uwierzytelnienia ustawia się **pustym polem użytkownika** — i tylko o tym mówi
> podpowiedź pod tym polem (prototyp nie miał jej wcale; po usunięciu tamtej karty jest to
> jedyne miejsce, które tę drogę nazywa).

> **Zmiana 1.18.22 → 1.18.23: komunikat o wyniku operacji to TOAST u dołu ekranu, a pasek
> `#flash` znika** (zlecenie Z47, 18.10.2026).
> **(1) Komponent.** `.toasty` (pojemnik `position: fixed`, `left: 50%`, `bottom: 28px`,
> `width: min(620px, 100vw − 2·--s-4)`, `z-index: 50`) i `.toast` w środku: biała belka
> `--c-surface`, ramka 1 px `--c-line`, promień 12 px, cień `--shadow-pop`, wcięcie 10/8/10/12 px,
> odstęp 12 px. **Bez kolorowego paska z boku i bez ciemnego tła** — barwę niesie wyłącznie
> kółko 24 px z lewej: błąd `--c-danger-soft`/`--c-danger-text` „!", sukces
> `--c-accent-soft`/`--c-accent-text` „✓", ostrzeżenie `--c-warn-soft`/`--c-warn-text`,
> informacja `--c-neutral-soft`/`--c-text-3` „i". Treść 14 px (`--t-body`) `--c-text-2`
> z pogrubionym pierwszym zdaniem (`--c-text`), krzyżyk 28 × 28 px z `aria-label` „Zamknij".
> ⚠ **To jest nazwany wyjątek od §4b („zielonego wariantu nie ma")**: zieleń stoi w kółku 14 px,
> a nie na tle komunikatu — rodzaj wyniku ma być czytelny jednym spojrzeniem, bo komunikat znika
> po pięciu sekundach. Tło zostaje białe, więc toast dalej nie udaje przycisku.
> **(2) Zachowanie.** Wejście: wsunięcie od dołu 16 px i przejście przezroczystości 0,25 s
> (bez ruchu przy `prefers-reduced-motion`). Znika sam po 5 s; najechanie kursorem albo fokus
> **wstrzymuje** odliczanie i zostawia tyle, ile zostało, a zjechanie je wznawia. Najwyżej trzy
> naraz, najnowszy na dole, czwarty wypycha najstarszy.
> **(3) Jedno źródło: `modules/flash.js`.** Trzy drogi wchodzą tym samym wejściem: błędy htmx
> (z powodem z odpowiedzi `text/plain`, 1.18.20), zdarzenie `wezurlop:komunikat` z innych modułów
> i z nagłówka `HX-Trigger` (`Support\Komunikat`), oraz komunikat serwera wyrenderowany
> w treści ekranu przez `partials/komunikat.twig` (`[data-komunikat]`), który moduł przenosi
> do toasta i chowa klasą `.komunikat--w-toascie`.
> ⚠ **Ścieżka bez JavaScriptu nie ma drugiego mechanizmu**: klasa po prostu nie powstaje,
> więc zdanie zostaje tam, gdzie je postawił serwer — zmierzone, 1560 × 44 px w treści ekranu.
> **(4) Pasek `#flash` skasowany** razem z regułami `.flash` w obu arkuszach powłoki. Na telefonie
> był już pływającym pudełkiem u dołu, czyli toastem bez nazwy; w wariancie web stał pod belką
> górną, czyli tam, gdzie po kliknięciu nikt nie patrzy. W powłoce mobilnej toast stoi **nad**
> dolną nawigacją (`.toasty--nad-nawigacja`, odstęp liczony z `--m-nav-h`) — zmierzone: luka
> 16 px, nawigacja klikalna.
> ⚠ **Kryterium bramki przepięte z `#flash` na `#toasty`**: pojemnik stoi na każdym ekranie,
> ma `role="status"` i `aria-live="polite"`, a pusty ma 0 px wysokości i `pointer-events: none`.
> Powód ten sam co przy pasku: rzecz, której nie widać, nie ma prawa zajmować miejsca ani łapać
> wskaźnika. Kryterium złamane celowo (zdjęte `pointer-events`) zapaliło 14 ekranów.
> **(5) Błąd pola.** Pole dostaje `aria-invalid="true"`, ramkę `--c-danger` i obwódkę 3 px
> `--c-danger-soft` (cieniem, bo `outline` należy do fokusu klawiatury), wartość zostaje, fokus
> wraca do pola, a ramka znika przy pierwszej zmianie wartości. ⚠ Pole wskazuje **żądanie**
> (`hx-include`), nie zgadywanie: zapis adresu w wierszu listy osób wisi na przycisku, a pole
> stoi obok. Gdy w zakresie jest więcej niż jedno pole, nie oznaczamy **żadnego** — czerwień
> na niewłaściwym polu jest gorsza niż jej brak.
> **(6) Treść komunikatów skrócona do zdania wiodącego i jednego dopowiedzenia** (33 miejsca).
> Wyjaśnienia zeszły tam, gdzie zostają na ekranie: o haśle z linku mówi formularz nowego konta,
> o wymianie klucza notka w stopce karty kluczy, o wielkości liter podpowiedź pod polem nazwy grupy.

> **Zmiana 1.18.21 → 1.18.22: rodzaj zdarzeń na `/admin/dziennik` wybiera się LISTĄ, nie paskiem
> chipów** (zlecenie Z42, 18.10.2026).
> Pasek siedmiu chipów (`.dz-filtry`, `.dz-filtr`, `.dz-filtr--wybrany`) **znika razem z regułami** —
> rodzaj stoi teraz jako `<select name="rodzaj">` w formularzu nagłówka karty, przed listą kont,
> w tej samej wysokości (38 px) i tym samym stylu `.select`. `aria-label` „Rodzaj zdarzeń",
> opcje małą literą („wszystkie zdarzenia", „konta i uprawnienia", „integracja i system"),
> wartości parametru bez zmian. Oba filtry stosuje jeden przycisk „Pokaż", oba dalej działają
> bez JavaScriptu i oba jadą w stronicowaniu.
> ⚠ **Rodzaj przestał być polem ukrytym.** Dotąd wybór konta niósł rodzaj w `<input type="hidden">`,
> bo wybierało się go gdzie indziej; teraz oba filtry są w jednym formularzu i problem znika
> u źródła, a nie przez pamiętanie o polu.
> ⚠ **Nazwy rodzajów małą literą w `KodyZdarzen::RODZAJE`**, bo to są teraz pozycje listy obok
> „wszystkie konta", a nie napisy na chipach. Jedynym ich odbiorcą jest ten ekran.
> ⚠ Telefon: obie listy na całą szerokość, jedna pod drugą, „Pokaż" pod nimi (zmierzone przy
> 375 px: 293 px każda, y 1041 / 1087 / 1133). Razem z paskiem chipów znika **jedyne w tej
> aplikacji pudełko przewijane w poziomie** (`overflow-x: auto` na `.dz-filtry` przy 640 px) —
> lista rozwijana tego nie potrzebuje.
> ⚠ Liczby sprawdzone wobec bazy dla każdej pozycji: 5199 + 324 + 2189 + 972 + 1463 + 148 = 10 295,
> czyli tyle, ile bez filtra; w połączeniu z kontem i stroną też zgodne co do wpisu.

> **Zmiana 1.18.20 → 1.18.21: wiersz klucza na `/admin/integracja` wyśrodkowany, podpisy kolumn
> w jednej linii, notka na szerokość tabeli** (zlecenie Z41, 18.10.2026).
> - **Komórki wiersza**: `padding-block: 16px` i `vertical-align: middle` (`.table--klucze tbody td`).
>   Wiersz ma przy 1920 px **78 px** zamiast 48. ⚠ Odstęp daje wcięcie, a **nie** większe
>   `height`: w stanie „czeka na pierwsze użycie" pod plakietką stoi jeszcze `.cell__sub`,
>   więc wysokość musi wynikać z treści. Zmierzone odchylenie środka treści od środka komórki
>   spadło z **13,5 px → 0,59 px** przy 1920 i z **25,75 px → 0,59 px** przy 1366 (próg: 1 px).
>   ⚠ Ostatnie 1,09 px zdjęło `vertical-align: middle` **na plakietce**: wyśrodkowanie komórki
>   środkuje linię pisma, a plakietka wisi w tej linii jak pudełko. Warianty z `line-height: 0`
>   i `font-size: 0` na komórce dawały 0,25 px i **są odrzucone** — psują komórkę z samym
>   tekstem, bo data w „Ostatnim użyciu" zawija się przy 1366 na dwie linie.
> - **Kwadrat 34 px przy kluczu w barwach obojętnych** (`--c-neutral-soft` / `--c-text-3`)
>   zamiast zielonych. Zieleń w tym systemie znaczy „zgoda / dobry stan" (§3.1), a ten kwadrat
>   stał tak samo przy kluczu unieważnionym i przy kluczu, który milczy. Stan mówi plakietka.
> - **Podpisy kolumn `white-space: nowrap`** (`table--zawija` zabrało je razem z treścią):
>   było 1 podpis w dwóch liniach przy 1920 i 2 przy 1366, jest **0** przy obu.
> - **Notka w stopce na szerokość wnętrza karty** — `.card__foot--notka` (`display: block`,
>   wcięcie `4px 0 0`, bez tła i bez górnej linii). Zmierzone przy 1920: tabela 1558 px,
>   notka **1071 → 1558 px**; odstęp nad notką **17 → 4 px**. ⚠ `display: block` jest konieczne:
>   dziecko `.card__foot` (flex) bez `flex-grow` bierze szerokość ze swojej treści, więc samo
>   zdjęcie wcięcia niczego nie dało.
>
> ⚠ **Szerokości kolumn przeliczone trzeci raz i rozbite na dwa rozdania** — bo podpis
> z `nowrap` ma własną szerokość minimalną, której wcześniej nie miał. Zmierzone minima:
> „Firmy w zakresie" 144 px, „Ostatnie użycie" 136, „Utworzony" 106, „Działania" 105,
> „Nazwa" 82, „Stan" 61 — razem **634 px**. Do tego treść: trzy przyciski w jednej linii 401 px,
> nazwa z kwadratem i początkiem klucza 156, plakietka „unieważniony przez administratora" 229.
> **Przy 1366 px (tabela 1052 px) nie ma rozdania, które to spina** — same minima to 1120 px.
> ⚠ **Rozdania zwinięte do JEDNEGO w 1.18.24**, gdy podpisy zostały skrócone — opis niżej zostaje,
> bo wyjaśnia, skąd brały się trzy progi i dlaczego przestały być potrzebne.
> Stąd: rozdanie szerokie (13/13/9/12/22/31) trzyma wszystko w jednej linii do ~1650 px,
> a wąskie (≤ 1600 px: 16/15/11/14/22/22) chroni podpisy, początek klucza i plakietkę,
> oddając linijkę rzędowi przycisków — przy 1366 px stoją w trzech linijkach, a wiersz ma 145 px.
> ⚠ Poniżej **1280 px** podpisy wracają do zawijania: przy tabeli węższej niż ~966 px nie da się
> ich zmieścić, a podpis wychodzący za kolumnę nachodzi na podpis sąsiada i jest gorszy
> niż podpis w dwóch liniach. Dwie klasy na dwie kolumny z datą (`.col--klucz-utworzony`,
> `.col--klucz-uzycie`), bo ich podpisy mają różną długość; `.col--klucz-czas` znika.
> ⚠ **Telefon**: komórka-blok dostaje `height: auto`. `.table td` dawało jej 48 px, więc rząd
> trzech przycisków (112 px) wychodził poza komórkę — dotąd zasłaniało to wcięcie stopki,
> a po zejściu odstępu nad notką do 4 px „Unieważnij" wszedł pod ramkę notki. Wiersz-blok
> przy 375 px ma **272 px** zamiast 354 i niczego nie przykrywa.
>
> ⚠ Przy okazji zdjęty **przedrostek „Odrzucone:"** z linii w sekcji ODRZUCONE okna zakresu
> (1.18.19): etykieta sekcji mówi to samo, a przedrostek powtarzał ją w każdej linii.

> **Zmiana 1.18.19 → 1.18.20: pasek komunikatu pokazuje POWÓD odmowy, gdy serwer go poda**
> (zlecenie Z40, 18.10.2026).
> `flash.js` przy odpowiedzi **4xx typu `text/plain`** pokazuje jej treść zamiast napisu
> dobranego po kodzie stanu. Warunek jest wąski celowo: tylko `text/plain`, tylko 4xx, do 300
> znaków — odpowiedzi z fragmentem HTML dalej idą do podmiany treści, a nie na pasek.
> Treść wstawia `textContent`, więc ta droga nie przenosi HTML-a (CLAUDE.md §6).
> ⚠ Powód: zapis adresu w wierszu listy osób zwracał dotąd `422` z pustym ciałem, a pasek
> pokazywał „Wpisane dane są nieprawidłowe" — zdanie, po którym nie wiadomo, co poprawić.
> Przy duplikacie adresu ma stać „Ten adres ma już inna osoba: <imię i nazwisko>.".
> ⚠ **Nazwisko pada tylko wtedy, gdy administrator i tak widzi tę osobę na swojej liście.**
> Kolizja z osobą spoza jego zakresu daje „Ten adres ma już inna osoba." bez nazwy — odmowa
> musi paść zawsze, ale nazwisko spoza zakresu jest daną, do której nie ma dostępu. Pilnuje
> tego `tests/adres-osoby.php` (sprawdzenie „nazwisko spoza zakresu NIE wycieka").

> **Zmiana 1.18.18 → 1.18.19: w oknie „Zakres klucza" wraca droga do „Cofnij odrzucenie"**
> (zlecenie Z39, 18.10.2026 — odpowiedź na pytanie otwarte z Z35).
> Okno zakresu dostaje trzecią sekcję, **„ODRZUCONE"**, widoczną tylko wtedy, gdy klucz ma choć
> jedno odrzucone zgłoszenie: jedna linia na zgłoszenie (13 px, `--c-text-3`) i przycisk
> „Przywróć" (`.btn--akcja .btn--sm`, 32 px) po prawej.
> ⚠ **To nie jest dodanie funkcji, tylko przywrócenie drogi, którą przebudowa ekranu odcięła.**
> Trasa `POST /admin/integracja/zgloszenia/{id}/przywroc` istniała od Z21 i działała, ale po Z35
> **nie prowadził do niej żaden odnośnik** — a ekran potwierdzenia odrzucenia przez cały ten czas
> obiecywał: „Decyzję można cofnąć na tym ekranie". Interfejs pokazywał odrzucenie jako decyzję
> ostateczną, choć w bazie wraca jednym `UPDATE`. Tak wygląda regres, którego nie widać ani
> w testach, ani w bramce: nic nie jest zepsute, po prostu czegoś nie ma.
> ⚠ **Bez ramki ostrzeżenia i bez barwnego tła** — inaczej niż wiersz zgłoszenia oczekującego
> (`--c-warn-line`, żółte tło). Oczekujące **czeka na decyzję**; odrzucone jest stanem zamkniętym
> z drogą powrotu. Gdyby dostało tę samą oprawę, okno świeciłoby na pomarańczowo przy kluczu,
> w którym nie ma nic do zrobienia, i ostrzeżenie przestałoby cokolwiek znaczyć.
> ⚠ **Rząd NIE zawija się na biurku.** Przy `flex-wrap: wrap` zawijanie kontenera ma pierwszeństwo
> nad ściskaniem elementu, więc opis szerszy niż wolne miejsce **zrzucał przycisk pod spodem
> już przy 1920 px** (rząd 510 px, sam opis 450). Bez zawijania opis ściska się do 415 px
> i zawija w sobie, a „Przywróć" zostaje po prawej; zawijanie całego rzędu włącza się dopiero
> w bloku ≤ 560 px, gdzie `flex: 1 1 100%` na opisie świadomie spycha przycisk pod nazwę.
> ⚠ **`decided_at` dojechało do szablonu razem ze zgłoszeniem**: przy odrzuconym `created_at`
> mówi, kiedy connector je przysłał, a nie kiedy ktoś je odrzucił — a to drugie jest tym,
> co administrator musi zobaczyć, zanim cofnie cudzą decyzję.
> ⚠ **Przywrócenie wraca do OTWARTEGO okna** (`?ok=przywrocona&okno=zakres&klucz=<id>`):
> inaczej skutek operacji widać dopiero po ponownym wejściu w „Zakres". Identyfikator klucza
> to liczba przechodząca przez tę samą białą listę, co `?okno=zakres`.
> **Pomiar (klucz „test", zgłoszenie próbne, dane zastępcze)**: brak odrzuconych → sekcji nie ma
> (0 elementów, etykiety: „W zakresie klucza"); po odrzuceniu → 1 linia, etykiety „W zakresie
> klucza" + „Odrzucone", znacznik na „Zakres" znika; „Przywróć" → zgłoszenie w „Czeka na decyzję",
> znacznik **1**, okno otwarte. 1920 px: rząd `[705, 630, 510, 40]`, opis 415 px, przycisk
> `[1132, 644, 83, 32]` — w tym samym rzędzie, pismo 13 px, ramka 0 px, tło przezroczyste.
> 375 px: opis na całą szerokość (291 px), przycisk pod nim `[42, 605, 83, 32]`.
> `scrollWidth` = `clientWidth` na obu szerokościach, 0 błędów JS.
> **Bez JavaScriptu ta sama droga działa w całości**: `?okno=zakres` daje `<dialog open>`,
> „Odrzuć" prowadzi na pełny ekran potwierdzenia, a „Przywróć" wysyła ten sam formularz przez
> `formaction` (POST z tokenem CSRF, bez drugiego formularza) i wraca do otwartego okna.

> **Zmiana 1.18.17 → 1.18.18: menu „⋯" karty w drzewie idzie w róg na wąskim ekranie**
> (zlecenie Z38, 18.10.2026).
> Karta w drzewie dostaje przy ≤ 560 px **ten sam układ, co karta w panelu** (Z34 §6):
> siatka `'znak tresc menu' '. chipy chipy'`, menu w prawym górnym rogu, plakietki w drugim
> rzędzie. ⚠ Powód jest zmierzony przy Z37, nie estetyczny: na wąskim ekranie plakietki
> zawijały się do drugiego rzędu i „⋯" lądowało **w środku karty**, a Chromium **dociąga dotyk
> do najbliższego celu klikalnego** — przytrzymanie w geometrycznym środku karty otwierało
> menu zamiast chwytać kartę. Po zmianie menu stoi **98 px od środka** i cała karta poza nim
> jest pewnym miejscem chwytu.
> ⚠ **Dotychczasowa reguła w bloku telefonu ustawiała samo `grid-template-columns` na karcie,
> która jest `display: flex` — czyli nie robiła nic.** Dopiero `display: grid` nadaje jej
> znaczenie; to jest cichy rodzaj martwej reguły, którą widać wyłącznie przy pomiarze układu.
> ⚠ Ten sam układ stoi w dwóch miejscach (`.hier__panel .hier__card` bez warunku szerokości
> i `.hier__card` w bloku ≤ 560 px) i jednej regułą się tego nie zapisze — panel obowiązuje
> na każdej szerokości. Zgodność pilnuje **pomiar chwytu w geometrycznym środku karty**,
> a nie zgodność zapisu.
> **Pomiar**: 375 px — karta 236 × 108, menu `[293, 361, 32, 32]` (prawy górny róg), środek
> karty `[216, 406]`, pod środkiem `.hier__fakty`; przytrzymanie w środku → **cień karty = 1**,
> menu **nie otwarte**; dotknięcie „⋯" → menu otwarte, 3 pozycje. 768 px i 1920 px: karta
> dalej jednym rzędem 68 px, menu przy prawej krawędzi, chwyt w środku też działa.
> Zestaw dotykowy Z37 (8 scenariuszy × 2 rozmiary) przechodzi bez zmian po tej przebudowie.

> **Zmiana 1.18.16 → 1.18.17: przeciąganie kart PALCEM na `/admin/hierarchia`**
> (zlecenie Z37, 18.10.2026).
> **(1)** ⚠ **Druga ścieżka, nie poprawka pierwszej.** HTML5 drag-and-drop
> (`dragstart`/`drop`) na przeglądarkach mobilnych **nie powstaje z dotyku** — przeglądarka nie
> emituje tych zdarzeń dla palca i nie ma czego naprawiać. Dotyk obsługują zdarzenia wskaźnika,
> mysz zostaje na dotychczasowej drodze. ⚠ **Rozstrzyganie celu i zapis są WSPÓLNE**
> (`celUpuszczenia`, nowe `zastosujUpuszczenie`): dwie kopie reguły „co znaczy upuszczenie"
> rozjechałyby się przy pierwszej zmianie, i to po cichu, bo każda ścieżka wygląda na działającą
> osobno.
> **(2)** ⚠ **Chwyt przez PRZYTRZYMANIE 350 ms, z luzem 10 px.** Powód jest rozstrzygający:
> drzewo jest dłuższe od ekranu, więc pierwszy ruch palca po karcie to prawie zawsze
> **przewijanie**. Gdyby przeciąganie startowało od razu, każda próba przewinięcia listy
> przestawiałaby komuś przełożonego — czyli zmiana danych kadrowych zrobiona przypadkiem.
> Luz 10 px, bo palec nigdy nie stoi w miejscu; przy zerowym progu chwyt nie powstałby nigdy.
> Uchwytu (drugi wariant ze zlecenia) **nie dokładamy**: karta ma już menu „⋯" 32 px, a drugi
> mały cel obok to zabawa w trafianie.
> **(3)** Karta-cień (`.hier__cien`) to kopia karty pod palcem, wyjęta z siatki: `position:
> fixed`, przesunięta w górę i w lewo od punktu dotyku, żeby palec nie zasłaniał tego, na co
> się ją kładzie. ⚠ `pointer-events: none` jest **warunkiem działania**, nie kosmetyką —
> bez tego `elementFromPoint` trafiałby zawsze w cień i cel nie byłby nigdy rozpoznany.
> **(4)** Autoprzewijanie przy krawędziach chodzi w **pętli klatek**, nie w procedurze obsługi
> ruchu: palec przytrzymany przy krawędzi nie wysyła zdarzeń, więc przewijanie „na ruch"
> stawałoby dokładnie wtedy, gdy jest potrzebne. ⚠ Pas **64 px, nie 90**: przy 90 px cel
> stojący nisko na ekranie telefonu wpadał w pas i **nie dało się na nim upuścić karty** —
> strona uciekała spod palca (zmierzone: scrollY 0 → 1880 w jednym przeciągnięciu).
> **(5)** ⚠ **Zaznaczanie tekstu na karcie wyłączone przy wskaźniku zgrubnym**
> (`@media (pointer: coarse)`), przy myszy zostaje. To też jest warunek działania: długie
> przytrzymanie palca na tekście uruchamia zaznaczanie słowa, przeglądarka **przerywa wtedy
> gest** (`pointercancel`) i chwyt nie powstaje. Zmierzone: karta w drzewie nie dawała się
> chwycić w ogóle, bo punkt dotyku wypadał na wierszu „zatrudniony od…".
> **(6)** Przewijanie strony w trakcie chwytu blokuje `touchmove` z `passive: false` — jedyne
> miejsce, w którym to działa po rozpoczęciu gestu (`touch-action` ustawione później gestu
> już nie dotyczy). Blokada obowiązuje **wyłącznie** w trakcie chwytu.
> **(7)** Przerwanie bez zmiany: puszczenie poza celem, `pointercancel`, **drugi palec**
> i **obrót ekranu**. Obrót zmienia układ w trakcie trzymania karty, więc zapamiętane
> współrzędne przestają cokolwiek znaczyć — bezpieczniej odpuścić niż zgadywać.
> **Pomiar** (emulacja dotyku przez CDP, 375 × 812 i 768 × 1024, liczniki przed i po):
> przeniesienie pod przełożonego 29/7 → 28/8 („1 zmiana"), na szczyt 28/8, z drzewa z powrotem
> do panelu 30/6, puszczenie poza celem „0 zmian", zwykłe przewinięcie palcem po karcie
> scrollY 0 → 1593 **bez chwytu** (cień: 0 w każdej chwili) i bez zmiany licznika, drugi palec
> i obrót ekranu kasują cień (1 → 0) i zostawiają „0 zmian". Mysz na 1920 px: bez zmian.
> Zero błędów JS. ⚠ Pomiar chwyta kartę **za nazwisko**, nie za geometryczny środek: przy
> 375 px środek wypada 9 px nad przyciskiem „⋯", a Chromium **dociąga dotyk do najbliższego
> celu klikalnego**, więc `pointerdown` dostawał menu. To nie jest usterka przeciągania —
> menu ma zostać dotykalne — ale znaczy, że na telefonie pewnym miejscem chwytu jest górna
> część karty.

> **Zmiana 1.18.15 → 1.18.16: `/admin/dziennik` jako LISTA ZDAŃ zamiast tabeli**
> (zlecenie Z36, 18.10.2026).
> **(1)** Znika `.page__head` i linia „Wynik: X z Y" — liczba wpisów stoi w nagłówku karty,
> a „wynik" powtarzał to, co widać na liście i w stopce. Karta nie ma tytułu: „Dziennik zdarzeń"
> jest w belce, a jeden ekran ma jeden tytuł.
> **(2)** Pasek siedmiu filtrów rodzaju (`.dz-filtry`, `.dz-filtr`) pod nagłówkiem. ⚠ **Filtr
> działa po stronie serwera** — to zwykłe odnośniki z `?rodzaj=`, łączące się z filtrem konta
> i ze stronicowaniem; bez JavaScriptu działa tak samo. Wybrany ma TŁO, nie samą ramkę: przy
> siedmiu pozycjach różnica w grubości ramki każe szukać, który jest czynny.
> ⚠ **Pasek chipów zastąpiony listą rozwijaną w 1.18.22 (Z42)** — zostaje tu zapisany, bo wyjaśnia,
> skąd wziął się filtr po stronie serwera, ale znaczników `.dz-filtry` i `.dz-filtr` już nie ma.
> ⚠ Zestaw filtrów pokrywa **wszystkie** rodziny kodów (ostatni jest zbiorczy: „Integracja
> i system"), bo rodzina zostawiona poza filtrami byłaby osiągalna wyłącznie przez „Wszystkie" —
> a dotyczy to także `security.*`. Zmierzone: 4777 + 2035 + 907 + 126 + 289 + 1357 = 9491,
> czyli dokładnie tyle, ile wpisów bez filtra.
> **(3)** Lista (`.dz-lista`) zamiast tabeli: nagłówek dnia („Dziś, 18.10.2026", „Wczoraj, …",
> sama data), wiersz w siatce `32px 1fr auto`. ⚠ **Siatka, nie flex**: przy flexie długie zdanie
> licytowało się z godziną o miejsce i spychało ją do drugiej linii.
> **(4)** Kółko 32 px z ikoną rodzaju 15 px. Barwy: logowanie błękitem dnia dzisiejszego,
> powstanie i nadanie zielenią, usunięcie i odmowa czerwienią, reszta obojętnie.
> **(5)** ⚠ **ZDANIE zamiast kolumn** (`Support/ZdanieZdarzenia.php`): „**Kto** + czynność +
> **obiekty**". Pogrubione jest to, **co pochodzi z danych** — nazwisko, nazwa grupy, uprawnienie
> — a nie to, co jest szablonem zdania. Wyjątek: „w przeglądarce" (tłumaczenie `client: web`)
> **nie jest** pogrubione, bo to okolicznik, nie treść wpisu.
> ⚠ **Zero surowego JSON-a w treści** (warunek odbioru): tablice wchodzą jako wyliczenie,
> **najwyżej trzy pozycje i „i jeszcze N"** — zmierzone, jedna zmiana składu grupy miała
> 36 nazwisk, czyli trzy linijki ekranu na jeden wpis. Pełne szczegóły i kod zdarzenia
> zostają w `title` wiersza.
> ⚠ **Kod bez zdania nie jest błędem**: dostaje „<kto> wykonał(a) operację" i swój kod w `title`.
> Ekran audytowy nie może ukryć zdarzenia dlatego, że ktoś zapomniał dopisać zdanie.
> **(6)** Godzina „HH:MM:SS" **jedną czcionką**, 13 px, 400, z cyframi tabelarycznymi, bez
> pogrubień i bez zmniejszania sekund: w audycie sekunda bywa treścią.
> **(7)** Stopka: „Pokazano 1–50 z N" i dwa przyciski, nieczynny **wyszarzony zamiast zniknąć**
> (`.btn--akcja-nieczynny`) — znikający przesuwa sąsiada na swoje miejsce i przy drugim
> kliknięciu trafia się w niewłaściwy.
> **Pomiar**: 1920 i 1366 px — 7 filtrów w jednym rzędzie (filtr 87 × 32), wiersz wpisu
> 1558 × 56 (1366: 1052 × 56), kółko 32 × 32, godzina 56 px po prawej, filtr konta 244 × 38;
> 375 px — wiersz 341 × 100 z godziną **pod** zdaniem, filtr konta 293 px (pełna szerokość karty),
> pasek filtrów **przewija się w sobie** (`scrollWidth > clientWidth`), a strona nie przewija
> się w poziomie na żadnej z trzech szerokości.
> **Spójność trzech miejsc**: zdanie (PHP), ikona (biała lista w `partials/ikona-zdarzenia.twig`)
> i barwa (`.dz-znak--*` w CSS) są sprawdzane testem `tests/zdania-dziennika.php` — rozjazd
> między nimi nie wywraca ekranu, tylko po cichu zabiera ikonę albo kolor. ⚠ Test od razu
> wyłapał, że ikona `wnioski` istniała wyłącznie jako gałąź zastępcza `{% else %}`.
> ⚠ Rodzina `.wizard*` **usunięta** (polecenie zarządcy przy Z36): po Z35 nie dotyczyła już
> żadnego znacznika.

> **Zmiana 1.18.14 → 1.18.15: `/admin/integracja` w układzie pulpitu, zakres klucza w oknie,
> kreska drzewa hierarchii liczona z siatki** (zlecenie Z35 wraz z dopiskiem, 18.10.2026).
> **(1)** Znika `.page__head`; zdanie o kluczach zeszło do nagłówka karty kluczy, a przycisk
> kreatora — do tej samej karty, bo to w niej stoi wiersz, którego dotyczy. Dawny drugi przycisk
> („Wydaj nowy klucz" w stopce) usunięty: otwierał dokładnie to samo okno.
> **(2)** Sześć kafli (`.kafle--auto`) zamiast `.card.stats`. ⚠ **Pierwszy kafel niesie STAN, nie
> liczbę** — stąd jedyna kropka w pasku: `.kafel__kropka` z obwódką 4 px rysowaną **cieniem, nie
> ramką** (ramka powiększyłaby pudełko i zepchnęła tekst). Wartość schodzi do `--t-h2`, bo
> „podłączanie w toku" w rozmiarze liczby zajmowało trzy linie; wersja connectora też, ale
> z własnego powodu (to napis, nie liczba).
> **(3)** Wiersz klucza: kwadrat **34 px** z ikoną klucza (nie 38 jak `.card__znak` — w wierszu
> tabeli znak stoi obok dwóch linii tekstu i kwadrat równy kontrolce był od nich wyższy),
> nazwa 14 px 600, pod nią początek klucza w ramce `--c-bg` z `title` „Całego klucza nie
> pokazujemy". Kolumna **„Firmy w zakresie"** zamiast listy NIP-ów: plakietka „w zakresie /
> zatwierdzone" (np. `2/2`), nazwy i NIP-y w `title`. To zdejmuje u źródła problem, dla którego
> ta tabela miała stałe szerokości — treść kolumny rosła z liczbą firm.
> **(4)** Trzy działania w stylu `.btn--akcja`: „Zakres" ze znacznikiem liczby oczekujących
> zgłoszeń (`.btn__znacznik`, kółko 18 px `--c-warn`, **widoczne tylko przy liczbie > 0** —
> kółko z zerem mówiłoby „jest tu coś do zrobienia" dokładnie wtedy, gdy nie ma), „Wymień klucz"
> i „Unieważnij" (`.btn--akcja-groznie` — **napis** w barwie błędu, tło zostaje białe: czerwony
> przycisk obiecywałby, że kliknięcie unieważnia, a ono otwiera potwierdzenie).
> **(5)** ⚠ **Karty „Zakres kluczy" i „Zgłoszenia firm" USUNIĘTE**, ich funkcje są w oknie
> „Zakres klucza" (`.modal--zakres`, 560 px, osadzone w stronie jak okno nazw firm). Okno działa
> **bez JavaScriptu**: „Zakres" to zwykły odnośnik `?okno=zakres&klucz=<id>`, a serwer renderuje
> wtedy `<dialog open>`. ⚠ **Jeden formularz na całe okno, a „Zatwierdź" idzie przez `formaction`**:
> formularzy nie wolno zagnieżdżać, a w oknie są dwie rzeczy do wysłania (zakres i decyzja
> o zgłoszeniu). `formaction` wysyła ten sam formularz pod inny adres — bez skryptu, bez
> zagnieżdżenia, z tym samym tokenem CSRF (zmierzone: `POST …/zgloszenia/<id>/zatwierdz`,
> token w treści). ⚠ Zgłoszenie **bez przypisanego klucza** trafia do okna każdego czynnego
> klucza — niepokazane nigdzie czekałoby w bazie bez śladu w interfejsie.
> **(6)** Przebiegi: tabela bez zebry, godzina `HH:MM` nad datą 11 px. ⚠ Godzina bez sekund ma
> własną nazwę (`czas_godzina_krotka`, `Strefa::godzinaKrotka`), a nie obcinanie w szablonie —
> reguła „prognoza bez sekund, pomiar z sekundami" dalej obowiązuje, a tutaj krótsza postać mówi
> **mniej**, niż wiemy, co jest bezpiecznym kierunkiem.
> **(7)** Okno kreatora: `.kroki` to **poziomy pasek czterech kafelków**, czyli wskaźnik postępu,
> a nie lista z formularzami w środku; to, co jest do zrobienia teraz, stoi jednym blokiem pod
> paskiem. Zrobiony krok dostaje barwę zgody, bieżący i przyszły zostają obojętne — kolor znaczy
> „to już jest", a nie „tu patrz". ⚠ Przycisk „Wygeneruj nowy klucz" **zniknął z kreatora**:
> unieważniał czynny klucz od ręki, stojąc obok zdania „klucz jest wydany", czyli najgroźniejsza
> operacja ekranu wyglądała jak podpowiedź. Zamiast niego ramka `.notka-info`. Cztery liczby
> i odnośnik „otwórz jako osobny ekran" usunięte, w stopce tylko „Zamknij".
> **Pomiar**: 1920 px — 6 kafli w rzędzie (kafel 250 × 114), wiersz klucza 1558 × 73, znak 34 × 34,
> wiersz przebiegu 1558 × 49, okno zakresu 560 × 537, okno kreatora 620 × 532, kroki 4 w rzędzie
> po 137 px; 1366 px — też 6 kafli (166 × 134); 375 px — kafle po 2, wiersz klucza jako blok
> 341 × 354, przebieg 341 × 178, okna 341 px, kroki 2 × 2. Zero przewijania w poziomie na trzech
> szerokościach (⚠ przy 375 px wyszło najpierw 528 px treści w 341 px karty: komórka z nazwą
> prawną spółki nie zawija się, bo `.table td` ma `nowrap` — stąd `white-space: normal`
> i `min-width: 0` w bloku telefonu).
>
> **Dopisek do Z35 — kreska drzewa na `/admin/hierarchia`.** `.hier__node::before` miała stałe
> `top: 26px`, czyli środek karty sprzed Z34; po Z34 karta ma 68 px i kreska wypadała nad
> przyciskiem zwijania. ⚠ **Kreska jest teraz ELEMENTEM SIATKI** węzła (wiersz 1, `align-self:
> center`), więc trafia w środek wiersza — przy każdej wysokości karty, także gdy na telefonie
> plakietki zawiną ją do 165 px. ⚠ **Pułapka, która kosztowała jeden pomiar**: `::before` jest
> pierwszym dzieckiem siatki, więc przypięty do wiersza 1 zabrał komórkę uchwytowi, a ten zszedł
> z automatu do wiersza 2 — pierwszy wiersz miał wtedy 1 px, a kreska wisiała 42 px (1920) i 77 px
> (375) nad środkiem karty. Dlatego wiersze `.hier__zwin`, `.hier__card` i `.hier__branch`
> są przypisane **jawnie**. Zasłona szyny pod ostatnim dzieckiem dalej liczy się z tokenu
> `--hier-karta-h` (68 px, na telefonie 108 px), bo element siatki nie sięgnie od środka
> pierwszego wiersza do dołu węzła. **Pomiar po zmianie**: |środek kreski − środek `.hier__zwin`|
> = **0 px** i |środek kreski − środek karty| = **0 px** na 1920 i 375 px, dla wszystkich
> 6 węzłów w gałęziach.

> **Zmiana 1.18.13 → 1.18.14: `/admin/hierarchia` w układzie pulpitu, „Nieustawieni" to
> „Bez przełożonego"** (zlecenie Z34, 18.10.2026).
> **(1)** Znika `.page__head`: nazwa firmy do podpisu pierwszego kafla (to ona mówi, czego dotyczą
> liczby), zdanie o „wszystkich spółkach" skasowane, a zdanie o tym, czym jest hierarchia, zeszło
> do nagłówka prawego panelu — przy strukturze, której dotyczy.
> **(2)** Pasek pięciu kafli (`.kafle--auto`) zamiast `.card.stats`. ⚠ Atrybuty `data-hier-liczba`
> zostają: `modules/hierarchia.js` przelicza te liczby po każdym przeciągnięciu, bez przeładowania
> ekranu — zmieniła się tylko nazwa klasy odchylenia (`.kafel__wartosc--odchylenie`), nie mechanizm.
> **(3)** ⚠ **NAZEWNICTWO: „Nieustawieni"/„Nieustawionych" → „Bez przełożonego"** wszędzie
> w interfejsie tego ekranu (kafel, panel, okno „Zmień przełożonego"). Zmierzone po zmianie:
> **0 wystąpień** „Nieustawion…" w widocznym tekście.
> **(4)** Lewy panel: nagłówek ze znakiem w **barwie ostrzeżenia** (`.card__znak--uwaga`) —
> ten panel jest listą zadań, nie zestawieniem; zdanie „Tu trafia każdy nowy pracownik…" weszło
> do nagłówka (było osobnym akapitem), po prawej bursztynowa plakietka „N z M". Z kart w tym
> panelu znika plakietka „nie ustawiono": powtarzała nazwę panelu, w którym stoją.
> **(5)** Prawy panel: znak z ikoną drzewa, tytuł „Struktura", zdanie o przeciąganiu; strefa
> upuszczania z ikoną strzałki, przerywaną ramką 1,5 px (1 px ginie przy przerywaniu, 2 px czyta
> się jak ramka karty) i tłem `--c-surface-mute`.
> **(6)** Karta osoby: kółko 30 px z inicjałami, nazwisko 14 px 600 **zwykłymi literami**
> (`NazwaOsoby::doWyswietlenia` w repozytorium — to **dana**, nie wygląd, więc tak samo trafia
> do `title`, do wyszukiwania i do paska zmian; wersaliki z programu kadrowego czyta się dwa razy
> wolniej i krzyczą z każdego poziomu drzewa), pod spodem „zatrudniony od DD.MM.RRRR" z dotychczasowymi
> dopiskami. Plakietki z kropką: „bez konta" szara, „szczyt" zielona (było „nie podlega nikomu" —
> w drzewie zdanie powtarzało to, co widać), a liczba podwładnych **błękitna i bez kropki**, bo
> niesie liczbę, a nie stan. ⚠ Skrypt tworzy te plakietki w DOKŁADNIE tej samej postaci, inaczej
> karta przeciągnięta wyglądałaby inaczej niż ta sama karta po przeładowaniu.
> **(7)** W panelu plakietki schodzą pod nazwisko, a menu „⋯" idzie w prawy górny róg: panel ma
> 340 px i nazwisko z trzema plakietkami zawijało się w cztery linie. W drzewie zostaje jeden
> wiersz, menu 32 px.
> ⚠ Treść karty bierze całą wolną szerokość i stoi DO LEWEJ — bez tego `justify-content:
> space-between` stawiał nazwisko pośrodku wiersza, co widać było dopiero na szerokim drzewie.
> **Pomiar**: 1920 i 1366 px — 5 kafli w jednym rzędzie, karta panelu 270 × 98, karta w drzewie
> 1134 × 68 (1366: 628 × 68), inicjały 30 × 30, pole szukania 290 × 38 z lupą w polu;
> 375 px — kafle po 2 w rzędzie, panele jeden pod drugim („Bez przełożonego" pierwszy), karta
> 273 × 108, wcięcie gałęzi 8 + 8 px zamiast 24 + 16 (przy dawnym cztery poziomy zjadały 160 px
> z 343 px szerokości). Zero przewijania w poziomie na wszystkich trzech szerokościach.

> **Zmiana 1.18.12 → 1.18.13: zwolnienie to PRZEKREŚLONE nazwisko, nie dopisek**
> (zlecenie Z32, 18.10.2026). Na `/admin/konta` znika „· zwolniony" z komórki osoby.
> Imię i nazwisko dostaje `line-through` w `--c-text-4` (razem z barwą kreski — sama kreska
> w kolorze tekstu bywa ciemniejsza niż wyciszony napis i czyta się jak skreślenie ręką,
> a nie jak stan), a kółko z inicjałami gaśnie do `--c-text-5` na tym samym tle.
> ⚠ **Powód**: stan zatrudnienia jest cechą TEJ SAMEJ danej — nazwiska — więc nie ma prawa
> zajmować własnego miejsca w kolumnie; dopisek przy co którymś wierszu rozpychał kolumnę
> i czytał się jak druga dana.
> ⚠ **Informacja nie może zniknąć dla niewidzącego**: przekreślenie jest wyłącznie wyglądem,
> więc obok nazwiska stoi tekst ukryty wizualnie („Zatrudnienie zakończone"), a cały znak niesie
> ten sam `title`. To samo oznaczenie dostaje głowa listy dostępu — panel otwiera się NAD wierszem
> i zasłania go, więc bez tego stan osoby znikałby dokładnie wtedy, gdy nadaje się jej dostęp.
> Reszta wiersza zostaje bez zmian, w tym „bez etatu" w kolumnie dostępu.
> **Pomiar** (1920 i 375 px): 25 wierszy na stronie, **1** przekreślony, **0** wystąpień słowa
> „zwolniony" w komórkach osób, kolor napisu i kreski `rgb(138, 144, 156)` = `--c-text-4`,
> inicjały `rgb(168, 174, 185)` = `--c-text-5`.

> **Zmiana 1.18.11 → 1.18.12: wiersz grupy bez menu „⋯" i bez stosu inicjałów**
> (zlecenie Z33, 18.10.2026).
> **(1)** Stos nakładających się kółek z inicjałami **usunięty** (razem z regułami `.stos`
> i `.inicjaly--stos` — martwy CSS jest gorszy niż brak CSS-u): kółka powtarzały to, co mówią
> nazwiska obok, a przy czterech osobach zajmowały ćwierć wiersza. Kwadrat z literą grupy zostaje
> — mówi co innego (która to grupa), a nie „kto w niej jest".
> **(2)** Menu `⋯` zastąpiły **dwa widoczne przyciski-ikony** (`.ikona-akcja`, 38 × 38, odstęp
> 6 px): ołówek „Zmień nazwę grupy" i kosz „Usuń grupę". Obie czynności są pojedyncze i częste,
> a menu kazało zgadywać, co w nim jest. Ikona niesie znaczenie, `aria-label` i `title` mówią je
> słowami — przycisk-ikona bez nich jest zagadką dla czytnika ekranu i dla kursora. Pod kursorem
> ołówek bierze barwę akcji, kosz barwę niebezpieczną; fokus klawiatury zostaje widoczny.
> **(3)** ⚠ **Obie drogi działają bez JavaScriptu**: ołówek jest **odnośnikiem** na ekran grupy,
> gdzie stoi ten sam formularz nazwy, a kosz jest przyciskiem `submit` w formularzu usunięcia —
> `ui.js` wstrzymuje wysyłkę i otwiera okno z potwierdzeniem tylko wtedy, gdy `showModal` istnieje.
> Jeden przycisk, dwie drogi, zero drugiego miejsca w szablonie.
> **(4)** **Nowe okno „Usuń grupę"** w stylu pozostałych okien grup, ze znakiem w barwie
> niebezpiecznej i przyciskiem `btn--danger`. Kosz nie kasuje od razu, bo usunięcie jest
> nieodwracalne, a stoi 6 px od ołówka. Grupa z osobami: kosz **wyłączony z powodem w `title`**
> (dawny `.menu__powod`), bo odmowę i tak wystawia zapytanie i klucz obcy.
> **Pomiar**: 1920 px — 0 elementów `.inicjaly--stos` i 0 `details.menu` w wierszach, ołówek
> i kosz 38 × 38 w odstępie 6 px, wiersz 1558 × 66; 375 px — oba przyciski w dolnym rzędzie
> wiersza (341 × 112), zero przewijania w poziomie. Drugie otwarcie każdego okna ma komplet pól
> (zmiana nazwy: 1 pole, usunięcie: 0 pól i 1 element treści).

> **Zmiana 1.18.10 → 1.18.11: `/admin/grupy` w układzie pulpitu, okna grup w stylu okna nazw firm**
> (zlecenie Z31 z dopiskiem, 18.10.2026, prototypy zatwierdzone przez Krzysztofa).
> **(1)** Znika `.page__head`: zdanie o tym, czym jest grupa, zeszło do nagłówka karty, „Nowa grupa"
> stanęła w tym samym nagłówku, a zdanie o „wszystkich spółkach" zniknęło.
> **(2)** Pasek czterech kafli (`.kafle--auto`): grupy, osób w grupach, w kilku grupach, bez grupy.
> ⚠ Wszystkie liczą **ten sam zbiór** — czynne zatrudnienia w tej firmie — i liczą LUDZI, nie
> przypisania; suma liczników przy grupach jest wyższa i to jest poprawne. Trzy liczby biorą się
> z jednego zapytania (`GroupRepository::statystyki`).
> **(3)** Wiersz grupy: kwadrat 38 px z pierwszą literą nazwy (16 px, bo tokeny nie mają 15),
> nazwa 600, pod nią **do pięciu nakładających się kółek 24 px** z inicjałami (obwódka jest
> CIENIEM, nie ramką — ramka zjadłaby 2 px ze środka) i lista nazwisk 12 px, ucinana. Po prawej
> plakietka „N osób", „Członkowie ›" (`.btn--akcja`, 156 px) i menu `⋯` 38 × 38.
> ⚠ „Członkowie" i menu stoją NAD pseudoelementem odnośnika wiersza, inaczej klik w nie
> otwierałby grupę zamiast działania.
> **(4)** „Osoby bez grupy" to teraz **karta**, nie pasek z przerywaną ramką: stała obok karty
> z grupami i czytało się ją jak jej stopkę. Po rozwinięciu siatka `repeat(auto-fill, minmax(210px, 1fr))`
> z kółkiem 26 px przy każdym nazwisku.
> **(5)** **Okna „Nowa grupa" i „Zmień nazwę" w stylu okna nazw firm** (Z29): znak 38 px, tytuł
> `--t-h2`, pod nim firma (a przy zmianie nazwy także obecna nazwa), krzyżyk jako **ikona**
> (litera „×" brała krój z otoczenia i stała niecentrycznie), szara ramka z regułą o wielkości
> liter, stopka `--c-surface-mute` z „Anuluj" i przyciskiem zapisu.
> ⚠ **Stopka jest WEWNĄTRZ formularza** — przycisk `submit` poza swoim formularzem nic nie wysyła,
> więc to formularz pełni rolę `.modal__wrap` i sam rozkłada treść na `.modal__body` i `.modal__foot`.
> ⚠ **Odmowa zapisu wraca DO OKNA**, z wpisaną nazwą w polu i polem w stanie błędu; pasek strony
> wtedy milczy, bo ten sam komunikat w dwóch miejscach każe szukać, który jest aktualny.
> Bez JavaScriptu działa tak samo: serwer renderuje `<dialog open>`, a `ui.js` je podnosi.
> ⚠ `data-zaznacz` zaznacza treść pola po otwarciu (nazwę zmienia się zwykle w całości).
> **(6)** ⚠ **Poprawka usterki zgłoszonej w Z29**: oba okna dostały `data-modal-trwale`, bo wspólna
> obsługa czyściła `.modal__body` przy każdym zamknięciu. Zmierzone przed poprawką: po zamknięciu
> okno miało **0 elementów i 0 pól**, a drugie otwarcie pokazywało puste pudełko. Po poprawce
> drugie otwarcie ma komplet (nowa grupa: 3 elementy i 1 pole; zmiana nazwy: 2 i 1).
> **(7)** Okno członków: nagłówek jak wyżej, kółko 26 px przy osobie, nazwa innej grupy jako
> plakietka 11 px, „Usuń/Dodaj wszystkich" zielonym napisem bez ramki (znaki „+" i „×" zniknęły —
> robiły za ikonę, której nie ma), wyszukiwarki z lupą **w polu** i etykietą ukrytą wizualnie.
> ⚠ Pole w kolumnie musiało dostać `display: block`: `.pole-szukania` z listy osób ustawia `flex`,
> co przestawiało etykietę-pudełko w rząd i pole brało szerokość własnej treści (210 px w kolumnie
> 483 px).
> **Pomiar**: 1920 px — 4 kafle w rzędzie, wiersz grupy 1558 × 72 (kolumny `38 / 1184 / 62 / 156 / 38`),
> okno nowej grupy 520 × 398, zmiany nazwy 520 × 355, członków 1040 × 559 z kolumnami 483 px;
> 375 px — kafle po 2 w rzędzie, wiersz 341 × 118 w trzech kolumnach, okna 341 px szerokości,
> kolumny okna członków jedna pod drugą. Zero przewijania w poziomie na obu szerokościach.
> Po zamknięciu okna i odświeżeniu listy htmx-em układ wiersza zostaje (zmierzone: te same
> pięć kolumn, znak i przycisk „Członkowie" na miejscu).

> **Zmiana 1.18.9 → 1.18.10: `/admin/konta` w układzie pulpitu — pasek kafli, znak karty,
> wiersz z inicjałami** (zlecenie Z30, 18.10.2026, prototyp zatwierdzony przez Krzysztofa).
> Funkcje, htmx i adresy bez zmian.
> **(1)** Znika `.page__head`: zdanie o zakresie listy zeszło do nagłówka karty (dotyczy tej
> listy, nie ekranu), nazwa firmy do **podpisu pierwszego kafla** (bo to ona mówi, czego dotyczy
> liczba), a „Konto administracyjne" do paska działań karty.
> **(2)** Zamiast `.card.stats` — pasek kafli `.kafle--auto` (ten sam modyfikator co na
> `/ustawienia`; nazwa zmieniona z `--piec`, bo obsługuje pięć kafli tam i sześć tutaj).
> Siódmy kafel „Do odblokowania" **zostaje warunkowo**: to jedyny stan czekający na działanie
> administratora i pojawia się tylko wtedy, gdy jest co odblokować.
> **(3)** Nagłówek karty ze znakiem (`.card__head--znak`) plus pasek działań po prawej:
> wyszukiwarka 300 × 38 z lupą **w polu** i etykietą ukrytą wizualnie (`.visually-hidden` —
> pole bez żadnej etykiety byłoby polem bez nazwy), „Filtruj" i „+ Konto administracyjne"
> w stylu `.btn--akcja`. ⚠ Nagłówek jest **własnym kontenerem zapytań** (`container-name: glowa`):
> `karta` siedzi na `.card__body`, a nagłówek stoi obok niego, nie w nim.
> **(4)** Tabela osób bez zebry: separator `--c-line-soft`, podświetlenie wiersza pod kursorem,
> nagłówki 11 px 600 w `--c-text-3`, przy nazwisku **kółko z inicjałami** (`.inicjaly`, bok
> ze zmiennej — ten sam znak ma 30 px na liście, 34 px w kontrolce dostępu, 26 px w oknie grupy).
> **(5)** Pole adresu i kontrolka dostępu **bez ramki w spoczynku**: w kolumnie stało kilkadziesiąt
> ramek pod sobą i lista wyglądała jak formularz. Ramka wraca pod kursorem, a przy pisaniu dochodzi
> obwódka 3 px w `--c-accent-soft`. „Zapisz" **pojawia się dopiero przy zmianie** — robi to CSS
> na stanie `disabled`, który ustawia `modules/zapisz.js`; bez JavaScriptu atrybut nie powstaje,
> więc przycisk jest widoczny i działa (ukrywanie nie może zabrać jedynej drogi zapisu).
> **(6)** ⚠ **Kolumna „Konto" łamie regułę „zieleń to działanie, nie stan"** — świadomie i tylko
> tutaj. Plakietki mają kropkę i własne barwy: „brak konta" i „niezaproszone" szare, „zaproszony"
> `--c-dzis-bg`/`--c-dzis-text`, **„aktywne" zielone**, „zablokowane" `--c-danger-soft`.
> Powód: pięć szarych plakietek w kolumnie, której się szuka wzrokiem, nie różnicowało niczego,
> a administrator czyta tę kolumnę właśnie po to, żeby zobaczyć, kto ma działające konto.
> Pozostałe ekrany zostają przy dawnej regule.
> **(7)** Lista rozwijana dostępu: 340 px, promień karty, `--shadow-pop`, głowa z inicjałami 34 px
> w barwie akcji, pozycje z polem wyboru 18 px (zaznaczona ma tło `--c-accent-soft`), „z etatem"
> jako zielona plakietka, „bez etatu" jako szara, pozycja bez etatu z powodem w podpowiedzi.
> **(8)** ⚠ **Na telefonie wiersz osoby przestaje być wierszem tabeli i staje się blokiem**
> (próg 560 px od okna, bo tabela stoi poza `.card__body` i nie ma nad sobą kontenera zapytań).
> Powód jest zmierzony: `table-layout: fixed` z kolumnami w procentach **nie przewija się w bok**,
> tylko ściska pięć kolumn do szerokości, przy której kontrolka dostępu ma 60 px, a adres jedną
> literę. „Nie przewija się" nie znaczy „da się przeczytać".
> **Nazewnictwo:** na tym ekranie, w liście dostępu i w oknach „spółka" → „firma". Sprawdzone
> pomiarem na wyrenderowanych ekranach: w widocznym tekście, podpowiedziach i etykietach **nie ma
> już naszego słowa „spółka"** — zostają wyłącznie nazwy własne firm z programu kadrowego.
> **Pomiar**: 1920 px — 6 kafli w jednym rzędzie po 250 px, wiersz 1558 × 48, pole szukania
> 300 × 38, inicjały 30 × 30, panel dostępu 340 px; 1366 px — kafle po 166 px w jednym rzędzie;
> 375 px — kafle po 2 w rzędzie, wiersz jako blok 341 × 241, pole adresu i kontrolka dostępu
> na całą szerokość. Zero przewijania w poziomie na każdej z trzech szerokości.

> **Zmiana 1.18.8 → 1.18.9: nazwy wyświetlane firm edytuje się w OKNIE, nie na osobnym ekranie**
> (zlecenie Z29, 18.10.2026). Przycisk „Zmień nazwy" na `/ustawienia` otwiera okno `.modal`
> z listą wszystkich firm; `/ustawienia/spolki` przekierowuje na `/ustawienia?okno=nazwy`, więc
> stare odnośniki i zakładki nie są martwe.
> **(1)** ⚠ **Okno jest OSADZONE w stronie, nie dociągane htmx-em — i to jest decyzja o dostępności,
> nie o wygodzie.** Osadzone działa bez JavaScriptu: serwer renderuje `<dialog open>`, gdy adres
> niesie `?okno=nazwy` albo gdy zapis odmówił, a `ui.js` tylko **podnosi** je do warstwy
> wierzchniej (`close()` + `showModal()`, bo `showModal()` na otwartym oknie rzuca wyjątek).
> Wersja z htmx-em byłaby krótsza i **nieosiągalna bez skryptów**, czyli edycja nazw zniknęłaby
> dla przeglądarki bez JS — a dotąd była osobną stroną.
> **(2)** ⚠ **`data-modal-trwale`**: wspólna obsługa okien czyści `.modal__body` przy zamknięciu
> (treść kreatora przychodzi z serwera i nie może zostać na drugie otwarcie). Okno z treścią
> osadzoną w stronie wyczyszczone **nie wróciłoby** — drugie otwarcie pokazałoby puste pudełko.
> Nowy atrybut wyłącza sprzątanie tam, gdzie treść nie jest dociągana.
> **(3)** **Jeden zapis dla całego zestawu.** Jednoznaczność nazw dotyczy wszystkich firm naraz:
> zamiana nazw między dwiema firmami jest poprawna jako całość, a rozbita na dwa żądania odbija
> się o klucz unikalny w połowie drogi. Przy choćby jednej odmowie **nie zapisuje się nic**,
> okno wraca otwarte z wpisanymi wartościami i komunikatem przy właściwym polu.
> **(4)** Układ okna: nagłówek ze znakiem jak nagłówki kart `/ustawienia` (kwadrat 38 px,
> `--c-accent-soft`, ikona 20 px), wiersz firmy w siatce `minmax(0, 1fr) 260px` z separatorem
> `--c-line-soft` (pierwszy wiersz bez kreski), szara podpowiedź `--c-bg` pod listą, stopka
> `--c-surface-mute` z „Anuluj" i „Zapisz nazwy". `autofocus` na pierwszym polu — bez niego
> `showModal()` stawia fokus na krzyżyku, czyli okno otwiera się z zaznaczonym „wyjdź stąd".
> **Pomiar**: 1920 i 1366 px — okno 720 × 487, kolumny wiersza `386px 260px`, pole 260 × 38;
> 375 px — okno 341 px szerokości (margines `--s-4`), jedna kolumna, pole 291 × 38, stopka
> widoczna, treść przewija się w `.modal__body`. Zero przewijania w poziomie na każdej szerokości.

> **Zmiana 1.18.7 → 1.18.8: trzy małe karty na `/ustawienia` mają równą wysokość**
> (zlecenie Z28, 18.10.2026). `.ustaw-trio` straciło `align-items: start` i zostaje przy
> domyślnym `stretch` siatki — zmierzone: **213 / 150 / 212 px przed, 213 / 213 / 213 px po**,
> przy 1920 i 1366 px. „Powiadomienia" mają jeden wiersz, a pozostałe karty po dwa, więc środkowa
> wisiała krócej i rząd wyglądał na niedokończony.
> ⚠ Na telefonie nic się nie zmienia i nie trzeba tam ani progu, ani drugiej reguły: każda karta
> jest wtedy jedyna w swoim rzędzie, więc rząd ma dokładnie jej wysokość (zmierzone przy 375 px:
> 213 / 150 / 212 px, trzy rzędy).

> **Zmiana 1.18.6 → 1.18.7: ekran `/ustawienia` ma układ pulpitu, nie listy kart**
> (zlecenie Z27, 18.10.2026 — prototyp zaakceptowany przez Krzysztofa). Funkcje, formularze
> i adresy zostały bez zmian; zmienił się wyłącznie układ i to, co widać bez przewijania.
> **(1)** **Pasek pięciu kafli stanu instalacji** na górze — ten sam komponent `.kafle`/`.kafel`,
> co na pulpicie, bez otaczającej karty, w modyfikatorze `.kafle--auto`. Kafle odpowiadają
> na pytanie „czy instalacja jest gotowa" przed rozwinięciem czegokolwiek: strefa, cron, poczta,
> weryfikacja dwuetapowa, skrzynka na alerty. Stan nieustawiony idzie barwą wartości
> (`--kafel__wartosc--odchylenie`, czyli `--c-warn-text`), nie plakietką.
> ⚠ **Liczbę kolumn robi `auto-fit` z podłogą 160 px, a nie zapytanie kontenerowe.** Pierwsza
> wersja pytała `@container kafle` o samo `.kafle--auto` i nie zadziałała ani razu — **zapytanie
> kontenerowe nie pyta o siebie**. Na telefonie zostawało pięć kolumn, a nazwa strefy łamała się
> po jednej literze. Wychodzi 5 kolumn przy 1558 px, 3 przy ~610 px i 2 na telefonie.
> **(2)** **Nagłówek karty ma znak**: kwadrat o boku `--control-h` (38 px — tyle co pole
> i przycisk, więc nagłówek trzyma ten sam rytm), promień `--radius-card`, tło `--c-accent-soft`,
> ikona 20 px w `--c-accent-text`. Opis karty zszedł **spod tytułu** zamiast stać po prawej:
> wyrównany do prawej czytał się jak druga, niezależna informacja, a jest podpisem tytułu.
> **(3)** ⚠ **NOWY WZORZEC: wiersz konfiguracji z przyciskiem** (`.settings-list--akcje`).
> Cały wiersz jest jednym odnośnikiem, a przycisk po prawej jest jego **częścią**, nie drugim
> celem kliknięcia — stąd `<span class="btn …">` wewnątrz `<a>`, bo przycisku w odnośniku
> zagnieździć nie wolno. Oba przyciski mają jedną szerokość z jednej zmiennej
> (`--ustaw-akcja-w`): dwa przyciski w kolumnie o dwóch szerokościach czytają się jak dwa różne
> rodzaje działania. Po najechaniu tło wiersza `--c-surface-hover`, a przycisku `--c-accent-soft`
> z ramką `--c-accent`. Na karcie węższej niż 380 px przycisk schodzi pod nazwę.
> **(4)** **Działanie drugorzędne z ikoną** (`.btn--akcja`): ramka `--c-line-strong`, tło
> `--c-surface`, napis `--t-small` 600 w `--c-accent-text`, ikona 16 px, wysokość `--control-h`.
> ⚠ To **modyfikator `.btn--ghost`**, nie nowy przycisk: sama klasa `.btn--ghost` stoi w 92
> miejscach w szablonach, więc przemalowanie jej byłoby przerysowaniem pół programu — czyli
> decyzją zakresową, nie poprawką jednego ekranu. W prototypie stały dwie wysokości (36 i 40 px);
> w tym systemie kontrolka ma 38, więc obie zlewają się w jedną i „Kopiuj" jest równe polu.
> **(5)** **Cron**: cztery kafelki danych na tle strony (`--c-bg`, promień `--radius`) zamiast
> listy par, pasek z ramką „Ostatnie potwierdzenie …" plus „Sprawdź cron", a instrukcja
> „Co ustawić u hostingodawcy" przestała być osobną kartą i jest **zwiniętą sekcją `<details>`**
> wewnątrz karty crona. Zniknęła plakietka „cron podejmuje zadania": stan spodziewany nie
> potrzebuje znaku, a plakietki zostają tam, gdzie coś jest nie tak.
> **(6)** **Trzy małe karty obok siebie** na dole (weryfikacja, powiadomienia, integracja),
> na telefonie jedna pod drugą. Plakietki niosą znaczenie: zielona „wymagana", szara
> „do ustalenia (Etap 3)", bursztynowa „nie ustawiono"; progi godzinowe są wartością 14 px 600,
> nie plakietką. ⚠ Znak wiersza przychodzi **z kontrolera** (`w.znak`), szablon tylko dobiera
> klasę — o tym, co wiersz znaczy, wie serwer.
> **(7)** Zniknęły: zdanie „Konfiguracja instalacji." razem z pustym `.page__head` i plakietka
> stanu poczty w wierszu listy (stan jest w kaflu i na ekranie poczty — trzy miejsca na jedną
> daną to trzy miejsca do rozjechania).
> **Pomiar**: 1920, 1366 i 375 px — zero przewijania w poziomie na stronie i w każdej karcie,
> pasek kafli 5 / 5 / 2 w rzędzie, oba przyciski wierszy po 156 × 38 px.

> **Zmiana 1.18.5 → 1.18.6: przyklejony wiersz dni JEST górną krawędzią bloku z siatką**
> (zlecenie Z26, 18.10.2026 — poprawka Z24/Z25 po sprawdzeniu z Krzysztofem w jego przeglądarce,
> także przy powiększeniu). Zgłoszenie miało trzy objawy i trzy różne przyczyny.
> **(1)** ⚠ **Zmierzona liczba musi NADĄŻAĆ za wysokością karty nagłówka.** `--kal-dol-naglowka`
> powstawało raz, na wczytaniu — więc każda późniejsza zmiana wysokości (powiększenie
> przeglądarki, zawinięta legenda, późno wczytany krój pisma) zostawiała wiersz dni na starym
> `top`: chował się pod kartą albo odjeżdżał od niej. Teraz liczy to `ResizeObserver`
> na `.card--kal-naglowek` (`box: 'border-box'`), przeliczając w `requestAnimationFrame`.
> Zmierzone: `padding-bottom: 30px` dołożony karcie daje **284 → 314 → 284 px** po zdjęciu;
> przed poprawką `top` nie ruszał się wcale.
> **(2)** ⚠ **Każda komórka wiersza dni niesie własny pas tła strony w górę**
> (`.team-grid__head::before`, `--kal-pas-nad-dniami` = odstęp bloków plus zapas jednej
> wyściółki). To druga warstwa: pas pod kartą jedzie **razem z kartą**, więc zakrywa szczelinę
> tylko przy zgodnej wysokości, a ten jedzie razem z wierszem. Skrajne komórki ciągną pas
> do krawędzi kolumny treści, bo pod spodem przejeżdżają też pionowe ramki i cień bloku.
> **(3)** ⚠ **Blok z siatką ODDAJE górną krawędź wierszowi dni** (`.card--kal-siatka`:
> bez `border-top` i bez górnych promieni). Do Z26 krawędź rysowały dwa elementy jeden nad
> drugim — zmierzone: ramka karty na `y = 284`, ramka komórki na `y = 285`, czyli w spoczynku
> kreska 2 px i dwa współśrodkowe łuki, a po przyklejeniu kreska 1 px i jeden łuk. To była cała
> „różnica rogów po przewinięciu". Wycinek poza łukiem (≈ 21 px² w narożniku) zamalowuje teraz
> tłem strony sama komórka (`radial-gradient` z promieniem z `--radius-card`) — wcześniej
> prześwitywały tam przewijane wiersze.
> **(4)** ⚠ **Brzegi pionowe bloku rysują KOMÓRKI SKRAJNE, nie ramka karty** (`margin-inline:
> -1px` na wnętrzu karty, `border-right` wrócił na ostatnią kolumnę). Bez tego ramka karty —
> która stoi 1 px **za** siatką — zostawała przy przyklejonym wierszu **odczepioną kreską**
> biegnącą prosto do góry obok łuku narożnika. Zakrycie jej pseudoelementem jest niemożliwe:
> **każdy piksel wystający w prawo dokłada się do `scrollWidth`**, a to na tym ekranie jest
> naruszeniem bramki (zmierzone: 1558 → 1559 przy 1 px, 1558 → 1591 przy pasie ciągniętym
> do krawędzi kolumny treści — bramka złapała to w pierwszym przebiegu Z26).
> Przy okazji znika stara nierówność: lewy brzeg miał **2 px** (ramka karty plus ramka kolumny
> osoby), prawy 1 px — teraz oba mają 1 px, a kreski zostają w tych samych miejscach.
> Dół bloku zostaje nietknięty.
> **Dowód z pomiaru** (Z26): pasy szczelin przy przewinięciu 0–240 px, w **pięciu oknach** —
> 1920 × 1080 i 1366 × 768 bez powiększenia oraz 1745 / 1536 / 1280 px CSS przy powiększeniu
> 110 / 125 / 150 % — plus próba zmiany wysokości karty w trakcie: **0 pikseli innych niż tło
> strony** i odstęp wiersza od karty **równy 16 px na każdym położeniu suwaka**. Narożniki
> w spoczynku i po przewinięciu różnią się **4 i 13 pikselami** z 256 w wycinku 16 × 16,
> wyłącznie wygładzaniem napisu. Dwie kontrole negatywne: zwężony pas tła daje **233 228**
> obcych pikseli, a wyłączony `ResizeObserver` — odstęp **−14 px** zamiast 16 px.
> ⚠ **Przy powiększeniu 150 % wiersz dni przykleja się do PUDEŁKA, nie do strony**: 1280 px CSS
> to blok o 966 px, czyli poniżej progu 1006 px z 1.18.5. To ta sama otwarta sprawa, nie nowa
> usterka — decyzja Krzysztofa.

> **Zmiana 1.18.4 → 1.18.5: na kalendarzu przewija się STRONA, a nie pudełko osi czasu**
> (zlecenia Z24 i Z25, 17.10.2026 — wybór Krzysztofa po dwóch prototypach). Oś czasu miała
> własny pasek przewijania i własną wysokość liczoną z okna; teraz jest tak wysoka, jak ma
> wierszy, a w kadrze zostają **dwa przyklejone pasy**.
> **(1)** **Blok nagłówka kalendarza przykleja się w swojej NATURALNEJ pozycji**
> (`.card--kal-naglowek`, `top: var(--przyklej-pod-belka)` = belka plus wyściółka strony,
> zmierzone 64 + 32 = 96 px; przy oknie ≤ 1024 px wyściółka ma 16 px). Nie „pod belką":
> przy `top: var(--topbar-h)` blok podjechałby o te 32 px i dopiero stanął, czyli pierwszy ruch
> suwakiem ruszyłby rzeczą, która ma stać nieruchomo. Dotyczy wszystkich skal: Miesiąca, Roku,
> Listy, Zespołu i Firmy — jeden układ, nie pięć.
> **(2)** ⚠ **Szczeliny nad blokiem i pod nim muszą być ZAKRYTE tłem strony na pełnej szerokości
> kolumny treści.** Przewijane wiersze przechodzą pod przyklejonym blokiem i bez zakrycia widać
> je w szczelinach. Robią to dwa pseudoelementy bloku (`::before` i `::after`), a nie `box-shadow`
> z prototypu: cień ma promień karty, więc zostawia niezakryte ścięcia w narożnikach.
> ⚠ Zasięg pasa to `--przyklej-luka` **w obie strony**, bo `left: 0` to krawędź pudełka
> wyściółki, czyli już za ramką karty — i w tej szparze widać było 1 px ramki bloku
> przejeżdżającego pod spodem razem z jego cieniem.
> **Dowód z pomiaru** (Z25): skan pasów szczelin przy przewinięciu 0–240 px co 4 px, przy
> 1920 × 1080 i 1366 × 768, na Firmie, Roku i Liście — **0 pikseli innych niż tło strony**
> (6 × 61 × 2 pasów). Kontrola negatywna ze zwężonym pasem: **59 z 61** położeń z obcymi
> pikselami w pasie dolnym i **12 z 61** w górnym.
> **(3)** **Przyklejony wiersz dni wygląda jak górna krawędź karty.** Górna i lewa ramka
> przeszły z `.team-grid` **na komórki** (`.team-grid__head`, `.team-grid__person`), bo ramka
> na siatce jedzie razem z nią i przyklejony wiersz zostawał bez góry; komórki skrajne dostają
> `border-top-left/right-radius: var(--radius-card)`. W spoczynku wygląd jest co do piksela ten
> sam. ⚠ Przy okazji poprawka błędu: pod progiem szerokości przyklejona w bok kolumna nazwisk
> jechała bez własnej lewej krawędzi.
> **(4)** ⚠ **Próg 1006 px SZEROKOŚCI BLOKU** (`@container karta`): poniżej wraca cały dotychczasowy
> arkusz we własnym pudełku (`overflow: auto`, `max-height: var(--kadr-h)`, nagłówek dni na jego
> górnej krawędzi). Powodem jest reguła, nie wybór: **`overflow-x: auto` też czyni element
> kontenerem przewijania**, a wtedy `sticky` wiersza dni odnosi się do pudełka, nie do okna.
> 1006 px to `min-width` siatki w najszerszym przypadku (200 + 31 × 26); od okna wypada to
> na 1320 px, czyli dokładnie tam, gdzie stał stary próg z 11.10. **Powłoka telefonu nie
> definiuje zmiennych przyklejenia**, więc `/m/` zostaje przy dotychczasowym układzie — blok
> nagłówka ma tam 380 px przy ekranie 812 px i przyklejony zabrałby połowę widoku danych.
> **(5)** Jedyny nowy JavaScript to **jedna zmierzona liczba**
> (`modules/kalendarz-przyklej.js` → `--kal-dol-naglowka`): wysokości bloku nagłówka arkusz nie
> zna, bo legenda i przełączniki się zawijają. Bez tego pliku blok nagłówka dalej się przykleja
> (czysty CSS), a wiersz dni po prostu nie — i to jest zamierzony stan zapasowy.
> **(6)** Z ekranu znika komunikat **„To konto nie ma zatrudnienia, więc nie ma zespołu
> do pokazania"** (Z24 punkt 2): to samo konto widzi już zdanie w karcie poniżej, a pusty
> pojemnik komunikatu nie powstaje wcale.

> **Zmiana 1.18.3 → 1.18.4: znacznik „dziś” to BŁĘKITNE TŁO kratki** — trzeci i ostatni
> kształt tego znacznika w ciągu jednego dnia (pełna obwódka → przerywana → tło), wybrany przez
> Krzysztofa po porównaniu wszystkich trzech na żywo. Obwódka — i pełna, i przerywana — czytała
> się jak ZAZNACZENIE albo ramka kontrolki; wypełnienie mówi „ten dzień jest inny” tym samym
> językiem, co pastele rodzajów.
> **Nowe tokeny:** `--c-dzis-bg` `#E3EEFB`, `--c-dzis-line` `#BBD3F2`, `--c-dzis-text` `#1B4A86`.
> ⚠ **Błękit jest JEDYNYM zastosowaniem tej barwy w aplikacji i nie wolno go użyć do niczego
> innego.** Zieleń znaczy akcję, bursztyn i czerwień znaczą stan, pastele znaczą rodzaj
> nieobecności — dzień dzisiejszy nie jest żadną z tych rzeczy, więc dostaje własną barwę.
> Użycie jej w drugim miejscu odbiera „dziś” rozpoznawalność jednym spojrzeniem.
> ⚠ **Wypełnienie RODZAJU ma pierwszeństwo.** Gdy dziś wypada w dzień z nieobecnością albo
> w dzień wolny, tło zostaje tamto, a „dziś” niesie **sama ramka 1 px w `--c-dzis-text`**
> i numer w tym samym kolorze. Znacznik nawigacyjny nie ma prawa zasłonić danej — to ta sama
> zasada, co w nazwanym wyjątku 1.14.
> ⚠ **Zmierzone kontrasty** (próg tekstu 4,5; próg znaku graficznego 3,0 — WCAG 1.4.11):
>
> | co | na czym | kontrast |
> |---|---|---|
> | numer `--c-dzis-text` | tło `--c-dzis-bg` | **7,55** |
> | ramka `--c-dzis-text` | urlop | **7,44** |
> | ramka `--c-dzis-text` | zwolnienie | **7,59** |
> | ramka `--c-dzis-text` | kadrowe | **7,35** |
> | ramka `--c-dzis-text` | dzień wolny | **7,90** |
> | ramka `--c-dzis-text` | kratka zwykła | **8,64** |
> | wypełnienie `--c-dzis-bg` | kratka zwykła | 1,10 |
>
> Ostatni wiersz jest niski świadomie: wypełnienie należy do tej samej klasy znaków, co pastele
> rodzajów (1,12–1,21), a czytelność niesie TEKST na nim — dokładnie jak w wyjątku 1.14.
>
> **Zmiana 1.18.2 → 1.18.3:** znacznik „dziś” to **linia PRZERYWANA wewnątrz kratki**, nie pełna
> obwódka (decyzja Krzysztofa po porównaniu trzech wariantów na żywo). Pełna obwódka 2 px czytała
> się jak ZAZNACZENIE albo ramka kontrolki; przerywana mówi „to jest dziś”, a nie „to jest
> wybrane”. W siatce (Miesiąc i Rok) `outline: 1.5px dashed var(--c-accent)` z `outline-offset:
> -4px`, w nagłówku osi czasu ten sam rysunek pseudoelementem z `inset: 3px` — bo komórka
> nagłówka jest `sticky`, a obrys na niej wędrowałby razem z przyklejeniem. Numer bez zmian.
> ⚠ **Zmierzony prēświt do numeru** (linia nie dotyka plakietki nigdzie): Miesiąc 1920 — 42 px
> po bokach i 14 px góra–dół, Rok 1920 — 5,4 px, oś czasu — 5,4 i 6,4 px, a najciasniej
> w powłoce telefonu: **1,7 px** przy kratce 37 px. Gdyby to było za mało, przesunięcie schodzi
> do -3 px (linia idzie wtedy BLIŻEJ krawędzi, czyli DALEJ od numeru).
> ⚠ **Kontrast linii** (WCAG 1.4.11, próg 3:1) na wszystkich tłach kratki: zwykła 4,46 · dzień
> wolny 4,08 · urlop 3,84 · zwolnienie 3,92 · kadrowe 3,79 · biel karty 4,57 — wszędzie z zapasem.
> ⚠ **Chromium przy DPR 1 renderuje `outline-width: 1.5px` jako 1 px** (wartość obliczona
> `1px`). Linia jest więc cieńsza, niż mówi arkusz; na ekranach o większej gęstości dostanie
> pół piksela więcej. Zapis zostaje 1.5 px świadomie — to wartość projektowa, nie złudzenie.
>
> **Zmiany 1.18.1 → 1.18.2:**
> **(1)** **`.kafle` NIE JEST kartą.** Pasek kafli na pulpicie nie ma tła, ramki, cienia ani
> wyściółki — kafle stoją wprost na tle strony, a krawędzie skrajnych równają się z krawędziami
> kart niżej. Karta wokół kafli-kart to ramka w ramce: białe tło paska i białe tło kafelka zlewały
> się w jedną plamę, a 2 × 24 px wyściółki zjadało szerokość kafli. Zmierzone po zmianie: kafelek
> rośnie z 242 do 250 px przy 1920, z 157 do 343 px przy 1366 (trzy kolumny) i ze 149 do 165 px
> w powłoce telefonu, a wysokość paska spada ze 164 do 114 px. Odstęp pod paskiem daje `gap`
> kolumny `.page`, więc jest taki sam, co między kartami — bez własnej reguły.
> **(2)** ⚠ **W napisach dla użytkownika używamy wyłącznie znaków, które SA w hostowanym podzbiorze
> kroju.** Zmierzone 17.10.2026: podzbiór IBM Plex Sans nie ma glifów ← → ≠ ≥ ⚠ ✕ — wszystkie
> rysują się jako pusty prostokąt (`.notdef`), a `unicode-range` w arkuszu jest tu bez winy, bo
> pliku z tymi glifami po prostu nie ma. Strzałki zastępuje słowo („Ustawienia, w sekcji Poczta"),
> krzyżyk — `×` (U+00D7, jest w podzbiorze), a znak uwagi znika z treści. **Lista dozwolonych
> znaków rozszerza się świadomie, osobnym zleceniem** (podzbiór trzeba zbudować od nowa
> z oryginalnego kroju) — nie przy okazji pisania zdania. W komentarzach kodu ⚠ zostaje: tam nic
> się nie renderuje.
>
> **Zmiana 1.18 → 1.18.1:** z §1 znika zapowiedź arkusza **`40-screens.css`** („wyjątki per
> ekran"). Nigdy nie powstał i nie jest potrzebny: wyjątki ekranowe stoją w `30-components.css`,
> przy komponencie, którego dotyczą, i mają tam komentarz z powodem — czyli dokładnie to, co
> tamten plik miał zapewnić, tylko bez szukania w dwóch miejscach. W zamian w spisie plików
> pojawia się `20-layout-mobile.css`, który istnieje od dawna, a w opisie go nie było.
>
> **Zmiany 1.17 → 1.18:** domknięcie tury Z6–Z15 (17.10.2026).
> **(1)** Nowy token typografii **`--t-stat: 24px`** — dana kafelka na pulpicie. Nie `--t-h1`:
> liczba w kaflu nie jest nagłówkiem strony, a przy 30 px pasek sześciu kafli wymagał 1320 px
> na jeden rząd, czyli zawijał się już na 1366 px.
> **(2)** Dwie nowe rodziny komponentów w spisie (§4): **`.kafle` / `.kafel`** (pasek kafli
> pulpitu) i **`.kal-nav` / `.kal-btn` / `.kal-grupa`** (blok nagłówka kalendarza, Z8). Obie mówią
> tym samym językiem: ramka 1 px `--c-line`, promień `--radius-card`, tło `--c-surface`.
> ⚠ `.kafle` **nie jest** modyfikatorem `.stats` i `.stats--duze` już nie istnieje — tamten
> modyfikator urósł do 82 linii, czyli przerósł komponent, który modyfikował, a `.stats` stoi
> na czterech innych ekranach, gdzie wartość kafelka bywa tekstem, nie liczbą.
> **(3)** Reguła zmierzona, nie wyczytana: **zapytanie kontenerowe nie dotyczy samego kontenera.**
> `@container` pyta o najbliższego PRZODKA-kontener, więc reguła ustawiająca `grid-template-columns`
> na elemencie, który sam jest kontenerem, nie wejdzie nigdy — i nie zgłosi błędu. Zmierzone:
> sześć kolumn po 38,7 px w powłoce telefonu. Zawijanie przestawia więc **rozpiętość dzieci**
> (`grid-column: span N`), nie liczbę kolumn rodzica.
> **(4)** Znacznik „dziś" to **obwódka całej kratki** (`msiatka__kratka--dzis`, `inset 0 0 0 2px`
> w `--c-accent`), a nie plakietka na numerze. Plakietka przy numerze dwucyfrowym wychodziła poza
> kratkę i myliła się z paskiem rodzaju.
> **(5)** Legenda rodzajów stoi **w bloku nagłówka kalendarza**, jedna dla wszystkich widoków —
> i **nie renderuje się na skali Lista**, bo tam rodzaj stoi słowem i nie ma ani jednej
> pokolorowanej kratki, którą legenda miałaby opisywać.
> ⚠ §4c (dwie siatki miesiąca) **przestaje być pytaniem** — patrz tam.

> **Zmiany 1.16 → 1.17:** nazwany wyjątek osi czasu (1.14) **przepisany**, nie opatrzony uwagą —
> kolor rodzaju niesie **wypełnienie**, obrysu nie ma, nośnikiem czytelności jest **tekst na pasku**
> (5,08–6,60), a legenda bierze te same tokeny. ⚠ Dopisane **zmierzone ograniczenie**: trzy pastele
> różnią się tylko barwą (wzajemnie 1,012–1,033), więc w odcieniach szarości rodzaj jest
> nierozpoznawalny, a ściemnianie pasteli tego nie naprawia — drugi, niekolorowy kanał rodzaju
> to osobna decyzja. §3.1 dostaje **drugi nazwany wyjątek** od „zieleń = akcja", z granicą
> na `.team-grid__bar`.

> **Zmiany 1.15 → 1.16:** nowy **§13 — słownictwo: jednostka organizacyjna nazywa się FIRMA**
> (rozstrzygnięcie Krzysztofa 11.10.2026: „nie każda firma jest spółką").

> **Zmiany 1.0 → 1.1** (po zmierzeniu pierwszego wdrożenia w przeglądarce): `--page-max` 1280 → 1560
> i odstęp doliczany **na zewnątrz** limitu; `--c-text-4` i `--c-text-5` przestają być kolorami tekstu
> (3,2:1 — poniżej normy); nagłówek kolumny dostaje z powrotem górny odstęp i wyrównanie zgodne
> ze swoją kolumną; nowa reguła kolumny rozciągliwej w tabeli; nowy §4a (wysokość kontrolek)
> i §5.5 (kreator podłączenia). Trzy z tych rzeczy były błędami w wersji 1.0, nie w kodzie.
>
> **Zmiany 1.1 → 1.2** (po obejrzeniu wdrożenia przez Krzysztofa): nowy token `--c-overlay`;
> reguła kontrastu dla podpisów leżących **poza kartą**; doprecyzowana reguła przedrostków w §9
> (sformułowanie 1.1 było sprzeczne z własnymi przypadkami testowymi); nowy §5.6 — **potwierdzanie
> operacji nieodwracalnych** i **pokazywanie sekretu jeden raz**, czego dokument nie opisywał wcale;
> nowy §5.7 — wymiana fragmentu zamiast przeładowania strony.
>
> **Zmiany 1.2 → 1.3:** nowy §10 — **czas i strefa czasowa** (zmierzone: ekrany pokazują UTC,
> czyli u klienta w Polsce godzinę o dwie wcześniejszą niż na jego zegarze); poprawiony zapis
> o `:focus-visible` w §5.9 (wymaganie 1.2 było niewykonalne i sprzeczne ze specyfikacją
> przeglądarek); `.btn--google` i `.btn--apple` dopisane do §4a.
>
> **Zmiany 1.3 → 1.4:** **licznik nakładki przy wymianie klucza znika** — stary klucz działa,
> dopóki nowy nie przejmie, bez terminu. Poprawiona treść reguły na ekranie integracji (§5.3).
>
> **Zmiany 1.9 → 1.10:** ⚠ `.input--sm` **usunięty ze spisu — nie istniał w arkuszu**, a spis
> go wymieniał; węższe pole robimy regułą kontekstową. ⚠ Nowa reguła w §4c: **jedna nazwa
> komponentu nagłówka strony** — `.page__head` zostaje, `.page-header` znika (trzeci raz ta sama
> klasa błędu po `.note`/`.notice` i dwóch siatkach miesiąca).
>
> **Zmiany 1.8 → 1.9:** §4 przepisany na **spis rodzin** po skasowaniu starego systemu — trzy
> stare arkusze nie istnieją, a `30-components.css` ma **164 klasy**. Pełne opisy
> („czego NIE robi") w `docs/komponenty-do-spisu.md`, **część obowiązująca**, nie załącznik.
> Nowy §4c — **dwie siatki miesiąca**, decyzja odłożona do makiety świadomie.
>
> **Zmiany 1.7 → 1.8:** §4b rozstrzyga **`.note` kontra `.notice`** — dwa paski komunikatu
> istniały obok siebie, a `.note` był w arkuszu **przed** regułą o jednej nazwie. Zostaje
> `.notice`. Do §4 dochodzą `.input--area` i nazwana luka `.form-grid`. ⚠ Zmierzone przy okazji:
> `30-components.css` definiuje **115 klas**, a spis §4 wymienia **45** — brakuje **dwudziestu
> jeden rodzin**. Pełny wykaz w `docs/komponenty-do-spisu.md`.
>
> **Zmiany 1.6 → 1.7:** ⚠ poprawiony §11.4 — warunek zamiany tabeli na listę był **błędny**
> (`wskaźnik zgrubny`, potem `szerokość okna`); obowiązuje **szerokość kontenera**, przez zapytanie
> `@container`. Zmierzone: mobilny na komputerze ma kolumnę **520 px** przy oknie 1920, więc żaden
> z poprzednich warunków nie zadziałał. Ta sama poprawka dotyczy komórki kalendarza. Do §4 dochodzą
> `.table__lead` i `.table__state`; nowy §4b — **jedna nazwa paska komunikatu**.
>
> **Zmiany 1.5 → 1.6:** nowy §11 — **wariant mobilny**, po obejrzeniu go po raz pierwszy
> na szerokości telefonu. Odwołana wcześniejsza diagnoza („mobilny bez reguł" — była błędna,
> wyciągnięta z jedynego ekranu widocznego bez sesji); powłoka mobilna na tokenach; fundament
> dotykowy jako reguła **wspólna**, nie mobilna; tabela na telefonie zamieniona w listę.
>
> **Zmiany 1.4 → 1.5:** nowy komponent **`.input-group`** w §4a — kontrolka wbudowana w pole
> (pokazanie hasła, ikona, jednostka). Powód: pole hasła było **węższe od pola loginu**
> o szerokość przycisku „Pokaż" stojącego obok niego.

Ten dokument jest źródłem prawdy dla warstwy wizualnej tenanta. Powstał na podstawie przeglądu
działającej instancji i zatwierdzonej makiety czterech ekranów.

> ⚠ **Zasada nadrzędna: nie poprawiamy ekranów, tylko wprowadzamy system.**
> Poprawka „na tym jednym ekranie" jest tym, co doprowadziło do stanu obecnego: każdy ekran ma
> dziś własne odstępy, własny rozmiar nagłówka i własne rozumienie tego, czym jest karta.
> Najpierw zmienne i klasy bazowe, potem ekran po ekranie — nigdy odwrotnie.

---

## 0. Diagnoza stanu zastanego (skrót)

Aplikacja nie jest brzydka — jest **bez systemu**. Objawy, które to zdradzają:

- bloki stykają się z krawędziami kontenera i ze sobą; tabela „wisi" wewnątrz karty,
- nagłówek strony ~44 px wersalikami, a zaraz pod nim 13 px — brak skali pośredniej,
- trzy zielenie konkurują ze sobą (logo, przycisk, plakietka), a 36 plakietek `aktywne`
  w jednej kolumnie to tapeta, nie informacja,
- dane techniczne (`Etat 1,0000`, `hmac:…`, `Identyfikator R2`) stoją obok danych dla człowieka,
- lewy pasek ma 12 pozycji bez podziału i ikony z różnych zestawów; górny pasek ma pustą lewą połowę.

---

## 1. Pliki i kolejność ładowania

```
/public/assets/css/
  00-tokens.css       <- WYŁĄCZNIE :root { --… }. Żadnego selektora poza :root.
  10-base.css         <- reset, @font-face, typografia, linki, focus
  20-layout.css       <- powłoka aplikacji: sidebar, topbar, main, page
  30-components.css   <- card, table, btn, chip, input, nav, segmented, stats, defs, kafle,
                         kal-nav, msiatka, team-grid — z wyjątkami ekranowymi na miejscu
  20-layout-mobile.css <- powłoka telefonu (§11.2) i nieliczne nadpisania wymuszone szerokością
/public/assets/fonts/
  ibm-plex-sans-{400,500,600}-{latin,latin-ext}.woff2
  OFL.txt             <- licencja, obowiązkowo w paczce klienta
```

Ładowane w `layout/base.twig` przez `asset()`, w tej kolejności, jako `<link rel="stylesheet">`.
Nie łączymy ich na razie w jeden plik — na etapie budowania systemu rozdział jest wart jednego
dodatkowego żądania. Gdy system się ustabilizuje, konkatenację robi krok builda, nie ręka.

⚠ **Reguła blokująca: poza `00-tokens.css` w żadnym pliku CSS nie ma wartości `#rrggbb`,
`rgb(...)` ani `px` odstępu spoza skali.** Dopisz to do checklisty odbioru i sprawdzaj
`grep -nE '#[0-9a-fA-F]{3,8}' public/assets/css/ --exclude=00-tokens.css` — ma nie zwracać nic.

### Krój pisma — hostujemy u siebie, nie z Google Fonts

**IBM Plex Sans**, wagi 400/500/600, podzbiory `latin` i `latin-ext` (polskie znaki), format `woff2`.

⚠ Pobieranie z `fonts.googleapis.com` **łamie CSP z §13** (`default-src 'self'`, bez `font-src`)
i wymagałoby rozluźnienia polityki — czyli dokładnie tego, czego §6 i §13 zabraniają.
Dodatkowo instalacje w intranecie bez wyjścia na świat dostałyby fallback systemowy.
Dlatego: pliki `woff2` jadą w paczce, `@font-face` w `10-base.css`, licencja OFL w `/public/assets/fonts/OFL.txt`.

---

## 2. `00-tokens.css` — do przepisania wprost

```css
:root {
  /* ——— kolor: role, nie nazwy barw ——— */
  --c-bg:             #F5F6F8;   /* tło strony */
  --c-surface:        #FFFFFF;   /* karta, pasek boczny, pasek górny */
  --c-surface-mute:   #FCFCFD;   /* stopka karty, co drugi wiersz w tabeli z zebrą */
  --c-surface-hover:  #F7F8FA;   /* wiersz tabeli pod kursorem */
  --c-line:           #E6E8EC;   /* linia rozdzielająca */
  --c-line-soft:      #F0F2F5;   /* linia między wierszami tabeli */
  --c-line-strong:    #D4D8DF;   /* obrys kontrolki */

  --c-text:           #15181D;   /* nagłówek, dana główna */
  --c-text-2:         #2A2F39;   /* treść */
  --c-text-3:         #6B7280;   /* dana drugorzędna, podtytuł */
  --c-text-4:         #8A909C;   /* ⚠ TYLKO ikony i separatory — nigdy tekst (3,2:1) */
  --c-text-5:         #A8AEB9;   /* ⚠ TYLKO elementy dekoracyjne — nigdy tekst */

  --c-accent:         #12874A;   /* AKCJA — i nic innego */
  --c-accent-hover:   #0E6E3C;
  --c-accent-soft:    #E8F4EC;
  --c-accent-text:    #0E6E3C;

  --c-warn:           #C07C00;
  --c-warn-soft:      #FDF3E2;
  --c-warn-text:      #8A5300;
  --c-warn-line:      #F0D8A8;
  --c-warn-bg:        #FFFCF5;

  --c-danger:         #B42318;
  --c-danger-soft:    #FDECEA;
  --c-danger-text:    #9F2115;

  --c-neutral-soft:   #F0F2F5;   /* plakietka stanu obojętnego */
  --c-dot:            #B9BFC9;   /* kropka stanu normalnego */
  --c-focus:          #12874A;
  --c-overlay:        rgba(20, 24, 32, .45);  /* przyciemnienie tła pod oknem modalnym */

  /* ——— odstępy: dozwolone są TYLKO te wartości ——— */
  --s-1: 4px;  --s-2: 8px;  --s-3: 12px; --s-4: 16px;
  --s-5: 24px; --s-6: 32px; --s-7: 48px;

  /* ——— typografia ——— */
  --f-sans: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --t-h1:      30px;   /* nagłówek strony */
  --t-stat:    24px;   /* dana kafelka na pulpicie (1.18) */
  --t-h2:      20px;   /* nagłówek sekcji / karty */
  --t-lead:    16px;   /* wyróżniona treść, tytuł karty */
  --t-body:    14px;   /* treść i KAŻDA komórka tabeli */
  --t-small:   13px;   /* pomocnicze, przyciski drugorzędne */
  --t-caption: 12px;   /* podpis, opis pola, metryka */
  --t-label:   11px;   /* NAGŁÓWEK KOLUMNY, etykieta sekcji nawigacji */
  --lh-tight: 1.25;
  --lh-body:  1.55;
  --w-regular: 400; --w-medium: 500; --w-semibold: 600;
  --ls-tight: -0.5px;  /* tylko h1 */
  --ls-label:  0.5px;  /* tylko wersaliki 11px */

  /* ——— kształt: jeden promień, jeden cień ——— */
  --radius:       8px;
  --radius-card: 10px;
  --shadow-card: 0 1px 2px rgba(20, 24, 32, .04);
  --shadow-pop:  0 8px 24px rgba(20, 24, 32, .10);

  /* ——— wymiary powłoki ——— */
  --sidebar-w:  248px;
  --topbar-h:    64px;
  --row-h:       48px;   /* wiersz tabeli */
  --control-h:   38px;   /* input, select, przycisk */
  --control-h-sm: 32px;
  --page-max:  1560px;   /* szerokość TREŚCI; odstęp dolicza się na zewnątrz, nie od środka */
  --measure:     72ch;   /* maksymalna długość wiersza tekstu ciągłego */
}
```

⚠ **Ciemny motyw: nie teraz.** Tokeny są tak nazwane, żeby dało się go dołożyć jednym blokiem
`@media (prefers-color-scheme: dark)` nadpisującym wyłącznie `--c-*`. Robienie dwóch motywów przed
ustabilizowaniem jednego to podwójna praca — wracamy do tego po zamknięciu tury B.

---

## 3. Reguły, z których wynika wygląd

### 3.1 Kolor

- **Zieleń = akcja.** Przycisk główny, aktywna pozycja nawigacji, link. Nigdzie indziej.
- ⚠ **Jeden przycisk główny na ekran** — ten, po który użytkownik na ten ekran przyszedł.
  Dwa `btn--primary` obok siebie nie podpowiadają niczego: zieleń przestaje znaczyć „to
  zrób teraz" i zaczyna znaczyć „to jest przycisk". Zmierzone 21.09 na `/admin/konta`:
  `Wyślij zaproszenia` (główna czynność ekranu) i `Konto administracyjne` (czynność
  wykonywana raz na wdrożenie) miały tę samą klasę. **Czynność rzadka jest `btn--ghost`**,
  nawet jeśli jest ważna — waga w interfejsie opisuje częstość i kolejność, nie doniosłość.
  Okno modalne albo szuflada mają własny przycisk główny; liczy się jeden na warstwę.
- **Stan normalny nie dostaje koloru.** `aktywne` to szara kropka (`--c-dot`) i tekst
  `--c-text-3`, nie zielona plakietka. Kolorem świeci wyłącznie odstępstwo:
  `wygasa za 6 dni` (bursztyn), `przerwany` (czerwień), `zakończone` (szarość).
- **Kolumna nazwisk nie jest niebieska.** Nazwisko to `--c-text`, waga 500, `text-decoration: none`;
  podkreślenie pojawia się dopiero przy `:hover`.
- ⚠ **Jedyny nazwany wyjątek od „zieleń = akcja": zaznaczenie wiersza** (wybór osób do wysyłki
  zaproszeń). Zaznaczony wiersz ma tło `--c-accent-soft` i pasek `inset 3px 0 0 var(--c-accent)`
  przy lewej krawędzi. **To nie jest odstępstwo, tylko wyjątek z uzasadnieniem:** zaznaczenie
  jest **decyzją użytkownika**, a nie cechą danych — i prowadzi wprost do zielonego przycisku
  głównego („Wyślij zaproszenia") w pasku nad tabelą. Zieleń łączy tu decyzję z jej skutkiem,
  zamiast oceniać dane. ⚠ **Pasek jest ważniejszy niż tło** i on niesie tę informację: przy
  zmianie tonu albo w trybie wysokiego kontrastu tło może zniknąć, pasek zostaje.
  Rozstrzygnięcie 22.09.2026 (wariant C makiety `konta/07-warianty-wierszy.html`).
- ⚠ **Drugi nazwany wyjątek (11.10.2026): pastel urlopu w osi czasu.** Pasek nieobecności
  typu „urlop" ma zielone **wypełnienie** (`--c-pasek-urlop-tlo`, `#DCF0E4`) i stoi na **danej**,
  nie na akcji. Powód i granica w nazwanym wyjątku 1.14: ton jest o rząd słabszy od
  `--c-accent`, element jest nieklikalny i nigdy nie sąsiaduje z przyciskiem. ⚠ Wyjątek
  kończy się na `.team-grid__bar` — nie wolno go rozciągać na plakietki, wiersze tabel
  ani siatkę miesiąca.

### 3.2 Typografia

- Nagłówek strony: `--t-h1`, waga 600, `letter-spacing: var(--ls-tight)`, **bez `text-transform`**.
- Nagłówek kolumny tabeli: `--t-label`, wersaliki, **`--c-text-3`** — ciszej niż dane, ale wciąż
  czytelnie. ⚠ W wersji 1.0 było tu `--c-text-4`; zmierzone na żywo dało **3,21:1**, czyli poniżej
  wymaganych 4,5:1. Tekst nigdy nie jest jaśniejszy niż `--c-text-3`.
- ⚠ **Nazwisk nie transformujemy w CSS — nigdy `text-transform` na danych osobowych.**
  Wielkość liter poprawia **funkcja w PHP**, po stronie prezentacji, wedle zasad z §9.
  CSS nie wie, gdzie kończy się przedrostek, a gdzie zaczyna nazwisko.

### 3.3 Odstępy i proporcje

- Wewnątrz karty **24 px**, między kartami **24 px**, treść od krawędzi obszaru **32 px**.
- ⚠ **`--page-max` to szerokość treści, a odstęp dolicza się na zewnątrz**, nie od środka:
  ```css
  .page { max-width: calc(var(--page-max) + 2 * var(--s-6)); padding-inline: var(--s-6); margin-inline: auto; }
  ```
  W wersji 1.0 limit 1280 px obejmował również odstępy, więc przy oknie 1920 px treść dostawała
  **1216 px z 1657 px dostępnych** — 441 px pustki po bokach. Zmierzone, nie oszacowane.
- **Tekst ciągły ma własne ograniczenie `--measure` (72ch)**, niezależne od szerokości strony.
  Akapit czyta się źle w linii 1500 px, a tabela nie — jedno ograniczenie nie obsłuży obu.
- Nic nie styka się z krawędzią: kafelki, karty i tabele mają zawsze odstęp od kontenera.

### 3.4 Tabele — to jest 80% tej aplikacji

- Wiersz `--row-h` (48 px), padding poziomy 16 px; **pierwsza i ostatnia komórka 20 px**,
  żeby treść trzymała się krawędzi karty, a nie wisiała w niej.
- ⚠ **Nagłówek kolumny ma górny odstęp `--s-4`**: `th { padding: var(--s-4) var(--s-4) 10px; }`.
  Bez niego tekst nagłówka leży na krawędzi karty i jest przycinany przez `overflow: hidden`
  i zaokrąglenie — tak wyglądało pierwsze wdrożenie.
- ⚠ **`th` ma wyrównanie swojej kolumny.** Nagłówek kolumny liczbowej jest wyrównany w prawo
  razem z liczbami; nagłówek wyrównany w lewo nad liczbami wyrównanymi w prawo wisi obok nich,
  a nie nad nimi.
- ⚠ **Nadmiar szerokości idzie w JEDNĄ kolumnę, nie we wszystkie.** To jest prawdziwy powód,
  dla którego szeroka tabela wygląda źle: przeglądarka rozdziela zapas równo, więc liczba odsuwa się
  od swojego nagłówka. Kolumna niosąca najdłuższy tekst dostaje klasę `.table__grow`
  (`width: 100%`), pozostałe `white-space: nowrap` i kurczą się do treści.
  Kolumna rozciągliwa jest **wskazywana per tabela** — w tabeli pracowników to nazwisko,
  w tabeli przebiegów nazwa firmy.
- **Bez linii pionowych.** Pozioma linia `--c-line-soft` między wierszami, mocniejsza
  `--c-line` pod nagłówkiem. Ostatni wiersz bez linii.
- ⚠ **Tabela na pełną szerokość karty jest BEZPOŚREDNIM DZIECKIEM karty.** Wcięcie treści robi
  `padding` komórki (24 px + 1 px obramowania karty = **25 px** od krawędzi), nigdy opakowanie
  z własnym `padding`. Paski narzędzi nad tabelą i stopka pod nią mają swój `padding` osobno.
  Zmierzone 22.09.2026 przed poprawką: `/admin/konta` miało tabelę w `card__body` (padding 24),
  więc pierwsza komórka zaczynała się **49 px** od krawędzi karty, a tabela była **o 48 px
  węższa** niż karta, w której stała. Ten sam defekt miało jeszcze **pięć ekranów** (14 tabel):
  `/wnioski`, `/dane`, `/dane/synchronizacja`, `/diagnostyka`, `/styleguide`.
  ⚠ Pilnuje tego `dev/bramka-ekranowa.py` — jedna liczba na tabelę, sprawdzana na każdym ekranie
  (23 tabele, stan 22.09.2026). Sprawdzenie pomija tabelę rekordów w trybie karty, gdzie wiersz
  nie jest wierszem (`display: block`, zerowy padding komórki).
- ⚠ **Zebra i podświetlenie wiersza to MODYFIKATOR `.table--zebra`, nie domyślny wygląd tabeli**
  (rozstrzygnięcie 22.09.2026, wariant C makiety). Co drugi wiersz `--c-surface-mute`, wiersz
  pod kursorem `--c-surface-hover`, wysokość i linie bez zmian.

  **Reguła, po której się przydziela — nie lista ekranów:**

  > `.table--zebra` dostaje tabela, która **rośnie z pracą systemu** — jest stronicowana albo
  > jej długość zależy od tego, ile rekordów **przybyło** (osoby, zdarzenia, przebiegi, wnioski,
  > nieobecności) — **i ma co najmniej cztery kolumny**. Nie dostaje jej tabela o **stałej,
  > krótkiej** liczbie wierszy (konfiguracja, podsumowanie, karta jednego rekordu) ani żadna,
  > w której **kolor niesie znaczenie**.

  ⚠ Sformułowanie „rośnie z pracą systemu" jest **poprawką do pierwszej wersji reguły**, która
  mówiła „zależy od tego, ile klient ma rekordów". Tamta wersja wybierała też `/dane` (tyle
  wierszy, ile spółek) i „Limity z kadr" na karcie pracownika (tyle, ile limitów) — czyli tabele
  **konfiguracyjne**, o krótkiej i przewidywalnej długości. Rozróżnienie jest takie:
  spółek i limitów **przybywa od decyzji człowieka**, a zdarzeń i przebiegów **od samej pracy
  systemu**; zebra jest dla tych drugich.

  Powód, dla którego to nie weszło do `.table`: zmierzone 22.09 — tabelę ma **11 z 33 ekranów**
  (23 tabele), a `/diagnostyka` dziesięć tabel po kilka wierszy, gdzie zebra dokłada szumu
  w miejscu, w którym informację niesie kolor odchylenia. Zebra ma sens tam, gdzie wiersz jest
  **długi i trzeba go przewieść wzrokiem** — na liście osób między nazwiskiem a kolumną „Konto"
  jest 1200 px. Podświetlenie idzie tym samym modyfikatorem, bo na tabeli trzywierszowej
  sugeruje klikalność, której tam nie ma.

  Reguła wybrała (stan 22.09.2026): `/admin/konta`, `/admin/dziennik`, `/dane/firma/{id}`,
  `/dane/synchronizacja`, `/dane/synchronizacja/{id}` (tabela rekordów i tabela konfliktów,
  **bez** „Liczb przebiegu"), `/admin/integracja` (klucze i przebiegi), `/dane/pracownik/{id}`
  (nieobecności), `/wnioski` — a przez wspólny szablon także `/m/wnioski`, gdzie wiersz jest
  kartą i co drugi wpis dostaje przygaszone tło (sprawdzone: czyta się lepiej, nie gorzej).
- **Liczby do prawej**, `font-variant-numeric: tabular-nums`.
- **Formaty dla człowieka, nie z bazy:** `1,0000` → `1,00`; jeśli jest miejsce — `0,75 (3/4 etatu)`.
  Minuty normy → `8 h 00 min`. Jeden format daty w całej aplikacji: `RRRR-MM-DD`.
- **Kolumny techniczne znikają z widoku głównego.** `Identyfikator R2`, `Wpis`, `hmac:…`,
  `Zmieniono` idą pod zwijany pasek „Szczegóły techniczne" na dole ekranu.
- Grupowanie (np. po roku) to wiersz `.table__group` — 34 px, tło `--c-surface-mute`,
  tekst jak nagłówek kolumny.

### 3.5 Nawigacja

- Lewy pasek dzieli się na **trzy sekcje z etykietami**:
  - **Moja praca** — Pulpit, Kalendarz, Moje wnioski, Nowy wniosek
  - **Zespół** — Zespół, Dane z kadr
  - **Administracja** — Konta, Integracja, Diagnostyka, Licencja, Ustawienia
- Pozycja aktywna: tło `--c-accent-soft`, tekst `--c-accent-text`, waga 500, ikona `--c-accent`.
- Górny pasek: **ścieżka po lewej** (`Dane z kadr / Budomex / Nowak Piotr`) — rozwiązuje przy okazji
  brak nawigacji wstecz na ekranie pracownika. Po prawej sam awatar i imię, bez ramki i bez
  podtytułu „Moje konto"; **`Wyloguj` chowa się w menu pod awatarem** — to nie jest akcja pierwszego rzędu.

### 3.6 Ikony

Jeden zestaw, jedna grubość: **konturowe, `viewBox="0 0 20 20"`, `stroke-width: 1.5`,
`fill: none`, `stroke-linecap/linejoin: round`**, rozmiar 18 px w nawigacji, 16 px w treści.
Kolor dziedziczony z `currentColor` albo ustawiony klasą — nigdy `fill` na sztywno.

⚠ **Ikony wstawiamy makrami Twiga, nie `include` ze zmienną.** `{% include 'partials/ikony/' ~ nazwa %}`
to SSTI/LFI i jest zakazane w §5. Poprawnie:

```twig
{# templates/partials/ikony.twig #}
{% macro kalendarz() %}<svg class="ico" viewBox="0 0 20 20" aria-hidden="true">…</svg>{% endmacro %}
```
```twig
{% import 'partials/ikony.twig' as ikony %}
{{ ikony.kalendarz() }}
```

⚠ **Znak pisma nie jest ikoną** (dopisane 22.09.2026, po znalezisku na `/admin/hierarchia`).
Przycisk menu „⋯" renderował się jako **pusty prostokąt**: krój tego środowiska nie ma znaku
U+22EF. Skutek był nieproporcjonalny do przyczyny — **cała ścieżka obsługi bez myszki wisiała
na niewidocznej kontrolce**, a użytkownik widział puste miejsce tam, gdzie miał kliknąć.

Reguła: **do ikon wyłącznie SVG z `partials/ikony.twig`**. Nie używamy znaków spoza podstawowego
zakresu łacińskiego (`⋯ ⋮ × ▾ ✓ ⚙ ⚠ →` i podobnych) jako elementów interfejsu — krój u klienta
jest nieznany, a zastępczy glif albo wygląda inaczej, albo nie istnieje. W treści zdania takie
znaki są dopuszczalne, ale **nazwę kontrolki piszemy słowami**: „menu (trzy kropki)", nie „menu ⋯".

⚠ **Bramka tego nie złapie i nie ma jak.** Przycisk miał poprawne wymiary (28 × 24 px),
poprawny `aria-label` i poprawne miejsce w układzie — pustego glifu nie widać w żadnej mierze
liczbowej, tylko na zrzucie w powiększeniu. To jest znany limit bramki ekranowej, nie jej usterka.

### 3.7 Dostępność — nie na koniec, tylko od razu

- Kontrast tekstu 4.5:1 (3:1 od 24 px). Dlatego akcent to `#12874A`, a nie jaśniejsza zieleń
  z dzisiejszych przycisków — biały tekst na tamtej nie przechodzi (zmierzone: 4,57:1, przechodzi).
- ⚠ **Podpis leżący poza kartą bierze `--c-text-2`, nie `--c-text-3`.** `--c-text-3` jest
  sprawdzony względem `--c-surface` (biel) i daje tam 4,83:1; na tle strony `--c-bg` spada
  do **4,47:1**, czyli tuż pod próg. Dotyczy etykiet pól w pasku filtrów — jedynego miejsca,
  gdzie podpis 12 px leży poza kartą. Tokenu nie ruszamy: zmiana `--c-text-3` dotknęłaby
  wszystkich podpisów w aplikacji, żeby naprawić jedno miejsce.
- ⚠ **Żaden tekst nie jest jaśniejszy niż `--c-text-3`.** `--c-text-4` i `--c-text-5` służą
  do ikon i separatorów. Dotyczy to również podpisów 11–12 px — **im mniejszy tekst, tym bardziej
  potrzebuje kontrastu, a nie mniej.** Zmierzone na pierwszym wdrożeniu: nagłówki kolumn 3,21:1.
- `:focus-visible` z widocznym obrysem w kolorze akcentu — dziś go nie ma w ogóle,
  a aplikacja kadrowa bywa obsługiwana z klawiatury.
- Cel dotykowy ≥ 38 px wysokości dla każdej kontrolki.
- Element klikalny to `<button>` albo `<a href>`. **Nigdy `role="button"` na `<div>`** —
  tabulator go pomija. Przycisk z samą ikoną dostaje `aria-label`.
- Kolor nigdy nie jest jedynym nośnikiem informacji: przy kropce stanu zawsze stoi słowo.

### 3.8 htmx

- Reguły `.htmx-indicator` mieszkają w `30-components.css`, a w layoucie jest
  `<meta name="htmx-config" content='{"includeIndicatorStyles":false}'>` — inaczej htmx wstrzykuje
  własny `<style>` do `<head>` i łamie CSP na każdym ekranie (§5).
- Stan ładowania to **klasa**, nigdy `style=`.
- Fragmenty dziedziczą te same klasy co widok pełny — fragment nie ma własnego wyglądu.

---

## 4. Klasy — nazewnictwo i zakres

Konwencja: `blok`, `blok__element`, `blok--wariant`. Bez zagnieżdżania głębszego niż jeden poziom.

**Powłoka (`20-layout.css`)**
`.app` `.sidebar` `.sidebar__brand` `.nav-group` `.nav-group__label` `.nav-item` `.nav-item--on`
`.nav-item__ico` `.topbar` `.crumbs` `.crumbs__sep` `.user-menu` `.user-menu__avatar`
`.main` `.page` `.page__head` `.page__title` `.page__sub` `.page__actions` `.toolbar`

**Komponenty (`30-components.css`) — spis rodzin**

⚠ **Ten spis wymienia RODZINY, nie każdą klasę.** Pełny wykaz z opisami „do czego" i — ważniejszym —
„**czego NIE robi**" jest w `docs/komponenty-do-spisu.md` i jest jego **częścią obowiązującą**,
nie załącznikiem. ⚠ **Nazwa komponentu, której nie ma w spisie, to nazwa, którą ktoś za miesiąc
wymyśli drugi raz** — dokładnie tak powstały dwa paski komunikatu (§4b) i dwie siatki miesiąca.

*Komunikat o wyniku operacji (1.18.23, zlecenie Z47)*
`.toasty` `.toasty--nad-nawigacja` `.toast` (`--blad` `--sukces` `--uwaga`) `.toast__znak`
`.toast__tresc` `.toast__zamknij` `.toast--wchodzi` `.toast--wychodzi` `.komunikat--w-toascie`
⚠ `.flash` **skasowane** razem z paskiem `#flash`; znacznik komunikatu składa `partials/komunikat.twig`.

*Ekran diagnostyki (1.18.30, zlecenie Z50)*
`.diag-wiersze` `.diag-wiersz` `.diag-wiersz__tresc` `.diag-wiersz__nazwa` `.diag-wiersz__grupa`
`.diag-wiersz__wartosc` (`--uwaga` `--blad`) `.diag-wiersz__uwaga` `.diag-stan` (`--ok` `--uwaga`
`--blad`) `.diag-grupa` `.diag-grupa__glowa` `.diag-grupa__znak` `.diag-grupa__nazwa`
`.diag-grupa__strzalka` `.btn--ryzyko` (działanie niebezpieczne drugorzędne)
⚠ `.diag-group` i `.col--diag-*` **zostają** — to tabela kroku 1 i 7 instalatora, nie tego ekranu.

*Ekran licencji (1.18.29, zlecenie Z48)*
`.licencja-dane` `.licencja-wiersz` `.licencja-wiersz__etykieta` `.licencja-wiersz__wartosc`
(`--brak`) `.kod` `.kafel__kropka--duza` `.kafel__kropka--dzis`

*Karta pracownika (1.18.28, zlecenie Z46)*
`.limit-wiersz` (`--przekroczony`) `.limit-wiersz__glowa` `.limit-wiersz__nazwa` `.limit-wiersz__rok`
`.limit-wiersz__saldo` `.limit-wiersz__stopka` `.progress--limit` `.stan-kropka`
`.stan-kropka__znak--cichy` `.defs__dopisek` `.defs__link` `.table--nieobecnosci`
`.chip--urlop` `.chip--zwolnienie` `.chip--kadrowe` (barwy pasków kalendarza)

*Lista pracowników firmy (1.18.27, zlecenie Z45)*
`.table--pracownicy` (tabela bez zebry, podświetlenie wiersza, układ blokowy na telefonie)
`.table__num--kadrowy` `.pole-szukania__pole--waskie` `.pole-sortowania` `.pole-sortowania__pole`

*Okno skrzynki alertowej (1.18.26, zlecenie Z49)*
`.modal--alerty` (520 px, stopka o dwóch stronach: sprawdzenie po lewej, decyzje po prawej)

*Lista firm na `/dane` (1.18.25, zlecenie Z44)*
`.dane-firmy` `.dane-firma` `.dane-firma__znak` `.dane-firma__glowna` `.dane-firma__nazwa`
`.dane-firma__plakietki` `.dane-firma__liczba` (`--czas`) `.dane-firma__etykieta`
`.dane-firma__wartosc` `.dane-firma__czas` `.dane-firma__akcja`
⚠ `.col--firma-*` (kolumny tabeli firm) **skasowane razem z tabelą**.

*Ekran poczty (1.18.24, zlecenie Z43)*
`.poczta-forma` `.poczta-sekcja` `.poczta-sekcja__etykieta` `.poczta-proba` `.poczta-proba__pole`
`.poczta-proba__akcja` `.poczta-proba__notka` `.poczta-kolejka` `.poczta-kolejka__lista`
`.poczta-kolejka__wiersz` `.poczta-kolejka__dane` `.poczta-kolejka__nazwa` `.poczta-kolejka__kod`
`.ikona-akcja--sm` (kosz 32 px)

*Karta i treść*
`.card` `.card__head` `.card__title` `.card__meta` `.card__body` `.card__body--tight` `.card__foot`
`.card__foot--notka` (1.18.21, zlecenie Z41 — stopka, w której stoi sama notka w ramce)
`.stack` `.stack--sm` `.stack--lg` `.divider` `.empty-state` `.disclosure` `.disclosure__body`

*Tabela i lista rekordów*
`.table` `.table--rekordy` `.table__lead` `.table__state` `.table__num` `.table__mute`
`.table__name` `.table__group` `.table__grow` `.table__empty` `.table-scroll` `.pager`

*Lista wierszy*
`.settings-list` `.settings-list__row` `.settings-list__copy`

*Kalendarz — siatka miesiąca (jedna, od 1.18)*
`.msiatka` `.msiatka__siatka` `.msiatka__kratka` `.msiatka__kratka--urlop|--zasilek|--kadrowe`
`.msiatka__kratka--wolny` `.msiatka__kratka--poza` `.msiatka__kratka--dzis` `.msiatka__nazwa`
`.msiatka__dzien-tygodnia` `.msiatka__suma` `.dzien-dzis`
⚠ Ta sama siatka obsługuje **skalę Roku** (dwanaście pudełek) i **Miesiąc „Tylko ja"** — różnicę
robią reguły zawężone do `.mies__kalendarz`, a nie drugi komponent (§4c).

*Kalendarz — oś czasu zespołu i firmy*
`.team-grid` `.team-grid--days-28…31` `.team-grid__head` `.team-grid__head--dzis|--free|--ostatni`
`.team-grid__person` `.team-grid__cell` `.team-grid__cell--free|--ostatnia|--span-N`
`.team-grid__bar` `.team-grid__bar--urlop|--zasilek|--kadrowe|--jeden` `.team-grid__dow`
`.team-grid__numer`

*Kalendarz — Miesiąc „Tylko ja" i wykaz wpisów (od 1.18.31 także kalendarz osoby na `/dane`)*
`.mies` `.mies__kalendarz` `.mies__wpisy` `.mies__naglowek`
`.wpisy` `.wpisy__poz` `.wpisy__kropka` `.wpisy__kropka--urlop|--zasilek|--kadrowe`
`.wpisy__tresc` `.wpisy__rodzaj` `.wpisy__meta`
⚠ Wykaz pokazuje **pięć wpisów i odnośnik „wszystkie (N)"** — przycina serwer, nie wysokość
pudełka (lista ucięta stylem dalej niesie cały HTML dla czytnika ekranu).

*Kalendarz — blok nagłówka (1.18, zlecenie Z8; przyklejenie 1.18.5, Z24/Z25; 1.18.6, Z26)*
`.card--kal-naglowek` (blok nagłówka przyklejony przy przewijaniu strony, z pasami tła w szczelinach)
`.card--kal-siatka` (blok z osią czasu, który oddaje górną krawędź przyklejonemu wierszowi dni)
`.kal-nav` `.kal-nav__gora` `.kal-nav__dol` `.kal-nav__lewa` `.kal-nav__prawa`
`.kal-grupa` `.kal-btn` `.kal-btn--on` `.kal-btn--ikona` `.kal-btn__etykieta`
`.legend` `.legend__item` `.legend__mark` `.legend__mark--urlop|--zasilek|--kadrowe|--free`
⚠ Kwadraty `.kal-btn` to **osobny komponent, nie `.segmented`** — `.segmented` stoi na czterech
innych ekranach (przełącznik roku, zakres uprawnień) i tam zostaje taki, jaki jest.
⚠ Od 1.18.31 ta rodzina obsługuje **dwa ekrany**: `/kalendarz` i `/dane/pracownik/{id}/kalendarz`.
Znacznik nagłówka składają dwa partiale (`naglowek-kalendarza.twig` i `naglowek-kalendarza-osoby.twig`),
ale **reguły są jedne** — drugiego zestawu klas dla drugiego ekranu nie ma i mieć nie będzie.

*Pasek kafli pulpitu (1.18, zlecenie Z15; `--piec` 1.18.7, Z27)*
`.kafle` `.kafel` `.kafel__glowa` `.kafel__etykieta` `.kafel__dana` `.kafel__wartosc`
`.kafel__wartosc--brak` `.kafel__wartosc--odchylenie` `.kafel__reszta` `.kafel__opis`
`.kafle--auto` (pasek pięciu kafli stanu instalacji — liczba kolumn z `auto-fit`, nie z progu)

*Ekran grup (1.18.11, zlecenie Z31)*
`.inicjaly--grupa` `.inicjaly--stos` `.inicjaly--wiecej` `.inicjaly--bez-grupy` `.inicjaly--assign`
`.stos` `.group-list__sklad` `.group-list__nazwiska` `.group-list__czlonkowie`
`.card__znak--cichy` `.bez-grupy__tytuly` `.bez-grupy__opis` `.bez-grupy__siatka`
`.bez-grupy__osoba` `.bez-grupy__imie` `.modal--grupa` `.chip--mala` `.assign__hurt`
`.ikona-akcja` `.ikona-akcja--edycja` `.ikona-akcja--usun` `.ikona-akcja__form`

*Hierarchia — przeciąganie palcem (1.18.17, zlecenie Z37)*
`.hier__cien` `.hier-ciagnie-palcem` (klasa na `body` w trakcie chwytu)
⚠ `.hier__card { touch-action: manipulation }` zdejmuje opóźnienie podwójnego stuknięcia,
ale NIE blokuje przewijania — blokadę na czas chwytu robi `touchmove` w `modules/hierarchia.js`.

*Dziennik zdarzeń (1.18.16, zlecenie Z36)*
`.dz-wybor` (1.18.22, zlecenie Z42 — formularz obu filtrów w nagłówku karty; zastąpił
`.dz-filtry` `.dz-filtr` `.dz-filtr--wybrany`, które **skasowano razem z regułami**)
`.dz-lista` `.dz-dzien` `.dz-wpis`
`.dz-znak` (`--logowanie` `--dodanie` `--usuniecie`) `.dz-tresc` `.dz-zdanie` `.dz-kto`
`.dz-firma` `.dz-czas` `.dz-pusto` `.dz-podsumowanie` `.btn--akcja-nieczynny`
⚠ Treść zdań mieszka w `Support/ZdanieZdarzenia.php`, ikony w `partials/ikona-zdarzenia.twig`
(biała lista, nie wywołanie makra ze zmiennej — §5 CLAUDE.md).

*Integracja (1.18.15, zlecenie Z35)*
`.kafel__wartosc--stan` `.kafel__wartosc--napis` `.kafel__kropka` (`--ok` `--uwaga` `--blad`)
`.klucz-wiersz` `.klucz-wiersz__znak` `.klucz-wiersz__dane` `.klucz-wiersz__nazwa` `.klucz-wiersz__prefix`
`.zakres-chip` `.btn__znacznik` `.btn--akcja-groznie` `.klucz-akcje` `.table--klucze`
`.notka-info` `.notka-info__znak` `.notka-gotowe` `.notka-gotowe__znak`
`.table--przebiegi` `.przebieg-czas` `.przebieg-czas__godzina` `.przebieg-czas__data` `.przebieg-liczba`
`.modal--zakres` `.zakres__etykieta` `.zakres__zgloszenie` `.zakres__dane` `.zakres__nazwa`
`.zakres__meta` `.zakres__decyzja` `.zakres__odrzuc` `.zakres__firma` `.zakres__notka`
`.zakres__odrzucone` `.zakres__odrzucone-opis` (1.18.19, zlecenie Z39)
`.col--klucz-utworzony` `.col--klucz-uzycie` (1.18.21, zlecenie Z41 — zamiast `.col--klucz-czas`;
od 1.18.24 obie mają tę samą szerokość, ale zostają dwie, bo różnią się długością PODPISU)
`.modal--kreator` `.kreator` `.kreator__stan` `.kreator__teraz` `.kreator__co-dalej`
`.kreator__form` `.kreator__zgloszenie` `.kreator__czekam` `.kroki` `.krok` (`--zrobione` `--biezacy`)
`.krok__kolko` `.krok__nazwa` `.krok__stan`
⚠ Rodzina `.wizard*` przestała być używana (kreator nie ma już formularzy w krokach) — reguły
zostają w pliku do osobnej decyzji o sprzątaniu, zgłoszonej w raporcie.

*Hierarchia (1.18.14, zlecenie Z34)*
`.inicjaly--hier` `.card__znak--uwaga` `.hier__licznik`
⚠ Plakietki karty: „szczyt" zielona z kropką, liczba podwładnych błękitna bez kropki,
„bez konta" szara z kropką. Skrypt `hierarchia.js` tworzy je w tej samej postaci co szablon.
`.card__znak--niebezpieczny` (1.18.12, Z33)
⚠ `.stos` i `.inicjaly--stos` **usunięte w 1.18.12** — nie szukaj ich na ekranie.
⚠ `data-zaznacz` na polu w oknie każe `ui.js` zaznaczyć jego treść po otwarciu.

*Lista osób i kont (1.18.10, zlecenie Z30)*
`.inicjaly` `.inicjaly--lista` `.inicjaly--dostep` `.osoba-wiersz` `.osoba-wiersz__nazwa`
`.pole-szukania` `.pole-szukania__pole` `.card__head--lista` `.card__akcje`
`.table--osoby` `.card__foot--lista` `.chip__kropka` `.chip--dzis` `.btn--akcja-sm`
`.osoba-wiersz--zwolniony` `.inicjaly--wygaszone` (1.18.13, Z32)
⚠ Makro `partials/inicjaly.twig` liczy inicjały z nazwy osoby w szablonie — to formatowanie
danej, którą wiersz i tak wyświetla, a nie druga dana z bazy.

*Okno „Nazwy wyświetlane firm" (1.18.9, zlecenie Z29)*
`.modal--nazwy` `.modal__head--znak` `.nazwy-lista` `.nazwy-wiersz` `.nazwy-wiersz__kadry`
`.nazwy-wiersz__nazwa` `.nazwy-wiersz__meta` `.nazwy-wiersz__pole` `.nazwy-podpowiedz`
⚠ `data-modal-trwale` na `<dialog>` wyłącza czyszczenie `.modal__body` przy zamknięciu —
dla okien z treścią osadzoną w stronie, a nie dociąganą z serwera.

*Ekran ustawień (1.18.7, zlecenie Z27)*
`.card__head--znak` `.card__znak` `.card__tytuly` `.card__opis`
`.ustaw-dwie` `.ustaw-rzad` `.ustaw-rzad__pole`
`.settings-list--akcje` `.settings-list__akcja` `.settings-list__dalej`
`.ustaw-dane` `.ustaw-dana` `.ustaw-dana__etykieta` `.ustaw-dana__wartosc`
`.ustaw-dana__wartosc--brak` `.ustaw-dana__podpis`
`.ustaw-pasek` `.ustaw-pasek__tresc` `.ustaw-trio` `.ustaw-para` `.ustaw-para__etykieta`
`.ustaw-para__wartosc` `.disclosure__tytul` `.disclosure__meta` `.btn--akcja`

*Wybór zakresu i kontekstu*
`.menu` `.menu--wybor` `.menu__trigger` `.menu__trigger--osoby` `.menu__body` `.menu__item`
`.menu__powod` `.wybor-osob` `.wybor-osob__etykieta` `.wybor-osob__stan`
`.pickbox` + `__head` `__szukaj` `__list` `__row` `__item` `__name` `__tag` `__licznik` `__foot`
`__akcja` `__note` `__form` `__box`, warianty `--js` `--nad` `--bez-masowych`
`.spolka` `.spolka--wybor` `.spolka--staly` `.spolka__trigger` `.spolka__nazwa` `.spolka__panel`
`.spolka__grupa` `.spolka__pozycja` `.spolka__pozycja--on` `.spolka__stopka` `.spolka__uwaga`
`.spolka__strzalka`

*Kontrolki*
`.btn` `.btn--primary` `.btn--ghost` `.btn--quiet` `.btn--danger` `.btn--sm` `.btn--block`
`.btn--google` `.btn--apple` `.btn-row`
`.field` `.field__label` `.field__hint` `.field--error` `.field--check`
`.input` `.input--code` `.input--area` `.input-group` `.input-group__action` `.select`
`.form-column` `.form-grid` `.form-link` `.inline-form`

*Stan i oznaczenia*
`.chip` `.chip--warn` `.chip--danger` `.notice` `.notice--warn` `.notice--danger` `.notice--dev`
`.status` `.status__dot` `.dot--ok` `.dot--warn` `.dot--err`
`.progress` `.progress--warning` `.progress-line` `.stepper` `.step-dot` `.step-dot--active`
`.step-dot--done` `.eyebrow` `.company-mark` `.event-id`

*Zestawienia*
`.stats` `.stats__item` `.stats__key` `.stats__value` `.defs` `.defs__term` `.defs__value`
`.segmented` `.segmented__item` `.segmented__item--on`

*Okna i przebiegi*
`.modal` + `__head` `__body` `__foot` `__close`
`.confirm` + `__title` `__lead` `__list` `__actions`
`.checklist` + `__item` `__label`
`.secret` + `__row` `__value` `__done`
`.wizard` + `__form` `__field` `__state` `__step` `__mark` `__name` `__hint` `__next`
`.qr-card` `.backup-grid` `.backup-code`

*Pozostałe*
`.ico` `.ico--sm` `.mono` `.htmx-indicator` `.htmx-request` `.spinner`

⚠ **`.input--sm` NIE ISTNIEJE i nie powstanie.** Był w tym spisie przez pomyłkę — spis kłamał.
Trzeci rozmiar kontrolki otwiera natychmiast pytanie, co z nim przy wskaźniku zgrubnym, gdzie
`.input` rośnie do 44 px (§11.3). **Węższe pole w konkretnym miejscu robimy regułą kontekstową**
(`.pick__adres .input { width: 15rem }`), wzorem `.toolbar .field__label` — kontekst zmienia
szerokość, a nie wysokość, więc nie kłóci się z fundamentem dotykowym.

⚠ **Kolor ma dokładnie dwa z nich**: `.progress--warning` (bursztyn — stan wymagający uwagi)
i `.field--error` (czerwień **na obrysie**, bo tło zrobiłoby z pola komunikat). Wszystko inne
jest bezbarwne, łącznie z krokiem zrobionym w kreatorze i wypełnieniem paska postępu —
**postęp jest daną, nie akcją** (§2).

⚠ `.mono` nie oznacza innego kroju — oznacza `font-variant-numeric: tabular-nums` i lekki
`letter-spacing` dla identyfikatorów i godzin. Drugiego kroju nie wprowadzamy.

---

## 4a. Kontrolki — wysokość jest ustawiana, nie dziedziczona

⚠ **`.input`, `.select` i `.btn` mają `height: var(--control-h)` wpisaną wprost.** Bez tego
w pasku ustawionym na flex z domyślnym `align-items: stretch` rozciągają się do wysokości
najwyższego sąsiada. Zmierzone na pierwszym wdrożeniu: pole tekstowe 38 px (poprawnie, bo siedzi
w opakowaniu z etykietą), a stojące obok `select` **56 px** i przycisk **52 px** — bo to opakowanie
z etykietą wyznaczyło wysokość wiersza.

- Pasek filtrów i każdy rząd kontrolek: `align-items: flex-end` (etykiety mają różną wysokość,
  a kontrolki mają stać równo dołem).
- `.btn--sm` i `.input--sm`: `--control-h-sm`.
- **Pole wyszukiwania to `input type="text"`, nie `type="search"`.** Przeglądarki dokładają
  polom wyszukiwania własne wykończenie i krzyżyk czyszczący, więc ten jeden element wyłamuje się
  z paska niezależnie od naszego CSS.
- ⚠ **`.btn--google` i `.btn--apple` są wariantami systemu, nie reliktami.** Zmierzone na
  `/logowanie`: oba mają dziś **przezroczyste tło i przezroczysty obrys**, czyli są niewidoczne —
  ich reguły zostały w starym `base.css`, a nasz `.btn` ładuje się później i przy tej samej wadze
  selektora wygrywa. Oba wchodzą do `30-components.css`: obrys `--c-line-strong`, tło `--c-surface`,
  tekst `--c-text-2`, wysokość `--control-h`, logo dostawcy jako `<svg>` w makrze Twiga.
  Kolorów marek nie odtwarzamy — przycisk ma być nasz, z ich znakiem.
- ⚠ **Pole z kontrolką w środku to `.input-group`, nie dwa elementy obok siebie.**
  Zmierzone na `/logowanie`: pole loginu leżało w `.field` i zajmowało całą szerokość, a pole hasła
  w `.input-row` (flex, `gap: 8px`) **dzieliło tę szerokość z przyciskiem „Pokaż"** — więc dwa pola
  tego samego formularza miały różną szerokość i nie ustawiały się w pionie.

  ```
  .input-group { position: relative; }
  .input-group .input { width: 100%; padding-right: calc(var(--s-2) + 34px); }
  .input-group__action { position: absolute; right: var(--s-1); top: 50%; transform: translateY(-50%);
                         width: 30px; height: 30px; }
  ```

  - kontrolka w środku jest **przyciskiem z samą ikoną**, `aria-label` i `aria-pressed`,
    ⚠ **nigdy przyciskiem z etykietą tekstową, która się zmienia** („Pokaż" ↔ „Ukryj"):
    zmiana szerokości napisu przesuwałaby wcięcie pola przy każdym kliknięciu,
  - `type="button"` — przycisk w formularzu bez tego atrybutu **wysyła formularz**,
  - ⚠ `input::-ms-reveal { display: none }` — inaczej Edge dokłada **drugi** znak oka obok naszego,
  - odstęp od prawej krawędzi zostawiamy niewielki, ale nie zerowy: menedżery haseł wstawiają
    w tym miejscu własną ikonę i dwie rzeczy nie mogą stać na sobie.

  Komponent jest ogólny — ta sama konstrukcja obsłuży pole wyszukiwania z ikoną i pole z jednostką.
- Nazwy wariantów: `.btn--primary`, `.btn--ghost`, `.btn--quiet`, `.btn--danger`, `.btn--sm`.
  ⚠ `.btn--secondary` i `.btn--small` **nie istnieją** — element z taką klasą zostaje bez stylu.

## 4b. Pasek komunikatu ma jedną nazwę

⚠ Zmierzone przy migracji: pasek komunikatu istniał w **czterech** odmianach —
`.notice`, `.box`, `.error-message` i `.error--fragment` w odpowiedziach htmx — o różnym
wyglądzie i różnych modyfikatorach. Obowiązuje **jedna**: `.notice`, z wariantami
`--warn` i `--danger`.

- ⚠ **Od 1.18.23 (Z47) komunikat o WYNIKU OPERACJI nie jest już paskiem, tylko toastem**
  (`.toasty` / `.toast`, opis przy zmianie 1.18.23). `.notice` zostaje dla dwóch rzeczy i tylko
  dla nich: treści ekranu, która ma zostać (ostrzeżenia o stanie, objaśnienia), i dla ścieżki
  **bez JavaScriptu**, gdzie komunikat serwera zostaje w miejscu, w którym go wyrenderowano.
- ⚠ **Zielonego wariantu PASKA nie ma.** „Sesja wygasła", „zapisano", „gotowe" — to stany obojętne
  albo odstępstwa, nie akcje. Zieleń znaczy akcję (§2), a pasek komunikatu nie jest przyciskiem.
  ⚠ Jedyny wyjątek jest nazwany: **kółko 24 px w toaście**, gdzie zieleń z „✓" mówi, że operacja
  się udała. Tło belki zostaje białe — zielone tło robiłoby z komunikatu przycisk.
- ⚠ **Nazwy klas nie sklejamy w JavaScripcie z fragmentów** (`'flash ' + kind`). Zmierzone:
  `modules/flash.js` robił dokładnie to, więc przemianowanie komponentu wyłączyłoby styl
  **po cichu** — nie widać tego ani na zrzucie, ani w wyszukiwaniu po nazwie klasy.
- ⚠ **`.note` nie istnieje.** Zmierzone: pasek komunikatu był w arkuszu w **dwóch** wersjach —
  `.note` (pełne obramowanie 1 px, promień karty, tło `--c-surface`, **10+ użyć**) i `.notice`
  (lewa krawędź 3 px, tło `--c-neutral-soft`). Osobno oba wyglądają sensownie i właśnie dlatego
  nikt tego nie zauważył. **Zostaje `.notice`**, bo nie udaje karty i nie konkuruje z `.card`
  stojącą w tym samym miejscu; `.note` znika z szablonów.
- ⚠ **Modyfikatora nie budujemy z danych** (`chip--{{ status }}`). Stan mapuje na modyfikator
  **białą listą w jednym makrze**; inaczej pierwszy nowy status wytworzy klasę, której system
  nie ma, i nikt tego nie zauważy.
- ⚠ **Trzecie wystąpienie, 22.09.2026: `hidden`, który nie ukrywał.** `[hidden]` z arkusza
  przeglądarki ma najniższą możliwą wagę, więc `.notice { display: block }` je biło, i pusty
  `DIV#flash[hidden]` zajmował **1128 × 24 px na 27 ekranach**. Ponieważ stał **pod** `.app`,
  kontener siatki był o 24 px niższy niż dokument, a pasek boczny `position: sticky` odklejał się
  na ostatnich 24 px przewijania. Zgłoszenie brzmiało **„menu ucieka na dole strony"** — przyczyna
  siedziała 1000 px niżej, w elemencie, którego nie widać. Poprawka: `[hidden] { display: none
  !important }` w `10-base.css` (jedyne uprawnione `!important` w projekcie) i pasek przeniesiony
  **do środka układu**. Skanem znalazł się jeszcze jeden taki element: ukryty przycisk „Wyczyść"
  w pasku zaznaczania, 78 × 32 px (`display: flex` z `.btn`).

  ⚠ **Ten element ma historię i to jest powód, żeby ją tu czytać przed następną zmianą:**
  najpierw dwie nazwy (`.note` i `.notice`), potem `display: flex` rozbijający zdanie na trzy
  kolumny, teraz `hidden`, który nie ukrywa. Trzy razy ten sam mechanizm: **pasek komunikatu
  jest niewidoczny, gdy jest pusty, więc jego usterek nie widać, dopóki nie zobaczy się skutku
  gdzie indziej.** Pilnują tego dwie bramki: „żaden element z `hidden` nie ma pudełka"
  i „wysokość kontenera siatki równa się wysokości dokumentu".

## 4c. Jedna siatka miesiąca — decyzja zapadła (1.18, 17.10.2026)

Do 1.17 w arkuszu były **dwie** siatki miesiąca (`.calendar-grid` na `/kalendarz` i `.month-grid`
na `/dane/kalendarze`), które robiły to samo, i dokument zostawiał pytanie, która zostaje.
**Zostaje jedna i nie jest żadną z tamtych.**

⚠ **Domknięte dopiero w 1.18.31 (19.10.2026).** Ta tabela od 17.10 pisała `.month-grid`
w kolumnie „stan 1.17", jakby tej klasy już nie było — a była: `/dane/pracownik/{id}/kalendarz`
rysował nią miesiąc jeszcze przez dwa dni. Przebudowa tego ekranu (zlecenie Z51) skasowała
reguły `.month-grid*` z arkusza, więc dopiero teraz kolumna „stan 1.18" jest prawdziwa.
Zapisuję to, bo różnica między „rozstrzygnęliśmy" a „wykonaliśmy" jest dokładnie tym miejscem,
w którym dokument przestaje opisywać kod.

| | stan 1.17 | stan 1.18 |
|---|---|---|
| siatka miesiąca | `.calendar-grid` i `.month-grid` | **`.msiatka`** — jedna, w `<div>` + CSS grid |
| oś czasu zespołu i firmy | — | **`.team-grid`** — osobny komponent, bo pokazuje OSOBY w wierszach, nie dni w kratkach |
| Miesiąc „Tylko ja" | plakietki w kratkach | **`.mies`**: `.msiatka` po lewej, wykaz `.wpisy` po prawej |

⚠ **Dwa komponenty zamiast jednego są tu ROZSTRZYGNIĘCIEM, nie nawrotem problemu.** `.msiatka`
i `.team-grid` **nie robią tego samego**: pierwsza rysuje jeden miesiąc jednej osoby (kratka = dzień),
druga rysuje wiele osób w czasie (wiersz = osoba, kolumna = dzień). Dwie siatki z 1.17 różniły się
wyłącznie implementacją — te różnią się przeznaczeniem, a to jest granica z §4b.

⚠ **Jak wyróżniamy dzień z nieobecnością** — druga odłożona decyzja — też zapadła: kratkę niesie
**wypełnienie w kolorze rodzaju** (te same tokeny `--c-pasek-*-tlo`, co pasek osi czasu i próbka
legendy), a nie krawędź. Skanuje się wzrokiem i jest jedną rzeczą w trzech miejscach.

⚠ **Znacznik „dziś" to obwódka całej kratki** (`msiatka__kratka--dzis`), nie plakietka na numerze:
plakietka przy numerze dwucyfrowym wychodziła poza kratkę i myliła się z paskiem rodzaju.

⚠ Siatka Miesiąca ma **sześć wierszy zawsze**, także gdy miesiąc mieści się w pięciu — inaczej
wysokość karty skakała przy przewijaniu miesięcy, a obok stoi wykaz wpisów o stałej wysokości.

## 4d. ⚠ Jedna nazwa komponentu nagłówka strony

`20-layout.css` ma dziś **dwa** komplety nazw na to samo: `.page__head` / `.page__title` /
`.page__sub` **oraz** `.page-header` / `.page-title`. Część ekranów używa jednego, część drugiego.

⚠ **To jest trzecie wystąpienie tej samej klasy błędu** — po dwóch paskach komunikatu (§4b)
i dwóch siatkach miesiąca (§4c). Za każdym razem mechanizm był ten sam: **nazwa, której nie ma
w spisie, zostaje wymyślona drugi raz.**

**Zostaje `.page__head`** — zgodna z konwencją `blok__element` z §4. `.page-header`
i `.page-title` znikają z szablonów i z arkusza.

⚠ **Jeden tytuł na ekran.** Karta dostaje własny nagłówek wyłącznie wtedy, gdy ekran ma
**więcej niż jedną** kartę i trzeba je od siebie odróżnić. Ekran z jedną kartą ma tytuł
w `.page__head` i nic więcej — drugi nagłówek powtarza to samo i konkuruje z pierwszym.
Zmierzone 22.09.2026: `/admin/konta` miało `H1 „Osoby i konta"` (30 px) i pod nim
`H2 „Lista osób"` (20 px), a `/ustawienia/spolki` — `H1 „Spółki"` i `H2 „Nazwy wyświetlane"`,
przy czym podtytuł strony mówił o nazwach wyświetlanych to samo. Oba `H2` usunięte.

⚠ **`/wnioski` — droga (b), 22.09.2026:** `H2 „Moje wnioski"` znika, a przycisk „+ Nowy" zostaje
w nagłówku karty, wyrównany do prawej (`.card__head--akcja`), **w obu wariantach**. Powód:
akcja główna należy do `.page__actions`, ale wariant mobilny go nie ma, a rozjechanie wspólnego
szablonu na web i mobilny kosztowałoby więcej niż ten zysk. ⚠ Gdy wariant mobilny dostanie
`.page__actions`, wracamy do tego świadomie — decyzja ma datę właśnie po to.

⚠ **Wykonane 22.09.2026**: 19 szablonów przepisanych, reguły `.page-header` / `.page-title`
usunięte z `20-layout.css`. Dowód: `bin/inwentarz-klas.php` pokazuje **zero wystąpień** obu nazw,
a `grep` po `public/assets`, `templates`, `src` i `config` — również zero. ⚠ Koszt zwłoki jest
wart zapisania: decyzja stała niewykonana jeden dzień i **użytkownik sam zgłosił**, że dwa ekrany
mają inny nagłówek. Nazwa w spisie bez wykonania nie jest jeszcze jedną nazwą — jest dwiema.

## 5. Ekrany — co się zmienia

Wspólny szkielet, którego trzyma się **każdy** ekran:

```
ścieżka (topbar)
Nagłówek 30 px + jedno zdanie kontekstu        [akcja główna po prawej]
──────────────────────────────────────────────
pasek narzędzi / filtrów   (jeśli jest — POZA kartą)
karta / karty z treścią
```

Dziś ekran firmy trzyma filtry wewnątrz karty z tabelą, a ekran integracji trzyma przycisk akcji
w środku karty ze statusem — te same rzeczy leżą za każdym razem gdzie indziej.

### 5.1 `/dane/firma/{id}` — lista pracowników firmy

- `ALFA` (44 px, wersaliki) → nazwa firmy w `--t-h1`, bez wersalików.
- Podtytuł: `NIP 525 252 50 06 · 36 zatrudnień · dane tylko do odczytu, źródło: R2 Płatnik`.
  **NIP formatowany grupami**, przy nazwie firmy, nie osobno.
- `Wszystkie firmy` → `.btn--ghost` w `.page__actions`.
- Filtry w `.toolbar` **nad kartą**, z etykietami `.field__label`; po prawej stronie paska
  licznik wyniku (`Wynik: 36 z 36`).
- Tabela: `Pracownik · Nr kadrowy · Etat · Zatrudniony od · Rozkład czasu pracy · Nieobecności {rok} · Stan`.
  Kolumna `Stan` **pusta dla stanu normalnego**.
- Stopka karty: `Pokazano 1–8 z 36` + stronicowanie `.btn--ghost .btn--sm`.

### 5.2 `/dane/pracownik/{id}`

- Nagłówek: nazwisko; podtytuł `Budomex · nr kadrowy 413 · zatrudniony od 2006-05-05`.
  ⚠ **`hmac:…` znika z podtytułu** — dziś wygląda jak awaria.
- Pasek `.stats` — cztery pozycje w jednej karcie, rozdzielone cienką linią, nie cztery osobne karty:
  **Wymiar etatu** (`0,75 (3/4 etatu)`), **Norma dobowa** (`6 h 00 min`),
  **Rozkład czasu pracy**, **Nieobecności w {rok}** (`13 dni`).
- Karta **Zatrudnienie** — siatka `.defs`, 4 kolumny: firma, numer kadrowy, rodzaj umowy, stanowisko,
  zatrudniony od, zatrudniony do, norma urlopowa, ostatnia zmiana.
- Karta **Nieobecności** — przełącznik `.segmented` (`{rok} / {rok-1} / wszystkie`),
  tabela `Rodzaj · Od · Do · Dni robocze · Źródło`, wiersze grupujące po roku,
  stopka `6 nieobecności · 30 dni roboczych`.
  Źródło: `R2 Płatnik` albo `.chip` „wniosek w wezurlop" — **to jest informacja, której dziś brakuje,
  a jest istotna: pokazuje, co wjechało z kadr, a co wyszło od nas.**
- Na dole `.disclosure` „Szczegóły techniczne" (identyfikator R2, odcisk tożsamości, historia
  synchronizacji) — **domyślnie zwinięte**.

### 5.3 `/admin/integracja`

- Zielony pasek „Podłączone — ostatnia synchronizacja…" **przestaje być komunikatem o sukcesie
  i staje się stanem**: kropka + `Połączone` w `--t-h2`, pod spodem czas **względny**
  („3 minuty temu") i dokładny w drugiej linii. Obok, za pionową linią, cztery liczby
  (`Pracownicy · Nieobecności · Przebiegi dziś · Zakończone błędem`) i wersje connectora i R2.
- `Otwórz kreator podłączenia` → `Kreator podłączenia`, `.btn--primary` w `.page__actions`.
- Karta **Klucze instalacji**: `Nazwa · Zakres (NIP) · Utworzony · Ostatnie użycie · Stan · Działanie`.
  W stopce `Wydaj nowy klucz` i ⚠ **poprawiona treść reguły nakładki** — dziś ekran mówi
  „stary działa 30 dni", a obowiązuje:
  > Po wydaniu nowego klucza stary działa do chwili, w której nowy po raz pierwszy zakończy
  > przebieg — wtedy zostaje unieważniony automatycznie. Nie ma żadnego terminu: dopóki nowy klucz
  > nie zadziała, synchronizacja idzie na starym. Kursory i rekordy należą do firmy, nie do klucza —
  > wymiana nie powoduje ponownego ładowania danych.
- Karta **Zgłoszenia firm**: zgłoszenie oczekujące jako wiersz z bursztynowym obrysem,
  NIP grupami, kto zgłosił, kiedy, ile pracowników po stronie R2, i **dwa przyciski obok siebie**
  (`Odrzuć` ghost, `Zatwierdź` primary). Zgłoszenia rozstrzygnięte niżej, wyciszone.
- Karta **Ostatnie przebiegi**: `Rozpoczęty · Czas · Rekordy · Konflikty · Wynik`,
  link `Pełny dziennik zdarzeń` w nagłówku karty.
- ⚠ **`Zapisz zakres` przestaje być przyciskiem na całą szerokość.** Przycisk ma szerokość treści.

### 5.4 Pozostałe ekrany — w drugim rzucie

`/`, `/kalendarz`, `/diagnostyka`, `/konta`, logowanie. Reguły te same; szczegóły w osobnym
zleceniu po odbiorze trzech ekranów powyżej. Dwie rzeczy już wiadome:

- **Kalendarz**: komórki dnia są dziś wysokie i puste; wysokość ma wynikać z treści,
  a plakietka „bez danych" znika — brak danych to brak treści, nie etykieta.
- **Diagnostyka**: podsumowanie OK/ostrzeżenie/błąd jest dziś drobne i szare; to najważniejsza
  informacja na ekranie i ma być pierwszym blokiem, w rolach kolorów z §3.1.
- **Logowanie**: bez żargonu projektowego, ze stopką z numerem wersji.

---

### 5.5 Kreator podłączenia — okno modalne

⚠ **Wersja 1.0 tego dokumentu nie opisywała okien modalnych w ogóle** — opisywała strony.
Dlatego kreator został na starym wyglądzie i to on trzyma przy życiu stare arkusze CSS.
Zmierzone w działającej aplikacji:

| Co | Stan | Ma być |
|---|---|---|
| tło kroku „zrobione" | `rgb(235,249,241)`, obrys `rgb(215,239,226)` | brak tła; znacznik zrobienia, nie kolorowe pudełko |
| znacznik kroku | trzecia zieleń: `rgb(234,248,240)` / `rgb(22,132,80)` | `--c-accent` |
| promień | **16 px** i **999 px** | `--radius-card` (10 px) i `--radius` (8 px) |
| cień okna | **brak** | `--shadow-pop` |
| wysokość okna | 735 px z własnym paskiem przewijania przy oknie 855 px | `max-height: calc(100vh - 2 * var(--s-7))`, wyśrodkowane |
| przyciski w środku | `.btn--secondary`, `.btn--small` — klasy nieistniejące | `.btn--ghost`, `.btn--sm` |
| „Zamknij" | `.btn--quiet` rozciągnięty do 52 px | przycisk z ikoną × i `aria-label`, `--control-h-sm` |
| „Otwórz kreator jako osobną stronę" | `<a>` bez klasy | zwykły link w stopce okna |
| lista danych połączenia | `<dl>` bez klas systemu | `.defs` |

**Zasada, która z tego wynika i obowiązuje dalej:** kolorem oznaczamy **to, co wymaga uwagi**.
Cztery kroki z czterema zielonymi prostokątami to informacja „wszystko w porządku" pokazana
najbardziej krzykliwym środkiem, jaki mamy. Krok zrobiony ma być **cichy** — szara linia,
zielony znacznik, tytuł. Kolor rezerwujemy dla kroku, który czeka albo się nie udał.

**Komponent `.modal`** (do `30-components.css`): `<dialog>` otwierany przez `showModal()`,
`.modal__head` (tytuł + zamknięcie), `.modal__body` (jedyny obszar z `overflow-y: auto`),
`.modal__foot`. Blokada przewijania tła na czas otwarcia. `aria-labelledby` wskazujące tytuł.
Zamknięcie klawiszem Esc działa z `<dialog>` samo — nie dokładamy do tego JS.

### 5.6 Operacje nieodwracalne i sekrety — czego dokument nie opisywał

⚠ Wersje 1.0 i 1.1 opisywały, **jak coś wygląda**, i nie opisywały, **co się dzieje, gdy ktoś
to kliknie**. Zmierzone w działającej aplikacji: przyciski `Wymień` i `Unieważnij` przy kluczu
to formularze wysyłające `POST` prosto pod `/admin/integracja/klucze/{id}/wymien`
i `/uniewaznij` — **bez żadnego potwierdzenia**. Jedno kliknięcie zatrzymuje synchronizację kadr.

**Zasada: operacja, której nie da się cofnąć, ma osobny krok potwierdzenia.**

Dotyczy tego ekranu: **unieważnienie klucza, wymiana klucza, odrzucenie zgłoszenia firmy,
akceptacja zmienionego odcisku tożsamości**. Wzorzec obowiązuje też wszędzie, gdzie takie operacje
pojawią się później (kasowanie konta, wylogowanie wszystkich sesji, reset 2FA).

Wymagania:

- potwierdzenie to **osobny krok po stronie serwera**, nie `confirm()` w JS ani atrybut `hx-confirm`.
  Powód jest prosty: potwierdzenie ma **pokazać konsekwencje**, a `confirm()` pokazuje jedno zdanie
  bez formatowania i znika przy wyłączonym JS,
- ⚠ **`GET` pokazuje pytanie, `POST` wykonuje** (§3). Żądanie `GET` nigdy niczego nie zmienia,
- treść potwierdzenia mówi **co dokładnie zniknie i co przestanie działać**, po ludzku:
  której firmy dotyczy klucz, kiedy był ostatnio używany, że synchronizacja zatrzyma się
  natychmiast i że trzeba będzie wpisać nowy klucz w connectorze. Nie „Czy na pewno?",
- przycisk potwierdzający jest `.btn--danger` i **nie jest domyślnie ustawionym ogniskiem**;
  obok niego `Anuluj` jako `.btn--ghost`,
- po wykonaniu — **komunikat wyniku** (`.note`) mówiący, co się stało, i wpis w dzienniku zdarzeń.

### 5.7 Pokazanie sekretu — jeden raz, z kopiowaniem

Nowy klucz instalacji widać **jeden raz** (decyzja z 19.09: odczyt jednorazowy, TTL 5 minut).
Skoro tak, to ekran musi dać go przenieść bez przepisywania z palca.

- klucz w polu **tylko do odczytu**, zaznaczalnym, obok przycisk **„Kopiuj"**,
- kopiowanie przez `navigator.clipboard.writeText` w **pliku JS**, podpięte przez
  `addEventListener` (§6 — zero JS w szablonie, CSP bez `unsafe-inline` zostaje nietknięte),
- ⚠ **zapasowa ścieżka**: gdy `navigator.clipboard` nie istnieje albo odmówi, przycisk **zaznacza
  treść pola** i podpowiada skrót — inaczej na części przeglądarek przycisk po prostu nic nie robi,
- potwierdzenie kopiowania **zmianą klasy** (`Skopiowano`), nigdy `alert()`,
- ⚠ **klucz nigdy nie trafia do adresu URL** (§13: adresy lądują w logach serwera i proxy),
  do tytułu strony ani do dziennika zdarzeń,
- obok pola zdanie wprost: **„Zobaczysz go tylko teraz"**, a pod nim, co z nim zrobić
  (wkleić w konfiguracji connectora),
- ekran po zamknięciu tego komunikatu **nie pokazuje klucza ponownie** — także po odświeżeniu.

### 5.8 Zmiana widoku bez przeładowania strony

Zmierzone: przełącznik lat w nieobecnościach to zwykłe odnośniki `?rok=2025`, więc każde kliknięcie
**przeładowuje całą stronę** i przerzuca widok na górę. Przy okazji: **htmx jest wczytany na każdej
stronie, a używa go zero elementów**.

- przełączniki widoku (lata w nieobecnościach, filtry, stronicowanie) wymieniają **fragment**
  przez `hx-get` + `hx-target`, nie przeładowują dokumentu,
- ⚠ **odnośnik zostaje odnośnikiem.** `href` z parametrem jest ścieżką zapasową dla wyłączonego JS
  i dla otwarcia w nowej karcie; htmx tylko przechwytuje kliknięcie. `hx-push-url` utrzymuje adres,
  więc odświeżenie strony pokazuje ten sam widok,
- fragment mieszka w `/templates/fragments`, jeden plik = jeden fragment, renderowany bez layoutu,
  a jego endpoint sprawdza zalogowanie, uprawnienie i własność zasobu **tak samo jak zwykła akcja**
  (§5 `CLAUDE.md` — nie ma czegoś takiego jak „endpoint wewnętrzny, bo używa go tylko htmx"),
- `htmx:responseError` musi pokazywać komunikat; bez tego kliknięcie po prostu nic nie robi,
- ⚠ jeśli po tej zmianie htmx nadal nie ma zastosowania na danym ekranie — **nie ładujemy go tam**.
  Biblioteka wczytywana na każdej stronie i nieużywana to koszt bez pożytku.

### 5.9 Ekran logowania — pola zostały na starym systemie

Zmierzone na `/logowanie` (ekran drugiego rzutu, ale widoczny przy każdym wejściu):

| Element | Stan | Ma być |
|---|---|---|
| pola logowania | klasa `.control`, wysokość **56 px**, promień **16 px**, obrys `rgb(227,235,245)`, tło `rgb(249,251,255)` | `.input`, `--control-h`, `--radius`, `--c-line-strong`, `--c-surface` |
| podświetlenie ogniska | `.control:focus` → **niebieska poświata** `rgba(47,124,246,.08) 0 0 0 4px` ze starego `base.css` | zielony obrys 2 px z `10-base.css`, jak wszędzie |
| reakcja na kliknięcie myszą | `:focus` — niebieska poświata także po kliknięciu | `:focus-visible` |
| przycisk „Zaloguj" | **już przepisany** (`.btn--primary`, 38 px, zielony) | bez zmian |
| pole hasła kontra pole loginu | hasło w `.input-row` dzieli szerokość z przyciskiem „Pokaż" — **węższe od loginu** | oba pola tej samej szerokości; pokazanie hasła jako `.input-group__action` **w środku** pola |

⚠ **Sprostowanie do wersji 1.2:** wymaganie „obrys nie pojawia się po kliknięciu myszą" było
niewykonalne. **Pole tekstowe zawsze spełnia `:focus-visible`**, także przy ognisku z myszy —
tak działają Chrome i Firefox zgodnie ze specyfikacją, bo pole oczekujące wpisywania zawsze
potrzebuje widocznego ogniska. Obowiązuje: pole ma **zielony obrys 2 px i zero `box-shadow`**;
zniknięcie niebieskiej poświaty jest całą oczekiwaną zmianą.

⚠ Stąd wrażenie, które zgłosił Krzysztof: na jednym ekranie stoją dwa różne języki wizualne —
zielony przycisk w nowym stylu pod dwoma niebieskimi polami w starym. Ten ekran wchodzi wyjątkowo
przed drugim rzutem, bo widzi go **każdy użytkownik przy każdym wejściu**.

## 6. Kolejność prac (wiążąca)

1. `00-tokens.css` + `10-base.css` + krój pisma w paczce. **Nic więcej w tym kroku.**
2. `20-layout.css` — powłoka: sidebar z trzema sekcjami, topbar ze ścieżką, `.page`.
   Po tym kroku wszystkie ekrany wyglądają spójnie, choć treść jeszcze stara.
3. `30-components.css` — karta, tabela, przyciski, pola, plakietki, `.stats`, `.defs`, `.segmented`.
4. Przepisanie szablonów trzech ekranów z §5 na te klasy.
5. Usunięcie martwego CSS-u. ⚠ Nie po drodze — dopiero tu, żeby nie zgubić czegoś,
   co jeszcze jest w użyciu.

---

## 7. Pytania otwarte (rozstrzyga Krzysztof)

1. ~~Forma wyświetlania nazwisk.~~ **ROZSTRZYGNIĘTE 20.09** — zamieniamy, zasady w §9.
   Kolejność: `Kowalski Jan` w tabelach (sortowanie po nazwisku), `Jan Kowalski` w nagłówku karty
   pracownika i w wiadomościach e-mail.
2. Kolor interfejsu: zostajemy przy zieleni z logo jako akcencie (przyjęte), czy chcemy zobaczyć
   wariant z grafitem w interfejsie i zielenią wyłącznie w logo.
3. Czy `Nieobecności {rok}` w tabeli firmy liczymy w dniach roboczych, czy w dniach kalendarzowych —
   makieta pokazuje robocze.

---

## 8. Szerokość treści — co z tego wynika w liczbach

| Okno | Dostępne obok paska bocznego | Treść w wersji 1.0 | Treść w wersji 1.1 |
|---|---|---|---|
| 1440 px | 1177 px | 1177 px | 1177 px (bez zmian) |
| 1920 px | 1657 px | **1216 px** | **1560 px** |
| 2560 px | 2297 px | 1216 px | 1560 px |

Limit nadal działa tam, gdzie miał działać — na monitorach 2560+ tabela się nie rozlewa.
Przestał działać tam, gdzie szkodził.

---

## 9. Imię i nazwisko — jedna funkcja, jedno miejsce

R2 przysyła `imie` i `nazwisko` **rozdzielone**, ale zwykle wersalikami (`KOWALSKI`, `JAN`).

**`src/Support/NazwaOsoby.php`** — strefa jawna (§15), funkcja czysta, bez dostępu do bazy:

```php
public static function doWyswietlenia(string $wartosc): string
```

Zasady, w tej kolejności:

1. ⚠ **Jeśli wartość zawiera choć jedną małą literę — zwracamy ją bez zmian.** Reguła działa
   wyłącznie tam, gdzie zapis wersalikami zabrał informację o wielkości liter. Klient, który wpisał
   `von Neumann` poprawnie, nie może tego stracić.
2. Całość na małe litery (`mb_strtolower`, UTF-8 — ⚠ `strtolower` i `ucfirst` psują `Ł`, `Ó`, `Ą`).
3. Podział na człony po **spacji, myślniku i apostrofie** (`'` oraz `’`), z zachowaniem separatorów.
   Każdy człon pierwszą literą wielką. To załatwia `ANNA MARIA`, `NOWAK-JEZIORAŃSKA`, `O'BRIEN`.
4. **Przedrostki zawsze małą literą, chyba że są jedynym członem**: `de`, `del`, `della`, `di`,
   `du`, `da`, `das`, `dos`, `van`, `von`, `der`, `den`, `ten`, `ter`, `la`, `le`, `af`, `av`.
   Daje `de Vries`, `van der Berg`, a samo `DE` → `De`.
   ⚠ Wersja 1.1 mówiła „gdy nie są pierwszym członem", co było **sprzeczne z własnym przypadkiem
   testowym** `DE VRIES` → `de Vries`, gdzie `de` jest pierwsze. Obowiązuje brzmienie powyżej.
5. **`Mc` — tak. `Mac` — NIE.** `MCDONALD` → `McDonald` jest bezpieczne, bo w polskich nazwiskach
   człon zaczynający się od `mc` nie występuje. Reguła dla `mac` zamieniłaby **`MACIEJ` na `MacIej`**
   i jest zakazana.

Zastosowanie:

- funkcja działa na `imie` i `nazwisko` **osobno**, nigdy na sklejonym ciągu,
- ⚠ **formatowanie wyłącznie do wyświetlenia — nigdy zapis do bazy.** Wartość z R2 zostaje nietknięta;
  pomyłka w regule kosztuje poprawkę jednej funkcji, a nie migracji danych,
- ⚠ **sortowanie i wyszukiwanie działają na wartości źródłowej**, nie na sformatowanej — inaczej
  kolejność w tabeli zależałaby od warstwy prezentacji,
- dwie formy podaje warstwa PHP, nie szablon: `nazwiskoImie()` dla tabel i list,
  `imieNazwisko()` dla nagłówka karty, powitania i wiadomości e-mail.

Testy jednostkowe obowiązkowe, minimum te przypadki:
`KOWALSKI`→`Kowalski`, `ANNA MARIA`→`Anna Maria`, `NOWAK-JEZIORAŃSKA`→`Nowak-Jeziorańska`,
`O'BRIEN`→`O'Brien`, `DE VRIES`→`de Vries`, `VAN DER BERG`→`van der Berg`,
`MCDONALD`→`McDonald`, `MACIEJ`→`Maciej`, `ŁUKASZ`→`Łukasz`, `von Neumann`→`von Neumann` (bez zmian),
`Kowalski`→`Kowalski` (bez zmian).


---

## 10. Czas i strefa czasowa

**Zasada z `docs/szkielet-tenanta.md` §5 obowiązuje bez zmian: czas w bazie zawsze w UTC.**
Brakowało drugiej połowy tej zasady — **co robi z tym warstwa widoku.** Zmierzone 20.09.2026:

| Gdzie | Pokazane | Zegar użytkownika w tym momencie |
|---|---|---|
| `/admin/integracja`, stan połączenia | `2026-09-20 17:27:58 UTC` | `20:15` (Europe/Warsaw, UTC+2) |
| `/diagnostyka` | `2026-09-20 14:15:02 UTC` | — |
| karta pracownika, `title` przy „Ostatnia zmiana" | `2026-09-20 14:19:21 UTC` | — |

Kadrowa czytająca „ostatnia synchronizacja 17:27", gdy na jej zegarze jest 20:15, ma prawo
sądzić, że integracja stoi od trzech godzin. Stała w miejscu czterdzieści osiem minut.

### Reguły

1. **Proces PHP pracuje w UTC**: `date_default_timezone_set('UTC')` w bootstrapie, jawnie.
   ⚠ Nie polegamy na `date.timezone` z `php.ini` hostingu — na hostingach współdzielonych bywa
   ustawiona na cokolwiek i zmienia się bez naszej wiedzy.
2. **Strefa prezentacji to ustawienie tenanta** (`tenant_setting.timezone`), domyślnie
   `Europe/Warsaw`, widoczne i edytowalne w Ustawieniach. Instalator podpowiada wartość
   z `date_default_timezone_get()`, jeśli to prawdziwa nazwa strefy, a nie `UTC` z pustego pola.
   ⚠ **Zapisujemy nazwę strefy, nigdy przesunięcia** — przesunięcie zmienia się dwa razy w roku.
3. **Znacznik czasu wyświetla jeden filtr Twiga**, który przelicza UTC → strefa tenanta.
   Żadnego ręcznego formatowania dat w szablonach ani w kontrolerach.
4. ⚠ **Data kalendarzowa to nie znacznik czasu.** Daty z programu kadrowego — początek i koniec
   nieobecności, data zatrudnienia, data zwolnienia — są **datami**, nie momentami.
   Przeliczanie ich przez strefę **przesunęłoby urlop o jeden dzień** i jest zakazane.
   Rozstrzyga typ danych, nie wygląd wartości.
5. **Użytkownik nie ogląda UTC.** Skrót `UTC` może wystąpić wyłącznie w „Szczegółach technicznych",
   w dzienniku zdarzeń i w diagnostyce — zawsze podpisany, nigdy jako jedyna forma.
6. **Czas względny** („3 minuty temu") liczymy z UTC i jest niezależny od strefy — zostaje
   jako pierwsza forma przy stanie połączenia, z czasem bezwzględnym w strefie tenanta obok.
7. **E-maile i eksporty** używają strefy tenanta, z jej nazwą przy dacie — inaczej pracownik
   dostanie powiadomienie z godziną, której nie rozpozna.
8. `/diagnostyka` pokazuje jednym blokiem: **strefa PHP, strefa tenanta, czas serwera w UTC,
   ten sam czas w strefie tenanta.** Rozbieżność zegara serwera ma być widoczna tutaj,
   a nie odkrywana przy reklamacji.

---

## 11. Wariant mobilny — powłoka osobna, komponenty wspólne

⚠ **Stan zastany, zmierzony 20.09.2026 na szerokości 376 px** (sesja zalogowana, `mobile_access = open`).
Wcześniejsza diagnoza w dzienniku decyzji — „wariant mobilny renderuje się bez reguł" — **była błędna**
i wynikała z oglądania jedynego ekranu dostępnego bez sesji. Prawdziwy stan:

- ekrany po zalogowaniu są **w pełni ostylowane starym systemem**: `tokens.css`, `base.css`,
  `components.css`, `layout-mobile.css`,
- ⚠ **klasa istniejąca w obu systemach renderuje się starym wyglądem** (`.card` promień **22**
  zamiast 10, `.btn` waga **800** zamiast 500, pole **56 px** promień **16** zamiast 38/8),
- ⚠ **klasa wprowadzona przy przepisywaniu desktopu renderuje się bez żadnych reguł**
  (`.input`, `.input-group`, `.btn--google` — stąd rozwalony `/m/logowanie`),
- paleta jest **inna, nie brakująca**: tło `#EDF3FA`, pozycje `#0D2A74`, nawigacja `#6F84A1`,
  przycisk `#4A6294` — granatowa rodzina, której nowy system nie zna,
- trzeci zestaw nazw klas: `m-topbar`, `m-content`, `bottom-nav`, `nav-item`, `card--pad`,
  `card__head`, `btn--ghost`, `badge--pending`, `settings-list`.

⚠ **Wniosek wiążący: wpuszczenie `30-components.css` do wariantu mobilnego NIE jest przełącznikiem.**
Oba arkusze definiują te same selektory, więc wpuszczony jako ostatni przewraca cały wygląd mobilny
naraz — razem z dolną belką, która istnieje **wyłącznie** w starym arkuszu. To jest **migracja**
i musi iść w jednym kroku z powłoką opisaną niżej, nigdy przed nią.

### 11.1 Co przejmujemy ze starego systemu, zanim zniknie

⚠ **`env(safe-area-inset-*)` jest dziś używane — w starym `tokens.css`.** Nowy system nie ma go
wcale. Migracja bez przeniesienia tej obsługi **cofnie** telefon z paskiem gestów i wcięciem aparatu
do stanu gorszego niż obecny. To jedyna rzecz, którą stary system robi lepiej, i jedyna,
której nie wolno zgubić.

### 11.2 Powłoka mobilna — `20-layout-mobile.css`

Zasada: **komponenty wspólne, powłoka osobna.** `00-tokens.css`, `10-base.css` i `30-components.css`
są wspólne dla obu wariantów. Różni je **wyłącznie** powłoka: `20-layout.css` dla przeglądarki
na komputerze, `20-layout-mobile.css` dla telefonu. Stary `layout-mobile.css` znika.

Trzy elementy powłoki, wszystkie na tokenach — **ani jednej wartości `#rrggbb` poza `00-tokens.css`**:

| Element | Wartości |
|---|---|
| `.m-topbar` | `position: sticky`, wysokość **56**, tło `--c-surface` (pełne, **nie** półprzezroczyste), dolna linia 1 px `--c-line`, odstęp poziomy `--s-4`, tytuł `--t-h2` / `--w-semibold` / `--c-text` |
| `.m-content` | odstęp poziomy `--s-4`, pionowy `--s-4`, odstęp między kartami `--s-4`, **dół** `calc(56px + var(--s-5) + env(safe-area-inset-bottom, 0px))` |
| `.bottom-nav` | `position: fixed`, tło `--c-surface`, górna linia 1 px `--c-line`, wysokość **56** plus `env(safe-area-inset-bottom, 0px)` jako `padding-bottom`, pięć pozycji równej szerokości |
| `.nav-item` | pole dotykowe **min. 44 × 44**, ikona **20**, etykieta `--t-label` / `--w-medium`, stan spoczynkowy `--c-text-3`, stan aktywny `--c-accent` (⚠ ikona **i** etykieta, bez tła i bez pigułki) |

- ⚠ **Karta na telefonie ma `--radius-card` (10) i padding `--s-4` (16), nie `--s-5` (24).**
  To jedyne odstępstwo komponentu od wariantu komputerowego i jest celowe: 24 px z obu stron
  zjada 13% szerokości ekranu. Zapisujemy je jako nadpisanie w powłoce, nie jako drugi komponent.
- ⚠ **Nagłówek nie może być zdublowany.** Dziś belka górna mówi „Moje wnioski" i nagłówek karty
  mówi „Moje wnioski" — na każdym ekranie. Na telefonie tytuł nosi **belka**, karta nie ma własnego
  nagłówka, chyba że mówi co innego niż belka.
- Ikony w dolnej belce to **ikony z zestawu** (`viewBox 20×20`, jedna grubość), nie znaki
  `⌂ ▦ ＋ ▤ ☺`. Znaki tekstowe renderują się inaczej na każdym systemie i nie dają się ustawić w pionie.

### 11.3 Fundament dotykowy — `10-base.css` i `30-components.css`

⚠ **To nie są poprawki wariantu mobilnego.** Dotyczą laptopa z ekranem dotykowym i tabletu,
więc należą do arkuszy wspólnych i obowiązują w obu wariantach:

1. ⚠ **Pole tekstowe przy wskaźniku zgrubnym ma `font-size: 16px`.** Zmierzone: dziś **14 px**,
   a iOS przy tej wartości **sam przybliża całą stronę** w chwili dotknięcia pola i nie oddala jej
   potem. `@media (pointer: coarse) { .input, .select, textarea { font-size: 16px; height: 44px } }`.
   ⚠ Wariantu komputerowego to nie dotyka — `--control-h` zostaje 38 przy wskaźniku precyzyjnym.
2. **Cel dotykowy minimum 44 × 44** przy wskaźniku zgrubnym: przyciski, pozycje list, ikony w polach,
   odnośniki pomocnicze. Powiększamy **pole dotyku**, nie rysunek.
3. ⚠ **Każda reguła `:hover` idzie w `@media (hover: hover)`.** Zmierzone: **19** reguł `:hover`
   i **0** takich zabezpieczeń. Na telefonie stan najechania **zostaje zapalony po dotknięciu**
   i wygląda jak zaznaczenie, którego nie da się zdjąć.
4. **`dvh` obok `vh`** (6 wystąpień `vh`, 0 `dvh`). `100vh` liczy pasek adresu, więc układ skacze
   przy jego chowaniu.
5. `env(safe-area-inset-*)` — patrz §11.1.

### 11.4 Tabela rekordów przestaje być tabelą, gdy zabraknie jej miejsca

⚠ Zmierzone na `/m/wnioski`: kolumny `116 + 165 + 54 + 142 = 477 px`, sama treść bez odstępów
**317 px**, dostępne **308 px**. Tabela nie mieści się nawet przy zerowym paddingu, a ukryta
jest kolumna **Status** — nikt nie przewija w bok tabeli, o której nie wie, że jest szersza.
Ucinanie najdłuższej kolumny wielokropkiem **odrzucone**: typy urlopu różnią się końcówką,
więc ucięcie zabiera jedyną informację, po którą człowiek tu wchodzi, a pasek i tak zostaje.

### Warunek: szerokość KONTENERA, nie wskaźnik i nie szerokość okna

⚠ **To jest poprawka dwóch błędnych sformułowań tego dokumentu.** Wersja 1.6 mówiła
„przy wskaźniku zgrubnym"; wdrożenie dołożyło do tego `max-width: 700px`. **Oba warunki są złe**,
i widać to na jednym pomiarze: wariant mobilny otwarty w przeglądarce na komputerze ma okno
**1920 px**, wskaźnik precyzyjny — a kolumnę treści **520 px** i kartę **454 px**.
Ani `pointer: coarse`, ani `max-width: 700px` wtedy nie obowiązuje, więc reguła nie działa
i pasek przewijania zostaje. Ta sama pomyłka dotyczyła komórki kalendarza: przy oknie 1200 px
siatka dostaje **64,5 px** szerokości i **90 px** wysokości, czyli pusty prostokąt.

Reguła obowiązująca:

```
.table-scroll { container-type: inline-size; }
@container (max-width: 520px) { .table--rekordy { … lista … } }
```

- ⚠ powodem zamiany jest **brak miejsca**, a miejsce mierzy się tam, gdzie rzecz stoi —
  w jej kontenerze. Okno i rodzaj wskaźnika są **przybliżeniami**, które mylą się dokładnie
  w tych przypadkach, w których na ekran patrzymy,
- to samo dotyczy komórki kalendarza — próg na kontenerze, nie na oknie,
- ⚠ **`pointer: coarse` zostaje tam, gdzie jest prawdziwym powodem** — przy celach dotykowych
  i wielkości pól (§11.3). Palec to argument o **dotykaniu**, nie o szerokości,
- przeglądarka bez `@container` zostawia tabelę z przewijaniem. To **właściwy tryb awarii**:
  widoczny, a nie fałszywy.

#### ⚠ Nazwany wyjątek: oś czasu znaczy nieobecność WYPEŁNIENIEM (22.09.2026)

Reguła „nieobecność znaczymy **krawędzią, nie tłem**" obowiązuje w **siatce miesiąca**, gdzie wpis
jest blokiem wewnątrz dnia, sąsiaduje z numerem i tekstem, a jego długość niczego nie niesie.

⚠ **W osi czasu (wiersze osób, kolumny dni) jest odwrotnie: pasek JEST daną, a jego długość JEST
treścią** — od kiedy do kiedy kogoś nie ma. Odebranie mu wypełnienia odbiera dokładnie tę
informację. Zmierzone na `/kalendarz?widok=zespol`, zanim to poprawiliśmy:

```
pasek nieobecności   tło rgb(252,252,253)   kontrast do wiersza  1.03
tło weekendu         tło rgb(240,242,245)   kontrast do wiersza  1.12
```

Czyli **tło weekendu było czterokrotnie mocniej odznaczone niż sama nieobecność** — szum
głośniejszy od sygnału, na ekranie, po który człowiek przychodzi właśnie po ten sygnał.

Obowiązuje w osi czasu (**przepisane 11.10.2026**, po zatwierdzeniu wzoru pastelowych pasków):

- pasek ma **wypełnienie w kolorze rodzaju** i **nie ma obrysu**; końce są pełnym owalem
  (`border-radius: 999px`). Kolor pochodzi z sześciu tokenów `--c-pasek-{urlop|zwolnienie|kadrowe}-{tlo|tekst}`,
  nigdy z `#rrggbb` w komponencie,
- ⚠ **to jest drugie miejsce, w którym zieleń stoi na danej, nie na akcji** — pastel urlopu
  (`#DCF0E4`) jest zielony. Odstępstwo od §3.1 jest **nazwane i ograniczone do osi czasu**:
  ton jest o rząd słabszy od `--c-accent`, nie da się go kliknąć i nigdy nie stoi obok przycisku.
  Poza osią czasu „zieleń = akcja" nie ustępuje,
- **dni wolne schodzą poniżej paska**: kontrast paska do wiersza > kontrastu dnia wolnego.
  Zmierzone 11.10: pasek 1,191 / 1,167 / 1,206 do bieli wiersza, dzień wolny 1,06.
  To jest kryterium **liczbowe**, sprawdzane w bramce (`paski_nieobecnosci`),
- **tekst na pasku jest nośnikiem, nie ozdobą** — zakres (`17–21.08`) albo, na pasku
  jednodniowym, **numer dnia** (`24`), liczony **po stronie serwera** i wyrównany
  `font-variant-numeric: tabular-nums`. Kontrast tekstu do wypełnienia 5,08–6,60 —
  ⚠ **to on, a nie wypełnienie, spełnia tu próg czytelności**,
- **legenda jest częścią reguły**: próbki legendy biorą te same tokeny, co paski. Zmierzone
  11.10: `legend__mark--urlop` = `rgb(220,240,228)` = `--c-pasek-urlop-tlo`, co do bajtu.

⚠ **Czego ta reguła NIE załatwia — i to jest zmierzone 11.10, nie przeczucie.**
Trzy pastele różnią się **wyłącznie barwą**: wzajemne kontrasty jasności to **1,012 / 1,021 /
1,033**, a do tła dnia wolnego 1,098–1,135. W odcieniach szarości (achromatopsja, wydruk
czarno-biały, tryb wysokiego kontrastu) **rodzaj nieobecności jest nierozpoznawalny**, a sam
pasek na dniu wolnym blisko granicy widoczności. Poprzedni kształt miał na to drugi kanał —
obrys i grubą lewą krawędź, która dodatkowo znaczyła **początek** nieobecności; ten kanał
zniknął razem z obrysem. **Ściemnianie pasteli tego nie naprawia**: przesuwa wszystkie trzy
o tyle samo (propozycja z raportu: 1,246 / 1,232 / 1,254) i nie przekracza żadnego progu —
ani 3:1 z WCAG 1.4.11 dla elementu nieliterowego, ani progu dostrzegalności. Naprawą jest
**drugi, niekolorowy kanał rodzaju** (np. skrót rodzaju w tekście paska, gdzie się mieści)
i to jest **osobna decyzja produktowa** — wzór pasków został zatwierdzony wizualnie i nie
dokładamy do niego nic po cichu. Pozycja otwarta w `docs/architektura.md`.

⚠ Ten wyjątek dotyczy **wyłącznie** osi czasu (`.team-grid__bar`).
⚠ **Poprawka 1.18.31:** zdanie mówiło tu wcześniej, że siatka miesiąca `.month-grid`
na `/dane/pracownik/{id}/kalendarz` „zostaje na krawędzi, bo rodzaj niesie tam tekst wpisu,
nie kolor". Ten ekran rysuje od 19.10.2026 tę samą siatkę, co „Tylko ja · Miesiąc"
(`.msiatka`), a `.month-grid` jest skasowana — czyli **tego przypadku już nie ma**, i razem
z nim znikło jedyne miejsce, gdzie rodzaj nieobecności stał w kratce słowem. Kratka `.msiatka`
niesie rodzaj kolorem i `title`, więc podlega wyjątkowi opisanemu wyżej na tych samych
warunkach, co pasek osi czasu.

#### ⚠ Nazwany wyjątek: ekran hierarchii zostaje na `@media` (22.09.2026)

Dwie reguły — `.hier` (próg 1100 px, układ dwukolumnowy) i `.hier__tylko-komputer` (próg 760 px,
zdanie „ten ekran jest przeznaczony na komputer") — są **świadomie** na `@media`, nie `@container`.
Trzy powody, wszystkie zmierzone:

1. **Przypadek, dla którego powstała ta reguła, tu nie zachodzi.** `/admin/hierarchia` nie istnieje
   w powłoce mobilnej (zmierzone: `/m/` 200, `/m/kalendarz` 200, `/m/admin/hierarchia` **404** —
   cała Administracja jest webowa). Nie ma więc wąskiej kolumny w szerokim oknie.
2. **Cena kontenera jest realna, nie teoretyczna.** `.hier` leży wprost w `.page`, a kontenery mamy
   tylko na `.card__body` i `.drawer__body`. Postawienie kontenera na `.page` ustanawia **nowy blok
   zawierający** dla potomków `absolute`/`fixed` — a na `.page` wisi kontrolka dostępu do spółek
   (`position: fixed`, pozycja liczona w JavaScripcie **względem okna**) na `/admin/konta`.
   Kosmetyczna poprawka kupiona regresją na innym ekranie to zły interes.
3. **Zdanie o komputerze mówi o urządzeniu, nie o dostępnym miejscu.** Wąska karta w szerokim oknie
   nie znaczy, że ktoś pracuje palcem; wąskie okno — znaczy. To jest ten sam rozdział co
   `pointer: coarse` wyżej: nie każdy próg jest progiem o szerokości.

⚠ **Warunek wygaśnięcia wyjątku:** jeśli `/admin/hierarchia` kiedykolwiek trafi do tras
współdzielonych z powłoką mobilną, **obie** reguły — 760 px i 1100 px — muszą przejść
na `@container`. Nie jedna.

Ciekawostka z pomiaru, warta zapamiętania przy tym progu: przy 1100 px siatka schodzi do jednej
kolumny, więc **karta robi się szersza, nie węższa** (okno 1100 → karta 786 px, okno 1000 → karta
966 px). Próg kontenerowy zachowałby się tu więc inaczej niż okienny — to nie jest zwykłe
przeoczenie, tylko miejsce, w którym dwie miary naprawdę się rozjeżdżają.

### Kształt listy

#### ⚠ Wiersz może być wyższy, dana nie ma prawa być niewidoczna (22.09.2026)

Zmierzone przy zawężaniu pięciu tabel: `table-layout: fixed` usuwa przewijanie w bok i **zamienia
je na ucinanie** — 107 komórek na `/dane/synchronizacja`, w tym **znacznik czasu obcięty w połowie**
(`22.09.2026, 15:51:13`, widok 146 px, treść 175 px). To nie jest naprawa, tylko przeniesienie
problemu: zamiast przewijać, **tabela zaczyna kłamać o treści**.

Reguła: w tabeli danych kolumny dostają **stałe szerokości policzone z najdłuższej treści**,
a komórka **zawija**, nie ucina. Wyższy wiersz jest brzydszy; ucięta dana jest fałszywa,
a fałszu nikt nie zgłosi, bo nie widać, że czegoś brakuje.

⚠ Wyjątek po drugiej stronie: `.chip` **zachowuje `white-space: nowrap`** — plakietka złamana
na dwa wiersze przestaje wyglądać jak plakietka. Dlatego kolumna z plakietkami musi dostać
szerokość z **najdłuższej plakietki**, a nie równy podział.

**Reguła jest włączana znacznikiem `.table--rekordy` na `<table>`, nigdy wyłączana wyjątkiem.**
⚠ Powód jest w trybie awarii: brak znacznika na tabeli rekordów daje **przewijanie w bok**
(brzydkie, widoczne, zgłoszone od razu), a brak wyjątku na tabeli par dałby **odwrócony akcent**
(wygląda dobrze i jest fałszywe, więc nikt tego nie zgłosi).

- ⚠ **Tabela par etykieta/wartość nie jest tabelą rekordów** i nie dostaje tego znacznika.
  Układ dwuliniowy postawiłby nazwę ustawienia głośno, a jego wartość ciszej — czyli odwrotnie,
  niż znaczy ten ekran. Pary idą na `.defs`,
- `<td class="table__lead">` — dana wiodąca, `<td class="table__state">` — plakietka stanu.
  ⚠ Kolejności komórek nie da się użyć jako wskazówki: plakietka bywa w ostatniej komórce
  i w pierwszej, więc ani `:first-child`, ani `:last-child` jej nie znajdzie,
- pozycja ma dwie linie: wiodąca + plakietka w pierwszej, reszta rozdzielona `·` w drugiej,
  ciszej (`--t-caption`, `--c-text-3`). Nagłówki kolumn znikają,
- ⚠ **jednostka wchodzi do komórki** (`5 dni`, nie `5`) w **obu** wariantach. Powód nie jest
  estetyczny: w trybie listy nagłówek kolumny znika, więc liczba bez jednostki traci znaczenie,
  a dwa formaty tej samej tabeli to drugie miejsce do pomyłki,
- wysokość pozycji **nie jest stała** — `--row-h` dotyczy tabeli, nie listy,
- ⚠ **semantyka musi przetrwać**: `display: block` na elementach tabeli kasuje w części czytników
  ekranu rolę tabeli, wiersza i komórki. Jawne `role="table"`, `role="row"`, `role="cell"`.

## 12. Poczta — inne prawa niż strona, i to nie jest odstępstwo

⚠ **Czytaj to, zanim zgłosisz `style=` w `templates/mail/**` jako naruszenie §6.** Wiadomość
renderuje klient poczty, nie przeglądarka: nie ma tam naszego arkusza, nie ma CSP, a Outlook
rysuje treść **silnikiem Worda**. Reguły strony wzięły się z tego, że dzięki nim da się włączyć
CSP bez `unsafe-inline`; w skrzynce odbiorcy nie ma czego chronić CSP, a jest co stracić.

| Reguła strony | W poczcie | Dlaczego |
|---|---|---|
| zakaz `style=` (§6) | ⚠ **style wyłącznie w atrybutach** | część klientów wycina `<style>` z nagłówka, a Gmail usuwa cały arkusz |
| siatki, `flex`, `grid` | ⚠ **tabele układu** | w silniku Worda `flex`, `grid` i promień na `<a>` nie działają; przycisk to komórka z tłem |
| krój z tokenów | **krój systemowy** (`-apple-system, Segoe UI, Arial`) | IBM Plex Sans się nie wczyta, a wymuszony dałby inny wygląd na każdym telefonie |
| ikony i obrazki | ⚠ **zero obrazków**, logo jako **tekst** | klient blokuje obrazki domyślnie; piksela śledzącego w wiadomości od pracodawcy nie da się obronić |
| kolory z tokenów | te same **wartości** wpisane wprost | `var(--…)` w poczcie nie istnieje |
| `class=` | **zabronione** | nasz arkusz nie dociera; klasa bez reguły to atrapa |

**Co jeszcze obowiązuje w poczcie, a nie wynika z reguł strony:**

- ⚠ **wersja tekstowa jest obowiązkowa i powstaje z tego samego źródła co HTML.** Wiadomość
  wyłącznie HTML-owa zbiera punkty spamowe, a spam jest przy tej funkcji naszym głównym ryzykiem.
  `AltBody` pisany drugi raz z ręki rozjeżdża się przy pierwszej poprawce i **nikt tego nie
  zauważy**, bo wersję tekstową czyta ułamek odbiorców — dlatego `MailRenderer` renderuje obie
  z jednego zestawu zmiennych (`templates/mail/*.twig` i `templates/mail/text/*.twig`),
- ⚠ **jeden układ** (`templates/mail/layout.twig`) i **cztery szablony treści** dla wszystkich
  jedenastu rodzajów wiadomości: `zaproszenie`, `haslo-reset`, `alert` (sześć rodzajów alertów)
  i `proba` (trzy drogi wiadomości próbnej). Od Z60 **nie ma ani jednej wiadomości sklejanej
  w PHP** — osiem takich było do 20.10.2026 i żadna nie miała ani wersji HTML, ani wspólnej
  stopki. Kopia układu w kilku plikach to ten sam wzorzec, przez który mieliśmy dwie nazwy
  nagłówka strony,
- ⚠ **przycisk akcji i adres pod nim rysuje UKŁAD**, nie szablon treści (Z60): wiadomość ma
  najwyżej jedno działanie, a poprawka przycisku ma być w jednym miejscu,
- ⚠ **stopka niesie datę i godzinę w strefie instalacji** (Z60): alert czytany po dwóch dniach
  bez znacznika czasu nie mówi, czy opisuje stan z dziś, czy z zeszłego tygodnia; strefa jest
  wypisana, bo klient poczty pokazuje swoją,
- ⚠ **barwy poczty mieszkają w `Service\Mail\BarwyPoczty`**, a nie w szablonach: wartości
  przepisane z `00-tokens.css` raz, nazwy kluczy opisują rolę. Rozsypane po szablonach rozjadą
  się przy pierwszej zmianie palety — a rozjazdu nikt nie zobaczy,
- ⚠ **temat w jednym wzorze**: „wezurlop · …", prefiks dokłada `MailRenderer`, nie nadawcy.
  ⚠ **Jeden jawny wyjątek — zaproszenie** (Z62, 20.10.2026): „<nazwa wyświetlana firmy> ·
  Zaproszenie do aplikacji urlopowej — ustaw hasło", a dla konta administracyjnego bez etatu
  „wezurlop · Zaproszenie do aplikacji — ustaw hasło". Powód: to jedyna wiadomość czytana przed
  pierwszym logowaniem, więc odbiorca rozpoznaje pracodawcę, a nie dostawcę. Szablon stoi
  w `MailRenderer::PELNY_TEMAT`, temat buduje `AccountMailService::tematZaproszenia()`,
- ⚠ **nazwa firmy klienta w treści i w stopce** — i to **pełna nazwa z R2**, nie nasza nazwa
  wyświetlana: w wiadomości urzędowej liczy się nazwa z umowy. Bez nazwy pracodawcy zaproszenie
  wygląda jak phishing i tak zostanie potraktowane,
- ⚠ **link także jako tekst do skopiowania.** Gdy przycisk się nie wyrenderuje, zaproszenie
  bez adresu jest bezużyteczne,
- **zieleń tylko na przycisku** — ta sama reguła co w aplikacji (§3.1): kolor znaczy akcję.
  Alert bezpieczeństwa nie ma przycisku, bo nie ma czego kliknąć, więc nie ma w nim zieleni,
- ⚠ **alert nie zawiera adresów ani nazw kont** (CLAUDE.md §14) — w wiadomości są liczby,
  szczegóły zostają w dzienniku zdarzeń,
- **imię w wołaczu tylko wtedy, gdy umiemy odmienić** (`Support\Wolacz`: imiona żeńskie na `-a`).
  W pozostałych przypadkach wiadomość wita bez imienia — forma przekręcona jest gorsza niż brak
  formy, bo wygląda na automat z cudzej listy,
- **bez `Reply-To` na skrzynkę techniczną**; stopka mówi wprost „nie odpowiadaj na ten adres",
- ⚠ stopka używa **myślnika, nie kropki** po nazwie firmy: nazwy z R2 kończą się na `SP. Z O.O.`,
  więc kropka dawała `SP. Z O.O..` (zmierzone na prawdziwej nazwie, nie wymyślone).

⚠ **Bramka pilnuje tego osobnym zestawem reguł.** `bin/check-rules.php` ma dla
`templates/mail/**` własne wzorce (`MAIL_PATTERNS`): zabronione `<style>`, `class=`, `<img>`,
`background=`, `<script>`/`<form>`/`<iframe>`, `flex`/`grid`, `position`, `url(`, `@media`
i `var(--…)`. ⚠ Zmierzone 22.09.2026: przy **jednym** zestawie dla wszystkich szablonów bramka
zgłaszała **40 trafień `style=`** na trzech szablonach wiadomości, czyli karała za jedyny sposób,
w jaki poczta może działać. Przy okazji wyszło, że wzorce znaczników liczyły się także
**w komentarzach** — komentarz wyjaśniający, dlaczego w poczcie nie ma `<style>`, sam był
zgłaszany jako `<style>`; od tej zmiany wzorce znaczników pomijają komentarze Twiga, a wzorce
danych przykładowych nadal obowiązują w całym pliku.

## 11.5 Czego wariant mobilny NIE dostaje

- ekranów administracyjnych: `/m/dane`, `/m/admin/*`, `/m/diagnostyka` zwracają **404** i tak ma zostać.
  Lustro tras obejmuje `$shared`; ekrany administracyjne są w `$webOnly` i to jest decyzja, nie luka,
- ciemnego motywu — nie ma go w wariancie komputerowym,
- własnych komponentów. ⚠ Komponent potrzebny tylko na telefonie jest sygnałem, że ekran robi
  na telefonie co innego niż na komputerze — a to jest decyzja produktowa, nie wizualna, i zgłaszamy ją.

---

## 12. Nazwany wyjątek: panel kontrolki wyboru ma własne przewijanie (1.15, 06.10.2026)

Reguła bramki „zero przewijania w kontenerach i na stronie" **obowiązuje bez zmian dla TREŚCI** —
tabel, kart, siatek. Przewijany kontener z danymi ukrywa dane, i o to w tej regule chodzi.

⚠ **Wyjątek dotyczy wyłącznie panelu kontrolki wyboru** (`details` z listą pozycji do zaznaczenia).
Powód jest zmierzony, nie estetyczny: panel wyboru osób przy zasięgu 53 osób miał
**1408 px wysokości w oknie 855 px**, czyli jego własny przycisk zapisu znajdował się
**poza ekranem**, a zaznaczanie wymagało przewijania strony pod otwartym panelem,
który zasłaniał siatkę.

Warunki wyjątku:

- panel ma **trzy strefy**: nieprzewijalną górę (szukanie), przewijaną listę, nieprzewijalny dół
  (licznik i akcje). **Przewija się wyłącznie lista** — nigdy cały panel;
- wysokość panelu **dopasowana do okna**, tak by dół był zawsze widoczny;
- ⚠ **lista jest kontrolką, nie treścią.** Jeśli kiedykolwiek pojawi się pokusa, żeby tym samym
  wyjątkiem objąć tabelę danych — to znaczy, że tabela jest za szeroka albo za długa,
  i rozwiązaniem jest §11.4, nie przewijanie;
- ⚠ **lista przycięta limitem nie może być filtrowana po stronie przeglądarki.** Przy „30 z 53"
  filtr w przeglądarce przeszukuje trzydzieści i milczy o dwudziestu trzech — człowiek wnioskuje,
  że osoby nie ma. Szukanie w takiej liście idzie **do serwera**, zawsze.

Wyjątek wygasa, jeśli lista przestanie być przycinana limitem i zacznie mieścić cały zasięg —
wtedy wraca reguła ogólna.

---

## 13. Słownictwo: jednostka organizacyjna nazywa się FIRMA (1.16, 11.10.2026)

> **W interfejsie jednostka organizacyjna nazywa się „firma". Słowo „spółka" nie występuje
> w żadnym napisie widzianym przez użytkownika.**

⚠ Powód jest **rzeczowy, nie estetyczny** (rozstrzygnięcie Krzysztofa 11.10.2026):

> „nie każda firma jest spółką"

„Spółka" to **forma prawna** — sp. z o.o., S.A., jawna. Produkt sprzedajemy firmom, z których
część to jednoosobowa działalność, spółdzielnia albo fundacja. Dla nich „Wybierz spółkę" nie jest
niespójne, tylko **nieprawdziwe** — a w systemie kadrowym nieprawdziwa nazwa rzeczy podważa
zaufanie do liczb stojących obok niej.

**Obejmuje** wszystko, co człowiek czyta: etykiety, nagłówki, tytuły stron i okruchy, komunikaty
błędów i potwierdzeń, puste stany, dymki, `title`, `aria-label`, `placeholder`, teksty wiadomości
e-mail, napisy instalatora i diagnostyki oraz **etykiety** zdarzeń w dzienniku.

**Nie obejmuje** (granica, żeby nie było migracji bez zysku):

| zostaje | dlaczego |
|---|---|
| nazwy klas PHP i CSS, zmiennych, kolumn, tabel, pól formularzy (`KontekstSpolki`, `company_id`, `.spolka`, `name="spolka"`) | język kodu, nie człowieka |
| **kody** zdarzeń w `event_log` i **klucze ładunku** (`context.switched`, `z_spolka`) | identyfikatory; zmiana unieważniłaby stare wpisy |
| trasy i nazwy plików (`/kontekst/spolka/{id}`, `znacznik-spolki.twig`) | adres i ścieżka to identyfikator |
| dokumenty w `docs/`, `zlecenia/`, `raporty/` | nasz język roboczy — wyjątkiem jest **ten** dokument, gdzie stoi sama zasada |
| ⚠ **nazwa prawna przysłana przez R2** („BETA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ") | **to nie nasze słowo, tylko dana z kadr** — nazwy klienta nie poprawiamy, ta sama zasada co „nie liczymy za kadry" |

⚠ **Zasada ma kryterium w bramce (`bez_slowa_spolka`), bo inaczej słowo wróci przy pierwszym
nowym ekranie** — w kodzie „spolka" zostaje wszędzie. Kryterium przechodzi wszystkie ekrany,
które bramka i tak odwiedza, szuka **rdzenia z ogonkami** (`spółk`, więc wszystkie odmiany)
w `innerText` oraz **osobno** w atrybutach, i wskazuje **ekran i zdanie**. Jedyny wyjątek —
nazwa firmy — jest porównywany z **rzeczywistymi nazwami z bazy** (i ich początkami, bo nazwę
prawną sami przycinamy w polach), nigdy z kształtem napisu: wyjątek oparty na kształcie
przepuściłby nasz własny napis, który akurat wygląda podobnie.
