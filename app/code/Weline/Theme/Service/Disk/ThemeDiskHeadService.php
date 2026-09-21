<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
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

        $cacheKey = 'theme.disk_head_href.' . $area . '|' . $scope . '|' . (int)$theme->getId();
        if (\Weline\Framework\Runtime\RequestContext::isInitialized()) {
            $cached = \Weline\Framework\Runtime\RequestContext::get($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        // Only drop process L1 — never clearNamespace(weline_site_runtime) on the
        // storefront head hot path (each SharedState IPC ≈200ms under pool pressure).
        if ($this->shouldRefreshProcessCache($area)) {
            RequestLifecycleTrace::measurePhase(
                'theme.head.disk_override.reset',
                static function (): void {
                    ThemeData::clearProcessMemoryCache();
                },
                ['area' => $area, 'scope' => $scope],
            );
        }
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $bundleMap = RequestLifecycleTrace::measurePhase(
            'theme.head.disk_override.config',
            static fn(): array => ThemeData::getConfigList($area, 'disk_bundle', $scope),
            ['area' => $area, 'scope' => $scope],
        );
        $hash = (string)($bundleMap[$scope] ?? $bundleMap['default'] ?? '');
        if ($hash === '') {
            return $this->rememberHeadHref($cacheKey, '');
        }

        $filePath = RequestLifecycleTrace::measurePhase(
            'theme.head.disk_override.resolve',
            fn(): string => $this->compileService->resolveBundlePath((int)$theme->getId(), $area, $scope, $hash),
            ['area' => $area, 'scope' => $scope],
        );
        if ($filePath === '') {
            return $this->rememberHeadHref($cacheKey, '');
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

        return $this->rememberHeadHref($cacheKey, $urlPath . '?' . http_build_query($params));
    }

    private function rememberHeadHref(string $cacheKey, string $href): string
    {
        if ($cacheKey !== '' && \Weline\Framework\Runtime\RequestContext::isInitialized() && !$this->shouldRefreshProcessCache('frontend')) {
            \Weline\Framework\Runtime\RequestContext::set($cacheKey, $href);
        }

        return $href;
    }

    /**
     * 进程 L1 只在 query 显式 theme_disk_refresh=1|true 时清空。
     * 其它画布参数和后台区域都不刷新。
     */
    private function shouldRefreshProcessCache(string $area): bool
    {
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($query === '' && function_exists('w_env_request_uri')) {
            $requestUri = (string)w_env_request_uri();
            $query = (string)(parse_url($requestUri, PHP_URL_QUERY) ?: '');
        }
        if ($query === '') {
            return false;
        }

        $params = [];
        parse_str($query, $params);
        $value = strtolower(trim((string)($params['theme_disk_refresh'] ?? '')));

        return $value === '1' || $value === 'true';
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
