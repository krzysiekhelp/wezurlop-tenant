<?php

declare(strict_types=1);

/*
 * Grupy — **dowody przez łamanie na warstwie zapytań** (zlecenie 08.10 §4, zmiana modelu
 * 10.10.2026: jedno zatrudnienie należy do wielu grup).
 * Wejście dla bramki; nie jedzie do paczki klienta (katalog `dev/`), nieosiągalne po HTTP.
 *
 * Mierzy to, czego nie da się pokazać ekranem albo czego ekran nie pokazuje wprost:
 *   a) zatrudnienie z INNEJ spółki w żądaniu przypisania → nie wchodzi do grupy, i nie wchodzi
 *      **w zapytaniu**, a nie w `if`-ie nad zapisem,
 *   b) cudzy identyfikator **nie wypisuje** nikogo z jego grupy (zapis, nie odczyt — gorszy),
 *   c) nazwa różniąca się tylko wielkością liter → odrzucona przez `uq_company_group_name`,
 *   d) usunięcie grupy z członkami → odrzucone przez zapytanie, a po pominięciu warunku
 *      przez **klucz obcy** (`ON DELETE RESTRICT`),
 *   e) dwa zatrudnienia jednej osoby w dwóch spółkach → dwie niezależne przynależności,
 *   g) **jedno żądanie = jedna zmiana** (zapis natychmiastowy, zlecenie 10.10 §3): żądanie
 *      o jedną osobę zmienia **dokładnie jeden wiersz w całej tabeli** przynależności,
 *      a pozostałe grupy tej osoby zostają nietknięte,
 *   h) **wiele grup na jedno zatrudnienie**: druga grupa nie odbiera pierwszej, wypisanie
 *      z jednej zostawia drugą, a powtórzone przypisanie nie dubluje wiersza,
 *   i) **wygaszone zatrudnienie nie wchodzi do nowej grupy**: zapis odmawia, lista go nie
 *      oferuje, a ten, który był w grupie przed wygaszeniem, w niej zostaje.
 *
 * ⚠ **Pomiar sprząta po sobie**: grupy próbne, **własne osoby i własne zatrudnienia** oraz
 * przynależności powstają i znikają w tym samym przebiegu, także gdy pomiar padnie w połowie
 * (`finally`). Danych roboczych Krzysztofa nie dotyka: grupy nazywają się `__probka_*`,
 * a osoby mają nazwisko `__probka` i `outside_hr = 1`.
 *
 * Wywołanie: `php dev/probka-grup.php`, wynik w JSON.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\GroupRepository;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$env = $_ENV;
$db = new Connection([
    'host' => (string) $env['DB_HOST'],
    'port' => (int) $env['DB_PORT'],
    'name' => (string) $env['DB_NAME'],
    'user' => (string) $env['DB_USER'],
    'password' => (string) $env['DB_PASS'],
]);
$pdo = $db->pdo();
$repo = new GroupRepository($db);

/** Dwie spółki z czynnymi zatrudnieniami — identyfikatory czytane, nie wpisane. */
$spolki = $pdo->query(
    'SELECT e.company_id AS id, COUNT(*) AS ile FROM employment e WHERE e.is_active = 1
      GROUP BY e.company_id ORDER BY ile DESC LIMIT 2'
)->fetchAll();
if (count($spolki) < 2) {
    echo json_encode(['blad' => 'potrzebne dwie spółki z zatrudnieniami']), "\n";
    exit(1);
}
$a = (int) $spolki[0]['id'];
$bSpolka = (int) $spolki[1]['id'];
// ⚠ Placeholdery także w próbce: `bin/check-rules.php` zgłasza konkatenację w SQL bez wyjątku
// dla `dev/` — i ma rację. Zakaz jest absolutny (CLAUDE.md §4), a wyjątek „to tylko pomiar"
// jest dokładnie tym, jak taki nawyk wchodzi do kodu produkcyjnego.

