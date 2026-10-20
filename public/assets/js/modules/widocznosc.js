/* Ekran „Widoczność kalendarza" — **jedna wygoda, zero warunków działania** (CLAUDE.md §6).

   ⚠ Bez tego pliku ekran działa w całości: kroki okna to odnośniki `GET`, zapis to formularz
   `POST`, a wybór celu to pola wyboru w liście. Plik robi dokładnie jedną rzecz: w kroku 2
   pilnuje, żeby **„Cała firma" i wskazane osoby wykluczały się na ekranie od razu**, a nie
   dopiero po odpowiedzi serwera.

   ⚠ **Rozstrzyga SERWER, ten plik tylko to pokazuje.** Reguła serwera brzmi: gdy w żądaniu
   jest i „cała firma", i wskazane osoby — wygrywa cała firma (`VisibilityController::stanOkna()`),
   a gdy nie ma nic — też cała firma, bo to wartość domyślna tego kroku. Stąd niewidoczny
   na pierwszy rzut oka warunek niżej: odznaczenie ostatniej osoby **zaznacza z powrotem
   „Całą firmę"**. Bez tego ekran pokazywałby stan pusty, którego serwer nie zna, i zapis
   obejmowałby całą firmę bez jednego znaku o tym na ekranie.

   ⚠ Nasłuch jest NA DOKUMENCIE, nie na polach: treść okna wymienia htmx przy każdym kroku,
   szukaniu i doczytaniu, więc listener przypięty do pól przeżyłby dokładnie jedną wymianę.

   ⚠ **Stopka listy mówi prawdę od razu** (zlecenie Z57 U4). Do 20.10.2026 napis „wybrano: …"
   powstawał wyłącznie na serwerze, więc po kliknięciu osoby stopka dalej twierdziła „wybrano:
   cała firma · 52 osoby" — przeczyła zaznaczeniu, które ten plik właśnie zmienił. Teraz ten
   sam plik, który przestawia zaznaczenie, przepisuje też ten jeden napis.
   ⚠ **Słowa przychodzą z serwera** (`data-formy-osoby` z `Support\Odmiana`); w tym pliku
   zostaje sam wybór formy po liczbie. Polska odmiana ma jedno miejsce, nie dwa.
   ⚠ Bez JavaScriptu nic nie ginie: serwer renderuje ten napis tak jak dotąd, przy każdym
   żądaniu — a `textContent` nie buduje HTML-u (CLAUDE.md §6). */
(function () {
  'use strict';

  var OSOBY = 'input[name="grupy[]"]';

  function osoby(ramka) {
    return Array.prototype.slice.call(ramka.querySelectorAll(OSOBY));
  }

  function ktoras(ramka) {
    return osoby(ramka).some(function (pole) { return pole.checked; });
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

  document.addEventListener('change', function (event) {
    var cel = event.target;
    if (!(cel instanceof HTMLInputElement)) {
      return;
    }
    var ramka = cel.closest('.wid-lista-wybor');
    if (!ramka) {
      return;
    }
    var firma = ramka.querySelector('#wid-cala-firma');
    if (!firma) {
      /* Zakładka grup, krok 1 albo lista po wpisaniu tekstu — pozycji „Cała firma" nie ma,
         ale napis w stopce dotyczy każdej z tych list. */
      przepiszStopke(ramka);
      return;
    }
    if (cel === firma) {
      if (firma.checked) {
        osoby(ramka).forEach(function (pole) { pole.checked = false; });
      } else if (!ktoras(ramka)) {
        /* Stan „nic nie wybrano" nie istnieje — patrz nagłówek pliku. */
        firma.checked = true;
      }
      szukanie(ramka, firma.checked);
      przepiszStopke(ramka);
      return;
    }
    if (cel.matches(OSOBY)) {
      firma.checked = !ktoras(ramka);
      szukanie(ramka, firma.checked);
    }
    przepiszStopke(ramka);
  });
})();
