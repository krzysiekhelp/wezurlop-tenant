<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\HierarchyRepository;
use Wezurlop\Repository\PracownikRepository;

/**
 * Kogo wolno oglądać na kalendarzu zespołu: **siebie i całą gałąź w dół, w obrębie swojej spółki**
 * (decyzja Krzysztofa 22.09.2026, zlecenie `pulpit-pracownika-i-kalendarz` §5).
 *
 * ⚠ **To jest filtr, nie uprawnienie** — i dlatego zasięg liczy się TU, po stronie serwera,
 * przy każdym żądaniu od nowa. Lista osób w kontrolce służy wyłącznie wygodzie; identyfikator
 * zatrudnienia podany z zewnątrz (adres, podmieniony formularz, zapamiętany wybór sprzed zmiany
 * w hierarchii) przechodzi przez `przefiltruj()` i nie ma prawa dodać nikogo do wyniku.
 *
 * ⚠ Granica spółki bierze się z samego źródła danych: `krawedzie()` czyta krawędzie **jednej**
 * spółki, więc zatrudnienie z innej spółki nie trafia do mapy i nie może stać się podwładnym.
 * To mocniejsze niż warunek `if` po pobraniu — cudzej spółki nie da się „przeoczyć”, bo jej
 * w ogóle nie ma w danych, na których pracuje przejście.
 */
final class ZasiegPodgladu
{
    /**
     * Bezpiecznik przejścia. Ta sama wartość co w `Hierarchia::GLEBOKOSC_MAX` i z tego samego
     * powodu: dane z bazy mogą zawierać cykl (wykrywamy go przy zapisie, ale nie w danych
     * zastanych), a przejście bez licznika weszłoby w pętlę nieskończoną.
     */
    public const GLEBOKOSC_MAX = 64;

    public function __construct(
        private readonly HierarchyRepository $hierarchia,
        private readonly PracownikRepository $pracownicy,
    ) {
    }

    /**
     * **Jedno miejsce, w którym rozstrzyga się, jaki zasięg ma widok** — i czy wolno go mieć.
     *
     * ⚠ Trzy zasięgi to trzy okazje na trzy `if`-y w kontrolerach, a stąd na trzy różne
     * odpowiedzi na pytanie „kogo widać". Po ostatnich turach wiadomo, jak to się kończy:
     * jedna pusta lista znaczyła dwie rzeczy w dwóch drogach i zostawiała na ekranie wiersz,
     * którego nikt nie zaznaczył.
     *
     * ⚠ Zasięg „firma" wymaga **uprawnienia**, a nie roli i nie obecności przełącznika na ekranie:
     * żądanie `?widok=firma` bez poziomu ≥ 1 dostaje zasięg własnej gałęzi, a nie odmowę —
     * adres wklejony z cudzej sesji ma pokazać własny kalendarz, nie awarię (CLAUDE.md §11).
     *
     * ⚠ Zasięg NIE jest szczegółowością. Ta funkcja nie wie, czy widać nazwę rodzaju — to osobna
     * oś, którą liczy `CalendarVisibility` (`docs/etap3-logika-urlopowa.md` §8b).
     *
     * @param int|null $employmentId `null` = konto bez zatrudnienia (administrator z regułą dostępu)
     * @param list<int> $zRegul zatrudnienia widoczne z REGUŁ dostępu (zlecenie Z53) — dokładane
     *        do gałęzi w widoku „Zespół", bo „kogo widzę" to jedna lista, a nie tyle list,
     *        ile źródeł dostępu
     * @return array{widok: string, dozwolone: list<int>, galaz: int, spolka: int, moze_firme: bool}
     */
    public function dlaWidoku(
        string $zadany,
        int $companyId,
        ?int $employmentId,
        bool $moznaSpolke,
        array $zRegul = []
    ): array {
        $galaz = $employmentId === null ? [] : $this->dozwolone($companyId, $employmentId);
        $wSpolce = $moznaSpolke ? $this->pracownicy->zatrudnieniaSpolki($companyId) : [];
        // ⚠ **Gałąź i reguły schodzą się w JEDEN zbiór** (zlecenie Z53 §2): osoby z reguł stoją
        // na kalendarzu zespołu obok podwładnych i w kontrolce wyboru. Kolejność gałęzi zostaje
        // z przodu, bo pierwszym identyfikatorem ma być właściciel kalendarza — na tym stoi
        // `przefiltruj()`.
        $zespol = self::zlacz($galaz, $zRegul);

        // Kolejność warunków = kolejność pozycji przełącznika; każda pozycja istnieje tylko wtedy,
        // gdy ma treść (ta sama reguła, przez którą „Zespół" nie pokazuje się bez podwładnych).
        if ($zadany === KalendarzPracownika::WIDOK_FIRMA && $moznaSpolke) {
            $widok = KalendarzPracownika::WIDOK_FIRMA;
            $dozwolone = $wSpolce;
        } elseif ($zadany === KalendarzPracownika::WIDOK_ZESPOL && count($zespol) > 1) {
            $widok = KalendarzPracownika::WIDOK_ZESPOL;
            $dozwolone = $zespol;
        } elseif ($galaz !== []) {
            $widok = KalendarzPracownika::WIDOK_JA;
            $dozwolone = [$galaz[0]];
        } else {
            // Konto bez zatrudnienia: „Tylko ja" nie istnieje, bo nie ma czego pokazać.
            // Z regułą „cała firma" wchodzi wprost na „Firma"; z regułą na grupy — na „Zespół",
            // gdzie stoją wyłącznie osoby z reguł.
            if ($moznaSpolke) {
                $widok = KalendarzPracownika::WIDOK_FIRMA;
                $dozwolone = $wSpolce;
            } elseif ($zespol !== []) {
                $widok = KalendarzPracownika::WIDOK_ZESPOL;
                $dozwolone = $zespol;
            } else {
                $widok = KalendarzPracownika::WIDOK_JA;
                $dozwolone = [];
            }
        }

        return [
            'widok' => $widok,
            'dozwolone' => $dozwolone,
            // Liczby do zdań o zasięgu: ile osób w gałęzi i ile w całej spółce. Zdanie „dalej
            // nie sięgasz" musi znać oba, inaczej nie da się go napisać prawdziwie.
            // ⚠ `galaz` liczy od Z53 **gałąź RAZEM z osobami z reguł**, bo to ona rozstrzyga,
            // czy pozycja „Zespół" ma treść. Nazwa została, znaczenie się poszerzyło — i jest to
            // zapisane tutaj, a nie domyślane z nazwy.
            'galaz' => count($zespol),
            'spolka' => $moznaSpolke ? count($wSpolce) : 0,
            'moze_firme' => $moznaSpolke,
        ];
    }

