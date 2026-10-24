<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use InvalidArgumentException;
use Wezurlop\Db\Connection;
use Wezurlop\License\LicenseGate;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\RozdzialRolException;

/**
 * Dostęp do spółek — osobna rzecz niż konto.
 *
 * ⚠ Rozdzielenie jest sednem modelu (zlecenie `konta-budowa-2` §0.1): **konto to możliwość
 * zalogowania się**, a **dostęp to spółki, które widać**. Pracownik może mieć dane w dwóch
 * spółkach w R2 i dostęp tylko do jednej — albo do żadnej, a mimo to móc się zalogować.
 *
 * ⚠ I to dostęp, a nie konto, kosztuje licencję (§0.2). Dlatego zaproszenie nie może się
 * przerwać na limicie w środku partii, a sprawdzenie limitu siedzi tutaj.
 */
final class AccessService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PermissionRepository $permissions,
        private readonly AccountRepository $accounts,
        private readonly EmploymentRepository $employments,
        private readonly EventLogRepository $events,
        private readonly LicenseGate $license,
    ) {
    }

    /**
     * Zapis dostępu z kontrolki: **dokładnie ten zestaw spółek**, jedno żądanie, jedna transakcja
     * (zlecenie `konta-lista-dostep` §3 i §5).
     *
     * @param list<int> $spolki spółki, które mają zostać zaznaczone po zapisie
     * @return array{account: ?int, utworzono_konto: bool, nadane: list<int>, odebrane: list<int>, odmowy: list<string>}
     */
    public function saveCompanyAccess(int $personId, array $spolki, ?int $przez): array
    {
        return $this->zapisz($personId, $spolki, $przez, true);
    }

    /**
     * Dołożenie dostępu do wskazanych spółek, **bez odbierania pozostałych** — używa tego wysyłka
     * partii zaproszeń, gdzie przełącznik mówi „nadaj też dostęp do spółek z zatrudnienia",
     * a nie „ustaw dostęp dokładnie na te spółki".
     *
     * @param list<int> $spolki
     * @return array{account: ?int, utworzono_konto: bool, nadane: list<int>, odebrane: list<int>, odmowy: list<string>}
     */
    public function addCompanyAccess(int $personId, array $spolki, ?int $przez): array
    {
        return $this->zapisz($personId, $spolki, $przez, false);
    }

    /**
     * Jedyne miejsce, które decyduje o dostępie i o licencji.
     *
     * ⚠ **Konto powstaje tutaj, przy pierwszym zaznaczeniu spółki** (§4 zlecenia): uprawnienie
     * wisi na `role_assignment.account_id`, więc bez wiersza konta nie ma czego zapisać. Konto
     * rodzi się w stanie `niezaproszone` — bez adresu, bez hasła, bez możliwości logowania.
     *
     * ⚠ **Licencja liczy się od zaznaczenia spółki, nie od wysyłki zaproszenia** (§5). Cała
     * decyzja — ile miejsc zajętych, czy wolno dołożyć, założenie konta i zapis przypisań —
     * siedzi w JEDNEJ transakcji, a licznik czyta stan z `FOR UPDATE`. Bez tego dwóch
     * administratorów zapisujących w tej samej chwili policzyłoby ten sam stan i obaj
     * przeszliby przez limit.
     *
     * ⚠ Limit **z licencji** czytany jest POZA transakcją, bo pochodzi z podpisanego pliku,
     * nie z bazy; pod blokadą porównujemy z nim tylko stan bazy.
     *
     * @param list<int> $spolki
     * @return array{account: ?int, utworzono_konto: bool, nadane: list<int>, odebrane: list<int>, odmowy: list<string>}
     */
    private function zapisz(int $personId, array $spolki, ?int $przez, bool $dokladnie): array
    {
        $spolki = array_values(array_unique(array_filter($spolki, static fn (int $id): bool => $id >= 1)));
        $limit = $this->license->limit(self::KLUCZ_LICENCJI);

        $wynik = $this->db->transaction(function () use ($personId, $spolki, $przez, $dokladnie, $limit): array {
            if (!$this->accounts->personExists($personId)) {
                return self::PUSTY + ['odmowy' => [self::POWOD_BRAK_OSOBY]];
            }
            $konto = $this->accounts->findForPersonForUpdate($personId);
            $obecne = $konto === null ? [] : $this->permissions->companyAccessIds($konto['id']);

            $doOdebrania = $dokladnie ? array_values(array_diff($obecne, $spolki)) : [];
            $doNadania = array_values(array_diff($spolki, $obecne));

            // ⚠ Etat sprawdzamy PO OSOBIE i PRZED założeniem konta. Inaczej trzeba by założyć
            // konto, żeby dowiedzieć się, że nie wolno go zakładać — i cofać je wyjątkiem.
            $odmowy = [];
            $dozwolone = [];
            foreach ($doNadania as $spolka) {
                if ($this->employments->personHasActiveInCompany($personId, $spolka)) {
                    $dozwolone[] = $spolka;
                    continue;
                }
                // ⚠ Dostęp wolno nadać wyłącznie do spółki z aktywnym etatem (§3). Kontekst
                // pracownika idzie przez `employment`, więc dostęp bez etatu daje konto,
                // które się loguje i widzi odmowę — usterkę, nie uprawnienie.
                $odmowy[] = self::POWOD_BRAK_ZATRUDNIENIA;
            }
            if ($konto === null && $dozwolone === []) {
                // Nie ma konta i nic nie wejdzie — nie zakładamy konta, którego nikt nie chciał.
                return self::PUSTY + ['odmowy' => array_values(array_unique($odmowy))];
            }

            $utworzono = false;
            if ($konto === null) {
                // ⚠ Limit licencji sprawdzany PRZED założeniem konta, nie po. Nowe konto nie ma
                // czego zwolnić (zero przypisań), więc przy zajętym limicie żaden dostęp nie
                // wejdzie — a konto zostałoby puste i „niezaproszone", chociaż nikt go nie chciał.
                // ⚠ Zmierzone: pierwsza wersja tego zapisu zakładała konto i dopiero potem
                // odbijała się o limit, zostawiając w bazie wiersz bez żadnego dostępu.
                if ($limit !== null && $this->accounts->countLicensedAccessesForUpdate() >= $limit) {
                    return self::PUSTY + ['odmowy' => [self::POWOD_BRAK_LICENCJI]];
                }
                // ⚠ TUTAJ powstaje konto `niezaproszone` (§4): przy pierwszym zaznaczeniu spółki,
                // bo uprawnienie wisi na `account_id` i bez wiersza konta nie ma czego zapisać.
                $konto = ['id' => $this->accounts->createUninvited($personId),
                    'status' => AccountRepository::STAN_NIEZAPROSZONE, 'email' => null];
                $utworzono = true;
            }
            $accountId = $konto['id'];

            // ⚠ Odbieramy PRZED nadaniem: zwolnione miejsce ma być widoczne dla sprawdzenia limitu
            // w tym samym zapisie. Inaczej wymiana „Piaskarz na Beta" przy pełnym limicie
            // odbiłaby się o limit, który sama zwalnia.
            $odebrane = [];
            foreach ($doOdebrania as $spolka) {
                if ($this->permissions->revokeCompanyAccess($accountId, $spolka) > 0) {
                    $odebrane[] = $spolka;
                }
            }

            $nadane = [];
            foreach ($dozwolone as $spolka) {
                if ($limit !== null && $this->accounts->countLicensedAccessesForUpdate() >= $limit) {
                    $odmowy[] = self::POWOD_BRAK_LICENCJI;
                    continue;
                }
                try {
                    $this->permissions->assignRole($accountId, PermissionRepository::ROLA_PRACOWNIK, $spolka, $przez);
                } catch (RozdzialRolException) {
                    // ⚠ Rozdział „administrator tenanta ≠ pracownik" (`etap3` §8c, druga strona
                    // reguły). Odmowa jest JAWNA i idzie do interfejsu jak brak licencji —
                    // ciche pominięcie dałoby wiersz wyglądający jak zapisany.
                    // ⚠ Reguły pilnuje `assignRole()`, nie ten warunek: gdyby stała tu, trzy
                    // pozostałe drogi nadania roli zostałyby bez niej.
                    $odmowy[] = self::POWOD_KONTO_ADMINA;
                    continue;
                }
                $nadane[] = $spolka;
            }

            return ['account' => $accountId, 'utworzono_konto' => $utworzono, 'nadane' => $nadane,
                'odebrane' => $odebrane, 'odmowy' => array_values(array_unique($odmowy))];
        });

        // ⚠ Dziennik POZA transakcją i tylko dla tego, co się faktycznie stało.
        if ($wynik['utworzono_konto']) {
            $this->events->record('account.created_uninvited', $przez, null, null, 'account', $wynik['account']);
        }
        foreach ($wynik['nadane'] as $spolka) {
            $this->events->record('access.granted', $przez, null, null, 'account', $wynik['account'], $spolka);
        }
        foreach ($wynik['odebrane'] as $spolka) {
            $this->events->record('access.revoked', $przez, null, null, 'account', $wynik['account'], $spolka);
        }

        return $wynik;
    }

    /** Wynik „nic się nie stało" — bez konta i bez zmian. */
    private const PUSTY = ['account' => null, 'utworzono_konto' => false, 'nadane' => [], 'odebrane' => []];

    /** Ile dostępów zajmuje dziś licencję i ile ich wolno — do pokazania na ekranie. */
    public function licenceUsage(): array
    {
        return ['uzyte' => $this->accounts->countLicensedAccesses(), 'limit' => $this->license->limit(self::KLUCZ_LICENCJI)];
    }

    /**
     * **Ilu licencji BRAKUJE na tę partię** — czysta funkcja, bo to ona rozstrzyga o odmowie
     * (zlecenie Z84 cz. C).
     *
     * ⚠ Wydzielona po to, żeby dała się udowodnić testem bez bazy i bez pliku licencji:
     * ta jedna liczba decyduje i o ostrzeżeniu w oknie, i o tym, czy serwer przyjmie wysyłkę.
     * Policzona w dwóch miejscach rozjechałaby się przy pierwszej zmianie — a wtedy okno
     * obiecywałoby wysyłkę, którą serwer odrzuca (albo, co gorsze, odwrotnie).
     *
     * ⚠ **`$limit === null` znaczy licencję BEZ limitu i wtedy nie brakuje NIGDY.** Zero wolnych
     * miejsc to inna rzecz niż brak limitu i zlepienie ich dałoby ostrzeżenie na instalacji,
     * która limitu nie ma.
     * ⚠ Licencja liczy DOSTĘPY, nie konta (decyzja z 21.09.2026), więc `$potrzeba` to suma firm
     * z zatrudnienia po wybranych osobach — nie ich liczba.
     */
    public static function brakujeLicencji(int $potrzeba, ?int $limit, int $uzyte): int
    {
        if ($limit === null) {
            return 0;
        }

        return max(0, $potrzeba - max(0, $limit - $uzyte));
    }

    /**
     * **Trzy liczby panelu blokady**: ile licencji ma instalacja, ile jest wolnych teraz i ile
     * będzie wolnych po zmianie (zlecenie Z85 pkt 4).
     *
     * ⚠ Czysta funkcja, z tego samego powodu co `brakujeLicencji()`: te trzy liczby stoją na
     * ekranie obok siebie i muszą się zgadzać między sobą oraz ze zdaniem pod nimi. Policzone
     * w szablonie byłyby trzema wyrażeniami do rozjechania się przy pierwszej zmianie.
     *
     * ⚠ `$zajmuje` to licencje TEGO konta liczone regułą licznika instalacji
     * (`licensedAccessesOfAccount()`), a nie wszystkie jego przypisania do firm: dostęp bez
     * aktywnego zatrudnienia licencji nie zajmuje, więc nie ma czego zwolnić.
     *
     * ⚠ `$limit === null` (licencja bez limitu) znaczy, że „wolne" nie istnieje jako liczba —
     * wtedy obie pierwsze kolumny mówią słowem, a trzecia pokazuje, ile dostępów zmieni stan.
     *
     * @return array{limit: int|null, wolne_teraz: int|null, wolne_potem: int|null, zmiana: int}
     */
    public static function skutekNaLicencje(int $zajmuje, ?int $limit, int $uzyte, bool $odblokowanie): array
    {
        $zmiana = $odblokowanie ? $zajmuje : -$zajmuje;
        if ($limit === null) {
            return ['limit' => null, 'wolne_teraz' => null, 'wolne_potem' => null, 'zmiana' => $zajmuje];
        }

        return [
            'limit' => $limit,
            // ⚠ `max(0, …)`: użycie potrafi przekroczyć limit (odblokowanie go nie pilnuje),
            // a „wolnych −3" nie jest liczbą wolnych miejsc, tylko przekroczeniem.
            'wolne_teraz' => max(0, $limit - $uzyte),
            'wolne_potem' => max(0, $limit - ($uzyte + $zmiana)),
            'zmiana' => $zajmuje,
        ];
    }

    /**
     * **Ilu licencji brakuje, żeby ODBLOKOWAĆ to konto** (decyzja Krzysztofa z 24.10.2026).
     *
     * ⚠ Do wywołania WEWNĄTRZ transakcji odblokowania: licznik jest wariantem `FOR UPDATE`,
     * dokładnie jak przy nadaniu dostępu. Bez tego dwa odblokowania w tej samej chwili widzą
     * ten sam stan „jest jeszcze jedno miejsce" i oba przechodzą.
     *
     * ⚠ Licznik instalacji pomija konta zablokowane, a `licensedAccessesOfAccount()` pomija
     * status — więc suma tych dwóch liczb to użycie PO odblokowaniu, a nie przed. To jest
     * powód, dla którego te dwa liczniki różnią się jednym warunkiem.
     *
     * ⚠ Reguła jest ta sama, co przy nadaniu dostępu (`brakujeLicencji()`), bo to ta sama
     * rzecz: dostęp, który zacznie się liczyć. Licencja bez limitu nie brakuje nigdy.
     */
    public function brakujeNaOdblokowanie(int $accountId): int
    {
        return self::brakujeLicencji(
            $this->accounts->licensedAccessesOfAccount($accountId),
            $this->license->limit(self::KLUCZ_LICENCJI),
            $this->accounts->countLicensedAccessesForUpdate(),
        );
    }

    public const POWOD_BRAK_LICENCJI = 'brak wolnych licencji';
    // ⚠ „firmie", nie „spółce" — jedno słowo w całym interfejsie (rozstrzygnięcie Krzysztofa
    // z 11.10.2026: „nie każda firma jest spółką"). Ten napis wychodzi na ekran przy odmowie
    // nadania dostępu, a kryterium bramki przegapiło go do 21.10, bo szukało rdzenia „spółk",
    // a w miejscowniku stoi „spółce".
    public const POWOD_BRAK_ZATRUDNIENIA = 'brak aktywnego zatrudnienia w tej firmie';
    public const POWOD_BRAK_OSOBY = 'osoby już nie ma w kartotece';
    public const POWOD_KONTO_ADMINA = 'konto jest administratorem tenanta';

    /**
     * ⚠ Klucz limitu w licencji zostaje STARY (`accounts`), choć liczymy już dostępy.
     *
     * Zmiana klucza to zmiana kontraktu licencji, więc dotyczy także panelu, który go wystawia
     * (`LicenseValidator` czyta `data['limits']` z podpisanego pliku). Do ustalenia z panelem
     * liczymy po nowemu pod starą nazwą — miejsca do zmiany po obu stronach wypisane w raporcie.
     */
    private const KLUCZ_LICENCJI = 'accounts';
}
