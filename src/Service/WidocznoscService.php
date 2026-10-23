<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\GroupRepository;

/**
 * Reguły widoczności kalendarza — **zapis, walidacja i dziennik zdarzeń** (zlecenie Z53).
 * Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ **Walidacja jest TUTAJ, a nie w szablonie.** Ekran wyszarza pozycje, których nie wolno
 * wybrać, ale wyszarzenie nie jest zabezpieczeniem: żądanie da się wysłać z ręki, a wtedy
 * jedynym, co stoi między nim a bazą, jest ta klasa (CLAUDE.md §10 i §11). Dlatego każdy zapis
 * sprawdza od nowa: czy konto/grupa/zatrudnienie **należą do tej firmy**, czy wskazany podmiot
 * ma w ogóle gałąź, gdy celem są „podwładni", i czy wybór mieści się w białych listach.
 *
 * ⚠ **Treść wpisu w dzienniku to IDENTYFIKATORY, nie nazwiska** (§5 zlecenia, CLAUDE.md §18).
 * To odstępstwo od tego, co robi `GrupyService` — tam nazwiska wolno było zapisać, bo czytający
 * dziennik widzi je i tak na `/admin/konta`. Tutaj zapis mówi, **kto czyje nieobecności może
 * oglądać**, czyli opisuje dostęp do danych o zdrowiu: para nazwisk w treści wpisu byłaby tą
 * informacją zapisaną drugi raz, w miejscu, z którego się jej nie kasuje. Nazwy składa ekran
 * dziennika z identyfikatorów, przy odczycie.
 *
 * ⚠ **Reguła domyślna firmy nie daje się usunąć.** „Wszyscy przełożeni › Swoi podwładni"
 * istnieje zawsze — gdy wiersza jeszcze nie ma, obowiązuje zakres domyślny. Zakaz stoi tu,
 * a nie tylko w braku kosza na ekranie.
 */
final class WidocznoscService
{
    /** Powody odmowy — kody, nie zdania: tekst dla człowieka składa kontroler. */
    public const POWOD_DUPLIKAT = 'duplikat';
    public const POWOD_PODMIOT = 'podmiot';
    public const POWOD_CEL = 'cel';
    public const POWOD_BEZ_ETATU = 'bez_etatu';
    public const POWOD_DOMYSLNA = 'domyslna';
    public const POWOD_BRAK = 'brak';
    public const POWOD_SAM_SIEBIE = 'sam_siebie';