    /**
     * Zatrudnienia, które wolno oglądać: własne plus wszyscy podwładni na dowolnej głębokości.
     *
     * @return list<int> zawsze z własnym identyfikatorem na pierwszym miejscu
     */
    public function dozwolone(int $companyId, int $employmentId): array
    {
        return self::galaz($this->hierarchia->krawedzie($companyId), $employmentId);
    }

    /**
     * **Ilu podwładnych ma każdy ze wskazanych przełożonych — CAŁA GAŁĄŹ, nie bezpośredni.**
     *
     * ⚠ Powstało po pomiarze z 19.10.2026: ekran pokazywał u jednej osoby „4 podwładnych"
     * w kroku 1 i „dziś 6 osób" w kroku 2. Nie była to pomyłka arytmetyczna, tylko **dwie różne
     * definicje tego samego słowa**: zapytanie liczyło bezpośrednich podwładnych, a krok 2 —
     * gałąź liczoną przez `dozwolone()`. Reguła „Podwładni" daje gałąź, więc gałąź jest
     * jedyną prawdziwą liczbą; ta druga opisywała coś, czego nikt nie nadaje.
     *
     * ⚠ **Jedno czytanie krawędzi na całą listę**, a potem czysta funkcja na każdy korzeń —
     * dwadzieścia wierszy nie może znaczyć dwudziestu zapytań o hierarchię.
     *
     * @param list<int> $korzenie
     * @return array<int, int> zatrudnienie => liczba podwładnych (bez niego samego)
     */
    public function podwladnych(int $companyId, array $korzenie): array
    {
        if ($korzenie === []) {
            return [];
        }
        $krawedzie = $this->hierarchia->krawedzie($companyId);
        $wynik = [];
        foreach ($korzenie as $korzen) {
            // `galaz()` zawiera korzeń, więc podwładni to reszta.
            $wynik[$korzen] = max(0, count(self::galaz($krawedzie, $korzen)) - 1);
        }

        return $wynik;
    }

