<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Entity\EmploymentContext as Context;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Support\View;

/**
 * Aktywne zatrudnienie (docs/szkielet-tenanta.md rozdz. 6, „Wybór kontekstu”).
 *
 * zero zatrudnień → strona informacyjna (administrator tenanta przechodzi bez kontekstu),
 * jedno → ustawiane automatycznie, więcej → wybór; identyfikator w sesji to tylko wskazówka —
 * przy każdym żądaniu weryfikowany zapytaniem z warunkiem właściciela.
 */
final class EmploymentContext implements Middleware
{
    public function __construct(
        private readonly AuthSession $auth,
        private readonly EmploymentRepository $employments,
        private readonly PermissionRepository $permissions,
        private readonly View $view,
        private readonly string $chooserUrl,
        private readonly AccountRepository $konta,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $user = $request->attribute(Authenticate::USER);
        if (!$user instanceof CurrentUser) {
            return $next($request);
        }

        // ⚠ Lista ograniczona do spółek, do których konto ma DOSTĘP — nie do wszystkich zatrudnień.
        // Dane osoby mogą być w dwóch spółkach w R2, a dostęp obejmować jedną albo żadną (§0.1).
        $list = $this->employments->accessibleForAccount($user->accountId());
        $count = count($list);
        $selected = $this->selected($user->accountId(), $list);

        if ($route->isExempt(Route::EXEMPT_CONTEXT) || !$route->requiresAuthentication()) {
            return $next($request->withAttribute(Authenticate::USER, $user->withEmployment($selected, $count)));
        }

        if ($count === 0) {
            if ($this->permissions->hasTenantWideRole($user->accountId())) {
                return $next($request->withAttribute(Authenticate::USER, $user->withEmployment(null, 0)));
            }

            // ⚠ DWA RÓŻNE STANY, dwa różne komunikaty. Wcześniej jeden tekst obsługiwał oba
            // powody i mówił „nie masz aktywnego zatrudnienia" także temu, kto zatrudnienie MA,
            // a nie ma nadanego dostępu. Pracownik czytał wtedy komunikat o czymś, czego
            // administrator nie mógł naprawić w kadrach, bo problem był u nas.
            $maZatrudnienie = $this->employments->hasAnyActive($user->accountId());

            return $this->view->render('no-employment.twig', [
                'user_name'       => $user->displayName,
                'ma_zatrudnienie' => $maZatrudnienie,
            ], 403);
        }
        if ($selected === null) {
            return Response::redirectFor($request, $this->chooserUrl);
        }

        return $next($request->withAttribute(Authenticate::USER, $user->withEmployment($selected, $count)));
    }

    /** @param list<array{id: int, company_id: int, company_name: string}> $list */
    private function selected(int $accountId, array $list): ?Context
    {
        if (count($list) === 1) {
            $this->auth->setEmploymentId($list[0]['id']);

            return new Context($list[0]['id'], $list[0]['company_id'], $list[0]['company_name']);
        }

        $sessionId = $this->auth->employmentId();
        if ($sessionId === null) {
            // ⚠ **Po ponownym zalogowaniu sesja jest pusta, a kontekst ma przeżyć** (zlecenie
            // 10.10.2026 §1). Trwała część kontekstu to zapamiętana **spółka** na koncie;
            // zatrudnienie wyprowadzamy z niej tak samo, jak robi to kontrolka: pierwsze
            // czynne zatrudnienie tego konta w tej spółce. Dotąd konto z dwoma zatrudnieniami
            // po każdym logowaniu trafiało na ekran wyboru firmy.
            // ⚠ Zapamiętana spółka jest **podpowiedzią**: szukamy jej na liście dostępnych
            // w TYM żądaniu, więc utrata dostępu między sesjami nie przepuszcza nikogo
            // (CLAUDE.md §10) — wtedy zostaje ekran wyboru.
            $spolka = $this->konta->zapamietanaSpolka($accountId);
            if ($spolka === null) {
                return null;
            }
            foreach ($list as $row) {
                if ((int) $row['company_id'] === $spolka) {
                    $this->auth->setEmploymentId((int) $row['id']);

                    return new Context((int) $row['id'], (int) $row['company_id'], (string) $row['company_name']);
                }
            }

            return null;
        }
        $row = $this->employments->findActiveForAccount($sessionId, $accountId);
        if ($row === null) {
            // Podmieniony lub nieaktualny identyfikator — kontekst trzeba wybrać od nowa.
            $this->auth->setEmploymentId(null);

            return null;
        }

        return new Context($row['id'], $row['company_id'], $row['company_name']);
    }
}
