<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\View\PreviewThemeModeResolverInterface;
use Weline\Theme\Helper\LayoutScanner;

final class PreviewThemeModeResolver implements PreviewThemeModeResolverInterface
{
    public function __construct(
        private readonly PreviewContextService $previewContext,
        private readonly ThemeContextService $themeContext,
    ) {
    }

    public function resolveFrontendMode(): ?string
    {
        if (!$this->previewContext->shouldUseStoredContext()) {
            return null;
        }
        $context = $this->previewContext->getCurrentContext();
        if ($this->previewContext->getThemeIdForArea('frontend', $context, false) <= 0) {
            return null;
        }
        $theme = $this->themeContext->resolveTheme('frontend');
        if (!$theme || !$theme->getId()) {
            return null;
        }
        $color = LayoutScanner::getColorConfig($theme, 'frontend');
        if ($color === '') {
            return null;
        }
        return $color === 'light' ? '' : $color;
    }
}
