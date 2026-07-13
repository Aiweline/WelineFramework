<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Theme\Api\View\FrontendThemeModePreferenceProviderInterface;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

class ThemeModeResolver
{
    public function __construct(
        private readonly PreviewContextService $previewContextService,
        private readonly ThemeContextService $themeContext,
        private readonly BackendThemeConfigInterface $backendThemeConfig,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public static function getThemeMode(string $area = 'frontend'): string
    {
        return ObjectManager::getInstance(self::class)->resolve($area);
    }

    public function resolve(string $area = 'frontend'): string
    {
        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';

        try {
            if ($this->previewContextService->shouldUseStoredContext()) {
                $context = $this->previewContextService->getCurrentContext();
                $previewThemeId = $this->previewContextService->getThemeIdForArea($area, $context, false);
                if ($previewThemeId > 0) {
                    $theme = $this->themeContext->resolveTheme($area);
                    if ($theme && $theme->getId()) {
                        $previewMode = LayoutScanner::getColorConfig($theme, $area);
                        if ($previewMode) {
                            return $previewMode;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        if ($area === 'backend') {
            $themeMode = $this->backendThemeConfig->getThemeModel();
        } else {
            $provider = $this->runtimeProviders->resolve(FrontendThemeModePreferenceProviderInterface::class);
            $themeMode = $provider?->resolveFrontendMode();
        }

        return $themeMode ?: 'default';
    }
}
