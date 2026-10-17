<?php

declare(strict_types=1);

namespace Wezurlop\Entity;

/**
 * Aktywne zatrudnienie bieżącego żądania — zweryfikowane zapytaniem, nie wzięte na słowo z sesji.
 */
final class EmploymentContext
{
    public function __construct(
        public readonly int $employmentId,
        public readonly int $companyId,
        public readonly string $companyName,
    ) {
    }
}
