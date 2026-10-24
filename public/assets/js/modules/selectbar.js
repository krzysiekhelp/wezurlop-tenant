/* Pasek zaznaczania na liście osób (/admin/konta).

   Wszystko tutaj jest WYGODĄ, nie warunkiem działania: bez JavaScriptu zaznaczenie pól wyboru
   i „Wyślij zaproszenia" to zwykły POST formularza, a serwer sprawdza wszystko od nowa
   (CLAUDE.md §6). Ten plik tylko liczy zaznaczone, mówi, ilu brakuje adresu, i otwiera okno
   z wynikiem.

   ⚠ Zero innerHTML — tekst wyłącznie przez textContent (CLAUDE.md §6). */
(function () {
  'use strict';

  var LICZNIK_PUSTY = 'Zaznacz osoby, którym chcesz wysłać zaproszenie.';

  function pola() {
    /* Wyłączone pola (osoba ma już konto) nie są zaznaczalne, więc ich tu nie ma. */
    return Array.prototype.slice.call(document.querySelectorAll('.pick__box[form="konta-partia"]:not(:disabled)'));
  }

  function odmiana(n, jeden, kilka, wiele) {
    if (n === 1) {
      return jeden;
    }
    var reszta100 = n % 100;
    var reszta10 = n % 10;
    if (reszta10 >= 2 && reszta10 <= 4 && (reszta100 < 12 || reszta100 > 14)) {
      return kilka;
    }
    return wiele;
  }

  function odswiez() {
    var licznik = document.querySelector('[data-selectbar-count]');
    var czysc = document.querySelector('[data-selectbar-clear]');
    if (!licznik) {
      return;
    }
    var zaznaczone = pola().filter(function (p) { return p.checked; });
    var bezAdresu = zaznaczone.filter(function (p) { return p.getAttribute('data-ma-adres') === '0'; }).length;

    if (zaznaczone.length === 0) {
      licznik.textContent = LICZNIK_PUSTY;
    } else {
      var tekst = 'Zaznaczono ' + zaznaczone.length + ' '
        + odmiana(zaznaczone.length, 'osobę', 'osoby', 'osób');
      /* ⚠ Liczba „bez adresu" jest tu, a nie w komunikacie po wysyłce: administrator ma wiedzieć
         PRZED kliknięciem, że część zaznaczonych zostanie pominięta. Po wysyłce jest już za późno,
         żeby wpisać adres. */
      if (bezAdresu > 0) {
        tekst += ' — ' + bezAdresu + ' bez adresu e-mail, ' + odmiana(bezAdresu, 'zostanie', 'zostaną', 'zostanie') + ' pominięta';
      }
      licznik.textContent = tekst + '.';
    }
    if (czysc) {
      czysc.hidden = zaznaczone.length === 0;
    }
  }

  document.addEventListener('change', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) {
      return;
    }
    if (target.hasAttribute('data-selectbar-all')) {
      var stan = target.checked;
      pola().forEach(function (p) { p.checked = stan; });
    }
    if (target.classList.contains('pick__box') || target.hasAttribute('data-selectbar-all')) {
      odswiez();
    }
  });

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var czysc = target ? target.closest('[data-selectbar-clear]') : null;
    if (!czysc) {
      return;
    }
    pola().forEach(function (p) { p.checked = false; });
    var wszystkie = document.querySelector('[data-selectbar-all]');
    if (wszystkie) {
      wszystkie.checked = false;
    }
    odswiez();
  });

  document.addEventListener('htmx:afterSwap', function (event) {
    var cel = event.detail && event.detail.target ? event.detail.target : null;
    if (!cel) {
      return;
    }
    /* Okno z wynikiem otwieramy PO podmianie treści, a nie na klik: klik ma wysłać formularz.
       Gdyby okno otwierał `data-modal-open`, wspólna obsługa w ui.js zrobiłaby preventDefault
       i wysyłka nigdy by nie poszła. */
    /* ⚠ Okno POTWIERDZENIA (Z84 cz. C) otwieramy tą samą drogą, co okno wyniku: po podmianie
       treści, nie na klik. Przełącznik dostępu w oknie dociąga tę treść od nowa — wtedy okno
       jest już otwarte i `showModal()` się nie powtarza. */
    if (cel.id === 'zapros-tresc') {
      var oknoZapros = document.getElementById('zapros-modal');
      if (oknoZapros && typeof oknoZapros.showModal === 'function' && !oknoZapros.open) {
        oknoZapros.showModal();
      }
      return;
    }
    if (cel.id === 'wynik-tresc') {
      /* ⚠ Potwierdzenie zamykamy, zanim pokażemy wynik: dwa okna modalne naraz znaczą, że
         po zamknięciu wierzchniego człowiek wraca do listy osób, której już nie wyśle —
         a wygląda to jak niewysłana partia. */
      var doZamkniecia = document.getElementById('zapros-modal');
      if (doZamkniecia && doZamkniecia.open && typeof doZamkniecia.close === 'function') {
        doZamkniecia.close();
      }
      var okno = document.getElementById('wynik-modal');
      if (okno && typeof okno.showModal === 'function' && !okno.open) {
        okno.showModal();
      }
      return;
    }
    /* Każda inna podmiana na tym ekranie to zapis adresu w wierszu — licznik trzeba przeliczyć,
       bo osoba, która właśnie dostała adres, przestała być „bez adresu".
       ⚠ Nie sprawdzamy `tagName === 'TR'`: przy `hx-swap="outerHTML"` podmieniony wiersz jest
       już odczepiony od dokumentu i `detail.target` bywa jego rodzicem. */
    odswiez();
  });

  document.addEventListener('DOMContentLoaded', odswiez);
})();
