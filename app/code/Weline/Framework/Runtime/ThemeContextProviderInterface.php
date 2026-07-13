<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional runtime theme resolver implemented by a view/theme module.
 *
 * Framework and neutral modules intentionally exchange only object identity;
 * they never depend on the concrete theme model.
 */
interface ThemeContextProviderInterface
{
    public function resolveTheme(
        ?string $area = null,
        ?object $theme = null,
        bool $allowPreview = true,
    ): ?object;
}
