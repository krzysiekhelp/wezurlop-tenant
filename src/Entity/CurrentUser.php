<?php

declare(strict_types=1);

namespace Wezurlop\Entity;

/**
 * Zalogowany użytkownik bieżącego żądania. Budowany raz na żądanie; uprawnienia czytane z bazy
 * dla aktywnego kontekstu firmy. Bez hasha hasła i sekretów — może trafić do warstwy widoku
 * wyłącznie przez jawnie wybrane pola.
 */
final class CurrentUser
{
    /** @param list<string> $permissions */
    public function __construct(
        private readonly int $accountId,
        public readonly string $displayName,
        public readonly bool $twoFactorEnabled,
        public readonly int $employmentCount,
        private readonly ?EmploymentContext $employment = null,
        private readonly array $permissions = [],
    ) {
    }

    public function accountId(): int
    {
        return $this->accountId;
    }

    public function activeEmployment(): ?EmploymentContext
    {
        return $this->employment;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isAdmin(): bool
    {
        return $this->can('admin.users');
    }

    public function withEmployment(?EmploymentContext $employment, int $employmentCount): self
    {
        return new self($this->accountId, $this->displayName, $this->twoFactorEnabled, $employmentCount, $employment, $this->permissions);
    }

    /** @param list<string> $permissions */
    public function withPermissions(array $permissions): self
    {
        return new self($this->accountId, $this->displayName, $this->twoFactorEnabled, $this->employmentCount, $this->employment, $permissions);
    }
}
