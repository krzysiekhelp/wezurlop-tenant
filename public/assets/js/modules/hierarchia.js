/* Hierarchia przełożonych — przeciąganie kart i pasek zmian (zlecenie `hierarchia` §4–§8).
 *
 * ⚠ Przeciąganie NICZEGO nie zapisuje. Ten plik zbiera zmiany do paska; zapis to jeden POST
 * całej partii, wysłany formularzem `#hier-bar`. Rozstrzyga serwer — tutaj są wyłącznie
 * wygoda i podpowiedź (CLAUDE.md §10: interfejs, który „nie pozwala kliknąć", nie jest
 * zabezpieczeniem).
 *
 * ⚠ Bez `innerHTML` — drzewo i pasek budujemy przez `createElement` i `textContent` (§6 reguł).
 * ⚠ Zero zdarzeń w szablonie: wszystko przez `addEventListener`.
 *
 * ⚠ Ta ścieżka jest SKRÓTEM DLA MYSZY, nie jedynym wejściem: przeciąganie nie działa
 * z klawiatury ani przy czytniku ekranu, więc każda karta ma menu `⋯` z tą samą operacją,
 * obsługiwaną po stronie serwera. Gdyby ten plik się nie wczytał, ekran nadal działa.
 */
(function () {
  'use strict';

  var bar = document.getElementById('hier-bar');
  var lista = document.getElementById('hier-bar-list');
  var licznik = document.getElementById('hier-bar-count');
  if (!bar || !lista || !licznik) { return; }

  var zmiany = [];          // {employment, cel, supervisor, opis, wezel, poprzedniRodzic, poprzedniSasiad}
  var ciagniety = null;     // <li class="hier__node"> w trakcie ciągnięcia

  function karta(el) { return el ? el.closest('.hier__card') : null; }
  function wezel(el) { return el ? el.closest('.hier__node') : null; }
  /* ⚠ Karta jest DZIECKIEM węzła, więc szuka się jej w dół, a nie `closest()` w górę.
     Zmierzone: `closest` na węźle zwracał `null`, procedura obsługi upuszczenia wywracała się
     na `getAttribute` i pasek zmian zostawał pusty przy poprawnym zdarzeniu `drop`. */
  function kartaWezla(li) { return li ? li.querySelector(':scope > .hier__card') : null; }

  /* Gałąź dzieci danego węzła — tworzona dopiero, gdy pierwsze dziecko tam trafia. */
  function galaz(li) {
    var ul = li.querySelector(':scope > .hier__branch');
    if (!ul) {
      ul = document.createElement('ul');
      ul.className = 'hier__branch';
      li.appendChild(ul);
    }
    return ul;
  }

  function nazwa(li) {
    var k = kartaWezla(li);
    return k ? (k.getAttribute('data-hier-osoba') || '') : '';
  }

  /* ⚠ Cykl: w trakcie ciągnięcia własny poddrzew jest wygaszony i nie przyjmuje upuszczenia,
     więc pętli nie da się nawet ułożyć. Rozstrzyga i tak zapis po stronie serwera. */
  function wygasPoddrzew(li, wlacz) {
    var karty = li.querySelectorAll('.hier__card');
    for (var i = 0; i < karty.length; i++) {
      karty[i].classList.toggle('hier__card--nieaktywny', wlacz);
    }
  }

  function opis(li, cel, celLi) {
    var kto = nazwa(li);
    if (cel === 'szczyt') { return kto + ' → nie podlega nikomu (szczyt hierarchii)'; }
    if (cel === 'zdejmij') { return kto + ' → zdjęty ze struktury (nie ustawiono)'; }
    return kto + ' → przełożony: ' + nazwa(celLi);
  }

  function przerysujPasek() {
    while (lista.firstChild) { lista.removeChild(lista.firstChild); }
    for (var i = 0; i < zmiany.length; i++) {
      var li = document.createElement('li');
      li.className = 'hier__bar-item';
      li.textContent = zmiany[i].opis;
      lista.appendChild(li);
    }
    // Pola formularza budowane od nowa przy każdej zmianie — jedno źródło prawdy to `zmiany`.
    var stare = bar.querySelectorAll('input[data-hier-pole]');
    for (var s = 0; s < stare.length; s++) { stare[s].remove(); }
    for (var j = 0; j < zmiany.length; j++) {
      dodajPole('zmiany[' + j + '][employment]', zmiany[j].employment);
      dodajPole('zmiany[' + j + '][cel]', zmiany[j].cel);
      if (zmiany[j].cel === 'przelozony') {
        dodajPole('zmiany[' + j + '][supervisor]', zmiany[j].supervisor);
      }
    }
    licznik.textContent = zmiany.length === 1 ? '1 zmiana' : zmiany.length + ' zmian';
    bar.hidden = zmiany.length === 0;
  }

  function dodajPole(nazwaPola, wartosc) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = nazwaPola;
    input.value = String(wartosc);
    input.setAttribute('data-hier-pole', '1');
    bar.appendChild(input);
  }

  /* Plakietka „nie ustawiono" na karcie przestawionej, ale jeszcze niezapisanej. */
  function plakietkaNieustawiony(li, pokaz) {
    var k = kartaWezla(li);
    var chip = k ? k.querySelector('[data-hier-chip="nieustawiony"]') : null;
    if (chip) { chip.hidden = !pokaz; }
  }

  function zapamietaj(li, cel, celLi) {
    var k = kartaWezla(li);
    var kCelu = celLi ? kartaWezla(celLi) : null;
    if (!k) { return; }
    /* ⚠ Zapamiętujemy stan PRZED zmianą, bo cofnięcie musi go przywrócić co do atrybutu —
       inaczej po „Cofnij" pasek liczb policzyłby się z DOM-u już zmienionego. */
    zmiany.push({
      poprzedniPrzelozony: k.getAttribute('data-hier-przelozony') || '',
      poprzedniSzczytAtr: k.getAttribute('data-hier-szczyt') || '0',
      employment: k.getAttribute('data-hier-id'),
      cel: cel,
      supervisor: kCelu ? kCelu.getAttribute('data-hier-id') : '',
      opis: opis(li, cel, celLi),
      wezel: li,
      poprzedniRodzic: li.parentNode,
      poprzedniSasiad: li.nextElementSibling,
      bylNieustawiony: (function () {
        var k = kartaWezla(li);
        var chip = k ? k.querySelector('[data-hier-chip="nieustawiony"]') : null;
        return chip !== null && !chip.hidden;
      })()
    });
    plakietkaNieustawiony(li, cel === 'zdejmij');
    /* ⚠ Atrybuty karty idą za zmianą: od tej chwili DOM mówi prawdę o układzie, a wszystkie
       liczby na ekranie przeliczają się WYŁĄCZNIE z niego. */
    k.setAttribute('data-hier-przelozony', cel === 'przelozony' && kCelu ? kCelu.getAttribute('data-hier-id') : '');
    k.setAttribute('data-hier-szczyt', cel === 'szczyt' ? '1' : '0');
    plakietkaSzczyt(k, cel === 'szczyt');
    przerysujPasek();
    // ⚠ Zdarzenia NIE wysyłamy tutaj. `zapamietaj()` biegnie PRZED przeniesieniem węzła
    // w DOM-ie, więc przeliczenie zobaczyłoby jeszcze stary układ — zmierzone: po pierwszym
    // przeciągnięciu „Nieustawionych" zostawało 32, a plakietka „3 podwładnych" nie drgnęła;
    // każda kolejna zmiana pokazywała stan poprzedniej. To ta sama rodzina co „warstwa
    // wcześniejsza rozstrzyga, więc późniejsza nigdy nie działa". Zdarzenie wysyła ten,
    // kto skończył ruszać DOM-em — patrz `zmiana()` niżej.
  }

  /* Jedno wejście dla wszystkich dróg: wysyłane PO zmianie DOM-u, nie przed. */
  function zmiana() {
    document.dispatchEvent(new CustomEvent('hier:zmiana'));
  }

  /* Plakietka „nie podlega nikomu" — pojawia się i znika razem ze stanem szczytu. */
  function plakietkaSzczyt(k, pokaz) {
    var chip = k.querySelector('[data-hier-chip="szczyt"]');
    if (chip) {
      chip.hidden = !pokaz;
      return;
    }
    if (!pokaz) { return; }
    var nowy = document.createElement('span');
    /* ⚠ Ta sama postać, co w szablonie karty (Z34 §6): zielona plakietka z kropką i słowem
       „szczyt". Gdyby skrypt tworzył ją inaczej, karta przeciągnięta wyglądałaby inaczej niż
       ta sama karta po przeładowaniu — i nikt by nie skojarzył, że to jedno miejsce w JS. */
    nowy.className = 'chip chip--accent';
    nowy.setAttribute('data-hier-chip', 'szczyt');
    var kropka = document.createElement('span');
    kropka.className = 'chip__kropka';
    nowy.appendChild(kropka);
    nowy.appendChild(document.createTextNode('szczyt'));
    var miejsce = k.querySelector('.hier__card-chips');
    if (miejsce) { miejsce.insertBefore(nowy, miejsce.firstChild); }
  }

  /* --- przeciąganie --- */

  document.addEventListener('dragstart', function (e) {
    var li = wezel(e.target);
    if (!li || !karta(e.target)) { return; }
    ciagniety = li;
    li.classList.add('hier__node--ciagniety');
    wygasPoddrzew(li, true);
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); }
  });

  document.addEventListener('dragend', function () {
    if (ciagniety) {
      ciagniety.classList.remove('hier__node--ciagniety');
      wygasPoddrzew(ciagniety, false);
    }
    var cele = document.querySelectorAll('.hier__cel');
    for (var i = 0; i < cele.length; i++) { cele[i].classList.remove('hier__cel'); }
    ciagniety = null;
  });

  function celUpuszczenia(target) {
    if (!ciagniety) { return null; }
    var pasek = target.closest ? target.closest('[data-hier-drop]') : null;
    if (pasek) { return { rodzaj: pasek.getAttribute('data-hier-drop'), el: pasek }; }
    var k = karta(target);
    if (!k || k.classList.contains('hier__card--nieaktywny')) { return null; }
    var li = wezel(k);
    if (li === ciagniety) { return null; }
    return { rodzaj: 'przelozony', el: k, li: li };
  }

  document.addEventListener('dragover', function (e) {
    var cel = celUpuszczenia(e.target);
    if (!cel) { return; }
    e.preventDefault();
    if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
    cel.el.classList.add('hier__cel');
  });

  document.addEventListener('dragleave', function (e) {
    var cel = celUpuszczenia(e.target);
    if (cel) { cel.el.classList.remove('hier__cel'); }
  });

  document.addEventListener('drop', function (e) {
    var cel = celUpuszczenia(e.target);
    if (!cel) { return; }
    e.preventDefault();
    cel.el.classList.remove('hier__cel');
    var li = ciagniety;
    if (cel.rodzaj === 'przelozony') {
      zapamietaj(li, 'przelozony', cel.li);
      galaz(cel.li).appendChild(li);
    } else if (cel.rodzaj === 'szczyt') {
      zapamietaj(li, 'szczyt', null);
      var korzen = document.querySelector('.hier__branch--root');
      if (korzen) { korzen.appendChild(li); }
    } else {
      zapamietaj(li, 'zdejmij', null);
      var panel = document.getElementById('hier-nieustawieni');
      if (panel) { panel.appendChild(li); }
    }
    zmiana();
  });

  /* ⚠ Ostrzeżenie przy opuszczaniu strony z niezapisanymi zmianami. Okno usuwa JEDNĄ drogę
     utraty (kliknięcie „Zmień przełożonego…"), ale zamknięcie karty i kliknięcie w menu boczne
     zostają — a ułożona struktura żyje wyłącznie w przeglądarce do „Zapisz". Bez tego wyjście
     ze strony kasuje pracę po cichu. */
  /* ⚠ **Ostrzegamy przy WYJŚCIU, nigdy przy ZAPISIE.** Pierwsza wersja nie miała tego
     rozróżnienia i ostrzeżenie strzelało na własnym zapisie aplikacji: „Zapisz zmiany" to
     `submit` zwykłego formularza, czyli prawdziwa nawigacja, więc przeglądarka pytała „czy
     chcesz opuścić stronę" — a kto kliknął „Zostań", **tracił zapis, robiąc dokładnie to,
     o co ekran prosił.** Usterka blokująca podstawową operację ekranu, zgłoszona przez
     Krzysztofa 22.09.2026.
     ⚠ Flagi nie zerujemy: po zapisie idzie przekierowanie i strona ładuje się od nowa.
     ⚠ Rozbrajamy WYŁĄCZNIE zapis paska. Menu boczne, przełącznik spółek, „Wróć do struktury"
     i zamknięcie karty **nadal ostrzegają** — tam zmiana strony naprawdę kasuje ułożoną
     strukturę. */
  var trwaZapis = false;
  var pasek = document.getElementById('hier-bar');
  if (pasek) {
    pasek.addEventListener('submit', function () { trwaZapis = true; });
  }

  window.addEventListener('beforeunload', function (e) {
    if (trwaZapis || zmiany.length === 0) { return undefined; }
    e.preventDefault();
    // Treść komunikatu jest dziś narzucona przez przeglądarki; liczy się samo zapytanie.
    e.returnValue = '';

    return '';
  });

  /* Wybór z okna „Zmień przełożonego" — TA SAMA ścieżka co upuszczenie karty.
     ⚠ Zdarzenie, nie wołanie wprost: okno mieszka w innym module, a pasek zmian ma jednego
     właściciela. Bez tego dwa miejsca dokładałyby do tej samej tablicy i cofnięcie przestałoby
     odtwarzać kolejność. */
  document.addEventListener('hier:wybor', function (e) {
    var d = e.detail || {};
    var karta = document.querySelector('.hier__card[data-hier-id="' + d.employment + '"]');
    var kartaCelu = d.supervisor
      ? document.querySelector('.hier__card[data-hier-id="' + d.supervisor + '"]')
      : null;
    if (!karta || (d.cel === 'przelozony' && !kartaCelu)) { return; }
    var li = wezel(karta);
    var liCelu = kartaCelu ? wezel(kartaCelu) : null;
    zapamietaj(li, d.cel, liCelu);
    if (d.cel === 'przelozony' && liCelu) {
      galaz(liCelu).appendChild(li);
    } else if (d.cel === 'szczyt') {
      var korzenWyboru = document.querySelector('.hier__branch--root');
      if (korzenWyboru) { korzenWyboru.appendChild(li); }
    } else {
      var panelWyboru = document.getElementById('hier-nieustawieni');
      if (panelWyboru) { panelWyboru.appendChild(li); }
    }
    zmiana();
  });

  /* --- cofnięcie i odrzucenie: przywracamy DOM tam, gdzie był --- */

  function przywroc(z) {
    plakietkaNieustawiony(z.wezel, z.bylNieustawiony);
    var k = kartaWezla(z.wezel);
    if (k) {
      k.setAttribute('data-hier-przelozony', z.poprzedniPrzelozony);
      k.setAttribute('data-hier-szczyt', z.poprzedniSzczytAtr);
      plakietkaSzczyt(k, z.poprzedniSzczytAtr === '1');
    }
    if (z.poprzedniSasiad && z.poprzedniSasiad.parentNode === z.poprzedniRodzic) {
      z.poprzedniRodzic.insertBefore(z.wezel, z.poprzedniSasiad);
    } else {
      z.poprzedniRodzic.appendChild(z.wezel);
    }
  }

  var undo = document.getElementById('hier-undo');
  if (undo) {
    undo.addEventListener('click', function () {
      var z = zmiany.pop();
      if (z) { przywroc(z); przerysujPasek(); zmiana(); }
    });
  }

  var discard = document.getElementById('hier-discard');
  if (discard) {
    discard.addEventListener('click', function () {
      while (zmiany.length > 0) { przywroc(zmiany.pop()); }
      przerysujPasek();
      zmiana();
    });
  }
})();

