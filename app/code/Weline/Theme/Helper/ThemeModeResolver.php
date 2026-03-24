<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Block\ThemeConfig as BackendThemeConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Block\ThemeConfig as FrontendThemeConfig;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

class ThemeModeResolver
{
    public static function getThemeMode(string $area = 'frontend'): string
    {
        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';

        try {
            /** @var PreviewContextService $previewContextService */
            $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
            if ($previewContextService->shouldUseStoredContext()) {
                $context = $previewContextService->getCurrentContext();
                $previewThemeId = $previewContextService->getThemeIdForArea($area, $context, false);
                if ($previewThemeId > 0) {
                    /** @var ThemeContextService $themeContext */
                    $themeContext = ObjectManager::getInstance(ThemeContextService::class);
                    $theme = $themeContext->resolveTheme($area);
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

        /** @var BackendThemeConfig|FrontendThemeConfig $themeConfig */
        $themeConfig = $area === 'backend'
            ? ObjectManager::getInstance(BackendThemeConfig::class)
            : ObjectManager::getInstance(FrontendThemeConfig::class);
        $themeMode = $themeConfig->getThemeModel();

        return $themeMode ?: 'default';
    }
}
