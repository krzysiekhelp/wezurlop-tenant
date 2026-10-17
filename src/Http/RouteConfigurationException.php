<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use LogicException;

/**
 * Błąd w config/routes.php wykryty przy starcie aplikacji (tryb deweloperski).
 */
final class RouteConfigurationException extends LogicException
{
}