/* Wyszukiwanie w drzewie i w panelu, zwijanie gałęzi (zlecenie `domkniecie-hierarchii` §4).
 *
 * ⚠ Filtr ZAWĘŻA to, co ktoś i tak widzi, i nigdy tego nie poszerza — filtr nie jest
 * uprawnieniem (reguła z `etap3` §8a). Tu chowa węzły w DOM-ie; serwer wysłał dokładnie to,
 * co wolno zobaczyć. Ta sama kontrolka pojedzie potem do kalendarza, więc zasada ma tu zostać.
 *
 * ⚠ Filtr MÓWI, ILU UKRYŁ. Panel „Nieustawieni" pusty z powodu filtra i pusty, bo robota
 * skończona, to dwa różne stany — a to jedyny licznik na tym ekranie, który jest listą zadań.
 *
 * ⚠ Dopasowana karta zostaje widoczna RAZEM ZE SWOJĄ ŚCIEŻKĄ W GÓRĘ. Bez tego znaleziona
 * osoba traci kontekst: widać nazwisko i nie widać, komu podlega, czyli to, po co się szukało.
 *
 * ⚠ Bez tego pliku ekran działa dalej — kontrolki są `hidden` w szablonie i włącza je ten kod.
 */
(function () {
  'use strict';

  function karty(korzen) { return Array.prototype.slice.call(korzen.querySelectorAll('.hier__card')); }
  function wezelKarty(k) { return k.closest('.hier__node'); }

  function komunikatUkrytych(id, ukryte, razem, fraza) {
    var cele = document.querySelectorAll('[data-hier-ukryte="' + id + '"]');
    for (var i = 0; i < cele.length; i++) {
      var el = cele[i];
      if (fraza === '') {
        el.textContent = '';
        el.hidden = true;
        continue;
      }
      el.hidden = false;
      el.textContent = ukryte === 0
        ? 'Filtr „' + fraza + '": widać wszystkie ' + razem
        : 'Filtr „' + fraza + '" ukrył ' + ukryte + ' z ' + razem + ' — to nie jest pusta lista zadań.';
    }
  }

  function filtruj(korzen, fraza, id) {
    var szukane = fraza.trim().toLowerCase();
    var wszystkie = karty(korzen);
    var pokazane = {};
    for (var i = 0; i < wszystkie.length; i++) {
      var k = wszystkie[i];
      var osoba = (k.getAttribute('data-hier-osoba') || '').toLowerCase();
      if (szukane !== '' && osoba.indexOf(szukane) === -1) { continue; }
      // Dopasowanie pokazuje też CAŁĄ ŚCIEŻKĘ W GÓRĘ — inaczej znaleziona osoba traci kontekst.
      var wezel = wezelKarty(k);
      while (wezel && korzen.contains(wezel)) {
        pokazane[wezel.getAttribute('data-hier-wezel')] = true;
        wezel = wezel.parentElement ? wezel.parentElement.closest('.hier__node') : null;
      }
    }
    var ukryte = 0;
    var wezly = korzen.querySelectorAll('.hier__node');
    for (var j = 0; j < wezly.length; j++) {
      var w = wezly[j];
      var widoczny = szukane === '' || pokazane[w.getAttribute('data-hier-wezel')] === true;
      w.hidden = !widoczny;
      if (!widoczny && w.parentElement && !w.parentElement.closest('[hidden]')) { ukryte++; }
    }
    komunikatUkrytych(id, ukryte, wszystkie.length, szukane === '' ? '' : fraza.trim());
  }

  /* Identyfikator węzła nadany raz — filtr operuje na nim, nie na pozycji w drzewie. */
  var wezly = document.querySelectorAll('.hier__node');
  for (var n = 0; n < wezly.length; n++) {
    wezly[n].setAttribute('data-hier-wezel', 'w' + n);
  }

  var pola = document.querySelectorAll('[data-hier-filtr]');
  for (var f = 0; f < pola.length; f++) {
    (function (pole) {
      var id = pole.getAttribute('data-hier-filtr');
      var korzen = document.getElementById(id);
      if (!korzen) { return; }
      var kontener = pole.closest('#hier-filtr-panel') || pole.closest('#hier-narzedzia');
      if (kontener) { kontener.hidden = false; }
      pole.addEventListener('input', function () { filtruj(korzen, pole.value, id); });
    })(pola[f]);
  }

  /* --- wybór z okna „Zmień przełożonego" ---
     ⚠ Ta sama ścieżka co przeciągnięcie: zmiana ląduje w pasku, nie w żądaniu. Przechwytujemy
     WYŁĄCZNIE formularze w oknie (`#hier-modal`) — ten sam fragment otwarty jako osobna strona
     nie ma paska zmian, więc tam formularz ma zapisać od razu, jak dotąd. */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest ? e.target.closest('[data-hier-wybor]') : null;
    if (!form || !form.closest('#hier-modal')) { return; }
    var okno = form.closest('#hier-modal');
    e.preventDefault();
    /* ⚠ Tylko ZGŁOSZENIE wyboru. Pasek zmian ma jednego właściciela (moduł przeciągania),
       bo inaczej dwa miejsca dokładałyby zmiany do tej samej tablicy — a cofnięcie musi
       przywrócić DOM dokładnie w odwrotnej kolejności. */
    document.dispatchEvent(new CustomEvent('hier:wybor', {detail: {
      employment: form.getAttribute('data-hier-osoba-id'),
      cel: form.getAttribute('data-hier-cel') || 'przelozony',
      supervisor: form.getAttribute('data-hier-cel-id')
    }}));
    if (okno && typeof okno.close === 'function') { okno.close(); }
  });

  /* --- zwijanie wszystkiego ---
     ⚠ Uchwyty pojedynczych węzłów NALEŻĄ DO PRZELICZANIA (niżej, `hier:zmiana`), bo muszą
     powstawać i znikać razem ze zmianą struktury. Tutaj zostaje tylko „zwiń/rozwiń wszystko",
     które pyta DOM w chwili kliknięcia — lista zebrana raz przy starcie rozjechałaby się
     po pierwszym przeciągnięciu (dokładnie ta usterka, którą naprawiamy w tej rundzie). */

  function wszystkie(zwin) {
    var galezie = document.querySelectorAll('#hier-drzewo .hier__node > .hier__branch');
    for (var i = 0; i < galezie.length; i++) {
      var li = galezie[i].parentElement;
      var przycisk = li.querySelector(':scope > .hier__zwin');
      galezie[i].hidden = zwin;
      li.setAttribute('data-hier-zwiniety', zwin ? '1' : '0');
      if (przycisk) {
        przycisk.textContent = zwin ? '+' : '−';
        przycisk.setAttribute('aria-expanded', zwin ? 'false' : 'true');
      }
    }
  }

  var zwin = document.getElementById('hier-zwin');
  if (zwin) { zwin.addEventListener('click', function () { wszystkie(true); }); }
  var rozwin = document.getElementById('hier-rozwin');
  if (rozwin) { rozwin.addEventListener('click', function () { wszystkie(false); }); }
})();

