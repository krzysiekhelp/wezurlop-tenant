/* Przyklejony wiersz dni osi czasu — jedna zmierzona liczba dla arkusza (zlecenie Z24).

   ⚠ **Co tu się liczy i dlaczego nie da się tego zapisać w CSS:** wiersz dni ma się zatrzymywać
   DOKŁADNIE pod przyklejonym blokiem nagłówka kalendarza, więc jego `top` to „luka pod belką
   plus wysokość tego bloku plus odstęp bloków plus krawędzie". Wysokości bloku nagłówka arkusz
   nie zna: stoją w nim nazwa okresu, legenda, strzałki, kontrolka wyboru osób i dwa przełączniki,
   a przy węższym oknie legenda i przełączniki się zawijają. CSS nie ma wyrażenia „wysokość
   rodzeństwa", a `@container` mierzy szerokość, nie wysokość.

   ⚠ **Mierzymy jedną rzecz: odległość górnej krawędzi BLOKU Z SIATKĄ od góry `.bloki`** — i to
   celowo nie jest „wysokość karty nagłówka". Ta odległość zawiera wysokość bloku nagłówka
   i odstęp między blokami, czyli wszystko, co stoi między nimi, bez wypisywania tych
   składników w kodzie. Krawędzi bloku już **nie** doliczamy: pas tła pod kartą kończy się
   dokładnie tam, gdzie zaczyna się ten blok, a wiersz dni przykleja się do tej samej linii.
   ⚠ Oba pudełka są `static` (`.bloki` i blok z siatką),
   więc pomiar jest niezależny od przewinięcia strony i od tego, czy karta nagłówka jest
   w danym momencie przyklejona. Odległość od samej karty byłaby błędna o jej przesunięcie.

   ⚠ **To jest WYGODA, nie warunek działania** (CLAUDE.md §6, ta sama granica co
   w `kalendarz-wybor.js`). Bez JavaScriptu `--kal-dol-naglowka` nie istnieje, `top` wychodzi
   `auto` i wiersz dni po prostu się nie przykleja — dane, nawigacja i przyklejony blok
   nagłówka (czysty CSS) działają bez zmian.

   ⚠ **Przeliczenie po `htmx:afterSwap` jest wymaganiem, nie ostrożnością:** karta kalendarza
   jest wymieniana przy każdej zmianie miesiąca, widoku i spółki, a nowy `.bloki` przychodzi
   z serwera bez atrybutu `style`, czyli bez zmierzonej wartości. Ta sama lekcja, co w prototypie
   nadzoru kontrolki wyboru osób, który po pierwszym przełączeniu spółki znikał bez błędu.

   ⚠ Zero innerHTML, zero eval — ten plik ustawia jedną własność CSS i nic więcej. */
(function () {
  'use strict';

  function dopasuj(bloki) {
    var siatka = bloki.querySelector('.team-grid');
    var blok = siatka ? siatka.closest('.card') : null;
    if (!blok) { return; }
    var odstep = blok.getBoundingClientRect().top - bloki.getBoundingClientRect().top;
    /* Arytmetyka zostaje w arkuszu: stąd idzie wyłącznie zmierzony odstęp. */
    bloki.style.setProperty(
      '--kal-dol-naglowka',
      'calc(var(--przyklej-pod-belka) + ' + Math.round(odstep) + 'px)'
    );
  }

  var czeka = false;

  function przelicz() {
    if (czeka) { return; }
    czeka = true;
    window.requestAnimationFrame(function () {
      czeka = false;
      Array.prototype.forEach.call(document.querySelectorAll('.bloki'), dopasuj);
    });
  }

  przelicz();
  window.addEventListener('resize', przelicz);
  document.addEventListener('htmx:afterSwap', przelicz);
  /* Wymiana kroju pisma przesuwa wszystko, co stoi nad siatką — bez tego pierwsze wejście
     na ekran bywa policzone na kroju zapasowym. */
  if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
    document.fonts.ready.then(przelicz);
  }
})();
