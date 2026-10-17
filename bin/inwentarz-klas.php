<?php

declare(strict_types=1);

/**
 * Inwentaryzacja klas CSS — co żyje, co jest sierotą, co jest martwe.
 *
 * ⚠ To analiza STATYCZNA: czyta pliki i dopasowuje selektory, nie uruchamia przeglądarki.
 * Dlatego jest w PHP i leży obok `check-rules.php`. Pomiar wyrenderowanego układu robi
 * `dev/bramka-ekranowa.py` — to inne zadanie i inne narzędzie.
 *
 * Do czego służy: migracja wariantu mobilnego odsłoniła, że nowy system wizualny pokrywa powłokę
 * i rdzeń komponentów, a reszta ekranów stała na starym arkuszu, który wciąż się wczytywał.
 * Dopóki stare arkusze lecą obok nowych, braku nie widać — ten skrypt go liczy.
 *
 * Użycie:
 *   php bin/inwentarz-klas.php                 # raport na ekran
 *   php bin/inwentarz-klas.php --tsv=plik.tsv  # dodatkowo tabela do pliku
 *   php bin/inwentarz-klas.php --bramka        # kod 1, gdy jakakolwiek klasa ma reguły TYLKO w starych
 *
 * ⚠ Tryb `--bramka` jest bramką PRZED skasowaniem starych arkuszy: dowodzi, że lista
 * `TYLKO_STARY` doszła do zera. Dziś jest czerwony i taki ma być — to miara pracy, która została.
 *
 * ⚠ **`docs/inwentarz-klas.tsv` jest WYNIKIEM tego skryptu i nie wolno go edytować ręcznie.**
 * Zdarzyło się to 07.10.2026 (skasowanie pięciu wierszy po usunięciu komponentu) i jest to ta
 * sama rodzina usterek co dwie kopie jednej reguły: następny przebieg generatora nadpisze ręczną
 * zmianę, a do tego czasu nikt nie wie, która wersja jest prawdziwa. Zmieniło się coś w klasach?
 * Puść `--tsv=docs/inwentarz-klas.tsv` i scommituj wynik osobno.
 */

/** Arkusze nowego systemu wizualnego (docs/system-wizualny.md §1). */
const NOWE_ARKUSZE = ['00-tokens.css', '10-base.css', '20-layout.css', '20-layout-mobile.css', '30-components.css'];

/**
 * Arkusze starego systemu. ⚠ Od 21.09.2026 **nie istnieją** — lista zostaje jako STRAŻNIK:
 * gdyby któryś wrócił do `public/assets/css`, jego klasy znów zaczną wychodzić jako
 * `TYLKO_STARY` i `--bramka` zrobi się czerwona. Pusta lista dałaby bramkę zawsze zieloną,
 * czyli bramkę, która niczego nie pilnuje.
 */
const STARE_ARKUSZE = ['tokens.css', 'base.css', 'components.css'];

$root = dirname(__DIR__);
$tsv = null;
$bramka = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--bramka') {
        $bramka = true;
    } elseif (str_starts_with($arg, '--tsv=')) {
        $tsv = substr($arg, 6);
    } else {
        fwrite(STDERR, "Nieznany argument: {$arg}\n");
        exit(2);
    }
}

/**
 * Wszystkie pliki o danym rozszerzeniu pod katalogiem.
 *
 * @return list<string> ścieżki względne wobec korzenia
 */
function pliki(string $root, string $dir, string $ext): array
{
    $sciezka = $root . '/' . $dir;
    if (!is_dir($sciezka)) {
        return [];
    }
    $wynik = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sciezka, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $plik) {
        if ($plik instanceof SplFileInfo && $plik->isFile() && $plik->getExtension() === $ext) {
            $wynik[] = str_replace($root . '/', '', $plik->getPathname());
        }
    }
    sort($wynik);
    return $wynik;
}

/**
 * Nazwy klas z selektorów jednego arkusza, z pominięciem komentarzy.
 *
 * ⚠ Komentarze wycinamy, bo w nich opisujemy decyzje i cytujemy stare nazwy klas — inaczej
 * dokumentacja liczyłaby się jako reguła.
 *
 * @return array<string, true>
 */
function klasyZArkusza(string $tresc): array
{
    $tresc = (string) preg_replace('#/\*.*?\*/#s', ' ', $tresc);
    $klasy = [];
    // Blok reguły: wszystko przed `{`, pomijając bloki @media/@container (ich warunki nie mają klas).
    if (preg_match_all('/([^{}]+)\{/', $tresc, $m) > 0) {
        foreach ($m[1] as $selektor) {
            if (str_contains($selektor, '@')) {
                continue;
            }
            if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $selektor, $k) > 0) {
                foreach ($k[1] as $nazwa) {
                    $klasy[$nazwa] = true;
                }
            }
        }
    }
    return $klasy;
}

