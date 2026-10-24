<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\CompanyRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Security\BackupCodes;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\Telefon;

/**
 * **Model ekranu „Moje konto"** — jedno miejsce, w którym powstaje wszystko, co ta strona
 * pokazuje (zlecenie Z69, 21.10.2026; projekt zaakceptowany przez Krzysztofa).
 *
 * ⚠ **Dlaczego osobny serwis, a nie kontroler.** Ekran składa dane z siedmiu źródeł (konto,
 * osoba, zatrudnienia, uprawnienia, reguły widoczności, 2FA, sesje) i każde z nich odpowiada
 * na inne pytanie. W kontrolerze byłoby to sto linii bez możliwości sprawdzenia testem bez
 * przeglądarki; tutaj da się zapytać o kształt modelu wprost.
 *
 * ⚠ **Zero decyzji o dostępie.** Ten serwis tylko OPISUJE stan — nie nadaje, nie odbiera i nie
 * rozstrzyga, czy wolno coś zrobić. Każda akcja (telefon, hasło, 2FA, sesje) ma własną drogę
 * z własnym sprawdzeniem po stronie serwera (CLAUDE.md §10).
 *
 * ⚠ **Blok firmy powstaje TYLKO tam, gdzie jest wgląd w cudze nieobecności** (dopisek Krzysztofa
 * do Z69). Pusty blok „widzisz tylko siebie" przy każdej firmie z etatem mówił najwięcej tam,
 * gdzie nie było o czym mówić — a firmy, w których ktoś pracuje, widać i tak w plakietce typu
 * konta w nagłówku strony.
 */
