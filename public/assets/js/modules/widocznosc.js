/* Ekran „Widoczność kalendarza" — **jedna wygoda, zero warunków działania** (CLAUDE.md §6).

   ⚠ Bez tego pliku ekran działa w całości: kroki okna to odnośniki `GET`, zapis to formularz
   `POST`, a wybór to pola wyboru w liście. Plik robi trzy rzeczy, wszystkie natychmiastowe:
   pilnuje, żeby „Cała firma" i wskazane osoby wykluczały się **od razu**, pokazuje blokadę
   pozostałych sekcji kroku i mówi w stopce, co jest wybrane — zamiast czekać na odpowiedź serwera.

   ⚠ **Rozstrzyga SERWER, ten plik tylko to pokazuje.** Każde żądanie wraca z sekcjami
   zablokowanymi tak samo (`VisibilityController::stanOkna()` → `wybor_kto`, `wybor_czyje`),
   a zapis stanu bez wskazanego podmiotu albo celu jest odrzucany po stronie serwera.

   ⚠ **Niezmiennik „stan »nic nie wybrano« nie istnieje" ZOSTAŁ ZDJĘTY w Z80** (23.10.2026,
   decyzja Krzysztofa po prototypie). Do tej pory ten plik po odznaczeniu ostatniej osoby
   **zaznaczał z powrotem „Całą firmę"**, bo serwer i tak czytał pustą listę jako całą firmę.
   Od Z80 jest odwrotnie i to jest sedno zmiany: „Cała firma" BLOKUJE pojedyncze osoby, więc
   musi dać się odznaczyć — inaczej do osób nie da się przejść. Stan „sekcja Osoby, nic nie
   wskazane" jest teraz dozwolony w oknie, a odmowa zapisu stoi w serwerze.

   ⚠ **Blokady sekcji zdejmujemy BEZ okrągłego żądania.** Przeładowanie kroku adresem zakładki
   zgubiłoby wskazane grupy — one nie jadą w adresie, tylko w formularzu (patrz komentarz przy
   `hx-include` w `visibility-list.twig`). Dlatego kłódka i ikona sekcji stoją w drzewie **obie**,
   od renderowania, a tu przestawia się wyłącznie klasa i atrybuty: żadnego budowania SVG,
   żadnego `innerHTML` (CLAUDE.md §6).

   ⚠ Nasłuch jest NA DOKUMENCIE, nie na polach: treść okna wymienia htmx przy każdym kroku,
   szukaniu i doczytaniu, więc listener przypięty do pól przeżyłby dokładnie jedną wymianę.

   ⚠ **Stopka listy mówi prawdę od razu** (zlecenie Z57 U4). Do 20.10.2026 napis „wybrano: …"
   powstawał wyłącznie na serwerze, więc po kliknięciu osoby stopka dalej twierdziła „wybrano:
   cała firma · 52 osoby" — przeczyła zaznaczeniu, które ten plik właśnie zmienił. Teraz ten
   sam plik, który przestawia zaznaczenie, przepisuje też ten jeden napis.
   ⚠ **Słowa przychodzą z serwera** (`data-formy-osoby` z `Support\Odmiana`); w tym pliku
   zostaje sam wybór formy po liczbie. Polska odmiana ma jedno miejsce, nie dwa.
   ⚠ Bez JavaScriptu nic nie ginie: serwer renderuje te napisy tak jak dotąd, przy każdym
   żądaniu — a `textContent` nie buduje HTML-u (CLAUDE.md §6). */
