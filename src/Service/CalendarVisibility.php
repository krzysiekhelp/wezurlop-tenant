<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Support\Odmiana;

/**
 * **Jedna funkcja rozstrzyga, co widz widzi u oglądanej osoby** — dla TRÓJKI
 * (widz, osoba oglądana, firma) zwraca `brak` | `bez_szczegolow` | `ze_szczegolami`
 * (`docs/etap3-logika-urlopowa.md` §8b, przebudowa ze zlecenia Z53 z 19.10.2026).
 *
 * ⚠ **Przebudowa, nie dołożenie drugiej drogi.** Do 19.10.2026 ta klasa liczyła JEDNĄ LICZBĘ
 * NA KONTO I FIRMĘ (0 / 1 / 2) z uprawnień `calendar.view_all` i `calendar.view_all_details`,
 * a ta liczba jechała na cały ekran. Od Z53 szczegółowość zależy od tego, **kogo się ogląda**,
 * bo reguła dostępu może obejmować jedną grupę, a nie całą firmę. Uprawnień tych nic już nie
 * czyta; role, które je nadawały, zostają w bazie jako martwy zapis do osobnej decyzji.
 *
 * ⚠ **Trzy źródła, jeden wynik: MAKSIMUM** (`ZakresWidocznosci::wyzszy()`).
 *   1. własne dane — zawsze `ze_szczegolami`,
 *   2. hierarchia — przełożony widzi swoją gałąź w zakresie ustawionym przez administratora
 *      dla tej firmy (domyślnie `bez_szczegolow`),
 *   3. reguły dostępu — konto albo grupa, do której widz należy, wskazane po stronie „kto widzi".
 * Gdyby porównanie stało w trzech miejscach, czwarte źródło dołożone za miesiąc trafiłoby
 * do dwóch z nich.
 *
 * ⚠ **Zasięg i szczegółowość dalej są dwiema osiami, choć reguła nadaje je razem.** Reguła mówi
 * „kto widzi kogo" (zasięg) i „jak dokładnie" (szczegółowość) jednym zapisem, ale liczone są
 * osobno: `zasiegZRegul()` i `widziCalaFirme()` odpowiadają na pierwsze pytanie,
 * `zakresy()` na drugie. Zlanie ich dałoby przełożonego, który widzi szczegół, bo ma szeroki
 * zasięg — dokładnie to, przed czym §8b ostrzegał od 23.09.2026.
 *
 * ⚠ **Czytane z bazy przy KAŻDYM żądaniu**, więc odebranie reguły działa natychmiast, a nie
 * „po wylogowaniu" (CLAUDE.md §8).
 */
final class CalendarVisibility
{
    public const UPRAWNIENIE_EKRAN = 'calendar.visibility_manage';

    public function __construct(
        private readonly CalendarAccessRepository $reguly,
        private readonly ZasiegPodgladu $zasieg,
        private readonly PracownikRepository $pracownicy,
        private readonly PermissionRepository $uprawnienia,
    ) {
    }