/**
 * Nazwy klas użyte w atrybutach `class` szablonu.
 *
 * ⚠ Wyrażenia Twiga wycinamy PRZED szukaniem nazw: `class="nav-item{% if x %} nav-item--active{% endif %}"`
 * ma dać dwie klasy, a nie tokeny składni. Klasa sklejana z danych (`chip--{{ r.status }}`) zostaje
 * jako prefiks z myślnikami na końcu — i to jest właściwe, bo taka klasa NIE ISTNIEJE w arkuszu;
 * dokładnie ten błąd (`badge--approved`) kazał przenieść odwzorowanie stanu do makra.
 *
 * @return list<string>
 */
function klasyZSzablonu(string $tresc): array
{
    $klasy = [];
    if (preg_match_all('/class\s*=\s*"([^"]*)"|class\s*=\s*\'([^\']*)\'/', $tresc, $m) > 0) {
        foreach (array_merge($m[1], $m[2]) as $wartosc) {
            if ($wartosc === '') {
                continue;
            }
            $wartosc = (string) preg_replace('/\{[{%].*?[%}]\}/s', ' ', $wartosc);
            if (preg_match_all('/[A-Za-z][A-Za-z0-9_-]*/', $wartosc, $k) > 0) {
                foreach ($k[0] as $nazwa) {
                    $klasy[] = $nazwa;
                }
            }
        }
    }
    return array_values(array_unique($klasy));
}

/**
 * Nazwy klas, po które sięga JavaScript.
 *
 * ⚠ Osobno, bo hak JS bez reguły to funkcja bez wyglądu, a reguła bez haka to martwy kod —
 * i jedno z drugim łatwo pomylić. Tu szukamy też nazw SKLEJANYCH w stringu: `modules/flash.js`
 * budował `'flash box ' + 'box--error'`, czego pierwszy skan nie znalazł.
 *
 * @return list<string>
 */
function klasyZJs(string $tresc): array
{
    $klasy = [];
    $wzory = [
        '/classList\.(?:add|remove|toggle|contains)\(\s*[\'"]([^\'"]+)[\'"]/',
        '/querySelector(?:All)?\(\s*[\'"]\.([A-Za-z0-9_-]+)/',
    ];
    foreach ($wzory as $wzor) {
        if (preg_match_all($wzor, $tresc, $m) > 0) {
            foreach ($m[1] as $trafienie) {
                foreach (preg_split('/\s+/', trim($trafienie)) ?: [] as $nazwa) {
                    if ($nazwa !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $nazwa) === 1) {
                        $klasy[] = $nazwa;
                    }
                }
            }
        }
    }
    // ⚠ Przypisanie do `className` czytamy DO KOŃCA INSTRUKCJI i bierzemy wszystkie literały,
    // bo nazwy bywają SKLEJANE: `el.className = 'flash notice' + (blad ? ' notice--danger' : '')`.
    // Pierwsza wersja tego skryptu łapała to wzorcem „cokolwiek z myślnikiem w cudzysłowie"
    // i wyciągała `aria-label`, `data-copy`, `kreator-modal` — czyli atrybuty i identyfikatory,
    // nie klasy. Wąsko jest lepiej: fałszywe trafienie w bramce uczy ignorowania bramki.
    if (preg_match_all('/className\s*=\s*([^;]+);/', $tresc, $m) > 0) {
        foreach ($m[1] as $instrukcja) {
            // ⚠ Literał po operatorze porównania NIE jest nazwą klasy: w
            // `(kind === 'error' ? ' notice--danger' : '')` słowo `error` to wartość warunku.
            // Bez tego bramka zgłaszała klasę `.error`, której nikt nigdy nie napisał.
            $instrukcja = (string) preg_replace('/[=!]==?\s*[\'"][^\'"]*[\'"]/', ' ', $instrukcja);
            if (preg_match_all('/[\'"]([^\'"]*)[\'"]/', $instrukcja, $lit) > 0) {
                foreach ($lit[1] as $fragment) {
                    foreach (preg_split('/\s+/', trim($fragment)) ?: [] as $nazwa) {
                        if ($nazwa !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $nazwa) === 1) {
                            $klasy[] = $nazwa;
                        }
                    }
                }
            }
        }
    }
    return array_values(array_unique($klasy));
}

// ——— 1. Reguły w arkuszach ————————————————————————————————————————————————

