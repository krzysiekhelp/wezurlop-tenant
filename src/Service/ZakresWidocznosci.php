<?php

declare(strict_types=1);

namespace Wezurlop\Service;

/**
 * **Zakres wglądu w cudzą nieobecność — trzy stany i jedno miejsce, w którym się je porównuje**
 * (zlecenie Z53, 19.10.2026).
 *
 * ```
 * brak            nie widzi nawet tego, że ktoś jest nieobecny
 * bez_szczegolow  widzi, że osoba jest nieobecna — i nic poza tym
 * ze_szczegolami  widzi rodzaj i dokładną nazwę ze słownika kadr
 * ```
 *
 * ⚠ **„Bez szczegółów" nie jest słabszym „rodzajem ogólnym".** Dawny poziom 1 pokazywał słowo
 * „urlop" albo „zwolnienie" — a „zwolnienie" przy nazwisku jest daną o zdrowiu tak samo jak
 * nazwa ze słownika, tylko mniej dokładną. Dlatego ten stan niesie wyłącznie FAKT nieobecności:
 * szary pasek „nieobecny", jeden i ten sam dla wszystkich rodzajów. Rozróżnienie, które nie
 * dociera na ekran, nie ma prawa opuścić serwera — ani w klasie CSS, ani w `title`, ani w JSON-ie
 * (§2 zlecenia).
 *
 * ⚠ **Napisy, nie liczby.** Poprzednie wcielenie tej osi było liczbą 0/1/2 i właśnie na tym się
 * potknęło: „rodzaj ogólny" znika, więc liczba 1 miałaby od dziś inne znaczenie niż w zapisanych
 * wcześniej wierszach dziennika zdarzeń. Napis czyta się w dzienniku bez tłumacza i nie przesuwa
 * się przy następnej zmianie.
 *
 * ⚠ **Porządek jest TOTALNY i liczy go `wyzszy()`.** Regułę może obejmować kilka ścieżek naraz
 * (hierarchia, reguła na konto, reguła na grupę) — wygrywa najwyższy zakres. Gdyby porównanie
 * stało w trzech miejscach, czwarta ścieżka dołożona za miesiąc trafiłaby do dwóch z nich.
 */
final class ZakresWidocznosci
{
    public const BRAK = 'brak';
    public const BEZ_SZCZEGOLOW = 'bez_szczegolow';
    public const ZE_SZCZEGOLAMI = 'ze_szczegolami';

    /**
     * ⚠ **Zakres, w jakim przełożony widzi podwładnych, gdy administrator jeszcze nie
     * rozstrzygnął** (decyzja Krzysztofa 19.10.2026). Zaokrąglamy w dół, bo zaokrąglenie w górę
     * oddałoby rodzaje zwolnień całej kadrze kierowniczej bez decyzji kogokolwiek — a tego
     * nie da się cofnąć komunikatem. Wartość stoi TU, a nie w `DEFAULT` kolumny: dwa źródła
     * jednej domyślności rozjadą się przy pierwszej zmianie.
     */
    public const DOMYSLNY_PRZELOZONYCH = self::BEZ_SZCZEGOLOW;

    /**
     * Podmiot reguły — **cztery rodzaje** (dopisek Krzysztofa z 19.10.2026).
     *
     * ⚠ `KTO_PRZELOZENI` („wszyscy przełożeni") to **reguła domyślna firmy**: istnieje dokładnie
     * jedna, nie da się jej usunąć i nie da się jej dać innego celu niż „podwładni". Dawniej
     * miało to być osobne ustawienie firmy; regułą jest dlatego, że administrator ogląda ją
     * w tym samym wierszu listy i zmienia tym samym oknem, a funkcja licząca zakres nie
     * dostaje przez to drugiej gałęzi.
     *
     * ⚠ `KTO_PRZELOZONY` wskazuje **zatrudnienie**, nie konto: hierarchia łączy zatrudnienia,
     * a jedna osoba bywa zatrudniona w dwóch firmach i ma podwładnych tylko w jednej.
     */
    public const KTO_PRZELOZENI = 'supervisor_all';
    public const KTO_PRZELOZONY = 'supervisor';
    public const KTO_KONTO = 'account';
    public const KTO_GRUPA = 'group';

    /**
     * Czyje nieobecności — **gałąź pod widzem**, cała firma albo lista grup.
     *
     * ⚠ `CZYJE_PODWLADNI` liczy się w chwili ODCZYTU, więc obejmuje też podwładnych, którzy
     * pojawią się jutro. Przy regule na grupę znaczy „gałąź pod każdym członkiem tej grupy",
     * a nie „gałąź pod grupą" — grupa nie jest węzłem hierarchii (§8a: etykieta, nie warstwa
     * zatwierdzania).
     */
    public const CZYJE_PODWLADNI = 'subordinates';
    public const CZYJE_FIRMA = 'company';
    public const CZYJE_GRUPY = 'groups';
    /**
     * ⚠ Wskazane osoby — **zatrudnienia**, nie konta (dopisek z 19.10.2026). „Cała firma" jest
     * w oknie pierwszą pozycją tej samej listy, ale zapisuje się jako `CZYJE_FIRMA`: to inny
     * zbiór (liczony przy każdym odczycie), a nie „wszyscy zaznaczeni dzisiaj".
     */
    public const CZYJE_OSOBY = 'persons';