    /**
     * Złączenie gałęzi hierarchii z osobami widocznymi z reguł — **bez powtórzeń i z gałęzią
     * z przodu** (zlecenie Z53 §2).
     *
     * ⚠ Kolejność nie jest kosmetyką: pierwszym identyfikatorem ma zostać właściciel kalendarza,
     * bo na tym stoi `przefiltruj()` („nic nie wskazano, więc pokaż właściciela"). Dołożenie
     * osób z reguł na koniec zachowuje tę własność; wymieszanie list by ją odebrało.
     *
     * ⚠ Czysta funkcja — żeby dało się ją sprawdzić testem, a nie tylko układem danych roboczych.
     *
     * @param list<int> $galaz
     * @param list<int> $zRegul
     * @return list<int>
     */
    public static function zlacz(array $galaz, array $zRegul): array
    {
        $wynik = $galaz;
        $ma = [];
        foreach ($galaz as $id) {
            $ma[$id] = true;
        }
        foreach ($zRegul as $id) {
            if (!isset($ma[$id])) {
                $ma[$id] = true;
                $wynik[] = $id;
            }
        }

        return $wynik;
    }

    /**
     * Przejście gałęzi w dół po mapie `podwładny => przełożony`.
     *
     * ⚠ Czysta funkcja i to jest celowe: głębokość większą niż jeden poziom da się dzięki temu
     * udowodnić testem (`tests/pracownik.php`), a nie tylko układem danych roboczych — dziś
     * hierarchia w bazie jest płaska, więc pomiar na ekranie nie pokazałby wnuków.
     *
     * @param array<int, int> $krawedzie podwładny => przełożony, wyłącznie w obrębie jednej spółki
     * @return list<int>
     */
    public static function galaz(array $krawedzie, int $korzen): array
    {
        $dzieci = [];
        foreach ($krawedzie as $podwladny => $przelozony) {
            $dzieci[$przelozony][] = $podwladny;
        }

        $wynik = [$korzen];
        $widziane = [$korzen => true];
        $poziom = [$korzen];
        for ($glebokosc = 0; $glebokosc < self::GLEBOKOSC_MAX && $poziom !== []; $glebokosc++) {
            $nastepny = [];
            foreach ($poziom as $wezel) {
                foreach ($dzieci[$wezel] ?? [] as $dziecko) {
                    // ⚠ Warunek „już widziane” nie jest ozdobą: przy cyklu w danych zastanych
                    // bez niego ta sama osoba wpadałaby do listy raz za razem.
                    if (isset($widziane[$dziecko])) {
                        continue;
                    }
                    $widziane[$dziecko] = true;
                    $wynik[] = $dziecko;
                    $nastepny[] = $dziecko;
                }
            }
            $poziom = $nastepny;
        }

        return $wynik;
    }

    /**
     * Zasięg **bez odznaczonych** — to jest domyślna droga widoku zespołu.
     *
     * ⚠ Domyślnie widać WSZYSTKICH w zasięgu; kontrolka służy do zawężania (zlecenie
     * `kalendarz-zespolu-domyslny-wybor` §1.1). Pierwsza wersja miała odwrotnie i skutek był
     * gorszy niż suma części: widok „Zespół” wyglądał przy pierwszym wejściu **dokładnie tak samo
     * jak „Tylko ja”** — jeden wiersz — więc przełącznik czytał się jako zepsuty. Funkcja,
     * do której trzeba się domyślić drogi, dla użytkownika nie istnieje.
     *
     * ⚠ Wejściem są **wyłączenia**, nie włączenia: osoba, która weszła do gałęzi po zapisaniu
     * filtru, nie ma jak być wyłączona, więc pojawia się sama.
     *
     * @param list<int> $dozwolone
     * @param list<int> $wykluczone
     * @return list<int>
     */
    public static function bezWykluczonych(array $dozwolone, array $wykluczone): array
    {
        if ($wykluczone === []) {
            return $dozwolone;
        }
        $poza = [];
        foreach ($wykluczone as $id) {
            $poza[$id] = true;
        }

        return array_values(array_filter($dozwolone, static fn (int $id): bool => !isset($poza[$id])));
    }

    /**
     * Wyłączenia z listy zaznaczonych: wszystko z zasięgu, czego użytkownik nie zaznaczył.
     *
     * ⚠ Formularz z polami wyboru przysyła **zaznaczone**, a tabela trzyma **odznaczone** —
     * przeliczenie robi się TU, w jednym miejscu, na zasięgu z chwili zapisu. Dzięki temu
     * znacznik w formularzu zostaje naturalny (człowiek odznacza kogoś, kogo nie chce widzieć),
     * a trwały zapis jest listą zakazanych, która nie starzeje się po cichu.
     *
     * @param list<int> $dozwolone
     * @param list<int> $zaznaczone
     * @return list<int>
     */
    public static function wykluczeniaZZaznaczonych(array $dozwolone, array $zaznaczone): array
    {
        $ma = [];
        foreach ($zaznaczone as $id) {
            $ma[$id] = true;
        }

        return array_values(array_filter($dozwolone, static fn (int $id): bool => !isset($ma[$id])));
    }

