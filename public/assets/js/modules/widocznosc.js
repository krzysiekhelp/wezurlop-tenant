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

   ⚠ Zero innerHTML, zero budowania HTML ze stringów, zero liczenia czegokolwiek. Liczby
   w stopce („wybrano: cała firma · 12 osób") składa serwer — tutaj nie powstaje ani jedna. */
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
      /* Zakładka grup albo lista po wpisaniu tekstu — pozycji „Cała firma" wtedy nie ma. */
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
      return;
    }
    if (cel.matches(OSOBY)) {
      firma.checked = !ktoras(ramka);
      szukanie(ramka, firma.checked);
    }
  });
})();