    /**
     * Podpis reguły domyślnej firmy — stały, bo taka reguła jest dokładnie jedna.
     * ⚠ Zakres NIE wchodzi do tego podpisu, inaczej zmiana zakresu robiłaby **drugą** regułę
     * domyślną zamiast zmieniać jedyną.
     */
    public const PODPIS_DOMYSLNEJ = self::KTO_PRZELOZENI . ':0|' . self::CZYJE_PODWLADNI;

    /**
     * Rosnąco — **kolejność jest umową**, na której stoi `wyzszy()`.
     *
     * @var array<string, int>
     */
    private const SILA = [
        self::BRAK => 0,
        self::BEZ_SZCZEGOLOW => 1,
        self::ZE_SZCZEGOLAMI => 2,
    ];

    /**
     * Zakres z wejścia — **biała lista** (CLAUDE.md §11). Wartość spoza listy to nie błąd
     * do pokazania, tylko brak dostępu: żądanie z podmienionym polem nie ma prawa dać niczego,
     * czego nie dałby pusty formularz.
     */
    public static function zakres(string $wartosc): string
    {
        return isset(self::SILA[$wartosc]) && $wartosc !== self::BRAK ? $wartosc : self::BEZ_SZCZEGOLOW;
    }

    /** Czy napis jest zakresem, który wolno zapisać w regule (`brak` nie jest regułą). */
    public static function zapisywalny(string $wartosc): bool
    {
        return $wartosc === self::BEZ_SZCZEGOLOW || $wartosc === self::ZE_SZCZEGOLAMI;
    }

    /** Wyższy z dwóch zakresów — jedyne miejsce, w którym te stany się porównuje. */
    public static function wyzszy(string $a, string $b): string
    {
        return (self::SILA[$a] ?? 0) >= (self::SILA[$b] ?? 0) ? $a : $b;
    }

    /**
     * **Zakres dla JEDNEJ oglądanej osoby — cała decyzja, jako czysta funkcja.**
     *
     * ⚠ Wydzielone z `CalendarVisibility::zakresy()` po to, żeby dało się to **udowodnić testem**,
     * a nie tylko obejrzeć na ekranie (`tests/widocznosc-kalendarza.php`). Reguła dostępu
     * rozstrzyga o wglądzie w dane o zdrowiu, więc jej kombinacje — hierarchia plus dwie reguły
     * naraz, grupa po jednej stronie i firma po drugiej — muszą być sprawdzalne bez bazy,
     * bez przeglądarki i bez układu danych roboczych. Ta sama lekcja, co przy `ZasiegPodgladu::galaz()`:
     * hierarchia w bazie jest płaska, więc pomiar na ekranie nie pokazałby wnuków.
     *
     * ⚠ **Wynik to MAKSIMUM ze wszystkich źródeł** (zasada nadrzędna Krzysztofa z 19.10.2026):
     * niższa reguła nigdy nie ogranicza wyższej, bez względu na to, którą drogą przyszła.
     *
     * ⚠ **Własne dane zawsze w pełni.** Pracownik widzi swoją nieobecność z nazwą w „Tylko ja",
     * więc wiersz o sobie samym na siatce zespołu nie może pokazywać mniej.
     *
     * @param list<array{subject_kind: string, target_kind: string, scope: string, grupy: list<int>}> $reguly
     *        reguły obowiązujące WIDZA (dobrane już po stronie „kto widzi")
     * @param list<int> $grupyOsoby grupy OGLĄDANEJ osoby
     */
    public static function dlaOsoby(
        array $reguly,
        bool $toJa,
        bool $wGalezi,
        bool $widzJestPrzelozonym,
        array $grupyOsoby,
        int $osobaEtat = 0
    ): string {
        if ($toJa) {
            return self::ZE_SZCZEGOLAMI;
        }
        $zakres = self::BRAK;
        foreach ($reguly as $r) {
            // „Wszyscy przełożeni" obejmuje widza tylko wtedy, gdy ktoś pod nim DZIŚ stoi.
            if ($r['subject_kind'] === self::KTO_PRZELOZENI && !$widzJestPrzelozonym) {
                continue;
            }
            $obejmuje = match ($r['target_kind']) {
                // ⚠ Gałąź liczy się W CHWILI ODCZYTU, więc podwładny dopisany jutro jest objęty
                // regułą jutro, bez dotykania reguły.
                self::CZYJE_PODWLADNI => $wGalezi,
                self::CZYJE_GRUPY => array_intersect($r['grupy'], $grupyOsoby) !== [],
                // ⚠ Wskazane osoby porównujemy po ZATRUDNIENIU — tym samym identyfikatorze,
                // którym jedzie wiersz siatki. Po osobie (`person_id`) reguła z ALFY dotykałaby
                // tej samej osoby w BECIE, czyli przeciekłaby przez granicę firmy.
                self::CZYJE_OSOBY => in_array($osobaEtat, $r['grupy'], true),
                default => true,
            };
            if ($obejmuje) {
                $zakres = self::wyzszy($zakres, $r['scope']);
            }
        }

        return $zakres;
    }

