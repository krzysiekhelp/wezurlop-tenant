/* Odliczanie i pasek postępu sondy crona (zlecenie Z65).

   ⚠ **Skrypt nie zna reguły i nie wydaje wyroku.** Oba znaczniki czasu — początek i koniec
   odliczania — przychodzą z SERWERA w atrybutach `data-sonda-start` i `data-sonda-koniec`,
   a o tym, czy cron odpowiedział, rozstrzyga serwer przy odpytywaniu (`GET /ustawienia/cron/sonda`).
   Tutaj zostaje jedno: pokazać, ile zostało. Gdyby skrypt liczył termin sam, dwie przeglądarki
   z różnie ustawionym zegarem pokazałyby dwa różne wyroki o jednym cronie.

   ⚠ **Pętla gaśnie sama.** Stan końcowy przychodzi z serwera bez atrybutu `data-sonda-koniec`
   (i bez `hx-get` na pojemniku), więc pierwsze tyknięcie po podmianie nie znajduje elementu
   i czyści `setInterval`. Nie ma tu żadnego „wyłącz po wyniku" do przeoczenia.

   ⚠ Szerokość paska idzie przez zmienną CSS `--sonda-postep`, nie przez `style.width`:
   wartość podaje skrypt, ale regułę trzyma arkusz. Bez tego pliku wiersz działa — licznik
   pokazuje „do GG:MM:SS" z serwera, a wynik przychodzi po odświeżeniu ekranu. */
(function () {
  'use strict';

  var zegar = null;

  function dwieCyfry(n) {
    return (n < 10 ? '0' : '') + n;
  }

  function tyknij() {
    var wiersz = document.querySelector('[data-sonda-koniec]');
    if (!wiersz) {
      if (zegar !== null) {
        window.clearInterval(zegar);
        zegar = null;
      }
      return;
    }
    var koniec = Date.parse(wiersz.getAttribute('data-sonda-koniec'));
    var start = Date.parse(wiersz.getAttribute('data-sonda-start'));
    if (!isFinite(koniec) || !isFinite(start)) {
      return;
    }
    var teraz = Date.now();
    var zostalo = Math.max(0, Math.round((koniec - teraz) / 1000));
    var licznik = wiersz.querySelector('[data-sonda-licznik]');
    if (licznik) {
      licznik.textContent = Math.floor(zostalo / 60) + ':' + dwieCyfry(zostalo % 60);
    }
    var pasek = wiersz.querySelector('[data-sonda-pasek]');
    if (pasek) {
      var calosc = Math.max(1, koniec - start);
      var udzial = Math.min(1, Math.max(0, (teraz - start) / calosc));
      pasek.style.setProperty('--sonda-postep', (Math.round(udzial * 1000) / 10) + '%');
    }
  }

  function uruchom() {
    tyknij();
    if (zegar === null && document.querySelector('[data-sonda-koniec]')) {
      zegar = window.setInterval(tyknij, 1000);
    }
  }

  /* Po każdej podmianie fragmentu stan liczy się od nowa — razem z decyzją, czy w ogóle tykać. */
  document.addEventListener('htmx:afterSwap', uruchom);
  document.addEventListener('DOMContentLoaded', uruchom);
})();
