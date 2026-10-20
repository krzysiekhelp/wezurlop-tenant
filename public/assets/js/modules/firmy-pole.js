/* Pole wyboru firm w panelu osoby (`partials/pole-firm.twig`, zlecenie Z58 §3).

   ⚠ **Rozwijanie NIE JEST tu robotą JavaScriptu** — robi to `<details>`, natywnie, więc lista
   otwiera się także bez skryptów i nie ma prawa wyjść poza panel. Ten plik dokłada trzy rzeczy,
   których sam HTML nie potrafi, i **każda z nich jest wygodą**:
     1. napis zamkniętego pola i licznik „N z M" przeliczane od razu po zaznaczeniu,
     2. szukanie po nazwie firmy — po stronie przeglądarki, bo lista firm jest krótka
        (kilka pozycji) i serwer nie ma tu czego filtrować,
     3. „Zaznacz wszystkie" dla firm, w których osoba ma etat.

   ⚠ **Wyszukiwarka i „Zaznacz wszystkie" przychodzą z HTML-a UKRYTE** (`hidden`) i odsłania je
   ten plik. Bez niego byłyby atrapami: pole, które nie szuka, i przycisk, który nic nie robi,
   uczą, że kontrolki nie działają. Sama lista, zaznaczanie i zapis działają bez skryptu.

   ⚠ **Słowa przychodzą z serwera** (`data-formy-innych` z `Support\Odmiana`) — w tym pliku
   zostaje sam wybór formy po liczbie. Polska odmiana ma jedno miejsce, nie dwa.

   ⚠ Zero innerHTML: napisy przez `textContent`, stan przez klasy i właściwość `checked`
   (CLAUDE.md §6). Skutek licencyjny liczy ten sam wzór, co kontrolka na liście — obie biorą
   liczby wyjściowe z atrybutów `data-*`, żadna ich nie wymyśla. */