/*
 * ⚠ **WŁASNE ZATRUDNIENIA POMIAROWE** (aneks 10.10.2026 §6.2, zgoda Krzysztofa).
 *
 * Dotąd próbka pracowała na **pierwszych zatrudnieniach z danych roboczych** — i to jest
 * dokładnie ta droga, którą wstrzyknięte usterki dwóch tur z rzędu stały się stratami
 * w danych Krzysztofa: usterka „zapis czyści pozostałe przynależności" zdjęła osobę próbną
 * z Serwisu, a usterka „żądanie wkłada do wszystkich grup spółki" dorzuciła 12 cudzych
 * przynależności. Teraz próbka zakłada **swoje osoby i swoje zatrudnienia**, używa ich
 * do wszystkich dowodów i usuwa je w `finally`.
 *
 * ⚠ **Czego to NIE załatwia** (i to jest ważniejsze od tego, co załatwia): zdejmuje klasę
 * „usterka poszerzyła zapis dla osoby pod testem". **Nie zdejmuje** klasy „usterka poszerzyła
 * zapis na całą spółkę" — zapytanie bez granicy grupy dotknie wszystkich i tam jedyną obroną
 * zostaje osłona (`dev/stan-grup.php`: migawka przed przebiegiem, odtworzenie po).
 *
 * ⚠ Koszt jest jawny: przez czas przebiegu w kadrach stoją dwie osoby `__probka` — widoczne
 * na listach i w kalendarzu spółki. `outside_hr = 1` mówi wprost, że nie przyszły z kadr.
 */
$probneOsoby = [];
$probneEtaty = [];
$dodajOsobe = static function (string $imie) use ($pdo, &$probneOsoby): int {
    $st = $pdo->prepare(
        'INSERT INTO person (first_name, last_name, outside_hr, created_at)
         VALUES (:imie, :nazwisko, 1, UTC_TIMESTAMP())'
    );
    $st->execute(['imie' => $imie, 'nazwisko' => '__probka']);
    $id = (int) $pdo->lastInsertId();
    $probneOsoby[] = $id;

    return $id;
};
$dodajEtat = static function (int $osoba, int $spolka, bool $czynne) use ($pdo, &$probneEtaty): int {
    $st = $pdo->prepare(
        'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                 contract_employment, is_active, created_at)
         VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, :czynne, UTC_TIMESTAMP())'
    );
    $st->execute(['osoba' => $osoba, 'spolka' => $spolka, 'czynne' => $czynne ? 1 : 0]);
    $id = (int) $pdo->lastInsertId();
    $probneEtaty[] = $id;

    return $id;
};

/** Osoba z zatrudnieniem w OBU spółkach — do dowodów (a), (b), (e), (g) i (h). */
$osobaDwie = $dodajOsobe('Pomiar');
$etatA = $dodajEtat($osobaDwie, $a, true);
$etatB = $dodajEtat($osobaDwie, $bSpolka, true);
$dwaEtaty = ['a' => $etatA, 'b' => $etatB];
/** Druga osoba pomiarowa: trzy czynne zatrudnienia w spółce A — do dowodu (g). */
$osobaTrzy = $dodajOsobe('Pomiar Dwa');
$etatyG = [
    $dodajEtat($osobaTrzy, $a, true),
    $dodajEtat($osobaTrzy, $a, true),
    $dodajEtat($osobaTrzy, $a, true),
];
/**
 * Trzecia osoba pomiarowa: zatrudnienie, które **najpierw jest czynne, a potem wygaszone** —
 * do dowodów (f) i (i).
 *
 * ⚠ Kolejność jest częścią dowodu, nie wygodą. Od 10.10.2026 (wieczór) zapis **odmawia**
 * dodania wygaszonego zatrudnienia do grupy, więc jedyna droga, żeby wygaszony **był** w grupie,
 * jest taka jak w życiu: wszedł jako czynny i został wygaszony później. Próbka odtwarza
 * dokładnie tę kolejność — inaczej dowód (f) mierzyłby stan, którego aplikacja nie potrafi
 * już wytworzyć.
 */
$osobaNieczynna = $dodajOsobe('Pomiar Trzy');
$etatNieczynny = $dodajEtat($osobaNieczynna, $a, true);
$wygaszanie = $pdo->prepare('UPDATE employment SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                              WHERE id = :e');