(function () {
  'use strict';

  var OSOBY = 'input[name="grupy[]"]';
  /* Każde pole, którego zaznaczenie jest WYBOREM w sekcji. Jedna lista, bo „czy w tym kroku
     coś wybrano" to jedno pytanie — rozbite na trzy odpowiadałoby trzy razy inaczej. */
  var WYBORY = 'input[name="wybor"]:checked, #wid-cala-firma:checked, '
    + 'input[name="grupy[]"]:checked, #wid-podwladni:checked';
  var TRESC_OKNA = '#widocznosc-okno-tresc';

  function osoby(ramka) {
    return Array.prototype.slice.call(ramka.querySelectorAll(OSOBY));
  }

  /* Treść okna — zakres, w którym szukamy zakładek i pól. Zakładki sekcji stoją poza ramką
     listy, więc wspólnym przodkiem jest cel wymiany htmx. */
  function trescOkna(el) {
    return (el && el.closest(TRESC_OKNA)) || document.querySelector(TRESC_OKNA) || document.body;
  }

  function maWybor(zakres) {
    return !!zakres.querySelector(WYBORY);
  }

  /* Szukanie przy zaznaczonej „Całej firmie" nie ma czego szukać — ten sam stan, który
     przy renderowaniu stawia serwer atrybutem `disabled`. */
  function szukanie(ramka, wylaczone) {
    var pole = ramka.querySelector('.wid-szukanie__pole');
    if (pole) {
      pole.disabled = wylaczone;
    }
  }

  /* Forma słowa po liczbie — ta sama reguła, co `Odmiana::mnoga()` w PHP (1 / 2–4 / 5+,
     z wyjątkiem 12–14). Same słowa przychodzą z serwera. */
  function forma(ile, formy) {
    var ostatnia = Math.abs(ile) % 10;
    var dwie = Math.abs(ile) % 100;
    if (Math.abs(ile) === 1) { return formy[0]; }
    return (ostatnia >= 2 && ostatnia <= 4 && (dwie < 12 || dwie > 14)) ? formy[1] : formy[2];
  }

  function zaznaczone(ramka) {
    return osoby(ramka).filter(function (pole) { return pole.checked; });
  }

  function komunikat(tekst) {
    document.dispatchEvent(new CustomEvent('wezurlop:komunikat', {
      detail: { tekst: tekst, rodzaj: 'uwaga' }
    }));
  }

  /* Pojedyncze osoby przy zaznaczonej „Całej firmie": `disabled` (żądanie ich nie poniesie)
     plus znak dla oka i kursora. Ten sam stan stawia serwer przy renderowaniu. */
  function blokujOsoby(ramka, blok) {
    osoby(ramka).forEach(function (pole) {
      var wiersz = pole.closest('.wid-poz');
      pole.disabled = blok;
      if (!wiersz) { return; }
      wiersz.classList.toggle('wid-poz--zablokowana', blok);
      if (blok) {
        wiersz.setAttribute('aria-disabled', 'true');
        wiersz.setAttribute('title', 'Odznacz „Cała firma", żeby wybrać pojedyncze osoby');
      } else {
        wiersz.removeAttribute('aria-disabled');
        wiersz.removeAttribute('title');
      }
    });
  }

  function nazwaSekcji(poz) {
    return poz ? (poz.getAttribute('data-sekcja') || '') : '';
  }

  /* Blokada pozostałych sekcji kroku — przeliczana z ŻYWEGO zaznaczenia, nie z klasy, którą
     przyniósł serwer: inaczej po odznaczeniu wyboru kłódki zostałyby do następnego żądania. */
  function odswiezBlokady(zakres) {
    var seg = zakres.querySelector('.wid-seg');
    if (!seg) { return; }
    var aktywna = seg.querySelector('.wid-seg__poz--on');
    var nazwa = nazwaSekcji(aktywna);
    var blok = maWybor(zakres) && nazwa !== '';
    Array.prototype.forEach.call(seg.querySelectorAll('.wid-seg__poz'), function (poz) {
      /* Sekcja wyłączona z innego powodu (brak gałęzi do policzenia) ma własny napis
         i nie jest odnośnikiem — tej nie dotykamy. */
      if (poz === aktywna || poz.classList.contains('wid-seg__poz--wylaczona')) { return; }
      poz.classList.toggle('wid-seg__poz--zablokowana', blok);
      if (blok) {
        poz.setAttribute('aria-disabled', 'true');
        poz.setAttribute('title', 'Odznacz wybór w sekcji „' + nazwa + '", żeby wybrać z tej sekcji');
      } else {
        poz.removeAttribute('aria-disabled');
        poz.removeAttribute('title');
      }
    });
  }

  /* ⚠ **Kafelek kroku musi nadążać za wyborem** (Z80 A3): wybór zmienia się bez żądania
     do serwera, więc podpis wyrenderowany przy ostatniej odpowiedzi kłamałby do następnej.
     Zmierzone 23.10.2026 przed poprawką: po wskazaniu osoby kafelek dalej mówił „nie wybrano".
     ⚠ W kroku 3 podpisem jest nazwa ZAKRESU, nie sekcji — i tam `--gotowy` zostaje taki, jaki
     przysłał serwer (ptaszek znaczy tam „reguła już istnieje", nie „zakres ma wartość"). */
  function odswiezKafelek(zakres) {
    var biezacy = zakres.querySelector('.wid-krok[aria-current="step"]');
    var podpis = biezacy ? biezacy.querySelector('.wid-krok__podpis') : null;
    if (!podpis) { return; }
    var seg = zakres.querySelector('.wid-seg');
    if (!seg) {
      var wybrany = zakres.querySelector('.wid-zakres input:checked');
      var karta = wybrany ? wybrany.closest('.wid-zakres') : null;
      var nazwaZakresu = karta ? karta.querySelector('.wid-zakres__nazwa') : null;
      if (nazwaZakresu) {
        podpis.textContent = nazwaZakresu.textContent.trim();
        podpis.classList.remove('wid-krok__podpis--pusty');
      }

      return;
    }
    var jest = maWybor(zakres);
    var nazwa = jest ? nazwaSekcji(seg.querySelector('.wid-seg__poz--on')) : '';
    podpis.textContent = nazwa !== '' ? nazwa : 'nie wybrano';
    podpis.classList.toggle('wid-krok__podpis--pusty', nazwa === '');
    biezacy.classList.toggle('wid-krok--gotowy', jest && nazwa !== '');
  }

  /* Napis „wybrano: …" — jeden element, jedno miejsce (`data-wybrano` w stopce ramki). */
  function przepiszStopke(ramka) {
    var cel = ramka.querySelector('[data-wybrano]');
    if (!cel) { return; }
    var formy = (cel.getAttribute('data-formy-osoby') || '||').split('|');
    var rodzaj = cel.getAttribute('data-rodzaj');
    var firma = ramka.querySelector('#wid-cala-firma');
    var wybrane = zaznaczone(ramka);
    var tekst = '';

    if (rodzaj === 'jeden') {
      /* Krok 1: wybór pojedynczy — nazwa stoi w zaznaczonym wierszu, więc nic nie liczymy. */
      var wybor = ramka.querySelector('input[name="wybor"]:checked');
      var nazwa = wybor ? wybor.closest('.wid-poz') : null;
      nazwa = nazwa ? nazwa.querySelector('.wid-poz__nazwa') : null;
      tekst = nazwa ? 'wybrano: ' + nazwa.textContent.trim() : '';
    } else if (firma && firma.checked) {
      var ile = parseInt(cel.getAttribute('data-firma-osob'), 10) || 0;
      tekst = 'wybrano: cała firma · ' + ile + ' ' + forma(ile, formy);
    } else if (rodzaj === 'grupy') {
      /* Grupy: liczba grup i suma ich osób — obie z danych serwera (`data-osob`). */
      var osob = wybrane.reduce(function (suma, pole) {
        return suma + (parseInt(pole.getAttribute('data-osob'), 10) || 0);
      }, 0);
      tekst = wybrane.length === 0 ? '' : 'wybrano ' + wybrane.length + ' · ' + osob + ' ' + forma(osob, formy);
    } else if (wybrane.length > 0) {
      tekst = 'wybrano ' + wybrane.length + ' ' + forma(wybrane.length, formy);
    }

    /* ⚠ Przy pustym wyborze stopka wraca do podpowiedzi serwera, a nie zostaje pusta. */
    var pusto = tekst === '';
    cel.textContent = pusto ? (cel.getAttribute('data-pusto') || '') : tekst;
    cel.classList.toggle('wid-lista-wybor__wybrano--podpowiedz', pusto);
  }

  /* ⚠ **Blokady ŁAPIEMY W FAZIE PRZECHWYTYWANIA (`true`), nie w bąbelkowaniu** — i to jest
     poprawka błędu, nie styl. Zablokowana zakładka jest odnośnikiem z `hx-get`, a htmx słucha
     kliknięcia NA ELEMENCIE; nasłuch na dokumencie w fazie bąbelkowania dostaje zdarzenie
     PÓŹNIEJ, więc `preventDefault()` zatrzymywało samo przejście po `href`, ale żądanie htmx
     i tak wychodziło: zmierzone 23.10.2026 — klik w zablokowaną „Grupę" pokazywał toast
     I PRZEŁĄCZAŁ sekcję. Faza przechwytywania idzie przed nasłuchami elementu, więc
     `stopPropagation()` zabiera htmx-owi to zdarzenie w całości.
     ⚠ Toast jest tu jedynym komunikatem, który dociera także na telefonie, gdzie najechania
     (`title`) nie ma (zlecenie Z80 A2). */
  document.addEventListener('click', function (event) {
    var cel = event.target;
    if (!cel || !cel.closest) { return; }

    var zablokowana = cel.closest('.wid-seg__poz--zablokowana');
    if (zablokowana) {
      event.preventDefault();
      event.stopPropagation();
      var seg = zablokowana.closest('.wid-seg');
      var czynna = seg ? seg.querySelector('.wid-seg__poz--on') : null;
      komunikat('Odznacz wybór w sekcji „' + nazwaSekcji(czynna) + '", żeby wybrać z sekcji „'
        + nazwaSekcji(zablokowana) + '".');
      return;
    }

    var wierszBlokowany = cel.closest('.wid-poz--zablokowana');
    if (wierszBlokowany) {
      event.preventDefault();
      event.stopPropagation();
      komunikat('Odznacz „Cała firma", żeby wybrać pojedyncze osoby.');
    }
  }, true);

  /* ⚠ **Odznaczenie wyboru pojedynczego łapiemy w bąbelkowaniu** — przeciwnie niż blokady
     wyżej. Tutaj nic nie zabieramy innym nasłuchom: po odznaczeniu `change` i tak się nie
     wywoła (pole radio go nie daje), więc stan przeliczamy sami, na końcu. */
  document.addEventListener('click', function (event) {
    var cel = event.target;
    if (!cel || !cel.closest) { return; }

    /* Odznaczenie wyboru pojedynczego: pole radio samo się nie odznacza, a od Z80 jest to
       JEDYNA droga do pozostałych sekcji (pasek „Wyczyść wybór" wypadł w A3).
       ⚠ **Kliknięcie etykiety wywołuje DRUGIE kliknięcie — na samym polu** (tak działa aktywacja
       `<label>`), a ono też tu dolatuje, już po zaznaczeniu. Bez warunku niżej pierwszy wybór
       odznaczał się sam: etykieta zaznaczała pole, przesłane kliknięcie wracało z `checked = true`
       i ten kod je zdejmował. Zmierzone 23.10.2026: po kliknięciu wiersza zero zaznaczonych.
       Pola są `visually-hidden`, więc człowiek nigdy nie klika w nie wprost — pominięcie takiego
       kliknięcia nie zabiera żadnej drogi. */
    var wiersz = cel.closest('.wid-poz');
    var radio = wiersz ? wiersz.querySelector('input[name="wybor"]') : null;
    if (radio && cel === radio) {
      return;
    }
    if (radio && radio.checked) {
      event.preventDefault();
      radio.checked = false;
      var ramka = wiersz.closest('.wid-lista-wybor');
      if (ramka) { przepiszStopke(ramka); }
      var zakresOkna = trescOkna(wiersz);
      odswiezBlokady(zakresOkna);
      odswiezKafelek(zakresOkna);
    }
  });

  document.addEventListener('change', function (event) {
    var cel = event.target;
    if (!(cel instanceof HTMLInputElement)) {
      return;
    }
    var zakres = trescOkna(cel);
    var ramka = cel.closest('.wid-lista-wybor');
    if (!ramka) {
      /* Pole spoza ramki listy — „Podwładni" w kroku 2 i karty zakresu w kroku 3. Blokady
         i podpis kafelka dotyczą ich tak samo. */
      odswiezBlokady(zakres);
      odswiezKafelek(zakres);
      return;
    }
    var firma = ramka.querySelector('#wid-cala-firma');

    if (firma && cel === firma) {
      /* ⚠ Zaznaczenie „Całej firmy" ODZNACZA i BLOKUJE osoby (Z80 A4). Odznaczenie jej
         zwalnia listę — i nie zaznacza niczego z powrotem. */
      if (firma.checked) {
        osoby(ramka).forEach(function (pole) { pole.checked = false; });
      }
      blokujOsoby(ramka, firma.checked);
      szukanie(ramka, firma.checked);
    } else if (cel.matches(OSOBY)) {
      /* Wskazanie osoby zdejmuje „całą firmę": to jedna sekcja i jeden cel reguły. */
      if (firma && firma.checked) {
        firma.checked = false;
        szukanie(ramka, false);
      }
    }

    przepiszStopke(ramka);
    odswiezBlokady(zakres);
    odswiezKafelek(zakres);
  });
})();
