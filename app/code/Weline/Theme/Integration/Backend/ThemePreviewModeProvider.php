<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Backend;

use Weline\Backend\Api\View\ThemePreviewModeProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ThemeContextProviderInterface;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Service\PreviewContextService;

final class ThemePreviewModeProvider implements ThemePreviewModeProviderInterface
{
    public function __construct(
        private readonly PreviewContextService $previewContextService,
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    public function resolveBackendMode(): ?string
    {
        if (!$this->previewContextService->shouldUseStoredContext()) {
            return null;
        }
        $context = $this->previewContextService->getCurrentContext();
        if ($this->previewContextService->getThemeIdForArea('backend', $context, false) <= 0) {
            return null;
        }
        $themeContext = $this->runtimeProviderResolver->resolve(ThemeContextProviderInterface::class);
        $previewTheme = $themeContext?->resolveTheme('backend');
        if (!$previewTheme || !$previewTheme->getId()) {
            return null;
        }
        $previewColor = LayoutScanner::getColorConfig($previewTheme, 'backend');
        if (!$previewColor) {
            return null;
        }
        return $previewColor === 'light' ? '' : $previewColor;
    }
}
