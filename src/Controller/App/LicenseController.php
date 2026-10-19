<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\License\LicenseGate;
use Wezurlop\License\LicenseState;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Service\AccessService;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\View;

/**
 * Stan licencji dla administratora (admin.license). Pokazuje WYNIK, nie sposób sprawdzania —
 * ekran nie zdradza, gdzie leży plik licencji ani co dokładnie jest weryfikowane (CLAUDE.md §15).
 */
final class LicenseController
{
    /**
     * Stan licencji rozbity na DWIE części, bo kafel ma krótką nazwę i podpis (zlecenie Z48 §2).
     *
     * ⚠ Barwa kropki idzie za stanem, a nie za „dobrze / źle": stan deweloperski nie jest ani
     * sukcesem, ani awarią — jest trybem pracy, więc dostaje błękit „dziś" z kalendarza, ten sam,
     * którym oznaczamy stan przejściowy. Zieleń zostaje dla licencji, która naprawdę obowiązuje.
     *
     * @var array<string, array{nazwa: string, podpis: string, kropka: string}>
     */
    private const STANY = [
        LicenseState::MODE_DEV => [
            'nazwa' => 'deweloperska', 'podpis' => 'bez ograniczeń', 'kropka' => 'dzis',
        ],
        LicenseState::MODE_ACTIVE => [
            'nazwa' => 'ważna', 'podpis' => 'licencja produkcyjna', 'kropka' => 'ok',
        ],
        LicenseState::MODE_GRACE => [
            'nazwa' => 'wygasła', 'podpis' => 'trwa okres karencji', 'kropka' => 'uwaga',
        ],
        LicenseState::MODE_LIMITED => [
            'nazwa' => 'nieważna', 'podpis' => 'tryb ograniczony', 'kropka' => 'blad',
        ],
    ];

    public function __construct(
        private readonly View $view,
        private readonly LicenseGate $license,
        /**
         * ⚠ Repozytorium ustawień było w `use`, ale NIE w konstruktorze — a kod czytał z niego
         * strefę przy formatowaniu terminu ważności. Na instalacji bez licencji `expiresAt`
         * jest `null`, więc ta gałąź nigdy się nie wykonywała i błąd czekał na pierwszą
         * licencję z terminem. Zmierzone i naprawione przy Z48, 19.10.2026.
         */
        private readonly TenantSettingRepository $settings,
        /**
         * ⚠ Zajęte dostępy i limit biorą się z TEGO SAMEGO wywołania, co kafel „Licencje"
         * na `/admin/konta` (`AccessService::licenceUsage()`). Drugie liczenie tej samej rzeczy
         * rozjechałoby się przy pierwszej zmianie reguły — a dwa ekrany o jednej licencji muszą
         * mówić to samo (zlecenie Z48 §2).
         */
        private readonly AccessService $access,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $state = $this->license->state();
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $stan = self::STANY[$state->mode] ?? ['nazwa' => $state->mode, 'podpis' => '', 'kropka' => ''];
        // ⚠ Notka o wgraniu licencji TYLKO tam, gdzie licencji nie ma: w trybie deweloperskim
        // i na instalacji bez pliku licencji (`L100`). Przy licencji wygasłej albo odrzuconej
        // zdanie „po wgraniu pojawią się dane" byłoby nieprawdą — dane już są, tylko licencja
        // nie obowiązuje.
        $bezLicencji = $state->mode === LicenseState::MODE_DEV
            || ($state->mode === LicenseState::MODE_LIMITED && $state->reasonCode === 'L100');

        return $this->view->render('page/web/license.twig', [
            'mode'         => $state->mode,
            'stan'         => $stan,
            'reason'       => $state->reasonCode,
            'license_id'   => $state->licenseId,
            // Termin licencji czytany przez człowieka — w strefie instalacji (docs §10 punkt 3).
            'expires_at'   => $state->expiresAt === null
                ? null
                : Strefa::czas(gmdate('Y-m-d H:i:s', $state->expiresAt), $strefa, 'd.m.Y'),
            // ⚠ `updates_until` to DATA, nie moment: licencja mówi „do końca tego dnia", więc
            // nie przeliczamy jej przez strefę — przeliczenie przesunęłoby ją o dzień.
            'updates_until' => $state->updatesUntil === null ? null : gmdate('d.m.Y', $state->updatesUntil),
            'updates_expired' => $state->updatesUntil !== null && $state->updatesUntil < time(),
            'limity'       => $state->limits,
            'funkcje'      => array_keys(array_filter($state->features)),
            'licencja'     => $this->access->licenceUsage(),
            'bez_licencji' => $bezLicencji,
        ])->withHeader('Cache-Control', 'no-store');
    }
}
