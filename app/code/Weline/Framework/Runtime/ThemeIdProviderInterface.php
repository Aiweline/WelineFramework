<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional current-theme identity supplied by the theme module.
 */
interface ThemeIdProviderInterface
{
    public function currentThemeId(): ?int;
}