$regulyNowe = [];
$regulyStare = [];
foreach (NOWE_ARKUSZE as $nazwa) {
    $plik = $root . '/public/assets/css/' . $nazwa;
    if (!is_file($plik)) {
        continue;
    }
    foreach (array_keys(klasyZArkusza((string) file_get_contents($plik))) as $klasa) {
        $regulyNowe[$klasa][] = $nazwa;
    }
}
foreach (STARE_ARKUSZE as $nazwa) {
    $plik = $root . '/public/assets/css/' . $nazwa;
    if (!is_file($plik)) {
        continue;
    }
    foreach (array_keys(klasyZArkusza((string) file_get_contents($plik))) as $klasa) {
        $regulyStare[$klasa][] = $nazwa;
    }
}

// ——— 2. Użycia w szablonach i w JS ————————————————————————————————————————

$szablony = pliki($root, 'templates', 'twig');
$uzycia = [];
$zaleznosci = [];
foreach ($szablony as $plik) {
    $tresc = (string) file_get_contents($root . '/' . $plik);
    foreach (klasyZSzablonu($tresc) as $klasa) {
        $uzycia[$klasa][] = str_replace('templates/', '', $plik);
    }
    // Graf: `extends`/`include` z literałem. Nazwa szablonu ze zmiennej (layout_auth) — osobno.
    if (preg_match_all('/\{%-?\s*(?:extends|include)\s+\'([^\']+)\'/', $tresc, $m) > 0) {
        foreach ($m[1] as $cel) {
            $zaleznosci[str_replace('templates/', '', $plik)][] = $cel;
        }
    }
    if (preg_match('/\{%-?\s*extends\s+layout_auth/', $tresc) === 1) {
        // Biała lista z TwigFactory::LAYOUTS — ekran uwierzytelnienia działa w OBU wariantach.
        $zaleznosci[str_replace('templates/', '', $plik)][] = 'layout/auth.twig';
        $zaleznosci[str_replace('templates/', '', $plik)][] = 'layout/auth-mobile.twig';
    }
}
$hakiJs = [];
foreach (pliki($root, 'public/assets/js', 'js') as $plik) {
    if (str_contains($plik, '/vendor/')) {
        continue;
    }
    foreach (klasyZJs((string) file_get_contents($root . '/' . $plik)) as $klasa) {
        $hakiJs[$klasa][] = str_replace('public/assets/js/', '', $plik);
    }
}

// ——— 3. Ile ekranów dotyka dana klasa ————————————————————————————————————
// „Ekran" = plik z `page/**`. Klasa z partiala liczy się do każdego ekranu, który go dołącza —
// ⚠ `page/mobile/*.twig` dołącza TE SAME `partials/page/*.twig` co `page/web/*.twig`, więc
// liczenie po położeniu pliku dałoby połowę prawdy.

$ekranyPliku = [];
$rozwiaz = static function (string $plik, array $slad) use (&$rozwiaz, $zaleznosci, $szablony): array {
    if (isset($slad[$plik])) {
        return [];
    }
    $slad[$plik] = true;
    $wynik = str_starts_with($plik, 'page/') ? [$plik => true] : [];
    foreach ($szablony as $kandydat) {
        $wzgledny = str_replace('templates/', '', $kandydat);
        foreach ($zaleznosci[$wzgledny] ?? [] as $cel) {
            if ($cel === $plik) {
                $wynik += $rozwiaz($wzgledny, $slad);
            }
        }
    }
    return $wynik;
};
foreach ($szablony as $plik) {
    $wzgledny = str_replace('templates/', '', $plik);
    $ekranyPliku[$wzgledny] = count($rozwiaz($wzgledny, []));
}

// ——— 4. Klasyfikacja ————————————————————————————————————————————————————

$wszystkie = array_unique(array_merge(
    array_keys($uzycia),
    array_keys($hakiJs),
    array_keys($regulyNowe),
    array_keys($regulyStare)
));
sort($wszystkie);

$wiersze = [];
$licznik = ['BRAK_REGUL' => 0, 'TYLKO_STARY' => 0, 'TYLKO_NOWY' => 0, 'OBA' => 0,
    'MARTWA_REGULA' => 0, 'PREFIKS_Z_DANYCH' => 0];
