/* Ekran grup — **dwie wygody, zero warunków działania** (CLAUDE.md §6).

   ⚠ Bez tego pliku ekran działa w całości: dodawanie i wypisywanie to formularze `POST`,
   szukanie to `GET`, a bez JavaScriptu okna grupy nie ma wcale (kliknięcie wiersza prowadzi
   na `/admin/grupy/{id}`). Ten plik robi dwie rzeczy, o które prosił Krzysztof:

   1. **przenosi ognisko klawiatury na następny wiersz TEJ SAMEJ kolumny** po kliknięciu
      `+` albo `✕` (aneks §6.4). Powód jest zmierzony: po podmianie fragmentu
      `document.activeElement` to `BODY`, więc dodanie dziesięciu osób z klawiatury znaczyło
      dziesięć razy tabulator od góry strony. ⚠ Tej samej kolumny, nie drugiej: ludzi dodaje
      się seriami, a skok do przeciwnej kolumny odwracałby kierunek pracy przy każdym kliknięciu,
   2. **odświeża listę grup po zamknięciu okna** (aneks §1) — żeby wiersz pod spodem nie został
      z liczbą i składem sprzed otwarcia.

   ⚠ Zero innerHTML, zero budowania HTML ze stringów. Ten plik przestawia ognisko i zleca
   htmx jedno żądanie; treść powstaje w Twigu. */
(function () {
  'use strict';

  /* ——— 1. Ognisko w kolumnie ——————————————————————————————————————————————— */

  /* Zapamiętane PRZED żądaniem, bo po podmianie fragmentu klikniętego przycisku nie ma już
     w DOM-ie: która to kolumna i który wiersz po kolei. */
  var zapamietane = null;

  function kolumny() {
    return Array.prototype.slice.call(document.querySelectorAll('.assign__col'));
  }

  function przyciskiWierszy(kolumna) {
    return Array.prototype.slice.call(kolumna.querySelectorAll('.assign__row .assign__btn'));
  }

  document.addEventListener('htmx:beforeRequest', function (event) {
    var elt = event.detail && event.detail.elt instanceof Element ? event.detail.elt : null;
    var przycisk = elt ? elt.querySelector('.assign__btn') : null;
    var kolumna = elt ? elt.closest('.assign__col') : null;
    if (!przycisk || !kolumna) {
      /* Żądanie z wyszukiwarki albo z akcji zbiorczej — ogniska nie przestawiamy;
         tam pole tekstowe przeżywa podmianę samo (`hx-select`). */
      zapamietane = null;
      return;
    }
    zapamietane = {
      kolumna: kolumny().indexOf(kolumna),
      pozycja: przyciskiWierszy(kolumna).indexOf(przycisk)
    };
  });

  document.addEventListener('htmx:afterSwap', function () {
    if (zapamietane === null) {
      return;
    }
    var stan = zapamietane;
    zapamietane = null;                       /* jedno żądanie przestawia ognisko dokładnie raz */
    var cel = kolumny()[stan.kolumna];
    if (!cel) {
      return;
    }
    var wszystkie = przyciskiWierszy(cel);
    if (wszystkie.length === 0) {
      /* Kolumna opustoszała (zdjęto ostatniego) — ognisko idzie na akcję zbiorczą albo
         na wyszukiwarkę TEJ SAMEJ kolumny, żeby nie wracać na początek strony. */
      var zapas = cel.querySelector('.assign__foot button, .assign__search input');
      if (zapas) {
        zapas.focus();
      }
      return;
    }
    /* Ten sam numer porządkowy: po zdjęciu wiersza następny wskakuje na jego miejsce, więc
       ta sama pozycja znaczy „następna osoba". Na końcu listy — ostatni wiersz. */
    var i = Math.max(0, Math.min(stan.pozycja, wszystkie.length - 1));
    wszystkie[i].focus();
  });

  /* ——— 2. Lista grup po zamknięciu okna ————————————————————————————————————— */

  /* ⚠ Jedno żądanie na adres listy z wyborem tej samej karty, a nie `location.reload()`:
     przeładowanie gubi przewinięcie i mruga całym ekranem, a odświeżyć trzeba jeden element.

     ⚠ **Odświeżenie wisi na ATRYBUCIE `open`, nie na zdarzeniu `close`** (zlecenie 10.10.2026,
     druga tura §2). Nadzór zmierzył u siebie okno, które zamyka się **bez zdarzenia `close`**,
     i miał rację w konsekwencji, choć nie w przyczynie: kryterium oparte na zdarzeniu opisuje
     **jedną z dróg zamknięcia**, a obserwator atrybutu opisuje **każdą** — `close()`, `Escape`,
     kliknięcie w tło, zdjęcie atrybutu czyimś kodem. Jedna reguła zamiast trzech obsług, więc
     czwarta droga zamknięcia nie ma jak jej ominąć.
     ⚠ Zmierzone u mnie na Chromium: `close` **leci** przy wszystkich trzech drogach (sonda
     w fazie przechwytywania na `document` policzyła po jednym), więc u siebie nie odtworzyłem
     pomiaru nadzoru — ale mechanizm, którego nie da się obejść, jest tańszy niż śledztwo,
     czyja przeglądarka miała rację. */
  document.addEventListener('DOMContentLoaded', function () {
    var okno = document.getElementById('grupa-okno');
    if (!okno) {
      return;
    }
    new MutationObserver(function () {
      /* Zmiana atrybutu leci też przy OTWARCIU — odświeżamy wyłącznie po zamknięciu. */
      if (okno.hasAttribute('open')) {
        return;
      }
      var cel = document.getElementById('grupy-lista');
      var adres = cel ? cel.getAttribute('data-grupy-adres') : null;
      if (!adres || !window.htmx) {
        /* Okno grupy otwarte z podstrony (bez listy pod spodem) — nie ma czego odświeżać. */
        return;
      }
      window.htmx.ajax('GET', adres, {
        target: '#grupy-lista',
        select: '#grupy-lista',
        swap: 'outerHTML'
      });
    }).observe(okno, {attributes: true, attributeFilter: ['open']});
  });
})();