    public function __construct(
        private readonly CalendarAccessRepository $reguly,
        private readonly GroupRepository $grupy,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * **Czy stan okna wolno zapisać** — `null` znaczy „wolno", kod powodu znaczy odmowę
     * (zlecenie Z80 A1 i A6).
     *
     * ⚠ **Funkcja czysta, bo inaczej nie da się jej udowodnić testem.** Te trzy odmowy dotyczą
     * stanów, które OKNO potrafi pokazać, a reguła nie potrafi znaczyć — rozstrzyga o nich kształt
     * żądania, nie baza. Postawione jako `if`-y w kontrolerze wymagałyby do sprawdzenia pełnego
     * żądania HTTP; tutaj sprawdza je `tests/widocznosc.php`, bez bazy i bez przeglądarki.
     *
     * ⚠ To NIE zastępuje odmów z `zapisz()`, a je poprzedza: tam pilnujemy tego, co da się
     * sprawdzić wyłącznie w bazie (cudza firma, zablokowane konto, brak etatu), tu — tego,
     * co wynika z samego żądania.
     *
     * ⚠ Przy regule stałej pytanie nie powstaje: podmiot i cel ma z definicji
     * (`CalendarAccessRepository::STALE`), a zapisać w niej można wyłącznie zakres.
     *
     * @param array{stala: bool, wybor_kto: bool, wybor_czyje: bool, cala_firma_zadana: bool,
     *              czyje: string} $stan stan okna z `VisibilityController::stanOkna()`
     */
    public static function powodStanuOkna(array $stan): ?string
    {
        if ($stan['stala']) {
            return null;
        }
        if (!$stan['wybor_kto']) {
            return self::POWOD_PODMIOT;
        }
        if (!$stan['wybor_czyje']) {
            return self::POWOD_CEL;
        }
        // ⚠ **Dwa rodzaje celu naraz: „cała firma" i wskazane grupy.** Druga wyrażalna
        // kombinacja („cała firma" + wskazane osoby) odrzuca się w `sprawdzCel()`, bo tam oba
        // rodzaje docierają do serwisu; przy grupach cel zapisuje się jako `groups` i znacznik
        // całej firmy zostaje tylko w żądaniu.
        if ($stan['cala_firma_zadana'] && $stan['czyje'] === ZakresWidocznosci::CZYJE_GRUPY) {
            return self::POWOD_CEL;
        }

        return null;
    }

    /**
     * Zapis reguły — nowej albo zmienionej. Zwraca `null` przy powodzeniu, kod powodu przy odmowie.
     *
     * @param list<int> $grupyCelu
     */
    public function zapisz(
        ?int $ruleId,
        int $companyId,
        string $ktoRodzaj,
        int $ktoId,
        string $czyjeRodzaj,
        array $grupyCelu,
        string $zakres,
        ?int $accountId,
        ?string $aktor
    ): ?string {
        if (!ZakresWidocznosci::zapisywalny($zakres)) {
            return self::POWOD_CEL;
        }
        $powod = $this->sprawdzPodmiot($companyId, $ktoRodzaj, $ktoId);
        if ($powod !== null) {
            return $powod;
        }
        $powod = $this->sprawdzCel($companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu);
        if ($powod !== null) {
            return $powod;
        }

        // ⚠ **Reguły stałe firmy mają JEDNĄ drogę zapisu — zmianę zakresu.** Dotyczy to obu:
        // „Wszyscy przełożeni" i „Administratorzy". Ani podmiotu, ani celu nie da się w nich
        // przestawić, bo przestałyby być tym, czym są; ekran też ich tak nie pokazuje (ołówek
        // otwiera od razu krok zakresu).
        if (CalendarAccessRepository::stala($ktoRodzaj)) {
            $przed = $this->reguly->regulaStala($companyId, $ktoRodzaj);
            $this->reguly->ustawStala($companyId, $ktoRodzaj, $zakres, $accountId);
            $this->zapiszWDzienniku(
                $przed === null ? 'calendar_access.granted' : 'calendar_access.changed',
                $accountId,
                $aktor,
                $companyId,
                $przed['id'] ?? 0,
                $ktoRodzaj,
                $ktoId,
                $ktoRodzaj === ZakresWidocznosci::KTO_ADMINI
                    ? ZakresWidocznosci::CZYJE_FIRMA
                    : ZakresWidocznosci::CZYJE_PODWLADNI,
                [],
                $zakres,
                $przed === null ? null : (string) $przed['scope'],
            );

            return null;
        }

        if ($ruleId === null) {
            $nowe = $this->reguly->dodaj($companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, $accountId);
            if ($nowe === null) {
                return self::POWOD_DUPLIKAT;
            }
            $this->zapiszWDzienniku('calendar_access.granted', $accountId, $aktor, $companyId,
                $nowe, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, null);

            return null;
        }

        $przed = $this->reguly->regula($ruleId, $companyId);
        if ($przed === null) {
            return self::POWOD_BRAK;
        }
        if (CalendarAccessRepository::stala((string) $przed['subject_kind'])) {
            // Reguła stała nie zmienia podmiotu ani celu — tu trafia tylko zmiana zakresu.
            return $this->zapisz(null, $companyId, (string) $przed['subject_kind'], 0,
                (string) $przed['target_kind'], [], $zakres, $accountId, $aktor);
        }
        if (!$this->reguly->zmien($ruleId, $companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres)) {
            return self::POWOD_DUPLIKAT;
        }
        $this->zapiszWDzienniku('calendar_access.changed', $accountId, $aktor, $companyId, $ruleId,
            $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, self::opisStanu($przed));

        return null;
    }

    /** Usunięcie reguły; `null` = usunięte, kod powodu = odmowa. */
    public function usun(int $ruleId, int $companyId, ?int $accountId, ?string $aktor): ?string
    {
        $przed = $this->reguly->regula($ruleId, $companyId);
        if ($przed === null) {
            return self::POWOD_BRAK;
        }
        if (CalendarAccessRepository::stala((string) $przed['subject_kind'])) {
            return self::POWOD_DOMYSLNA;
        }
        if (!$this->reguly->usun($ruleId, $companyId)) {
            return self::POWOD_BRAK;
        }
        $this->zapiszWDzienniku('calendar_access.revoked', $accountId, $aktor, $companyId, $ruleId,
            $przed['subject_kind'],
            self::podmiotId($przed),
            $przed['target_kind'],
            $przed['grupy'],
            (string) $przed['scope'],
            null);

        return null;
    }

    /**
     * **Sprzątnięcie reguł po usuniętej grupie** — z wpisem w dzienniku (§1 zlecenia).
     *
     * ⚠ Woła się to PRZED usunięciem grupy, bo po nim nie ma już czego policzyć. Reguła
     * z tą grupą po stronie „kto widzi" znika w całości; po stronie „czyje" grupa wypada
     * z listy, a reguła bez ani jednej grupy znika razem z nią.
     *
     * ⚠ Kaskada bazy zrobiłaby to samo **po cichu** — a administrator dowiedziałby się
     * o utracie dostępu od pracownika, nie z dziennika.
     */
    public function poUsunieciuGrupy(int $companyId, int $groupId, ?int $accountId, ?string $aktor): void
    {
        $dotkniete = $this->reguly->regulyZGrupa($companyId, $groupId);
        if ($dotkniete['kto'] === [] && $dotkniete['czyje'] === []) {
            return;
        }
        $skrocone = 0;
        $skasowane = count($dotkniete['kto']);
        foreach ($dotkniete['czyje'] as $r) {
            if ($r['zostanie'] === 0) {
                $skasowane++;
                continue;
            }
            $skrocone++;
        }
        $this->reguly->poUsunieciuGrupy($companyId, $groupId, $dotkniete['kto'], $dotkniete['czyje']);
        $this->events->record('calendar_access.auto_changed', $accountId, null, $aktor,
            'company_group', $groupId, $companyId,
            ['skasowanych' => $skasowane, 'skroconych' => $skrocone]);
    }

    /**
     * Podmiot musi należeć do TEJ firmy — inaczej reguła z ALFY dawałaby dostęp w BECIE.
     *
     * ⚠ Sprawdzamy przez listy, którymi ekran wypełnia okno: jedno źródło prawdy o tym, kogo
     * wolno wybrać, zamiast drugiego zapytania, które mogłoby się z nimi rozjechać.
     */
    private function sprawdzPodmiot(int $companyId, string $ktoRodzaj, int $ktoId): ?string
    {
        // ⚠ Reguły stałe nie mają podmiotu wskazywanego z listy — numer musi być zerem,
        // inaczej ktoś próbuje przerobić je na zwykłą regułę.
        if (CalendarAccessRepository::stala($ktoRodzaj)) {
            return $ktoId === 0 ? null : self::POWOD_PODMIOT;
        }
        if ($ktoRodzaj === ZakresWidocznosci::KTO_PRZELOZONY) {
            return $this->jestPrzelozonym($companyId, $ktoId) ? null : self::POWOD_PODMIOT;
        }
        if ($ktoRodzaj === ZakresWidocznosci::KTO_GRUPA) {
            return $this->grupy->find($ktoId, $companyId) === null ? self::POWOD_PODMIOT : null;
        }
        if ($ktoRodzaj === ZakresWidocznosci::KTO_KONTO) {
            return $this->kandydatem($companyId, $ktoId) ? null : self::POWOD_PODMIOT;
        }

        return self::POWOD_PODMIOT;
    }

    /**
     * Cel musi być z białej listy, a jego grupy — z tej firmy.
     *
     * ⚠ **„Podwładni" przy koncie BEZ etatu w tej firmie jest odrzucane po stronie serwera**
     * (decyzja Krzysztofa 19.10.2026). Takie konto nie ma gałęzi, więc reguła nigdy nic nie da —
     * a reguła, która wygląda na nadany dostęp i nie działa, jest gorsza niż jej brak.
     * Ekran wyszarza tę pozycję, ale to nie ona rozstrzyga.
     *
     * @param list<int> $grupyCelu
     */
    private function sprawdzCel(int $companyId, string $ktoRodzaj, int $ktoId, string $czyjeRodzaj, array $grupyCelu): ?string
    {
        if ($czyjeRodzaj === ZakresWidocznosci::CZYJE_PODWLADNI) {
            if ($ktoRodzaj === ZakresWidocznosci::KTO_KONTO && !$this->maEtat($companyId, $ktoId)) {
                return self::POWOD_BEZ_ETATU;
            }

            return null;
        }
        if ($czyjeRodzaj === ZakresWidocznosci::CZYJE_FIRMA) {
            // ⚠ **„Cała firma" RAZEM ze wskazanymi osobami jest odrzucana** (Z80 A6) — do
            // 23.10.2026 wygrywał zbiór szerszy, czyli cała firma. Powodem tamtej decyzji nie była
            // wygoda, tylko droga bez JavaScriptu: pola wykluczały się wtedy wyłącznie wzrokowo,
            // więc przeglądarka bez skryptów potrafiła wysłać oba i odmowa blokowałaby jej zapis.
            // ⚠ **Odmowa jest bezpieczna TYLKO dlatego, że od Z80 A4 wykluczenie jest strukturalne**:
            // przy zaznaczonej „Całej firmie" serwer renderuje pola osób z `disabled`
            // (`visibility-pozycje.twig`), a `disabled` nie jedzie w żądaniu. Gdyby A4 wypadło,
            // ta odmowa musi wypaść razem z nim — inaczej wraca błąd, który tamta decyzja omijała.
            return $grupyCelu === [] ? null : self::POWOD_CEL;
        }
        $grupyCelu = ZakresWidocznosci::uporzadkuj($grupyCelu);
        if ($grupyCelu === []) {
            return self::POWOD_CEL;
        }
        if ($czyjeRodzaj === ZakresWidocznosci::CZYJE_OSOBY) {
            // ⚠ **Tylko czynne zatrudnienia TEJ firmy.** Identyfikator z innej firmy nie ma
            // prawa wejść do reguły nawet wtedy, gdy ktoś wyśle żądanie z ręki — granica firmy
            // jest w zapytaniu, a nie w wyszarzeniu pozycji w oknie (CLAUDE.md §10).
            if (count($this->reguly->czynneZatrudnienia($companyId, $grupyCelu)) !== count($grupyCelu)) {
                return self::POWOD_CEL;
            }
            // ⚠ **Nie nadaje się dostępu do samego siebie** — ale tylko przy KTO = Osoba
            // (decyzja Krzysztofa 19.10.2026). Przy grupie widzów jest kilkunastu i wyrzucanie
            // ich z celu zabierałoby dostęp do kogoś, kto przypadkiem wszedł do tej samej grupy;
            // własne dane każdy widzi w pełni niezależnie od reguł.
            if ($ktoRodzaj === ZakresWidocznosci::KTO_KONTO
                && array_intersect($this->reguly->etatyKonta($companyId, $ktoId), $grupyCelu) !== []) {
                return self::POWOD_SAM_SIEBIE;
            }

            return null;
        }
        if ($czyjeRodzaj !== ZakresWidocznosci::CZYJE_GRUPY) {
            return self::POWOD_CEL;
        }
        foreach ($grupyCelu as $id) {
            if ($this->grupy->find($id, $companyId) === null) {
                return self::POWOD_CEL;
            }
        }

        return null;
    }

    private function jestPrzelozonym(int $companyId, int $employmentId): bool
    {
        foreach ($this->reguly->przelozeni($companyId, '', 1000, 0) as $p) {
            if ($p['id'] === $employmentId) {
                return true;
            }
        }

        return false;
    }

    private function kandydatem(int $companyId, int $accountId): bool
    {
        foreach ($this->reguly->kandydaciKonta($companyId, '', 1000, 0) as $k) {
            if ($k['id'] === $accountId) {
                return true;
            }
        }

        return false;
    }

    private function maEtat(int $companyId, int $accountId): bool
    {
        foreach ($this->reguly->kandydaciKonta($companyId, '', 1000, 0) as $k) {
            if ($k['id'] === $accountId) {
                return $k['etat'];
            }
        }

        return false;
    }

    /** @param array<string, mixed> $regula */
    private static function podmiotId(array $regula): int
    {
        return match ($regula['subject_kind']) {
            ZakresWidocznosci::KTO_KONTO => (int) $regula['subject_account_id'],
            ZakresWidocznosci::KTO_PRZELOZONY => (int) $regula['subject_employment_id'],
            ZakresWidocznosci::KTO_GRUPA => (int) $regula['subject_group_id'],
            default => 0,
        };
    }

    /**
     * Stan reguły jednym napisem — do pola „było" w dzienniku.
     *
     * ⚠ To ten sam napis, co podpis reguły, i **nie jest to przypadek**: wpis o zmianie ma
     * nieść stan poprzedni w postaci, która jednoznacznie go identyfikuje, a podpis jest
     * dokładnie tym. Drugi format znaczyłby drugie miejsce do rozjechania się.
     *
     * @param array<string, mixed> $regula
     */
    private static function opisStanu(array $regula): string
    {
        return ZakresWidocznosci::podpis(
            (string) $regula['subject_kind'],
            self::podmiotId($regula),
            (string) $regula['target_kind'],
            $regula['grupy'],
            (string) $regula['scope'],
        );
    }

    /** @param list<int> $grupyCelu */
    private function zapiszWDzienniku(
        string $kod,
        ?int $accountId,
        ?string $aktor,
        int $companyId,
        int $ruleId,
        string $ktoRodzaj,
        int $ktoId,
        string $czyjeRodzaj,
        array $grupyCelu,
        string $zakres,
        ?string $bylo
    ): void {
        // ⚠ **Przy wskazanych osobach zapisujemy NUMERY ZATRUDNIEŃ, tak samo jak przy grupach**
        // (zlecenie Z57 U2). Wcześniej wpis niósł sam napis `persons`, więc dziennik nie miał
        // z czego powiedzieć, kogo reguła dotyczyła — a po jej usunięciu nie da się tego
        // odtworzyć z niczego innego. Nazwiska dalej **nie** wchodzą do treści wpisu:
        // nazwy składa ekran przy odczycie (`Support\ZdanieDostepu`).
        $zListy = $czyjeRodzaj === ZakresWidocznosci::CZYJE_GRUPY
            || $czyjeRodzaj === ZakresWidocznosci::CZYJE_OSOBY;
        $tresc = [
            'kto' => $ktoRodzaj . ($ktoId > 0 ? ':' . $ktoId : ''),
            'czyje' => $zListy && $grupyCelu !== []
                ? $czyjeRodzaj . ':' . implode(',', ZakresWidocznosci::uporzadkuj($grupyCelu))
                : $czyjeRodzaj,
            'zakres' => $zakres,
        ];
        if ($bylo !== null) {
            $tresc['bylo'] = $bylo;
        }
        $this->events->record($kod, $accountId, null, $aktor, 'calendar_access_rule',
            $ruleId > 0 ? $ruleId : null, $companyId, $tresc);
    }
}