    /**
     * Nowe wyłączenia po zapisie z kontrolki, która pokazywała **tylko część zasięgu**.
     *
     * ⚠ **To jest miejsce, w którym limit wyświetlania mógłby zamienić się w filtr** — i dlatego
     * nie zamienia się. Kontrolka przy zasięgu 53 osób pokazuje 30 (limit) albo tyle, ile pasuje
     * do szukanego tekstu. Gdyby zapis liczył wyłączenia z całego zasięgu, jedno kliknięcie
     * „Pokaż wybrane" **ukryłoby 23 osoby, których człowiek nawet nie widział** — a przy liście
     * zakazanych oznaczałoby to, że pracownik przyjęty jutro trafia na listę wyłączeń, bo
     * „był niewidoczny w chwili zapisu". Dokładnie ta usterka, dla której reguła wyłączeń
     * powstała (`docs/etap3-logika-urlopowa.md` §8b).
     *
     * Reguła: **decyzja dotyczy wyłącznie tego, co człowiek widział.** Dla identyfikatorów
     * pokazanych w kontrolce bierzemy jego zaznaczenia; dla pozostałych zostaje stan poprzedni.
     *
     * @param list<int> $stare zapamiętane wyłączenia
     * @param list<int> $widoczne identyfikatory POKAZANE w kontrolce (już przecięte z zasięgiem)
     * @param list<int> $zaznaczone identyfikatory zaznaczone w formularzu (już przecięte z zasięgiem)
     * @return list<int>
     */
    public static function wykluczeniaPoZapisie(array $stare, array $widoczne, array $zaznaczone): array
    {
        $pokazane = [];
        foreach ($widoczne as $id) {
            $pokazane[$id] = true;
        }
        $ma = [];
        foreach ($zaznaczone as $id) {
            $ma[$id] = true;
        }

        $wynik = [];
        // Poza widoczną listą nic się nie zmienia — człowiek o tych osobach nie decydował.
        foreach ($stare as $id) {
            if (!isset($pokazane[$id])) {
                $wynik[$id] = true;
            }
        }
        // W obrębie widocznej listy rozstrzygają zaznaczenia.
        foreach ($widoczne as $id) {
            if (!isset($ma[$id])) {
                $wynik[$id] = true;
            }
        }

        return array_values(array_map('intval', array_keys($wynik)));
    }

    /**
     * Przecięcie żądanych identyfikatorów z dozwolonymi, z zachowaniem kolejności dozwolonych.
     *
     * ⚠ To jest ten jeden warunek, którego usunięcie otwiera IDOR — i dokładnie on jest łamany
     * w bramce ekranowej. Używa go **wyłącznie** droga jawnego wskazania osób w adresie
     * (`?osoby=`): żądanie „pokaż dokładnie te osoby” trzeba przeciąć z zasięgiem, bo
     * identyfikatory przyszły z zewnątrz. Pusta lista znaczy tu „nic nie wskazano”, więc
     * zostaje sam właściciel kalendarza — domyślny widok buduje `bezWykluczonych()`.
     *
     * @param list<int> $zadane
     * @param list<int> $dozwolone
     * @return list<int>
     */
    public static function przefiltruj(array $zadane, array $dozwolone): array
    {
        if ($zadane === []) {
            return $dozwolone === [] ? [] : [$dozwolone[0]];
        }

        return self::wZasiegu($zadane, $dozwolone);
    }

    /**
     * Samo przecięcie z zasięgiem, **bez żadnej domyślności**.
     *
     * ⚠ Rozdzielone od `przefiltruj()` po pomiarze, nie z upodobania do małych funkcji: domyślność
     * „pusta lista znaczy tylko ja” jest właściwa dla ADRESU (nic nie wskazano, więc pokaż
     * właściciela), ale wyciekła do zapisu FORMULARZA — a tam pusta lista znaczy „odznaczyłem
     * wszystkich”. Zmierzone: po odznaczeniu wszystkich pięciu osób na ekranie zostawał jeden
     * wiersz, którego nikt nie zaznaczył, i zdanie „filtr ukrył 4 z 5” przy prośbie o ukrycie
     * pięciu. Jedna decyzja — jedno miejsce; dwa znaczenia tej samej pustej listy muszą stać
     * w dwóch osobnych funkcjach, żeby nie dało się ich pomylić.
     *
     * @param list<int> $zadane
     * @param list<int> $dozwolone
     * @return list<int>
     */
    public static function wZasiegu(array $zadane, array $dozwolone): array
    {
        $szukane = [];
        foreach ($zadane as $id) {
            $szukane[$id] = true;
        }

        return array_values(array_filter($dozwolone, static fn (int $id): bool => isset($szukane[$id])));
    }
}