foreach ($wszystkie as $klasa) {
    $nowe = $regulyNowe[$klasa] ?? [];
    $stare = $regulyStare[$klasa] ?? [];
    $wSzablonach = $uzycia[$klasa] ?? [];
    $wJs = $hakiJs[$klasa] ?? [];
    $uzywana = $wSzablonach !== [] || $wJs !== [];

    if (!$uzywana) {
        $status = 'MARTWA_REGULA';
    } elseif ($nowe === [] && $stare === [] && str_ends_with($klasa, '-')) {
        // ⚠ Klasa SKLEJANA z danych (`class="chip--{{ r.status }}"`) zostaje jako prefiks.
        // Sam prefiks nigdy nie ma reguły — pytanie brzmi, czy istnieje JAKAKOLWIEK klasa,
        // która się nim zaczyna. Jeśli nie, szablon generuje nazwy, których system nie zna,
        // i to jest prawdziwa usterka: dokładnie tak powstawało `badge--approved`.
        $pasuje = false;
        foreach (array_merge(array_keys($regulyNowe), array_keys($regulyStare)) as $istniejaca) {
            if (str_starts_with($istniejaca, $klasa)) {
                $pasuje = true;
                break;
            }
        }
        $status = $pasuje ? 'PREFIKS_Z_DANYCH' : 'BRAK_REGUL';
    } elseif ($nowe === [] && $stare === []) {
        $status = 'BRAK_REGUL';
    } elseif ($nowe === []) {
        $status = 'TYLKO_STARY';
    } elseif ($stare === []) {
        $status = 'TYLKO_NOWY';
    } else {
        $status = 'OBA';
    }
    ++$licznik[$status];

    $ekrany = 0;
    foreach ($wSzablonach as $plik) {
        $ekrany = max($ekrany, $ekranyPliku[$plik] ?? 0);
    }
    $wiersze[] = [
        'klasa' => $klasa,
        'nowy' => implode(' ', $nowe),
        'stary' => implode(' ', $stare),
        'status' => $status,
        'uzyc' => count($wSzablonach),
        'ekrany' => $ekrany,
        'js' => implode(' ', $wJs),
        'pliki' => implode(',', array_slice($wSzablonach, 0, 6)) . (count($wSzablonach) > 6 ? ',…' : ''),
    ];
}

// ——— 5. Wypis ——————————————————————————————————————————————————————————

$out = fopen('php://stdout', 'w');
fwrite($out, "inwentarz-klas: klas łącznie " . count($wiersze) . "\n");
foreach ($licznik as $status => $ile) {
    fwrite($out, sprintf("  %-14s %4d\n", $status, $ile));
}
fwrite($out, sprintf(
    "  arkusze: stary definiuje %d klas, nowy %d\n",
    count($regulyStare),
    count($regulyNowe)
));

$brak = array_values(array_filter($wiersze, static fn (array $w): bool => $w['status'] === 'BRAK_REGUL'));
if ($brak !== []) {
    fwrite($out, "\n⚠ BRAK_REGUL — w szablonie albo w JS, bez reguły w ŻADNYM arkuszu:\n");
    foreach ($brak as $w) {
        $gdzie = $w['js'] !== '' ? 'JS: ' . $w['js'] : $w['pliki'];
        fwrite($out, sprintf("  %-22s ekranów %-3d %s\n", '.' . $w['klasa'], $w['ekrany'], $gdzie));
    }
}

$tylkoStary = array_values(array_filter($wiersze, static fn (array $w): bool => $w['status'] === 'TYLKO_STARY'));
if ($tylkoStary !== []) {
    fwrite($out, "\nTYLKO_STARY — praca przed skasowaniem starych arkuszy (" . count($tylkoStary) . "):\n");
    usort($tylkoStary, static fn (array $a, array $b): int => $b['uzyc'] <=> $a['uzyc'] ?: strcmp($a['klasa'], $b['klasa']));
    foreach ($tylkoStary as $w) {
        fwrite($out, sprintf("  %-26s użyć %-3d ekranów %-3d [%s]\n", '.' . $w['klasa'], $w['uzyc'], $w['ekrany'], $w['stary']));
    }
}

if ($tsv !== null) {
    $fh = fopen($tsv[0] === '/' ? $tsv : $root . '/' . $tsv, 'w');
    fwrite($fh, "klasa\tregula_nowy\tregula_stary\tstatus\tuzyta_w_szablonach\tekrany\thaki_js\tpliki_szablonow\n");
    foreach ($wiersze as $w) {
        fwrite($fh, implode("\t", [$w['klasa'], $w['nowy'], $w['stary'], $w['status'], (string) $w['uzyc'], (string) $w['ekrany'], $w['js'], $w['pliki']]) . "\n");
    }
    fclose($fh);
    fwrite($out, "\ntabela: {$tsv}\n");
}

if ($bramka) {
    if ($tylkoStary !== []) {
        fwrite($out, "\ninwentarz-klas: BRAMKA CZERWONA — " . count($tylkoStary)
            . " klas ma reguły wyłącznie w starych arkuszach.\n"
            . "Stare arkusze (" . implode(', ', STARE_ARKUSZE) . ") są nadal potrzebne i NIE WOLNO ich skasować.\n");
        exit(1);
    }
    fwrite($out, "\ninwentarz-klas: BRAMKA ZIELONA — zero klas zależnych od starych arkuszy.\n");
}

exit(0);
