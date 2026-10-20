<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PracownikRepository;

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
        foreach ($this->reguly->regulyWidza($companyId, $accountId, $widzEmploymentId) as $r) {
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
        foreach ($this->reguly->regulyWidza($companyId, $accountId, $widzEmploymentId) as $r) {
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