(function () {
  'use strict';

  var POZ = '[data-pole-firm-poz]';

  function forma(ile, formy) {
    var ostatnia = Math.abs(ile) % 10;
    var dwie = Math.abs(ile) % 100;
    if (Math.abs(ile) === 1) { return formy[0]; }
    return (ostatnia >= 2 && ostatnia <= 4 && (dwie < 12 || dwie > 14)) ? formy[1] : formy[2];
  }

  function pola(form) {
    return Array.prototype.slice.call(form.querySelectorAll('input[name="spolki[]"]'));
  }

  function zaznaczone(form) {
    return pola(form).filter(function (p) { return p.checked; });
  }

  /* Napis zamkniętego pola: dwie nazwy, potem „i N innych"; wszystkie — „Wszystkie firmy". */
  function przepiszNapis(form) {
    var cel = form.querySelector('.pf__nazwy');
    var licznik = form.querySelector('.pf__licznik');
    if (!cel) { return; }
    var formy = (form.getAttribute('data-formy-innych') || '||').split('|');
    var razem = parseInt(form.getAttribute('data-firm-razem'), 10) || pola(form).length;
    var wybrane = zaznaczone(form).map(function (p) {
      var nazwa = p.closest(POZ);
      nazwa = nazwa ? nazwa.querySelector('.pf__nazwa') : null;
      return nazwa ? nazwa.textContent.trim() : '';
    });
    var tekst;
    if (wybrane.length === 0) {
      tekst = 'brak dostępu';
    } else if (wybrane.length === razem) {
      tekst = 'Wszystkie firmy';
    } else if (wybrane.length > 2) {
      var reszta = wybrane.length - 2;
      tekst = wybrane.slice(0, 2).join(', ') + ' i ' + reszta + ' ' + forma(reszta, formy);
    } else {
      tekst = wybrane.join(', ');
    }
    cel.textContent = tekst;
    cel.classList.toggle('pf__nazwy--brak', wybrane.length === 0);
    if (licznik) {
      licznik.textContent = wybrane.length + ' z ' + razem;
    }
  }

  /* Skutek licencyjny PRZED zapisem — ten sam wzór, co w kontrolce na liście osób. */
  function przepiszLicencje(form) {
    var cel = form.querySelector('[data-licencja-tekst]');
    if (!cel) { return; }
    var uzyte = parseInt(form.getAttribute('data-licencja-uzyte'), 10);
    var teraz = parseInt(form.getAttribute('data-dostepy-teraz'), 10);
    var limitTekst = form.getAttribute('data-licencja-limit');
    var limit = limitTekst === '' ? null : parseInt(limitTekst, 10);
    if (isNaN(uzyte) || isNaN(teraz)) { return; }
    var roznica = zaznaczone(form).length - teraz;
    var po = uzyte + roznica;
    var tekst;
    if (roznica === 0) {
      tekst = limit === null ? 'Zajęte dostępy: ' + uzyte + ' (licencja bez limitu).'
        : 'Zajęte dostępy: ' + uzyte + ' z ' + limit + '.';
    } else {
      var kierunek = roznica > 0 ? 'Zajmie ' + roznica + ' więcej' : 'Zwolni ' + (-roznica);
      tekst = limit === null ? kierunek + ': ' + po + ' (licencja bez limitu).'
        : kierunek + ': ' + po + ' z ' + limit + '.';
    }
    /* ⚠ Przekroczenie limitu POKAZUJEMY, ale nie blokujemy przycisku: decyzję podejmuje serwer
       w transakcji. Wyłączony przycisk udawałby zabezpieczenie (CLAUDE.md §10). */
    var ponad = limit !== null && po > limit;
    if (ponad) {
      tekst += ' Przekracza limit — serwer odrzuci zapis.';
    }
    cel.textContent = tekst;
    cel.classList.toggle('pf__licencja--ponad', ponad);
  }

  function filtruj(form) {
    var pole = form.querySelector('.pf__szukanie-pole');
    var szukane = pole ? pole.value.trim().toLowerCase() : '';
    var widocznych = 0;
    Array.prototype.forEach.call(form.querySelectorAll(POZ), function (poz) {
      var pasuje = szukane === '' || (poz.getAttribute('data-nazwa') || '').indexOf(szukane) !== -1;
      poz.hidden = !pasuje;
      widocznych += pasuje ? 1 : 0;
    });
    /* ⚠ „Zaznacz wszystkie" znika przy wpisanym tekście: zaznaczałoby wtedy także firmy
       odfiltrowane, czyli coś innego, niż widać na ekranie (§3 zlecenia). */
    var wszystkie = form.querySelector('[data-pole-firm-wszystkie]');
    if (wszystkie) {
      wszystkie.hidden = szukane !== '';
    }
    var pusto = form.querySelector('[data-pole-firm-pusto]');
    if (pusto) {
      pusto.hidden = widocznych > 0;
    }
  }

  /* „Zaznacz wszystkie" obejmuje tylko pozycje, które WOLNO zaznaczyć (etat w firmie) —
     wyłączone pola zostawiamy w spokoju, bo serwer i tak by je odrzucił. */
  function zaznaczWszystkie(form, czy) {
    pola(form).forEach(function (p) {
      if (!p.disabled) { p.checked = czy; }
    });
  }

  function odswiez(form) {
    przepiszNapis(form);
    przepiszLicencje(form);
    var wszystkie = form.querySelector('[data-pole-firm-wszystkie] input');
    if (wszystkie) {
      var czynne = pola(form).filter(function (p) { return !p.disabled; });
      wszystkie.checked = czynne.length > 0 && czynne.every(function (p) { return p.checked; });
    }
  }

  /* Odsłonięcie kontrolek, które mają sens tylko ze skryptem. Wołane po każdej podmianie
     fragmentu, bo panel przychodzi z serwera od nowa (htmx). */
  function przygotuj(korzen) {
    var zakres = korzen && korzen.querySelectorAll ? korzen : document;
    Array.prototype.forEach.call(zakres.querySelectorAll('.pf'), function (form) {
      ['[data-pole-firm-szukanie]', '[data-pole-firm-wszystkie]'].forEach(function (sel) {
        var el = form.querySelector(sel);
        if (el) { el.hidden = false; }
      });
      odswiez(form);
    });
  }

  document.addEventListener('DOMContentLoaded', function () { przygotuj(document); });
  document.addEventListener('htmx:afterSwap', function (event) { przygotuj(event.target || document); });

  document.addEventListener('change', function (event) {
    var cel = event.target;
    if (!(cel instanceof HTMLInputElement)) { return; }
    var form = cel.closest('.pf');
    if (!form) { return; }
    if (cel.closest('[data-pole-firm-wszystkie]')) {
      zaznaczWszystkie(form, cel.checked);
    }
    odswiez(form);
  });

  document.addEventListener('input', function (event) {
    var cel = event.target;
    if (cel instanceof HTMLInputElement && cel.classList.contains('pf__szukanie-pole')) {
      var form = cel.closest('.pf');
      if (form) { filtruj(form); }
    }
  });
})();
