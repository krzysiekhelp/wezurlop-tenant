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