final class MojeKonto
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PersonRepository $people,
        private readonly EmploymentRepository $employments,
        private readonly CompanyRepository $companies,
        private readonly GroupRepository $groups,
        private readonly PermissionRepository $permissions,
        private readonly CalendarVisibility $widocznosc,
        private readonly TwoFactorService $twoFactor,
        private readonly DeviceService $devices,
        private readonly bool $wymogAdmina,
    ) {
    }

    /**
     * Pełny model strony.
     *
     * @return array<string, mixed>
     */
    public function model(CurrentUser $user, string $sessionId): array
    {
        $konto = $this->accounts->findById($user->accountId());
        $personId = $konto === null ? 0 : (int) $konto['person_id'];
        $zatrudnienia = $this->employments->activeForAccount($user->accountId());
        $urzadzenia = $this->devices->listForAccount($user->accountId(), $sessionId);
        $wlaczone = $this->twoFactor->isEnabled($user->accountId());

        return [
            'osoba' => [
                'nazwa'     => $user->displayName,
                'email'     => $konto === null ? '' : (string) $konto['email'],
                'telefon'   => Telefon::doWyswietlenia($this->people->phone($personId)),
                'konto_od'  => $konto === null ? null : ($konto['created_at'] ?? null),
            ],
            // ⚠ Wartość POLA (bez prefiksu `+48`) — osobno od postaci do wyświetlenia wyżej,
            // bo okno edycji i napis w karcie to dwie różne rzeczy (Z72/Z73).
            'telefon_pole' => Telefon::doPola($this->people->phone($personId)),
            'typ' => $this->typKonta($user, $zatrudnienia),
            'dwa_etapy' => [
                'wlaczone'       => $wlaczone,
                'od'             => $konto === null ? null : ($konto['totp_enabled_at'] ?? null),
                'kodow_zostalo'  => $wlaczone ? $this->twoFactor->unusedBackupCodes($user->accountId()) : 0,
                // ⚠ Liczba kodów z JEDNEJ stałej (`BackupCodes::COUNT`), nigdy wpisana w napis:
                // reguła stoi w CLAUDE.md §9, a ekran ma mówić to, co robi kod (Z69, rozstrzygnięcie
                // Krzysztofa 21.10.2026 — zostaje OSIEM, nie dziesięć).
                'kodow_razem'    => BackupCodes::COUNT,
                // ⚠ „Wymagane" znaczy: konfiguracja ORAZ rola. Do Z69 ekran pytał wyłącznie
                // o rolę, więc przy `ADMIN_2FA_REQUIRED=0` pisał „Twoja rola wymaga
                // weryfikacji dwuetapowej", a middleware nie wymuszał niczego — dwa zdania
                // tej samej aplikacji przeczyły sobie (punkt 10 zlecenia).
                'wymagane'       => $this->wymogAdmina && $this->permissions->requiresTwoFactor($user->accountId()),
            ],
            'haslo' => [
                'zmienione'  => $konto === null ? null : ($konto['password_changed_at'] ?? null),
            ],
            'ostatnie_logowanie' => $konto === null ? null : ($konto['last_login_at'] ?? null),
            'urzadzenia' => $urzadzenia,
            'urzadzen' => [
                'razem' => count($urzadzenia),
                'inne'  => count(array_filter($urzadzenia, static fn (array $u): bool => $u['current'] === false)),
            ],
            'dostep' => $this->dostep($user, $zatrudnienia),
        ];
    }

    /**
     * Plakietka typu konta: „Administrator · wszystkie firmy", „Pracownik · <firma>",
     * „Pracownik · 2 firmy".
     *
     * ⚠ Liczbę firm odmieniamy, a nie doklejamy „firm(y)": „2 firmy" i „5 firm" to dwie różne
     * formy i jedna z nich będzie widoczna na ekranie u kogoś z pięcioma etatami.
     *
     * @param list<array{id: int, company_id: int, company_name: string}> $zatrudnienia
     * @return array{rodzaj: string, napis: string}
     */
    private function typKonta(CurrentUser $user, array $zatrudnienia): array
    {
        if ($user->isAdmin()) {
            return ['rodzaj' => 'administrator', 'napis' => 'Administrator · wszystkie firmy'];
        }
        $ile = count($zatrudnienia);
        if ($ile === 0) {
            return ['rodzaj' => 'bez_etatu', 'napis' => 'Dostęp bez etatu'];
        }
        if ($ile === 1) {
            return ['rodzaj' => 'pracownik', 'napis' => 'Pracownik · ' . $zatrudnienia[0]['company_name']];
        }

        return ['rodzaj' => 'pracownik', 'napis' => 'Pracownik · ' . $ile . ' ' . Odmiana::firmy($ile)];
    }

    /**
     * Bloki karty „Dostęp" — po jednym na firmę, w której konto **ma** wgląd w cudze
     * nieobecności.
     *
     * ⚠ Źródła liczy `CalendarVisibility::zrodlaWidza()`, czyli ta sama funkcja, którą mówi
     * karta „Widoczność" w panelu osoby. Dwa rachunki tej samej rzeczy rozjechałyby się przy
     * pierwszej zmianie reguł — i to po cichu, bo każdy z osobna wygląda poprawnie.
     *
     * @param list<array{id: int, company_id: int, company_name: string}> $zatrudnienia
     * @return list<array<string, mixed>>
     */
    private function dostep(CurrentUser $user, array $zatrudnienia): array
    {
        $etatWFirmie = [];
        foreach ($zatrudnienia as $z) {
            $etatWFirmie[$z['company_id']] = $z;
        }
        $bloki = [];
        foreach ($this->companies->listWithCounts() as $firma) {
            if (!$firma['aktywna']) {
                continue;
            }
            $companyId = (int) $firma['id'];
            $etat = $etatWFirmie[$companyId] ?? null;
            $zrodla = $this->widocznosc->zrodlaWidza(
                $user->accountId(),
                $companyId,
                $etat === null ? null : (int) $etat['id'],
            );
            if ($zrodla === []) {
                continue;
            }
            $bloki[] = [
                'firma'   => $firma['nazwa_wyswietlana'] ?? $firma['nazwa_r2'],
                'rola'    => $this->rolaWFirmie($user, $companyId, $etat),
                // ⚠ Źródła idą do szablonu BEZ przerabiania: plakietkę zakresu rysuje ten sam
                // partial, co w panelu osoby (`partials/zakres-plakietka.twig`), a nazwy zakresów
                // dokłada kontroler z `ZakresWidocznosci::nazwy()`. Drugi słownik nazw w tym
                // serwisie byłby drugim miejscem do zmiany przy pierwszej korekcie napisu.
                'zrodla'  => $zrodla,
                'zrodel'  => count($zrodla),
            ];
        }

        return $bloki;
    }

    /**
     * Podpis roli w nagłówku bloku: „administrator", „pracownik · <grupy>", „dostęp bez etatu".
     *
     * @param array{id: int, company_id: int, company_name: string}|null $etat
     */
    private function rolaWFirmie(CurrentUser $user, int $companyId, ?array $etat): string
    {
        if ($etat === null) {
            return $user->isAdmin() ? 'administrator' : 'dostęp bez etatu';
        }
        $grupy = $this->groups->grupyZatrudnienia((int) $etat['id'], $companyId);

        return $grupy === [] ? 'pracownik' : 'pracownik · ' . implode(', ', $grupy);
    }
}