$grupaA = null;
$grupaA2 = null;
$grupaB = null;
/** Grupa wyłącznie dla dowodu (g) — **pusta na starcie**, żeby „jedna zmiana" była zmianą. */
$grupaG = null;
$wynik = [];
try {
    $utw = $repo->utworz($a, '__probka_A', null);
    $grupaA = is_array($utw) ? $utw['id'] : null;
    $utwB = $repo->utworz($bSpolka, '__probka_B', null);
    $grupaB = is_array($utwB) ? $utwB['id'] : null;
    // ⚠ Druga grupa W TEJ SAMEJ spółce — bez niej nie da się zmierzyć wielu grup na jedno
    // zatrudnienie, bo `__probka_B` stoi w innej spółce i granica spółki i tak by to odrzuciła.
    $utwA2 = $repo->utworz($a, '__probka_A2', null);
    $grupaA2 = is_array($utwA2) ? $utwA2['id'] : null;
    if ($grupaA === null || $grupaB === null || $grupaA2 === null) {
        throw new RuntimeException('nie udało się utworzyć grup próbnych: ' . json_encode([$utw, $utwB, $utwA2]));
    }

    // ⚠ Osobna, PUSTA grupa na dowód (g): kandydaci do niego są wybierani po identyfikatorze,
    // więc w `__probka_A` część z nich już siedzi po wcześniejszych krokach — i „dodanie"
    // wychodziło zerową zmianą (zmierzone: `dodany_wiersz: []`). Dowód „jedna zmiana" wymaga
    // tła, o którym wiadomo wszystko.
    $utwG = $repo->utworz($a, '__probka_G', null);
    $grupaG = is_array($utwG) ? $utwG['id'] : null;

    // (c) nazwa różniąca się tylko wielkością liter
    $wynik['c_nazwa_wielkie_litery'] = $repo->utworz($a, '__PROBKA_A', null);

    // (a) zatrudnienie z innej spółki do grupy spółki A
    $przypisanie = $repo->przypisz($grupaA, $a, [$etatA, $etatB], null);
    $wynik['a_przypisanie'] = $przypisanie;
    $st = $pdo->prepare('SELECT COUNT(*) FROM employment_group WHERE group_id = :g AND employment_id = :e');
    $st->execute(['g' => $grupaA, 'e' => $etatB]);
    $wynik['a_obcy_w_grupie'] = (int) $st->fetchColumn();

    // (b) cudzy identyfikator nie wypisuje nikogo z jego grupy: etatB wkładamy do grupy B,
    // a potem przez spółkę A próbujemy go i przypisać, i WYPISAĆ — jedno i drugie ma go
    // nie dotknąć. ⚠ Wypisanie jest tu ważniejszą połową: to zapis na cudzych danych.
    $repo->przypisz($grupaB, $bSpolka, [$etatB], null);
    $czyja = $pdo->prepare(
        'SELECT group_id FROM employment_group WHERE employment_id = :e ORDER BY group_id'
    );
    $czyja->execute(['e' => $etatB]);
    $przedB = array_map('intval', $czyja->fetchAll(PDO::FETCH_COLUMN));
    $repo->przypisz($grupaA, $a, [$etatB], null);
    $wypisanie = $repo->usunZGrupy($grupaB, $a, [$etatB]);
    $czyja->execute(['e' => $etatB]);
    $poB = array_map('intval', $czyja->fetchAll(PDO::FETCH_COLUMN));
    $wynik['b_przynaleznosc_obcego'] = [
        'przed' => $przedB,
        'po' => $poB,
        'wypisani_cudza_spolka' => $wypisanie,
        'nietknieta' => $przedB === $poB && $wypisanie === [],
    ];

    // (h) **Wiele grup na jedno zatrudnienie** (zmiana modelu 10.10.2026). Trzy rzeczy naraz,
    // bo wszystkie trzy są o tym samym kluczu głównym na PARZE `(employment_id, group_id)`:
    //   — druga grupa **nie odbiera** pierwszej,
    //   — powtórzone przypisanie **nie dubluje** wiersza (inaczej licznik grupy liczy jedną
    //     osobę dwa razy, a nikt nie wie, dlaczego grupa ma 4 osoby przy 3 nazwiskach),
    //   — wypisanie z jednej grupy **zostawia** drugą (granica `eg.group_id = :grupa`).
    $wDwochGrupach = $pdo->prepare(
        'SELECT group_id FROM employment_group WHERE employment_id = :e AND group_id IN (:g1, :g2)
          ORDER BY group_id'
    );
    $repo->przypisz($grupaA, $a, [$etatA], null);
    $drugaGrupa = $repo->przypisz($grupaA2, $a, [$etatA], null);
    $wDwochGrupach->execute(['e' => $etatA, 'g1' => $grupaA, 'g2' => $grupaA2]);
    $poDrugiej = array_map('intval', $wDwochGrupach->fetchAll(PDO::FETCH_COLUMN));
    $powtorzone = $repo->przypisz($grupaA2, $a, [$etatA], null);
    $wDwochGrupach->execute(['e' => $etatA, 'g1' => $grupaA, 'g2' => $grupaA2]);
    $poPowtorzeniu = array_map('intval', $wDwochGrupach->fetchAll(PDO::FETCH_COLUMN));
    $wypisanyZJednej = $repo->usunZGrupy($grupaA2, $a, [$etatA]);
    $wDwochGrupach->execute(['e' => $etatA, 'g1' => $grupaA, 'g2' => $grupaA2]);
    $poWypisaniu = array_map('intval', $wDwochGrupach->fetchAll(PDO::FETCH_COLUMN));
    $wynik['h_wiele_grup'] = [
        'po_drugiej' => $poDrugiej,
        'dodani_do_drugiej' => $drugaGrupa['dodani'],
        'po_powtorzeniu' => $poPowtorzeniu,
        'dodani_powtornie' => $powtorzone['dodani'],
        'wypisany_z_drugiej' => $wypisanyZJednej,
        'po_wypisaniu' => $poWypisaniu,
        'w_dwoch' => count($poDrugiej) === 2,
        'bez_duplikatu' => $poPowtorzeniu === $poDrugiej && $powtorzone['dodani'] === [],
        'wypisanie_nie_rusza_drugiej' => $poWypisaniu === [$grupaA],
    ];

    // (d) usunięcie grupy z członkami — warstwa zapytania
    $wynik['d_usuniecie_niepustej'] = $repo->usun($grupaA, $a);
    // …i warstwa klucza obcego: to samo bez warunku `NOT EXISTS`.
    $kod = null;
    try {
        $pdo->prepare('DELETE FROM company_group WHERE id = :id')->execute(['id' => $grupaA]);
        $kod = 'BRAK ODMOWY — klucz obcy nie zadziałał';
    } catch (PDOException $e) {
        $kod = $e->getCode() . ' ' . substr((string) ($e->errorInfo[2] ?? ''), 0, 60);
    }
    $wynik['d_usuniecie_bez_warunku'] = $kod;

    // (e) dwa zatrudnienia jednej osoby w dwóch spółkach
    if (is_array($dwaEtaty)) {
        $repo->przypisz($grupaA, $a, [(int) $dwaEtaty['a']], null);
        $repo->przypisz($grupaB, $bSpolka, [(int) $dwaEtaty['b']], null);
        $st = $pdo->prepare(
            'SELECT eg.employment_id, e.company_id, g.name FROM employment_group eg
               JOIN employment e ON e.id = eg.employment_id
               JOIN company_group g ON g.id = eg.group_id
              WHERE eg.employment_id IN (:a, :b) ORDER BY e.company_id'
        );
        $st->execute(['a' => (int) $dwaEtaty['a'], 'b' => (int) $dwaEtaty['b']]);
        $wynik['e_dwa_etaty'] = $st->fetchAll();
    } else {
        $wynik['e_dwa_etaty'] = 'brak osoby z etatami w dwóch spółkach — NIE zmierzono';
    }

    // (f) NIEAKTYWNE zatrudnienie w grupie — zostaje (§8a), ale licznik musi je wyodrębnić.
    //
    // ⚠ Od 10.10.2026 (wieczór) wygaszone zatrudnienie **pomiar zakłada sobie sam**, zamiast
    // szukać go w danych roboczych. Dotąd szukał: w spółce z największą liczbą etatów nie było
    // ani jednego nieczynnego, w drugiej był **jeden** — i kryterium wisiało na tym, czy ktoś
    // w kadrach kogoś wygasi. Dowód, który zależy od zawartości cudzych danych, jest dowodem
    // „z braku okazji" o jedną zmianę w kadrach.
    // Wchodzi jako CZYNNY, a wygaszamy go dopiero po wejściu — tak jak dzieje się to w kadrach.
    $repo->przypisz($grupaA, $a, [$etatNieczynny, $etatA], null);
    $wygaszanie->execute(['e' => $etatNieczynny]);
    foreach ($repo->lista($a) as $g) {
        if ($g['id'] === $grupaA) {
            $wynik['f_licznik_nieaktywnych'] = $g + ['spolka' => $a];
        }
    }

    // (i) **WYGASZONE ZATRUDNIENIE NIE WCHODZI DO NOWEJ GRUPY** (znalezisko Krzysztofa
    // z 10.10.2026 wieczorem). §8a zlecenia 08.10 mówiło tylko, że wygaszenie **nie czyści**
    // istniejącej przynależności — nadawania nowych nikt nie przewidywał, a lista oferowała
    // wygaszonych z przyciskiem `+`.
    //
    // ⚠ Trzy rzeczy w jednym dowodzie, bo to trzy różne twierdzenia:
    //   1. zapis **odmawia** (warunek w zapytaniu, nie w liście — żądanie da się wysłać z ręki),
    //   2. lista „pozostali" **nie oferuje** wygaszonych (ani jednego w wyniku),
    //   3. ten, który **był** w grupie przed wygaszeniem, **w niej zostaje** — §8a bez zmian.
    $przedProba = $repo->czlonkowie($grupaA2, $a);
    $probaWygaszonego = $repo->przypisz($grupaA2, $a, [$etatNieczynny], null);
    $poProbie = $repo->czlonkowie($grupaA2, $a);
    $pozaGrupa = $repo->osobyPozaGrupa($grupaA2, $a, '', 500);
    $wynik['i_wygaszone_nie_wchodzi'] = [
        'dodani' => $probaWygaszonego['dodani'],
        'pominieci' => $probaWygaszonego['pominieci'],
        'odmowa' => $probaWygaszonego['dodani'] === [] && $probaWygaszonego['pominieci'] === [$etatNieczynny],
        'bez_zmian_w_grupie' => count($przedProba) === count($poProbie),
        'wygaszonych_w_ofercie' => count(array_filter($pozaGrupa, static fn (array $o): bool => !$o['czynne'])),
        // …a ten, który wszedł jako czynny, dalej jest w swojej grupie:
        'zostal_w_swojej_grupie' => in_array(
            $etatNieczynny,
            array_column($repo->czlonkowie($grupaA, $a), 'id'),
            true,
        ),
    ];

    // (g) **JEDNO ŻĄDANIE = JEDNA ZMIANA** (zlecenie 10.10.2026 §3: zapis natychmiastowy).
    //
    // ⚠ To jest **zamiana**, nie usunięcie poprzedniego dowodu. Dotąd stało tu `g_stan_z_listy`:
    // zapis utrwalał STAN pokazanej listy, więc trzeba było dowodzić, że odznaczenie wypisuje
    // z grupy, a osoba poza pokazaną listą zostaje nietknięta. Takiego żądania już nie ma —
    // zastąpiły je dwie operacje przyrostowe, więc dowód dotyczy teraz tego, co faktycznie
    // istnieje: że żądanie o JEDNĄ osobę zmienia **dokładnie jeden wiersz** w całej tabeli
    // przynależności i nie rusza nikogo innego, ani w tej grupie, ani w pozostałych.
    //
    // ⚠ Pomiar bierze **migawkę całej tabeli** przed i po, nie samej grupy próbnej. Gdyby
    // zapis dotykał cudzej przynależności (np. czyścił pozostałe grupy osoby — usterka,
    // którą ten dowód ma łapać), pomiar liczony w granicach jednej grupy by tego nie zobaczył.
    $migawka = static function () use ($pdo): array {
        $w = $pdo->query('SELECT CONCAT(employment_id, "-", group_id) FROM employment_group')
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($w);

        return $w;
    };
    // ⚠ Trzy **własne** zatrudnienia, nie trzy pierwsze z danych: próbka musi wiedzieć
    // o swoim tle wszystko. Przy kandydatach z danych „dodanie" wychodziło zerową zmianą,
    // bo część z nich siedziała już w grupie po wcześniejszych krokach (zmierzone:
    // `dodany_wiersz: []`).
    {
        [$x, $y, $z] = $etatyG;
        // Tło: `$y` i `$z` w grupie G, `$z` dodatkowo w A2 — czyli jedna osoba w dwóch grupach.
        $repo->przypisz($grupaG, $a, [$y, $z], null);
        $repo->przypisz($grupaA2, $a, [$z], null);
        $przedDodaniem = $migawka();
        $repo->przypisz($grupaG, $a, [$x], null);
        $poDodaniu = $migawka();
        $przedUsunieciem = $poDodaniu;
        $repo->usunZGrupy($grupaG, $a, [$z]);
        $poUsunieciu = $migawka();
        $wynik['g_jedno_zadanie'] = [
            // Dodanie: dokładnie jeden wiersz więcej i to ten wskazany.
            'dodany_wiersz' => array_values(array_diff($poDodaniu, $przedDodaniem)),
            'przy_dodaniu_nic_nie_zniklo' => array_values(array_diff($przedDodaniem, $poDodaniu)),
            // Wypisanie: dokładnie jeden wiersz mniej i to ten wskazany.
            'zdjety_wiersz' => array_values(array_diff($przedUsunieciem, $poUsunieciu)),
            'przy_wypisaniu_nic_nie_doszlo' => array_values(array_diff($poUsunieciu, $przedUsunieciem)),
            // …a druga grupa tej samej osoby została nietknięta.
            'druga_grupa_osoby_zostala' => in_array($z . '-' . $grupaA2, $poUsunieciu, true),
        ];
    }

    // ⚠ Spółki, w których próbka pracowała, jadą do wyniku: część ekranowa bramki musi
    // **ustawić tę samą spółkę** w znaczniku w belce, bo od 10.10.2026 ekran bierze spółkę
    // z kontekstu, nie z `?spolka=` w adresie. Bez tego pomiar ekranowy mierzył jedną spółkę,
    // a próbka pisała w drugiej — i kryterium granicy spółki czerwieniło się bez usterki.
    $wynik['spolka_pomiaru'] = $a;
    $wynik['spolka_obca'] = $bSpolka;

    echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} finally {
    // ⚠ Sprzątanie: najpierw przynależności (klucz obcy RESTRICT na grupie), potem grupy.
    foreach ([$grupaA, $grupaA2, $grupaB, $grupaG] as $g) {
        if ($g === null) {
            continue;
        }
        $pdo->prepare('DELETE FROM employment_group WHERE group_id = :g')->execute(['g' => $g]);
        $pdo->prepare('DELETE FROM company_group WHERE id = :g')->execute(['g' => $g]);
    }
    $pdo->prepare("DELETE FROM company_group WHERE name LIKE '\\_\\_probka%'")->execute();
    // ⚠ Na końcu **własne zatrudnienia i osoby** — w tej kolejności (klucz obcy
    // `employment.person_id`). Usuwamy po identyfikatorach z tego przebiegu, a dodatkowo
    // po nazwisku `__probka`: jeśli poprzedni przebieg padł przed `finally` (np. zabity
    // z zewnątrz), jego osoby nie mają zostać w kadrach na zawsze.
    foreach ($probneEtaty as $e) {
        $pdo->prepare('DELETE FROM employment_group WHERE employment_id = :e')->execute(['e' => $e]);
    }
    $pdo->prepare('DELETE eg FROM employment_group eg JOIN employment e ON e.id = eg.employment_id
                    JOIN person p ON p.id = e.person_id WHERE p.last_name = :n')->execute(['n' => '__probka']);
    $pdo->prepare('DELETE e FROM employment e JOIN person p ON p.id = e.person_id
                    WHERE p.last_name = :n')->execute(['n' => '__probka']);
    $pdo->prepare('DELETE FROM person WHERE last_name = :n')->execute(['n' => '__probka']);
}
