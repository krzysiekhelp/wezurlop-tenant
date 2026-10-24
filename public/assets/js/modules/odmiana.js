/* Odmiana rzeczownika przez liczbę — **wybór formy, nigdy same słowa** (zlecenie Z80 D pkt 5).

   ⚠ Ten plik NIE ZNA ani jednego polskiego słowa i nie ma prawa poznać. Słowa składa
   `Support\Odmiana` w PHP i przysyła je atrybutem `data-formy-*` (np. `osoba|osoby|osób`);
   tutaj zostaje sama reguła „która z trzech form przy tej liczbie". Dlatego poprawka brzmienia
   („pracownik" zamiast „osoba") jest zmianą w jednym pliku PHP, a nie w PHP i w dwóch skryptach.

   ⚠ **Wyciągnięte z `modules/widocznosc.js`, a nie dopisane obok.** Ta sama funkcja była
   potrzebna licznikowi paska zmian w Hierarchii, który do 24.10.2026 pisał „2 zmian" i „3 zmian"
   — czyli formę poprawną wyłącznie od pięciu. Druga kopia reguły rozjechałaby się przy pierwszej
   poprawce wyjątku nastu, a ten wyjątek jest tu całym sednem.

   ⚠ Reguła musi być TA SAMA, co w `Odmiana::mnoga()` w PHP: 1 → pierwsza forma, 2–4 → druga,
   5 i więcej → trzecia, z wyjątkiem 12–14 (inaczej wychodzi „13 zmiany").

   Globalna nazwa jak przy `window.WezurlopFlash` — bez narzędzia budującego nie ma innej drogi
   współdzielenia funkcji między plikami modułów. */
(function () {
  'use strict';

  /* `formy` to tablica trzech napisów Z SERWERA; brak któregokolwiek daje pusty napis,
     a nie wyjątek — licznik ma dalej pokazywać liczbę, nawet gdy atrybut jest niepełny. */
  function forma(ile, formy) {
    var lista = formy || [];
    var n = Math.abs(ile);
    var ostatnia = n % 10;
    var dwie = n % 100;
    if (n === 1) { return lista[0] || ''; }
    if (ostatnia >= 2 && ostatnia <= 4 && (dwie < 12 || dwie > 14)) { return lista[1] || ''; }

    return lista[2] || '';
  }

  /* Formy z atrybutu `data-formy-…`, rozdzielone kreską pionową. Jedno miejsce na ten format,
     bo zapisuje go PHP, a czytają dwa skrypty. */
  function zAtrybutu(el, nazwa) {
    return ((el && el.getAttribute(nazwa)) || '||').split('|');
  }

  window.WezurlopOdmiana = { forma: forma, zAtrybutu: zAtrybutu };
})();