    /**
     * **Zakresy dla kompletu oglądanych osób naraz** — jedno czytanie reguł na ekran, nie jedno
     * na wiersz siatki.
     *
     * ⚠ Siatka zespołu ma do 30 wierszy; pytanie „jaki mam zakres do tej osoby" zadane w pętli
     * dałoby 30 odczytów reguł i 30 odczytów hierarchii na jedno wyświetlenie miesiąca.
     *
     * ⚠ **Własny wiersz zawsze `ze_szczegolami`** — to są własne dane i pracownik widzi je
     * w pełni także w „Tylko ja". Do 19.10.2026 szczegółowość była jednolita dla całej siatki
     * *łącznie z własnym wierszem*, i było to uzasadnione tym, że ekran służy do ZESTAWIANIA.
     * Z53 odwołuje to uzasadnienie wprost (decyzja Krzysztofa): wiersze mają się różnić, bo
     * dostęp do nich naprawdę jest różny, a legenda mówi, co znaczy szare „nieobecny".
     *
     * @param list<int> $osoby identyfikatory zatrudnień, o które pytamy
     * @return array<int, string> zatrudnienie => zakres
     */
    public function zakresy(int $accountId, int $companyId, ?int $widzEmploymentId, array $osoby): array
    {
        if ($osoby === []) {
            return [];
        }
        $galaz = $widzEmploymentId === null ? [] : $this->zasieg->dozwolone($companyId, $widzEmploymentId);
        $wGalezi = [];
        foreach ($galaz as $id) {
            $wGalezi[$id] = true;
        }
        $reguly = $this->regulyZDomyslna($companyId, $accountId, $widzEmploymentId);
        // Mapa grup potrzebna tylko wtedy, gdy jakakolwiek reguła celuje w grupy — inaczej
        // zapytanie o przynależności byłoby odczytem, z którego nikt nie skorzysta.
        $celujeWGrupy = false;
        foreach ($reguly as $r) {
            $celujeWGrupy = $celujeWGrupy || $r['target_kind'] === ZakresWidocznosci::CZYJE_GRUPY;
        }
        $grupyOsob = $celujeWGrupy ? $this->reguly->grupyZatrudnien($companyId, $osoby) : [];
        // ⚠ **Zwolnienie odbiera dostęp NATYCHMIAST — i rozstrzyga o tym jedno miejsce: to.**
        // Reguł po zwolnieniu nie sprzątamy (decyzja Krzysztofa z 19.10.2026): wiersz zostaje,
        // żeby administrator zobaczył przekreślone nazwisko i sam zdecydował. Skoro tak, to
        // dostęp musi znikać przy ODCZYCIE, a nie przy zapisie — i nie wolno tego zostawić
        // wywołującemu. Zmierzone 20.10.2026 przed poprawką: siatka zespołu rzeczywiście nie
        // pokazuje zwolnionych, więc wyglądało to na działające, ale zapytana wprost o zwolnione
        // zatrudnienie ta funkcja odpowiadała `ze_szczegolami`. Każdy nowy widok, który poda
        // własną listę osób (panel osoby, wykaz historyczny, eksport), dostałby wtedy dostęp,
        // którego nikt nie nadał — a reguła dotyczy danych o zdrowiu.
        // ⚠ Jedno zapytanie na komplet, nie jedno na wiersz; i jeden warunek zamiast czterech,
        // bo działa tak samo dla celu „osoby", „grupy", „cała firma" i „podwładni".
        $czynne = [];
        foreach ($this->reguly->czynneZatrudnienia($companyId, $osoby) as $id) {
            $czynne[$id] = true;
        }
        // ⚠ „Wszyscy przełożeni" obejmuje widza tylko wtedy, gdy ktoś pod nim DZIŚ stoi. Gałąź
        // zawiera właściciela, więc jeden element znaczy „bez podwładnych".
        $jestPrzelozonym = count($galaz) > 1;
        // ⚠ **Administratorem czyni UPRAWNIENIE, nie nazwa roli** (zlecenie Z55) — i sprawdza się
        // to przy każdym odczycie, więc odebranie roli odbiera wgląd natychmiast. Jedno pytanie
        // na ekran, nie jedno na wiersz.
        $jestAdminem = $this->jestAdminem($accountId, $companyId);

        $wynik = [];
        foreach ($osoby as $osoba) {
            $toJa = $widzEmploymentId !== null && $osoba === $widzEmploymentId;
            // Własny wiersz zostaje pełny nawet wtedy, gdy etat już wygasł — to są własne dane.
            if (!$toJa && !isset($czynne[$osoba])) {
                $wynik[$osoba] = ZakresWidocznosci::BRAK;
                continue;
            }
            // ⚠ Sama decyzja siedzi w czystej funkcji, żeby dało się ją udowodnić testem
            // (`tests/widocznosc.php`). Tutaj zostaje wyłącznie zebranie danych.
            $wynik[$osoba] = ZakresWidocznosci::dlaOsoby(
                $reguly,
                $toJa,
                isset($wGalezi[$osoba]),
                $jestPrzelozonym,
                $grupyOsob[$osoba] ?? [],
                $osoba,
                $jestAdminem,
            );
        }

        return $wynik;
    }

