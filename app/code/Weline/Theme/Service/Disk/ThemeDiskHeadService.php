<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Head helper: resolve conditional override stylesheet URL for current theme.
 */
class ThemeDiskHeadService
{
    public function __construct(
        private readonly ThemeDiskCompileService $compileService,
    ) {
    }

    /**
     * Absolute or site URL for override CSS, or empty when no bundle.
     */
    public function getOverrideHref(string $area, ?WelineTheme $theme = null, string $scope = 'default'): string
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $theme = $theme ?? $this->resolveTheme($area);
        if (!$theme || !(int)$theme->getId()) {
            return '';
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        // ThemeData::set() clears request/process caches via clearCache(); workers may still
        // hold a stale empty disk_bundle entry in process performanceCache across requests.
        ThemeData::clearCache();
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $bundleMap = ThemeData::getConfigList($area, 'disk_bundle', $scope);
        $hash = (string)($bundleMap[$scope] ?? $bundleMap['default'] ?? '');
        if ($hash === '') {
            return '';
        }

        $filePath = $this->compileService->resolveBundlePath((int)$theme->getId(), $area, $scope, $hash);
        if ($filePath === '') {
            return '';
        }

        $params = [
            'theme_id' => (int)$theme->getId(),
            'area' => $area,
            'scope' => $scope,
            'h' => $hash,
        ];

        // Relative URL avoids CLI/host misfires; works for both page and CDN same-origin.
        $urlPath = $area === 'backend'
            ? $this->backendRoutePath('theme/backend/disk/override')
            : '/theme/frontend/disk/override';

        return $urlPath . '?' . http_build_query($params);
    }

    public function buildOverrideLinkHtml(string $area, ?WelineTheme $theme = null, string $scope = 'default'): string
    {
        $href = $this->getOverrideHref($area, $theme, $scope);
        if ($href === '') {
            return '';
        }
        $safe = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<link rel="stylesheet" type="text/css" href="' . $safe . '" data-weline-theme-disk="1"/>';
    }

    private function resolveTheme(string $area): ?WelineTheme
    {
        $current = ThemeData::getCurrentTheme();
        if ($current instanceof WelineTheme && (int)$current->getId() > 0) {
            return $current;
        }

        /** @var WelineTheme $model */
        $model = ObjectManager::getInstance(WelineTheme::class);
        $active = $model->getActiveTheme($area);

        return $active instanceof WelineTheme && (int)$active->getId() > 0 ? $active : null;
    }

    private function backendRoutePath(string $route): string
    {
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $full = $url->getBackendUrl($route, [], false);
            $path = parse_url($full, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        } catch (\Throwable) {
        }

        $prefix = (string)(\Weline\Framework\App\Env::getInstance()->getConfig(
            'router.area_routes.backend.prefix',
            'admin'
        ) ?? 'admin');

        return '/' . trim($prefix, '/') . '/' . ltrim($route, '/');
    }
}
