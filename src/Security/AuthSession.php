<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use Wezurlop\Http\ClientKind;
use Wezurlop\Http\Session;

/**
 * Stan uwierzytelnienia w sesji (docs/szkielet-tenanta.md rozdz. 6):
 * ANONYMOUS → PARTIAL (hasło, przed 2FA) → FULL. W sesji tylko identyfikator konta i znaczniki
 * czasu — nigdy uprawnienia ani dane konta (CLAUDE.md §8).
 */
final class AuthSession
{
    public const PARTIAL = 'partial';
    public const FULL = 'full';

    private const ACCOUNT = 'account_id';
    private const LEVEL = 'auth_level';
    private const AUTH_AT = 'auth_at';
    private const PARTIAL_AT = 'partial_at';
    private const EMPLOYMENT = 'employment_id';
    /** Zapamiętana spółka konta bez zatrudnienia — drugi kształt kontekstu, patrz `companyId()`. */
    private const COMPANY = 'company_id';
    private const CLIENT = 'client';
    private const APP_VERSION = 'app_version';

    public function __construct(private readonly Session $session)
    {
    }

    public function accountId(): ?int
    {
        $id = $this->session->get(self::ACCOUNT);

        return is_int($id) ? $id : null;
    }

    public function level(): ?string
    {
        $level = $this->session->get(self::LEVEL);

        return $level === self::PARTIAL || $level === self::FULL ? $level : null;
    }

    /** Czas uwierzytelnienia (sekundy z mikrosekundami) — porównywany z password_changed_at. */
    public function authenticatedAt(): float
    {
        $at = $this->session->get(self::AUTH_AT);

        return is_float($at) ? $at : 0.0;
    }

    public function partialStartedAt(): int
    {
        $at = $this->session->get(self::PARTIAL_AT);

        return is_int($at) ? $at : 0;
    }

    /**
     * Rodzaj klienta zapisany przy logowaniu; null = sesja bez logowania (decyduje wtedy ciastko).
     * Znacznik prezentacji — nie bierze udziału w decyzjach o dostępie (CLAUDE.md §11).
     */
    public function client(): ?ClientKind
    {
        $client = $this->session->get(self::CLIENT);

        return is_string($client) ? ClientKind::fromStored($client, $this->session->get(self::APP_VERSION)) : null;
    }

    /** Hasło poprawne, konto ma 2FA: nowy identyfikator sesji, stan częściowy. */
    public function beginPartial(int $accountId, ClientKind $client): void
    {
        $this->session->reset();
        $this->storeClient($client);
        $this->session->set(self::ACCOUNT, $accountId);
        $this->session->set(self::LEVEL, self::PARTIAL);
        $this->session->set(self::AUTH_AT, microtime(true));
        $this->session->set(self::PARTIAL_AT, time());
    }

    /**
     * Pełne uwierzytelnienie. Identyfikator sesji zmieniany ZA KAŻDYM razem — po samym haśle
     * i ponownie po kodzie 2FA (CLAUDE.md §9). Znacznik czasu logowania z pierwszego kroku zostaje.
     */
    public function complete(int $accountId, ?ClientKind $client = null): void
    {
        $sameAccount = $this->accountId() === $accountId;
        $authAt = $sameAccount ? $this->authenticatedAt() : microtime(true);
        // Po kodzie 2FA klient zostaje ten, który był przy haśle — ciastko czytamy raz, przy logowaniu.
        $client ??= ($sameAccount ? $this->client() : null) ?? ClientKind::web();
        $this->session->reset();
        $this->storeClient($client);
        $this->session->set(self::ACCOUNT, $accountId);
        $this->session->set(self::LEVEL, self::FULL);
        $this->session->set(self::AUTH_AT, $authAt > 0 ? $authAt : microtime(true));
    }

    private function storeClient(ClientKind $client): void
    {
        $this->session->set(self::CLIENT, $client->client);
        $this->session->set(self::APP_VERSION, $client->appVersion);
    }

    public function logout(): void
    {
        $this->session->reset();
    }

    /**
     * Powiązanie wiersza sesji z kontem — tylko przy pełnym uwierzytelnieniu (rozdz. 5).
     *
     * @return array{account_id: ?int, employment_id: ?int, client: string, app_version: ?string}
     */
    public function registryMetadata(): array
    {
        $full = $this->session->peek(self::LEVEL) === self::FULL;
        $account = $this->session->peek(self::ACCOUNT);
        $employment = $this->session->peek(self::EMPLOYMENT);

        return [
            'account_id'    => $full && is_int($account) ? $account : null,
            'employment_id' => $full && is_int($employment) ? $employment : null,
            'client'        => $this->session->peek(self::CLIENT) === ClientKind::APP ? ClientKind::APP : ClientKind::WEB,
            'app_version'   => is_string($version = $this->session->peek(self::APP_VERSION)) ? $version : null,
        ];
    }

    public function employmentId(): ?int
    {
        $id = $this->session->get(self::EMPLOYMENT);

        return is_int($id) ? $id : null;
    }

    public function setEmploymentId(?int $employmentId): void
    {
        $this->session->set(self::EMPLOYMENT, $employmentId);
    }

    /**
     * Zapamiętana **spółka** konta, które nie ma w niej zatrudnienia (zlecenie 10.10.2026 §1).
     *
     * ⚠ To **drugi kształt tego samego kontekstu**, nie trzeci stan obok niego. Konto z rolą
     * tenantową ma zero zatrudnień (zmierzone: wszystkie trzy takie konta, w tym konto
     * Krzysztofa), więc nie ma czym wskazać spółki — `employmentId` jest wtedy `null`
     * i pytanie „w jakiej spółce jestem" musi mieć własną odpowiedź.
     * ⚠ Wartość jest **podpowiedzią, nie uprawnieniem**: przy każdym żądaniu waliduje ją
     * `KontekstSpolki` przeciw liście czytanej z bazy (CLAUDE.md §10).
     */
    public function companyId(): ?int
    {
        $wartosc = $this->session->get(self::COMPANY);

        return is_int($wartosc) && $wartosc > 0 ? $wartosc : null;
    }

    public function setCompanyId(?int $companyId): void
    {
        $this->session->set(self::COMPANY, $companyId);
    }
}