    /**
     * Reguły widza **z dołożoną regułą domyślną firmy**, gdy ta nie jest jeszcze zapisana.
     *
     * ⚠ To jedyne miejsce, w którym stosuje się domyślność „przełożony widzi bez szczegółów".
     * Gdyby brak wiersza obsługiwał osobno ekran i osobno kalendarz, administrator mógłby
     * widzieć na liście jeden zakres, a pracownik dostawać drugi.
     *
     * @return list<array<string, mixed>>
     */
    private function regulyZDomyslna(int $companyId, int $accountId, ?int $widzEmploymentId): array
    {
        $reguly = $this->reguly->regulyWidza($companyId, $accountId, $widzEmploymentId);
        // ⚠ **Obie reguły stałe dokładają się tą samą drogą**, bo obie znaczą to samo: „brak
        // wiersza to nie brak reguły, tylko nierozstrzygnięta reguła". Firma założona jutro ma
        // więc domyślności bez żadnej migracji, a administrator widzi na liście to samo, co
        // dostaje pracownik.
        $maStala = [];
        foreach ($reguly as $r) {
            $maStala[$r['subject_kind']] = true;
        }
        foreach ([
            ZakresWidocznosci::KTO_PRZELOZENI => [ZakresWidocznosci::CZYJE_PODWLADNI, ZakresWidocznosci::DOMYSLNY_PRZELOZONYCH],
            ZakresWidocznosci::KTO_ADMINI => [ZakresWidocznosci::CZYJE_FIRMA, ZakresWidocznosci::DOMYSLNY_ADMINISTRATOROW],
        ] as $kto => [$cel, $zakres]) {
            if (isset($maStala[$kto])) {
                continue;
            }
            $reguly[] = [
                'id' => 0,
                'scope' => $zakres,
                'target_kind' => $cel,
                'subject_kind' => $kto,
                'grupy' => [],
            ];
        }

        return $reguly;
    }

