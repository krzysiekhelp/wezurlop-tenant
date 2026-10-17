<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use InvalidArgumentException;
use Wezurlop\Db\Connection;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Support\Nip;

/**
 * Klucze instalacji connectora (docs/api-connector.md §2): 64 znaki hex, w bazie wyłącznie SHA-256,
 * jawna postać zwracana JEDEN raz — przy wydaniu.
 *
 * ⚠ DWIE operacje, nie trzy (rozstrzygnięcie 20.09.2026):
 *   • **wydanie nowego klucza** — stary działa dalej, BEZ TERMINU, aż nowy po raz pierwszy zakończy
 *     przebieg; wtedy stary jest unieważniany automatycznie (`przejmij()`),
 *   • **unieważnienie** — natychmiastowe; jedyne narzędzie na wyciek klucza i na wycofanie maszyny.
 *
 * ⚠ Licznik czasu przy wymianie ZNIKNĄŁ. Miał dokładnie jeden efekt praktyczny: gdy nowy klucz
 * nie został wklejony (literówka, nikt nie wszedł na maszynę), po dobie zabijał DZIAŁAJĄCY stary
 * klucz i zatrzymywał synchronizację kadr. Przed zapomnianą starą maszyną broni automatyczne
 * unieważnienie w chwili przejęcia, a nie zegar.
 */
