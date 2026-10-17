# docs/ — dokumentacja projektu wezurlop

Czytaj w tej kolejności, zanim napiszesz pierwszą linię kodu:

| Plik | O czym mówi |
|---|---|
| `../CLAUDE.md` | **jak** pisać — reguły wiążące przy każdej linii kodu |
| `specyfikacja.md` | **co** budujemy i w jakiej kolejności; ustalenia produktowe |
| `architektura.md` | **dlaczego** tak — siedem bloków systemu, technologia i uzasadnienie każdego wyboru |
| `szkielet-tenanta.md` | **zadanie na teraz** — Etap 2, szkielet tenanta: struktura, bootstrap, routing, migracje, logowanie, 2FA, widok, instalator, 8 kamieni milowych, 27 scenariuszy odbioru |
| `mostek-natywny.md` | kontrakt między powłoką Fluttera a stroną w WebView |
| `audyt.md` | znaleziska architektoniczne: co rozstrzygnięte, co odłożone i do którego bloku |

## Zakres bieżącego etapu

Budujemy **trzon aplikacji tenanta**. Bez logiki urlopowej: wnioski, salda, limity, akceptacje,
eskalacje, kalendarz z danymi, connector R2, import ZUS, SMS i push to Etap 3.

Ekrany biznesowe (`/kalendarz`, `/wnioski`, `/wniosek/nowy`, `/zespol`, `/ustawienia`) powstają
jako **puste ramy** — layout, nawigacja, stany puste, dane przykładowe wprost z kontrolera.

## Trzy rzeczy, o których łatwo zapomnieć

1. **Osoba ≠ zatrudnienie.** Konto logowania należy do osoby, uprawnienia do pary (konto, firma).
   Ta sama osoba bywa zatrudniona w dwóch firmach jednego tenanta.
2. **Kod nie może zakładać Apache'a.** Aplikacja instaluje się na hostingach, których nie widzimy
   przed sprzedażą — stąd `PATH_INFO`, `X-Api-Key`, konfigurowalne ścieżki i limit 30 sekund.
3. **`/src/License` i `/src/Core` będą kodowane ionCube'em.** Granica stref to granica katalogu
   i obowiązuje od pierwszego pliku. Przenoszenie klasy przez tę granicę to zmiana
   architektoniczna — zgłoś ją, nie rób po cichu.

## Nazewnictwo

**Angielski w kodzie i w bazie, polski w interfejsie i w adresach URL.**
Jeśli tekst zobaczy użytkownik — polski; jeśli tylko programista — angielski.
Uzasadnienie: `szkielet-tenanta.md`, rozdział 0.
