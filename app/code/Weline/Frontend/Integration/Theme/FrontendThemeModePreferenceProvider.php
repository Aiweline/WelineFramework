<?php

declare(strict_types=1);

namespace Weline\Frontend\Integration\Theme;

use Weline\Frontend\Block\ThemeConfig;
use Weline\Theme\Api\View\FrontendThemeModePreferenceProviderInterface;

final class FrontendThemeModePreferenceProvider implements FrontendThemeModePreferenceProviderInterface
{
    public function __construct(
        private readonly ThemeConfig $themeConfig,
    ) {
    }

    public function resolveFrontendMode(): ?string
    {
        $mode = $this->themeConfig->getThemeModel();
        return $mode === null ? null : (string)$mode;
    }
}
