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
    /** Z80 B-e: zapis trafił w podpis reguły, która leży wyłączona. */
    public const POWOD_WYLACZONA = 'wylaczona';
    /**
     * ⚠ **Brak zakresu ma WŁASNY powód, a nie `POWOD_CEL`** (zlecenie Z81 pkt 1). Od Z81 krok 3
     * startuje pusty i serwer nie podstawia już zakresu domyślnego, więc „nie wybrano zakresu"
     * przestało być stanem niemożliwym i stało się najzwyklejszą odmową. Dotąd wpadało do
     * powodu celu, czyli ekran mówił „wybierz, czyje nieobecności", kiedy cel był wybrany —
     * zdanie kierowało do złego kroku.
     */
    public const POWOD_ZAKRES = 'zakres';

    /**
     * **Powody braku skutku — ZAPISYWANE w kolumnie `inactive_reason`** (zlecenie Z80 cz. B).
     *
     * ⚠ Osobny zbiór od powodów odmowy wyżej i to jest rozmyśne: tamte żyją jedną odpowiedź
     * HTTP i mówią „czego nie zapisałem", te leżą w bazie miesiącami i mówią „dlaczego ta
     * reguła nic nie daje". Wspólna lista znaczyłaby, że pierwszy nowy powód odmowy da się
     * przez pomyłkę zapisać do bazy jako stan reguły.
     */
    public const BEZ_SKUTKU_PODWLADNI = 'brak_podwladnych';
    public const BEZ_SKUTKU_KONTO = 'konto_nieczynne';
    public const BEZ_SKUTKU_GRUPA = 'grupa_usunieta';
    public const BEZ_SKUTKU_OSOBY = 'bez_czynnych_osob';
    /** Wyłączenie ręczne — nie jest brakiem skutku, ale mieszka w tej samej kolumnie. */
    public const WYLACZONA_RECZNIE = 'recznie';

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
     * ⚠ **Funkcja czysta, bo inaczej nie da się jej udowodnić testem.** Te odmowy dotyczą
     * stanów, które OKNO potrafi pokazać, a reguła nie potrafi znaczyć — rozstrzyga o nich kształt
     * żądania, nie baza. Postawione jako `if`-y w kontrolerze wymagałyby do sprawdzenia pełnego
     * żądania HTTP; tutaj sprawdza je `tests/widocznosc.php`, bez bazy i bez przeglądarki.
     *
     * ⚠ To NIE zastępuje odmów z `zapisz()`, a je poprzedza: tam pilnujemy tego, co da się
     * sprawdzić wyłącznie w bazie (cudza firma, zablokowane konto, brak etatu), tu — tego,
     * co wynika z samego żądania.
     *
     * ⚠ Przy regule stałej pytania o podmiot i cel nie powstają: ma je z definicji
     * (`CalendarAccessRepository::STALE`). Pytanie o ZAKRES powstaje w niej tak samo jak
     * w każdej innej — to jedyny wybór, jaki w tym oknie ma (zlecenie Z81).
     *
     * @param array{stala: bool, wybor_kto: bool, wybor_czyje: bool, wybor_zakres: bool,
     *              cala_firma_zadana: bool, czyje: string} $stan stan okna
     *        z `VisibilityController::stanOkna()`
     */
    public static function powodStanuOkna(array $stan): ?string
    {
        // ⚠ Reguła stała ma własny, JEDEN krok — zakres — więc jego brak odrzuca się także
        // w niej (niżej, po wyjściu z tego skrótu). Podmiotu i celu nie ma po co sprawdzać:
        // ma je z definicji.
        if (!$stan['stala'] && !$stan['wybor_kto']) {
            return self::POWOD_PODMIOT;
        }
        if (!$stan['stala'] && !$stan['wybor_czyje']) {
            return self::POWOD_CEL;
        }
        // ⚠ **Dwa rodzaje celu naraz: „cała firma" i wskazane grupy.** Druga wyrażalna
        // kombinacja („cała firma" + wskazane osoby) odrzuca się w `sprawdzCel()`, bo tam oba
        // rodzaje docierają do serwisu; przy grupach cel zapisuje się jako `groups` i znacznik
        // całej firmy zostaje tylko w żądaniu.
        if (!$stan['stala'] && $stan['cala_firma_zadana']
            && $stan['czyje'] === ZakresWidocznosci::CZYJE_GRUPY) {
            return self::POWOD_CEL;
        }
        // ⚠ **Zakres sprawdzamy OSTATNI i także w regule stałej** (Z81): to jedyny wybór, który
        // reguła stała ma, a kolejność odmów idzie po numerach kroków — inaczej okno z pustym
        // krokiem 1 i pustym krokiem 3 odsyłałoby do trzeciego, zanim powie o pierwszym.
        if (!$stan['wybor_zakres']) {
            return self::POWOD_ZAKRES;
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
        // ⚠ Zakres niezapisywalny (pusty — bo krok 3 startuje pusty od Z81 — albo podmieniony
        // w żądaniu) odrzuca się TYM powodem, a nie powodem celu: zdanie na ekranie ma
        // odesłać do kroku, w którym czegoś brakuje.
        if (!ZakresWidocznosci::zapisywalny($zakres)) {
            return self::POWOD_ZAKRES;
        }
        $powod = $this->sprawdzPodmiot($companyId, $ktoRodzaj, $ktoId);
        if ($powod !== null) {
            return $powod;
        }
        $powod = $this->sprawdzCel($companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu);
        if ($powod !== null) {
            return $powod;
        }
        // ⚠ **Reguły bez skutku NIE ZAPISUJEMY** (Z80 B-f, decyzja Krzysztofa: „nie można
        // aktywować reguły bez sensu"). Odwraca to dawniejsze „zapisz, zadziała gdy się pojawią":
        // skoro system wyłącza regułę, która skutek STRACIŁA, to nadanie reguły, która go NIE MA,
        // różniłoby się od niej tylko tym, że nikt jej jeszcze nie przeliczył.
        // ⚠ Odmowa niesie KONKRETNY powód (`brak_podwladnych`, `konto_nieczynne`, …), bo ekran
        // ma powiedzieć, czego brakuje — „nie da się" bez powodu jest nie do naprawienia.
        // ⚠ Dotyczy też ZMIANY reguły: po zapisie nie wolno zostawić reguły, której automat
        // natychmiast by wyłączył.
        $bezSkutku = $this->skutek([
            'subject_kind' => $ktoRodzaj,
            'subject_account_id' => $ktoRodzaj === ZakresWidocznosci::KTO_KONTO ? $ktoId : 0,
            'subject_employment_id' => $ktoRodzaj === ZakresWidocznosci::KTO_PRZELOZONY ? $ktoId : 0,
            'subject_group_id' => $ktoRodzaj === ZakresWidocznosci::KTO_GRUPA ? $ktoId : 0,
            'target_kind' => $czyjeRodzaj,
            'grupy' => $grupyCelu,
        ], $companyId);
        if ($bezSkutku !== null) {
            return $bezSkutku;
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
                return $this->duplikatCzyWylaczona($companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres);
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
            return $this->duplikatCzyWylaczona($companyId, $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres);
        }
        $this->zapiszWDzienniku('calendar_access.changed', $accountId, $aktor, $companyId, $ruleId,
            $ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres, self::opisStanu($przed));

        return null;
    }

    /**
     * **Czy reguła daje dziś cokolwiek** — `null` znaczy „daje", kod powodu znaczy „nie daje"
     * (zlecenie Z80 B3, rozstrzygnięcia zarządcy i Krzysztofa z 23.10.2026).
     *
     * ⚠ **Każdy warunek pyta DOKŁADNIE tym predykatem, którym pyta zapis.** Podmiot „Osoba" —
     * `kandydatem()`, ten sam, którego używa `sprawdzPodmiot()`; „ma podwładnych" — ta sama
     * lista przełożonych, którą okno pokazuje do wyboru. Druga, podobna definicja znaczyłaby,
     * że reguła daje się zapisać, a następnego dnia automat ją wyłącza „bo według niego nie ma
     * skutku" — albo odwrotnie: że automat zostawia regułę martwą, bo pyta o co innego.
     *
     * ⚠ **Podmiot „Osoba" to NIE „osoba z etatem w tej firmie"** (korekta zlecenia, 23.10.2026).
     * Wolno nim uczynić także konto z rolą nadaną na tę firmę bez etatu i konto z rolą
     * tenantową — czyli kadry i administratorów. Warunek „bez aktywnego zatrudnienia" wyłączyłby
     * dostęp dokładnie tym kontom, dla których ten wariant powstał.
     *
     * ⚠ **Pusta grupa NIE jest brakiem skutku** — ani jako podmiot, ani jako cel (decyzja
     * Krzysztofa z 23.10.2026, świadomie inna niż przy przełożonym bez podwładnych). Grupa
     * zapełni się jutro bez żadnej zmiany w regule, a zmiana składu grupy nie jest zdarzeniem,
     * które ktoś zauważy. **Nie ujednolicaj tego z przełożonym** bez pytania.
     *
     * ⚠ **Przełożony bez podwładnych JEST brakiem skutku** (decyzja Krzysztofa z 23.10.2026)
     * — i odwraca to dwa dawniejsze zdania („dostęp zadziała, gdy się pojawią"). Konsekwencja
     * jest przyjęta świadomie: po powrocie podwładnych regułę włącza CZŁOWIEK, nie system,
     * a przed przypadkową utratą dostępu ostrzega pasek w Hierarchii (część C).
     *
     * @param array<string, mixed> $regula wiersz z `CalendarAccessRepository`
     */
    public function skutek(array $regula, int $companyId): ?string
    {
        $kto = (string) $regula['subject_kind'];
        // Reguły stałe firmy są aktywne zawsze i nie mają przełącznika (§B1).
        if (CalendarAccessRepository::stala($kto)) {
            return null;
        }
        $etatyPodmiotu = [];
        if ($kto === ZakresWidocznosci::KTO_KONTO) {
            if (!$this->kandydatem($companyId, (int) $regula['subject_account_id'])) {
                return self::BEZ_SKUTKU_KONTO;
            }
            $etatyPodmiotu = $this->reguly->etatyKonta($companyId, (int) $regula['subject_account_id']);
        } elseif ($kto === ZakresWidocznosci::KTO_PRZELOZONY) {
            $etatyPodmiotu = [(int) $regula['subject_employment_id']];
            if ($this->reguly->zEtatowZPodwladnymi($companyId, $etatyPodmiotu) === []) {
                return self::BEZ_SKUTKU_PODWLADNI;
            }
        } elseif ($kto === ZakresWidocznosci::KTO_GRUPA) {
            if ($this->grupy->find((int) $regula['subject_group_id'], $companyId) === null) {
                return self::BEZ_SKUTKU_GRUPA;
            }
            $etatyPodmiotu = $this->reguly->zatrudnieniaGrup($companyId, [(int) $regula['subject_group_id']]);
        }

        $czyje = (string) $regula['target_kind'];
        /** @var list<int> $cel */
        $cel = array_map('intval', $regula['grupy'] ?? []);
        if ($czyje === ZakresWidocznosci::CZYJE_PODWLADNI) {
            // ⚠ **Przy podmiocie GRUPA brak gałęzi NIE wyłącza reguły** — i to nie jest
            // przeoczenie, tylko wniosek z dwóch decyzji Krzysztofa z 23.10.2026 naraz:
            // „przełożony bez podwładnych wyłącza" (B3-b) ORAZ „pusta grupa nie wyłącza" (B3-d).
            // Skład grupy zmienia się bez żadnego zdarzenia, które ktoś zauważy — dopisanie
            // pracownika do grupy nie jest decyzją o dostępie — więc wyłączanie po składzie
            // kasowałoby reguły przy zwykłych porządkach w etykietach. Grupę wyłącza wyłącznie
            // jej USUNIĘCIE (wyżej) — stan nieodwracalny, w odróżnieniu od pustego składu.
            // ⚠ Przy grupie z członkami gałąź liczy się pod KAŻDYM z nich, więc jeden członek
            // z podwładnymi wystarcza — ale żaden nie jest warunkiem wyłączenia.
            if ($kto === ZakresWidocznosci::KTO_GRUPA) {
                return null;
            }

            return $this->reguly->zEtatowZPodwladnymi($companyId, $etatyPodmiotu) === []
                ? self::BEZ_SKUTKU_PODWLADNI
                : null;
        }
        if ($czyje === ZakresWidocznosci::CZYJE_OSOBY) {
            return $this->reguly->czynneZatrudnienia($companyId, $cel) === []
                ? self::BEZ_SKUTKU_OSOBY
                : null;
        }
        if ($czyje === ZakresWidocznosci::CZYJE_GRUPY) {
            // ⚠ Zabezpieczenie, nie główna droga: usunięcie grupy SPRZĄTA reguły
            // (`poUsunieciuGrupy()` wyrzuca grupę z celu, a regułę bez ani jednej grupy kasuje),
            // więc pusta lista celu nie powinna się tu zdarzyć. Gdyby jednak grupa zniknęła
            // kaskadą bazy — a to jedyna droga, której nie kontrolujemy — reguła ma o sobie
            // powiedzieć prawdę, a nie udawać czynnej.
            return $cel === [] ? self::BEZ_SKUTKU_GRUPA : null;
        }

        // Cel „cała firma": firma bez ani jednego pracownika nie jest brakiem skutku —
        // pierwszy przyjęty pracownik wchodzi do tego zbioru bez dotykania reguły.
        return null;
    }

    /**
     * **Przeliczenie reguł firmy: wyłącz te, które straciły skutek** (zlecenie Z80 B3).
     *
     * ⚠ **System NIGDY nie włącza reguły sam** (§B4) — dlatego pętla pomija reguły już
     * wyłączone i nie pyta ich o skutek. Włączyć może wyłącznie administrator, świadomie,
     * i tylko regułę, która skutek ma.
     *
     * ⚠ `$zapisz = false` daje PODGLĄD tej samej listy, bez tykania bazy — tego potrzebuje
     * ostrzeżenie w Hierarchii (część C). Jedna funkcja na podgląd i na zapis, bo dwie
     * rozjechałyby się przy pierwszym nowym wyzwalaczu i pasek ostrzegałby o czymś innym,
     * niż zrobi zapis.
     *
     * ⚠ **Lista dróg wołających tę metodę jest ZAMKNIĘTA** (stan na 23.10.2026): zapis
     * hierarchii, wygaszenie etatu z synchronizacji, blokada konta, usunięcie grupy i sam zapis
     * reguły. Wystarcza to dlatego, że **każde** dzisiejsze przejście jest zapisem — zatrudnienie
     * nie gaśnie „z daty". Gdyby kiedyś powstało pole w rodzaju `valid_to`, przeliczanie na
     * drogach przestanie wystarczać i trzeba dołożyć przebieg cykliczny (zadanie w kolejce,
     * w porcjach — CLAUDE.md §0 o limicie 30 s). Na razie go NIE MA świadomie.
     *
     * @return array<int, string> numer reguły => kod powodu, dla reguł wyłączonych tym przebiegiem
     */
    public function przelicz(int $companyId, bool $zapisz, ?int $accountId, ?string $aktor): array
    {
        $wylaczone = [];
        foreach ($this->reguly->reguly($companyId) as $regula) {
            if (CalendarAccessRepository::stala((string) $regula['subject_kind'])) {
                continue;
            }
            if ((int) ($regula['is_active'] ?? 1) !== 1) {
                continue;
            }
            $powod = $this->skutek($regula, $companyId);
            if ($powod === null) {
                continue;
            }
            $ruleId = (int) $regula['id'];
            $wylaczone[$ruleId] = $powod;
            if (!$zapisz) {
                continue;
            }
            if ($this->reguly->ustawAktywnosc($ruleId, $companyId, false, $powod, 'auto', null)) {
                $this->zapiszWDzienniku('calendar_access.auto_disabled', $accountId, $aktor, $companyId,
                    $ruleId, (string) $regula['subject_kind'], self::podmiotId($regula),
                    (string) $regula['target_kind'], array_map('intval', $regula['grupy'] ?? []),
                    (string) $regula['scope'], null, $powod);
            }
        }

        return $wylaczone;
    }

    /**
     * **Przeliczenie po zmianie, która dotyczy JEDNEGO KONTA** (zlecenie Z80 B3, droga 5).
     *
     * ⚠ Osobne wejście, ale TA SAMA pętla: blokada konta nie zna firmy, a kandydatura zależy
     * od etatów i ról w konkretnej firmie — więc najpierw pytamy, gdzie to konto ma reguły,
     * a potem przeliczamy każdą z tych firm zwykłą drogą. Drugiej definicji „co traci skutek"
     * tu nie ma i mieć nie może.
     *
     * @return array<int, string> numer reguły => kod powodu
     */
    public function przeliczDlaKonta(int $accountId, ?int $przez, ?string $aktor): array
    {
        $wylaczone = [];
        foreach ($this->reguly->firmyZRegulamiKonta($accountId) as $companyId) {
            foreach ($this->przelicz($companyId, true, $przez, $aktor) as $ruleId => $powod) {
                $wylaczone[$ruleId] = $powod;
            }
        }

        return $wylaczone;
    }

    /**
     * **Wyłączenie i włączenie reguły przez administratora** — `null` = zrobione, kod = odmowa
     * (zlecenie Z80 B2 i B4).
     *
     * ⚠ **Włączyć wolno WYŁĄCZNIE regułę, która ma skutek** (§B4) i rozstrzyga o tym serwer,
     * a nie zablokowany przełącznik na ekranie: żądanie da się złożyć z ręki (CLAUDE.md §10).
     * Odmowa niesie kod powodu braku skutku, żeby ekran mógł powiedzieć, CZEGO brakuje.
     *
     * ⚠ Reguły stałej nie da się ani wyłączyć, ani włączyć — jest aktywna zawsze (§B1).
     */
    public function przestaw(int $ruleId, int $companyId, bool $aktywna, ?int $accountId, ?string $aktor): ?string
    {
        $regula = $this->reguly->regula($ruleId, $companyId);
        if ($regula === null) {
            return self::POWOD_BRAK;
        }
        if (CalendarAccessRepository::stala((string) $regula['subject_kind'])) {
            return self::POWOD_DOMYSLNA;
        }
        if ($aktywna) {
            $powod = $this->skutek($regula, $companyId);
            if ($powod !== null) {
                return $powod;
            }
        }
        if (!$this->reguly->ustawAktywnosc($ruleId, $companyId, $aktywna, self::WYLACZONA_RECZNIE,
            'manual', $accountId)) {
            // Stan docelowy już był ustawiony — nie jest to błąd, ale i nie ma czego zapisywać
            // w dzienniku: dwa wpisy o tej samej zmianie to dwa razy ta sama historia.
            return null;
        }
        $this->zapiszWDzienniku($aktywna ? 'calendar_access.enabled' : 'calendar_access.disabled',
            $accountId, $aktor, $companyId, $ruleId, (string) $regula['subject_kind'],
            self::podmiotId($regula), (string) $regula['target_kind'],
            array_map('intval', $regula['grupy'] ?? []), (string) $regula['scope'], null,
            $aktywna ? null : self::WYLACZONA_RECZNIE);

        return null;
    }

    /**
     * **Czy w podpis trafiła reguła CZYNNA, czy WYŁĄCZONA** (zlecenie Z80 B-e).
     *
     * ⚠ Jedno i drugie to „taka reguła już jest", ale dla człowieka to dwie różne sytuacje:
     * czynnej nie ma po co nadawać drugi raz, a wyłączoną trzeba WŁĄCZYĆ — i bez tego
     * rozróżnienia odmowa „duplikat" kazałaby jej szukać wśród reguł czynnych, gdzie jej nie ma.
     *
     * @param list<int> $grupyCelu
     */
    private function duplikatCzyWylaczona(
        int $companyId,
        string $ktoRodzaj,
        int $ktoId,
        string $czyjeRodzaj,
        array $grupyCelu,
        string $zakres
    ): string {
        $podpis = ZakresWidocznosci::podpis($ktoRodzaj, $ktoId, $czyjeRodzaj, $grupyCelu, $zakres);
        $istnieje = $this->reguly->regulaPoPodpisie($companyId, $podpis);

        return $istnieje !== null && (int) $istnieje['is_active'] !== 1
            ? self::POWOD_WYLACZONA
            : self::POWOD_DUPLIKAT;
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
        ?string $bylo,
        // ⚠ Powód braku skutku jedzie w treści wpisu jako KOD, nie zdanie (Z80 cz. B): zdanie
        // dla człowieka składa ekran dziennika, a kod da się filtrować i nie zmienia się przy
        // poprawce stylistycznej. Dla wpisów bez powodu zostaje `null` i nie wchodzi do treści.
        ?string $powod = null
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
        if ($powod !== null) {
            $tresc['powod'] = $powod;
        }
        $this->events->record($kod, $accountId, null, $aktor, 'calendar_access_rule',
            $ruleId > 0 ? $ruleId : null, $companyId, $tresc);
    }
}
