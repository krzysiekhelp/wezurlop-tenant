<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\License\LicenseGate;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\View;

/**
 * Stan licencji dla administratora (admin.license). Pokazuje wynik, nie sposób sprawdzania.
 */
final class LicenseController
{
    private const MODES = [
        'dev'     => 'środowisko deweloperskie — bez ograniczeń',
        'active'  => 'aktywna',
        'grace'   => 'wygasła — okres karencji',
        'limited' => 'tryb ograniczony',
    ];

    public function __construct(
        private readonly View $view,
        private readonly LicenseGate $license,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $state = $this->license->state();

        return $this->view->render('page/web/license.twig', [
            'mode'         => $state->mode,
            'mode_label'   => self::MODES[$state->mode] ?? $state->mode,
            'reason'       => $state->reasonCode,
            'license_id'   => $state->licenseId,
            // Termin licencji też czytany przez człowieka — w strefie instalacji (docs §10).
            'expires_at'   => $state->expiresAt === null
                ? null
                : Strefa::czas(gmdate('Y-m-d H:i:s', $state->expiresAt), $this->settings->get('timezone', Strefa::DOMYSLNA)),
            'updates_until'=> $state->updatesUntil === null ? null : gmdate('Y-m-d', $state->updatesUntil),
            'updates_expired' => $state->updatesUntil !== null && $state->updatesUntil < time(),
            'limits'       => $state->limits,
        ])->withHeader('Cache-Control', 'no-store');
    }
}