/* Przeliczenie ekranu Z DOM-u po każdej zmianie struktury (zlecenie `runda-wizualna` §2.4).
 *
 * ⚠ Powód jest zmierzony i to najgorszy z tej rundy: **pasek liczb nie zmieniał się wcale.**
 * Można było przenieść trzydzieści osób i nadal widzieć „Nieustawionych 32" — a to **jedyny
 * licznik na tym ekranie, który jest listą zadań**. Ekran kłamał o postępie własnej pracy:
 * nie dawało się poznać, kiedy robota jest skończona, bez zapisania i przeładowania.
 * Do tego uchwyt zwijania nie powstawał u nowego przełożonego, zostawał nad pustą gałęzią
 * u byłego, a plakietka „3 podwładnych" wisiała przy zerze podwładnych.
 *
 * ⚠ Liczymy **z DOM-u**, nie z tablicy zmian: DOM jest tym, co widzi człowiek, a tablica
 * zmian mówi tylko, co się wydarzyło. Przy cofnięciu i „Odrzuć wszystkie" te dwa obrazy
 * musiałyby być uzgadniane osobno — czyli drugie miejsce, które zostaje w tyle.
 */
(function () {
  'use strict';

  var drzewo = document.getElementById('hier-drzewo');
  var panel = document.getElementById('hier-nieustawieni');
  if (!drzewo) { return; }

  function karta(li) { return li.querySelector(':scope > .hier__card'); }
  function dzieci(li) {
    var ul = li.querySelector(':scope > .hier__branch');
    return ul ? Array.prototype.slice.call(ul.querySelectorAll(':scope > .hier__node')) : [];
  }
  function zespol(li) { return li.querySelectorAll('.hier__node').length; }

  /* Uchwyt: powstaje przy pierwszym dziecku, znika z ostatnim. */
  function uchwyt(li, potrzebny) {
    var jest = li.querySelector(':scope > .hier__zwin');
    if (potrzebny && !jest) {
      var przycisk = document.createElement('button');
      przycisk.type = 'button';
      przycisk.className = 'hier__zwin';
      przycisk.setAttribute('aria-expanded', 'true');
      przycisk.title = 'Zwiń albo rozwiń podwładnych';
      przycisk.textContent = '−';
      przycisk.addEventListener('click', function () {
        var ul = li.querySelector(':scope > .hier__branch');
        if (!ul) { return; }
        var zwin = !ul.hidden;
        ul.hidden = zwin;
        przycisk.textContent = zwin ? '+' : '−';
        przycisk.setAttribute('aria-expanded', zwin ? 'false' : 'true');
        li.setAttribute('data-hier-zwiniety', zwin ? '1' : '0');
      });
      li.insertBefore(przycisk, li.firstChild);
    } else if (!potrzebny && jest) {
      jest.remove();
      li.removeAttribute('data-hier-zwiniety');
    }
  }

  /* Plakietka liczby podwładnych — albo aktualna treść, albo jej brak. */
  function plakietkaPodwladnych(k, ile, wZespole) {
    var chip = k.querySelector('[data-hier-chip="podwladnych"]');
    if (ile === 0) {
      if (chip) { chip.remove(); }
      return;
    }
    if (!chip) {
      chip = document.createElement('span');
      /* Błękitna plakietka bez kropki — tak samo, jak renderuje ją szablon (Z34 §6). */
      chip.className = 'chip chip--dzis';
      chip.setAttribute('data-hier-chip', 'podwladnych');
      var miejsce = k.querySelector('.hier__card-chips');
      if (!miejsce) { return; }
      miejsce.insertBefore(chip, miejsce.firstChild);
    }
    chip.textContent = ile + (ile === 1 ? ' podwładny' : ' podwładnych')
      + (wZespole > ile ? ' · zespół ' + wZespole : '');
  }

  function ustawLiczbe(klucz, wartosc, odchylenie) {
    var el = document.querySelector('[data-hier-liczba="' + klucz + '"]');
    if (!el) { return; }
    el.textContent = String(wartosc);
    /* ⚠ Od Z34 liczby stoją w kaflach (`.kafel__wartosc`), nie w pasku `.stats` — klasa
       odchylenia zmieniła nazwę razem z komponentem. Mechanizm ten sam: liczba przelicza się
       z DOM-u po każdym przeciągnięciu, bez przeładowania ekranu. */
    el.classList.toggle('kafel__wartosc--odchylenie', odchylenie === true && wartosc > 0);
  }

  function przelicz() {
    var wezly = Array.prototype.slice.call(drzewo.querySelectorAll('.hier__node'));
    var najglebszy = 0;
    var uwagi = 0;
    var szczytow = 0;

    for (var i = 0; i < wezly.length; i++) {
      var li = wezly[i];
      var k = karta(li);
      if (!k) { continue; }
      var ile = dzieci(li).length;
      var w = zespol(li) - 1;
      k.setAttribute('data-hier-podwladnych', String(ile));
      k.setAttribute('data-hier-zespol', String(w));
      uchwyt(li, ile > 0);
      plakietkaPodwladnych(k, ile, w);
      // ⚠ „Wymaga uwagi" liczy TYLKO tych, którzy MAJĄ podwładnych — przełożony bez zespołu
      // nie blokuje niczyjego wniosku, więc nie jest odchyleniem.
      if (ile > 0 && (k.getAttribute('data-hier-zablokowane') === '1'
          || k.getAttribute('data-hier-brak-roli') === '1')) {
        uwagi++;
      }
      // Głębokość: ile węzłów nad tym, licząc od korzenia drzewa.
      var poziom = 1;
      var rodzic = li.parentElement ? li.parentElement.closest('.hier__node') : null;
      while (rodzic && poziom < 64) { poziom++; rodzic = rodzic.parentElement.closest('.hier__node'); }
      najglebszy = Math.max(najglebszy, poziom);
      if (k.getAttribute('data-hier-szczyt') === '1') { szczytow++; }
      k.classList.toggle('hier__card--uwaga',
        k.getAttribute('data-hier-zablokowane') === '1' || k.getAttribute('data-hier-brak-roli') === '1');
    }

    // ⚠ „Nieustawieni" to panel PLUS korzenie drzewa bez jawnej decyzji o szczycie: osoba
    // z zespołem, ale bez własnego przełożonego, stoi w drzewie i nadal jest robotą do zrobienia.
    var nieustawionych = panel ? panel.querySelectorAll(':scope > .hier__node').length : 0;
    var korzen = drzewo.querySelector('.hier__branch--root');
    if (korzen) {
      var korzenie = korzen.querySelectorAll(':scope > .hier__node');
      for (var j = 0; j < korzenie.length; j++) {
        var kk = karta(korzenie[j]);
        if (kk && kk.getAttribute('data-hier-szczyt') !== '1') { nieustawionych++; }
      }
    }

    ustawLiczbe('nieustawionych', nieustawionych, true);
    ustawLiczbe('szczytow', szczytow, false);
    ustawLiczbe('najglebszy', najglebszy, false);
    ustawLiczbe('uwagi', uwagi, true);

    var licznikPanelu = document.querySelector('.hier__panel .card__meta');
    var zatrudnionych = document.querySelectorAll('.hier__card').length;
    if (licznikPanelu) { licznikPanelu.textContent = nieustawionych + ' z ' + zatrudnionych; }
  }

  // Jedno zdarzenie dla wszystkich dróg zmiany: upuszczenie, cofnięcie, odrzucenie, menu.
  document.addEventListener('hier:zmiana', przelicz);
  przelicz();
})();