    /**
     * **Skąd ta osoba ma wgląd w kalendarze tej firmy** — lista źródeł, gotowa do wyświetlenia
     * (zlecenie Z58, karta „Widoczność kalendarza" w panelu osoby).
     *
     * ⚠ **Liczy to TA SAMA funkcja, która egzekwuje dostęp** (`regulyZDomyslna()` plus
     * `obejmujeWidza()`), a nie druga, podobna. Panel pokazujący coś innego niż kalendarz
     * byłby gorszy niż panel milczący: administrator odbierałby dostęp, którego tam nie ma,
     * i zostawiał ten, którego nie widzi.
     *
     * ⚠ Panel jest **tylko do odczytu** — zmiana idzie jednym ekranem `/admin/widocznosc`,
     * bo „kto widzi kogo" jest relacją, a panel jednej osoby pokazuje połowę każdej relacji
     * (ta sama decyzja, co przy usunięciu przełącznika w Z53 §3).
     *
     * ⚠ Reguła celująca w „podwładnych" NIE jest tu pomijana, w odróżnieniu od `zasiegZRegul()`:
     * tam chodziło o to, KOGO widać (gałąź jest już w zasięgu z hierarchii), a tu o to, SKĄD
     * wgląd się bierze — i „z hierarchii" jest odpowiedzią, której administrator szuka najczęściej.
     *
     * @return list<array{rodzaj: string, ikona: string, cel: string, skad: string, zakres: string, osob: int}>
     */
    public function zrodlaWidza(int $accountId, int $companyId, ?int $widzEmploymentId): array
    {
        $jestAdminem = $this->jestAdminem($accountId, $companyId);
        // ⚠ **Przełożonym jest ten, kto ma dziś gałąź** — i tu flaga MUSI być policzona, choć
        // w `zasiegZRegul()` stoi na sztywno `true`. Tam cel „podwładni" i tak jest pomijany
        // (gałąź wchodzi do zasięgu z hierarchii), więc flaga nie ma na co zaważyć; tutaj
        // rozstrzyga o całym wierszu. Bez tego panel KAŻDEJ osoby pokazywał „Swoi podwładni ·
        // 0 osób" jako źródło wglądu — czyli wgląd, którego nie ma (zmierzone na żywo).
        $galaz = $widzEmploymentId === null
            ? 0
            : ($this->zasieg->podwladnych($companyId, [$widzEmploymentId])[$widzEmploymentId] ?? 0);
        $reguly = [];
        foreach ($this->regulyZDomyslna($companyId, $accountId, $widzEmploymentId) as $r) {
            if (ZakresWidocznosci::obejmujeWidza($r, $galaz > 0, $jestAdminem)) {
                $reguly[] = $r;
            }
        }
        if ($reguly === []) {
            return [];
        }
        // Nazwy i liczby jednym odczytem na komplet, nie jednym na wiersz.
        $grupy = $osoby = [];
        foreach ($reguly as $r) {
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_GRUPY) {
                $grupy = array_merge($grupy, $r['grupy']);
            }
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_OSOBY) {
                $osoby = array_merge($osoby, $r['grupy']);
            }
            if (($r['subject_kind'] ?? '') === ZakresWidocznosci::KTO_GRUPA) {
                $grupy[] = (int) ($r['subject_group_id'] ?? 0);
            }
        }
        $nazwyGrup = $this->reguly->nazwyGrupPoId($companyId, array_values(array_unique(array_filter($grupy))));
        $nazwyOsob = $this->reguly->osobyRegul($companyId, array_values(array_unique($osoby)));
        $wFirmie = count($this->pracownicy->zatrudnieniaSpolki($companyId));

        $wynik = [];
        foreach ($reguly as $r) {
            $wynik[] = $this->zrodlo($r, $companyId, $nazwyGrup, $nazwyOsob, $galaz, $wFirmie);
        }

        return $wynik;
    }

    /**
     * Jedno źródło dostępu jako wiersz do wyświetlenia.
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $nazwyGrup
     * @param array<int, array{nazwa: string, czynne: bool}> $nazwyOsob
     * @return array{rodzaj: string, ikona: string, cel: string, skad: string, zakres: string, osob: int}
     */
    private function zrodlo(array $r, int $companyId, array $nazwyGrup, array $nazwyOsob, int $galaz, int $wFirmie): array
    {
        $cel = (string) $r['target_kind'];
        $kto = (string) ($r['subject_kind'] ?? '');
        // ⚠ Liczba osób liczy się per rodzaj celu, bo każdy rodzaj liczy się inaczej —
        // i zawsze z tego samego źródła, z którego liczy ją kalendarz.
        $osob = match ($cel) {
            ZakresWidocznosci::CZYJE_FIRMA => $wFirmie,
            ZakresWidocznosci::CZYJE_PODWLADNI => $galaz,
            ZakresWidocznosci::CZYJE_GRUPY => count($this->reguly->zatrudnieniaGrup($companyId, $r['grupy'])),
            default => count($this->reguly->czynneZatrudnienia($companyId, $r['grupy'])),
        };
        $nazwy = [];
        foreach ($r['grupy'] as $id) {
            $nazwy[] = $cel === ZakresWidocznosci::CZYJE_GRUPY
                ? ($nazwyGrup[(int) $id] ?? '— (usunięta)')
                : ($nazwyOsob[(int) $id]['nazwa'] ?? '— (usunięta)');
        }

        return [
            'rodzaj' => $cel,
            'ikona'  => match ($cel) {
                ZakresWidocznosci::CZYJE_PODWLADNI => 'drzewo',
                ZakresWidocznosci::CZYJE_FIRMA => 'firma',
                ZakresWidocznosci::CZYJE_GRUPY => 'etykieta',
                default => 'konto',
            },
            'cel'    => match ($cel) {
                ZakresWidocznosci::CZYJE_PODWLADNI => 'Swoi podwładni',
                ZakresWidocznosci::CZYJE_FIRMA => 'Cała firma',
                ZakresWidocznosci::CZYJE_GRUPY => 'Grupy: ' . self::wyliczenie($nazwy),
                default => self::wyliczenie($nazwy),
            },
            // ⚠ Bez form osobowych („jako przełożona"): rodzaju gramatycznego osoby nie znamy
            // i nie zamierzamy go zgadywać z imienia. Nazwa reguły mówi to samo i jest prawdą
            // niezależnie od tego, kto nią jest objęty.
            'skad'   => match ($kto) {
                ZakresWidocznosci::KTO_PRZELOZENI => 'z hierarchii · reguła domyślna „Każdy przełożony"',
                ZakresWidocznosci::KTO_ADMINI => 'z uprawnień administratora · reguła domyślna „Administratorzy"',
                ZakresWidocznosci::KTO_PRZELOZONY => 'reguła dla tego przełożonego',
                ZakresWidocznosci::KTO_GRUPA => 'przez grupę '
                    . ($nazwyGrup[(int) ($r['subject_group_id'] ?? 0)] ?? '— (usunięta)'),
                default => 'reguła osobista',
            },
            'zakres' => (string) $r['scope'],
            'osob'   => $osob,
        ];
    }

    /**
     * Najwyżej trzy nazwy, potem „i N innych" — ta sama miara, co w zdaniach dziennika.
     *
     * @param list<string> $nazwy
     */
    private static function wyliczenie(array $nazwy): string
    {
        if ($nazwy === []) {
            return 'wskazane osoby';
        }
        $widoczne = array_slice($nazwy, 0, 3);
        $reszta = count($nazwy) - count($widoczne);

        return implode(', ', $widoczne)
            . ($reszta > 0 ? ' i ' . $reszta . ' ' . Odmiana::inne($reszta) : '');
    }

    /**
     * Czy to konto jest administratorem **w tej firmie** — uprawnienie z roli firmowej albo
     * tenantowej. ⚠ Jedno miejsce na tę odpowiedź: ekran liczy nią wiersz „Administratorzy",
     * a kalendarz rozstrzyga nią dostęp.
     */
    public function jestAdminem(int $accountId, int $companyId): bool
    {
        return in_array(
            ZakresWidocznosci::UPRAWNIENIE_ADMINA,
            $this->uprawnienia->permissionsFor($accountId, $companyId),
            true
        );
    }

    /**
     * Zakres dla JEDNEJ pary — cienka nakładka na `zakresy()`, żeby nie powstała druga
     * implementacja tej samej decyzji.
     */
    public function zakres(int $accountId, int $companyId, ?int $widzEmploymentId, int $osoba): string
    {
        return $this->zakresy($accountId, $companyId, $widzEmploymentId, [$osoba])[$osoba]
            ?? ZakresWidocznosci::BRAK;
    }

    /**
     * **Zatrudnienia widoczne z REGUŁ** — poza własną gałęzią hierarchii.
     *
     * ⚠ Osoby z reguł wchodzą do widoku „Zespół" obok podwładnych i do kontrolki wyboru osób
     * (§2 zlecenia). Nie dostają własnego widoku: „kogo widzę" to jedna lista, a nie tyle list,
     * ile źródeł dostępu.
     *
     * @return list<int>
     */
    public function zasiegZRegul(int $accountId, int $companyId, ?int $widzEmploymentId): array
    {
        $grupy = [];
        $osoby = [];
        // ⚠ Reguły stałe odsiewa ta sama funkcja, co przy liczeniu zakresu — inaczej reguła
        // „Administratorzy" wpuszczałaby **każde konto** na listę całej załogi (zmierzone).
        // ⚠ Flaga przełożonego jest tu `true` i nie ma jak zaważyć: reguła „Wszyscy przełożeni"
        // zawsze celuje w podwładnych, a ten cel pomijamy niżej z osobnego powodu.
        $jestAdminem = $this->jestAdminem($accountId, $companyId);
        // ⚠ **`regulyZDomyslna()`, nie `regulyWidza()`** (zlecenie Z58). Rozjazd zmierzony przy
        // budowie karty „Widoczność kalendarza": `zakresy()` liczyło zakres z regułami stałymi
        // DOMYŚLNYMI (brak wiersza = reguła nierozstrzygnięta), a te dwie funkcje tylko z wierszy
        // w tabeli. Skutek: w firmie bez wiersza `admins_all` administrator miał zakres
        // „ze szczegółami" do ludzi, których nie dostawał do zasięgu — czyli szczegół do nikogo.
        // Firmy założone po Z55 nie mają tych wierszy wcale, bo zakłada je migracja.
        foreach ($this->regulyZDomyslna($companyId, $accountId, $widzEmploymentId) as $r) {
            if (!ZakresWidocznosci::obejmujeWidza($r, true, $jestAdminem)) {
                continue;
            }
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_FIRMA) {
                // Jedna reguła „cała firma" przesłania wszystkie pozostałe — nie ma czego dokładać.
                return $this->pracownicy->zatrudnieniaSpolki($companyId);
            }
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_OSOBY) {
                // ⚠ Wskazane osoby wchodzą do zasięgu **po przefiltrowaniu przez czynne etaty
                // tej firmy** — zwolniona osoba znika z kalendarza sama, bez sprzątania reguł
                // (decyzja Krzysztofa 19.10.2026). Reguła zostaje i widzi ją tylko administrator.
                foreach ($this->reguly->czynneZatrudnienia($companyId, $r['grupy']) as $id) {
                    $osoby[$id] = true;
                }
                continue;
            }
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_PODWLADNI) {
                // ⚠ Gałąź pod widzem jest już w zasięgu z hierarchii — reguła „podwładni"
                // nie poszerza tego, KOGO widać, tylko JAK DOKŁADNIE. To ta sama para osi,
                // o której mówi §8b: zasięg i szczegółowość liczą się osobno.
                continue;
            }
            foreach ($r['grupy'] as $g) {
                $grupy[$g] = true;
            }
        }
        $zGrup = $grupy === []
            ? []
            : $this->reguly->zatrudnieniaGrup($companyId, array_map('intval', array_keys($grupy)));
        foreach ($zGrup as $id) {
            $osoby[$id] = true;
        }

        return array_map('intval', array_keys($osoby));
    }

    /**
     * Czy widz ma w tej firmie regułę obejmującą **całą firmę** — czyli czy pozycja „Firma"
     * w przełączniku widoku ma treść.
     *
     * ⚠ Reguła celująca w grupy NIE daje tej pozycji, choć daje dostęp do ludzi: „Firma" ma
     * znaczyć firmę. Te osoby widać w „Zespole", razem z podwładnymi.
     */
    public function widziCalaFirme(int $accountId, int $companyId, ?int $widzEmploymentId): bool
    {
        $jestAdminem = $this->jestAdminem($accountId, $companyId);
        // ⚠ Ta sama droga co w `zakresy()` i `zasiegZRegul()` — patrz komentarz wyżej.
        foreach ($this->regulyZDomyslna($companyId, $accountId, $widzEmploymentId) as $r) {
            if ($r['target_kind'] === ZakresWidocznosci::CZYJE_FIRMA
                && ZakresWidocznosci::obejmujeWidza($r, true, $jestAdminem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Firmy, w których konto ma jakąkolwiek regułę — biała lista ekranu kalendarza dla konta
     * bez zatrudnienia. Zastępuje dawne `spolkiZPoziomem()`.
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function spolkiZDostepem(int $accountId): array
    {
        return $this->reguly->spolkiZRegula($accountId);
    }

    /**
     * Zakres, w jakim przełożeni tej firmy widzą podwładnych — **jedno miejsce, w którym
     * stosuje się wartość domyślną**. Brak wiersza znaczy „administrator jeszcze nie
     * rozstrzygnął", a to ma znaczyć dokładnie to samo, co `bez_szczegolow`.
     */
    public function zakresPrzelozonych(int $companyId): string
    {
        return $this->zakresStalej($companyId, ZakresWidocznosci::KTO_PRZELOZENI,
            ZakresWidocznosci::DOMYSLNY_PRZELOZONYCH);
    }

    /**
     * Zakres reguły „Administratorzy" — **to samo miejsce i ta sama domyślność**, co u przełożonych.
     *
     * ⚠ Domyślność jest tu odwrotna (`ze_szczegolami`) i to jest decyzja Krzysztofa z 20.10.2026,
     * a nie przeoczenie: administrator i tak może sobie każdy dostęp nadać tym samym ekranem,
     * więc reguła tylko **nazywa** ten stan — a nazwany daje się ograniczyć.
     */
    public function zakresAdministratorow(int $companyId): string
    {
        return $this->zakresStalej($companyId, ZakresWidocznosci::KTO_ADMINI,
            ZakresWidocznosci::DOMYSLNY_ADMINISTRATOROW);
    }

    private function zakresStalej(int $companyId, string $ktoRodzaj, string $domyslny): string
    {
        $regula = $this->reguly->regulaStala($companyId, $ktoRodzaj);

        return $regula !== null && ZakresWidocznosci::zapisywalny($regula['scope'])
            ? $regula['scope']
            : $domyslny;
    }
}