final class ApiKeyService
{
    private const PREFIX_LENGTH = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly ApiKeyRepository $keys,
        private readonly EventLogRepository $events,
        private readonly SyncDomainRepository $domain,
        private readonly TenantSettingRepository $settings,
    ) {
    }

    /**
     * ⚠ Zakres klucza to WYBÓR istniejących firm, nie wpisane NIP-y: przy pierwszym kluczu lista jest pusta
     * i tak ma być — firma wchodzi wyłącznie przez zgłoszenie od connectora (rozstrzygnięcie 20.09.2026).
     *
     * @param list<int> $companyIds
     * @return array{id: int, key: string} klucz jawny — do pokazania raz, nigdy do logu
     */
    public function issue(string $name, array $companyIds, ?int $actorAccountId): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Nazwa klucza: od 1 do 100 znaków.');
        }
        $nips = $this->nipsFor($companyIds);
        $this->assertSingleActiveKey($nips, null);

        return $this->db->transaction(fn (): array => $this->create($name, $nips, $actorAccountId));
    }

    /**
     * @param list<int> $companyIds
     * @return list<string>
     */
    private function nipsFor(array $companyIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $companyIds), static fn (int $id): bool => $id > 0));
        $nips = $this->domain->nipsByCompanyIds($ids);
        if (count($nips) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Któraś z wybranych firm już nie istnieje — odśwież stronę.');
        }

        return $nips;
    }

    /**
     * @param list<string> $nips
     * @return array{id: int, key: string}
     */
    private function create(string $name, array $nips, ?int $actorAccountId): array
    {
        $key = bin2hex(random_bytes(32));
        $id = $this->keys->create($name, hash('sha256', $key), substr($key, 0, self::PREFIX_LENGTH), $actorAccountId, $nips);
        $this->events->record('api.key_issued', $actorAccountId, null, 'admin', 'api_key', $id, null, ['nips' => count($nips)]);

        return ['id' => $id, 'key' => $key];
    }

    /**
     * Wydanie nowego klucza z tym samym zakresem firm. ⚠ Stary działa dalej, BEZ TERMINU — aż nowy
     * po raz pierwszy zakończy przebieg (`przejmij()`), i dopiero wtedy dostaje `401 KLUCZ_WYGASL`.
     * Dopóki nowy klucz nie trafi do connectora, synchronizacja idzie na starym.
     *
     * @return array{id: int, key: string}
     */
    public function replace(int $oldId, ?int $actorAccountId): array
    {
        return $this->db->transaction(function () use ($oldId, $actorAccountId): array {
            $old = $this->keys->find($oldId);
            if ($old === null || $old['status'] !== 'active') {
                throw new InvalidArgumentException('Tego klucza nie można już wymienić.');
            }
            $new = $this->create($old['name'], $this->keys->nips($oldId), $actorAccountId);
            // ⚠ Klucz, którego connector nigdy nie użył, ginie OD RAZU — nie ma czego przejmować,
            // a firma ma mieć najwyżej jeden klucz wydany i jeszcze nieużyty.
            if ($old['last_used_at'] === null) {
                $this->keys->revoke($oldId);
                $this->events->record('api.key_unused_replaced', $actorAccountId, null, 'admin', 'api_key', $oldId, null,
                    ['nowy' => $new['id']]);

                return $new;
            }
            // Klucz w użyciu działa dalej, BEZ TERMINU — do pierwszego zakończonego przebiegu nowego.
            $this->keys->markReplaced($oldId, $new['id']);
            $this->events->record('api.key_replaced', $actorAccountId, null, 'admin', 'api_key', $oldId, null,
                ['nowy' => $new['id']]);

            return $new;
        });
    }

    /**
     * Nowy klucz w miejsce takiego, którego jawnej postaci już nie ma (wyświetlony albo przepadł).
     * ⚠ Ta ścieżka dotyczy klucza WYDANEGO I NIEUŻYTEGO — poprzedni ginie od razu, bo connector
     * nigdy go nie dostał. Klucz w użyciu wymienia się przez `replace()`, gdzie działa do przejęcia.
     *
     * @return array{id: int, key: string}
     */
    public function regenerate(int $oldId, ?int $actorAccountId): array
    {
        return $this->db->transaction(function () use ($oldId, $actorAccountId): array {
            $old = $this->keys->find($oldId);
            if ($old === null || $old['status'] !== 'active' || !$this->keys->revoke($oldId)) {
                throw new InvalidArgumentException('Tego klucza nie można już wygenerować ponownie.');
            }
            $new = $this->create($old['name'], $this->keys->nips($oldId), $actorAccountId);
            $this->events->record('api.key_regenerated', $actorAccountId, null, 'admin', 'api_key', $oldId, null, ['nowy' => $new['id']]);

            return $new;
        });
    }

    public function reopenSignup(int $signupId, ?int $actorAccountId): bool
    {
        $done = $this->keys->reopenSignup($signupId);
        if ($done) {
            $this->events->record('api.company_signup_reopened', $actorAccountId, null, 'admin', 'company_signup', $signupId, null, []);
        }

        return $done;
    }

    /**
     * ⚠ Jedna firma = jeden klucz w użyciu (rozstrzygnięcie 20.09.2026). Dwa connectory na jednym
     * strumieniu dają skaczące kursory i zadania pobrane dwa razy — bez cichego nadpisania i bez obejścia.
     * Wydanie nowego klucza jest wyjątkiem z definicji: to ta sama instalacja w trakcie przesiadki,
     * a nie druga — nowy klucz czeka wydany i nieużyty, dopóki nie trafi do connectora.
     *
     * @param list<string> $nips
     */
    private function assertSingleActiveKey(array $nips, ?int $keyId): void
    {
        foreach ($nips as $nip) {
            $other = $this->keys->activeKeyForNip($nip, $keyId);
            if ($other !== null) {
                $company = $this->domain->companyByNip($nip);
                throw new InvalidArgumentException(sprintf(
                    'Firma %s jest już obsługiwana przez klucz „%s” (wydany %s). Unieważnij tamten klucz albo odepnij od niego tę firmę.',
                    $company['name'] ?? $nip, $other['name'], $other['created_at'],
                ));
            }
        }
    }

    /**
     * ⚠ PRZEJĘCIE: nowy klucz domknął pierwszy przebieg, więc przesiadka się udała i stary klucz
     * przestaje być potrzebny — unieważniamy go od razu. To jest jedyny moment, w którym stary
     * klucz ginie sam; żaden zegar tego nie robi.
     *
     * @return int|null identyfikator unieważnionego klucza
     */
    public function przejmij(int $newKeyId): ?int
    {
        $old = $this->keys->poprzednikKlucza($newKeyId);
        if ($old === null || !$this->keys->revoke($old['id'])) {
            return null;
        }
        $this->events->record('api.key_superseded', null, null, 'sync', 'api_key', $old['id'], null,
            ['nowy' => $newKeyId]);

        return $old['id'];
    }

    public function revoke(int $keyId, ?int $actorAccountId): bool
    {
        $done = $this->keys->revoke($keyId);
        if ($done) {
            $this->events->record('api.key_revoked', $actorAccountId, null, 'admin', 'api_key', $keyId, null, []);
        }

        return $done;
    }

    /**
     * Skasowanie unieważnionego klucza — żeby nie zaśmiecał ekranu integracji.
     *
     * ⚠ **Kolejność jest treścią tej funkcji**: najpierw CZYTAMY wiersz i liczbę przebiegów,
     * potem kasujemy, a na końcu zapisujemy w dzienniku **to, co zniknęło** (nazwa, prefiks,
     * liczba przebiegów, czy klucz był czyjimś poprzednikiem). Po skasowaniu nie ma już czego
     * odczytać, a wpis „skasowano klucz #13" bez nazwy nie jest śladem, tylko numerem.
     *
     * ⚠ Czynnego klucza ta droga nie skasuje — warunek stoi w `ApiKeyRepository::usun()`,
     * czyli w zapytaniu, nie w tym `if`. Tu zwracamy tylko `false`.
     */
    public function usun(int $keyId, ?int $actorAccountId): bool
    {
        $key = $this->keys->find($keyId);
        if ($key === null || $key['status'] === 'active') {
            return false;
        }
        $slad = [
            'nazwa' => $key['name'],
            'prefiks' => $key['key_prefix'],
            'przebiegow' => $this->keys->przebiegowKlucza($keyId),
            'zastapiony_przez' => $key['replaced_by_id'],
        ];
        if (!$this->keys->usun($keyId)) {
            return false;
        }
        $this->events->record('api.key_deleted', $actorAccountId, null, 'admin', 'api_key', $keyId, null, $slad);

        return true;
    }

    /** @param list<int> $companyIds */
    public function setCompanies(int $keyId, array $companyIds, ?int $actorAccountId): void
    {
        $nips = $this->nipsFor($companyIds);
        $this->assertSingleActiveKey($nips, $keyId);
        $this->db->transaction(function () use ($keyId, $nips, $actorAccountId): void {
            $current = $this->keys->nips($keyId);
            foreach (array_diff($current, $nips) as $nip) {
                $this->keys->removeNip($keyId, $nip);
            }
            foreach (array_diff($nips, $current) as $nip) {
                $this->keys->addNip($keyId, $nip);
            }
            // ⚠ Zakres PRZED i PO, nie sama liczba po (zlecenie 08.10 §1). `$current` to stan
            // sprzed zmiany, odczytany linijkę wyżej w tej samej transakcji — żadne nowe
            // zapytanie. Sama liczba nie pozwala odpowiedzieć „której firmy klucz przestał
            // dotyczyć", a to jest jedyne pytanie, które się tu zadaje.
            // ⚠ NIP SPÓŁKI nie jest daną osobową (tą jest NIP osoby, §14) i stoi na ekranach
            // `/dane` oraz `/admin/integracja` — więc w dzienniku też może stać jawnie.
            $this->events->record('api.key_scope_changed', $actorAccountId, null, 'admin', 'api_key', $keyId, null, [
                'z' => array_values($current),
                'na' => array_values($nips),
                'dodane' => array_values(array_diff($nips, $current)),
                'usuniete' => array_values(array_diff($current, $nips)),
            ]);
        });
    }

    /**
     * Zatwierdzenie zgłoszenia firmy — decyzja administratora, nigdy automat (docs/api-connector.md §3.1).
     * Firma powstaje tutaj (jeśli jej jeszcze nie ma), a NIP wchodzi do zakresu klucza, który ją zgłosił.
     *
     * @return int id firmy
     */
    public function approveSignup(int $signupId, ?int $actorAccountId): int
    {
        return $this->db->transaction(function () use ($signupId, $actorAccountId): int {
            $signup = $this->keys->findSignup($signupId);
            if ($signup === null || $signup['status'] !== 'pending' || !$this->keys->decideSignup($signupId, 'approved', $actorAccountId)) {
                throw new InvalidArgumentException('Zgłoszenie nie czeka już na decyzję.');
            }
            $company = $this->domain->companyByNip($signup['nip']);
            $companyId = $company['id'] ?? $this->domain->createCompany($signup['nip'], $signup['name']);
            if ($signup['api_key_id'] !== null) {
                // Ta sama zasada co przy ręcznym zakresie: firma nie może trafić do drugiego aktywnego klucza.
                $this->assertSingleActiveKey([$signup['nip']], $signup['api_key_id']);
                $this->keys->addNip($signup['api_key_id'], $signup['nip']);
            }
            $this->events->record('api.company_approved', $actorAccountId, null, 'admin', 'company', $companyId, $companyId, ['zgloszenie' => $signupId]);

            return $companyId;
        });
    }

    public function rejectSignup(int $signupId, ?int $actorAccountId): bool
    {
        $done = $this->keys->decideSignup($signupId, 'rejected', $actorAccountId);
        if ($done) {
            $this->events->record('api.company_rejected', $actorAccountId, null, 'admin', 'company_signup', $signupId, null, []);
        }

        return $done;
    }

    /**
     * @param list<string> $nips
     * @return list<string>
     */
    public static function normalizeNips(array $nips): array
    {
        $out = [];
        foreach ($nips as $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            // Ta sama reguła co w API: normalizacja (myślniki, spacje, PL), potem suma kontrolna.
            $nip = Nip::parse((string) $raw);
            if ($nip === null) {
                throw new InvalidArgumentException('Nieprawidłowy NIP (błędna suma kontrolna albo format): ' . mb_substr((string) $raw, 0, 32));
            }
            $out[$nip] = $nip;
        }

        return array_values($out);
    }
}