    /** Czy przy tym zakresie wolno pokazać nazwę rodzaju ze słownika kadr. */
    public static function widziRodzaj(string $zakres): bool
    {
        return $zakres === self::ZE_SZCZEGOLAMI;
    }

    /** Czy przy tym zakresie widać cokolwiek — czyli czy osoba w ogóle trafia na ekran. */
    public static function widzi(string $zakres): bool
    {
        return $zakres !== self::BRAK;
    }

    /**
     * **Podpis reguły — jedno miejsce, w którym powstaje napis pilnowany kluczem unikalnym.**
     *
     * ⚠ „Identyczna reguła jest odrzucana" musi być pilnowane przez BAZĘ, a nie przez
     * sprawdzenie w kodzie: „policz, potem wstaw" przepuszcza dwa równoległe żądania z dwóch
     * kart przeglądarki. Klucz unikalny nie obejmie listy grup z tabeli obok, więc całą trójkę
     * sprowadzamy do jednego napisu.
     *
     * ⚠ **Identyfikatory grup są sortowane i odduplikowane**, bo „grupy 3 i 9" to ta sama reguła
     * co „grupy 9 i 3" — inaczej klucz unikalny przepuszczałby duplikat po zmianie kolejności
     * pól wyboru w formularzu.
     *
     * ⚠ Format jest **częścią schematu**: tak samo składa go migracja `20261019140005`.
     * Zmiana formatu wymaga przeliczenia kolumny `signature` migracją, nie samą zmianą tej
     * funkcji — dlatego jest tu test (`tests/widocznosc-kalendarza.php`) z dosłownymi napisami.
     *
     * @param list<int> $grupy identyfikatory po stronie „czyje" — grup albo zatrudnień, zależnie
     *        od `$czyjeRodzaj`; puste dla całej firmy i dla podwładnych
     */
    public static function podpis(string $ktoRodzaj, int $ktoId, string $czyjeRodzaj, array $grupy, string $zakres): string
    {
        // ⚠ Reguła domyślna firmy ma podpis BEZ zakresu — jest jedna i zmiana zakresu ma ją
        // zmieniać, a nie zakładać drugą.
        if ($ktoRodzaj === self::KTO_PRZELOZENI) {
            return self::PODPIS_DOMYSLNEJ;
        }
        $cel = match ($czyjeRodzaj) {
            self::CZYJE_GRUPY => self::CZYJE_GRUPY . ':' . self::lista($grupy),
            self::CZYJE_OSOBY => self::CZYJE_OSOBY . ':' . self::lista($grupy),
            self::CZYJE_PODWLADNI => self::CZYJE_PODWLADNI,
            default => self::CZYJE_FIRMA,
        };

        return $ktoRodzaj . ':' . $ktoId . '|' . $cel . '|' . $zakres;
    }

    /**
     * Posortowana, odduplikowana lista identyfikatorów — ta sama postać w podpisie i w zapisie.
     *
     * @param list<int> $grupy
     * @return list<int>
     */
    public static function uporzadkuj(array $grupy): array
    {
        $czyste = array_values(array_unique(array_filter($grupy, static fn (int $id): bool => $id >= 1)));
        sort($czyste);

        return $czyste;
    }

    /** @param list<int> $grupy */
    private static function lista(array $grupy): string
    {
        return implode(',', self::uporzadkuj($grupy));
    }

    /**
     * Nazwy zakresów dla człowieka — w kartach wyboru, w wierszu reguły i w kaflu.
     *
     * @return array<string, string>
     */
    public static function nazwy(): array
    {
        return [
            self::BEZ_SZCZEGOLOW => 'bez szczegółów',
            self::ZE_SZCZEGOLAMI => 'ze szczegółami',
        ];
    }

    /**
     * Jedno zdanie, co ten wybór naprawdę otwiera. ⚠ Zdanie mówi o **danych**, nie o uprawnieniu:
     * administrator ma wiedzieć, co zobaczy człowiek, a nie jak nazywa się kolumna.
     *
     * @return array<string, string>
     */
    public static function opisy(): array
    {
        return [
            self::BEZ_SZCZEGOLOW => 'Widzi tylko, że osoba jest tego dnia nieobecna — bez powodu '
                . 'i bez rodzaju nieobecności.',
            // ⚠ Bez dopisku „czyli dane o zdrowiu" (decyzja z 19.10.2026): administrator ma
            // zobaczyć, CO się pokaże, a nie usłyszeć ocenę tego, co właśnie wybiera. Przykłady
            // rodzajów mówią to samo mocniej — i to one stoją w podglądzie pięciu dni obok.
            self::ZE_SZCZEGOLAMI => 'Widzi wszystko: rodzaj i dokładną nazwę nieobecności, '
                . 'np. „urlop wypoczynkowy", „zwolnienie lekarskie".',
        ];
    }
}
