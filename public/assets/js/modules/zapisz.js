/* Przyciski „Zapisz" czynne tylko wtedy, gdy jest co zapisać
   (zlecenie `ostatni-administrator-i-przyciski-zapisz` §3).

   ⚠ Przycisk, który nic nie robi, uczy, że przyciski nic nie robią. Po kilku takich kliknięciach
   administrator przestaje wierzyć, że „Zapisz" cokolwiek zapisało — i wtedy klika dwa razy tam,
   gdzie to ma znaczenie.

   ⚠ To jest WYGODA, nie zabezpieczenie: bez JavaScriptu przyciski są czynne jak dotąd, a serwer
   zachowuje się identycznie. Po stronie serwera nic się nie zmienia.

   ⚠ Wartość wczytana czytamy z `defaultValue` (czyli z atrybutu `value` w HTML), a nie
   z własnej zmiennej. Dwa powody: nie trzymamy stanu obok dokumentu, a po podmianie fragmentu
   przez htmx nowy HTML PRZYNOSI nową wartość wczytaną — więc stan liczy się od nowa sam,
   bez pamiętania czegokolwiek między żądaniami. */
(function () {
  'use strict';

  /* Zakres przycisku: formularz albo kontrolka adresu w wierszu listy. Przycisk wskazuje
     zakres sam (`data-zapisz`), więc nie zgadujemy — nie ruszamy „Filtruj" ani „Wyślij
     zaproszenia", które nie są zapisem pola. */
  function zakres(przycisk) {
    if (przycisk.getAttribute('form')) {
      return document.getElementById(przycisk.getAttribute('form'));
    }
    return przycisk.closest('form') || przycisk.closest('.pick__adres');
  }

  function pola(obszar) {
    if (!obszar) {
      return [];
    }
    return Array.prototype.slice
      .call(obszar.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]'))
      .filter(function (p) { return !p.disabled; });
  }

  function zmienione(obszar) {
    return pola(obszar).some(function (p) { return p.value !== p.defaultValue; });
  }

  function odswiez(przycisk) {
    var obszar = zakres(przycisk);
    var lista = pola(obszar);
    /* Brak pól do porównania (albo wszystkie wyłączone) — przycisku nie ruszamy, bo nie mamy
       na czym oprzeć decyzji. Wyłączenie „na wszelki wypadek" zablokowałoby działający zapis. */
    if (lista.length === 0) {
      return;
    }
    przycisk.disabled = !zmienione(obszar);
  }

  function odswiezWszystkie() {
    Array.prototype.slice.call(document.querySelectorAll('[data-zapisz]')).forEach(odswiez);
  }

  document.addEventListener('input', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (target) {
      odswiezWszystkie();
    }
  });
  document.addEventListener('change', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (target) {
      odswiezWszystkie();
    }
  });

  /* ⚠ Enter w polu robi to samo co przycisk i podlega temu samemu warunkowi.
     Dla przycisku `type="submit"` załatwia to przeglądarka (wyłączony przycisk domyślny =
     brak wysłania). Dla przycisku `type="button"` z `hx-post` — jak w wierszu listy — Enter
     nie robi NIC, więc musimy go obsłużyć sami. */
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') {
      return;
    }
    var target = event.target instanceof Element ? event.target : null;
    if (!target || target.tagName !== 'INPUT') {
      return;
    }
    var obszar = target.closest('.pick__adres');
    var przycisk = obszar ? obszar.querySelector('[data-zapisz]') : null;
    if (!przycisk || przycisk.type === 'submit') {
      return;
    }
    event.preventDefault();
    if (!przycisk.disabled) {
      przycisk.click();
    }
  });

  /* ⚠ Po odpowiedzi htmx stan liczy się OD NOWA: podmieniony wiersz przychodzi z serwera
     z nową wartością wczytaną, więc jego przycisk ma być wyłączony. To jest dokładnie miejsce,
     w którym takie rzeczy się psują — jednorazowa inicjalizacja przy starcie strony by nie
     wystarczyła. */
  document.addEventListener('htmx:afterSwap', odswiezWszystkie);
  document.addEventListener('DOMContentLoaded', odswiezWszystkie);
})();
