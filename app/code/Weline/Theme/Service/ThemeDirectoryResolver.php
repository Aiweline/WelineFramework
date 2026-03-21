<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Theme\Model\WelineTheme;

class ThemeDirectoryResolver
{
    private array $themeChainCache = [];
    private array $areaDirectoriesCache = [];

    public function __construct(
        private readonly WelineTheme $welineTheme,
    ) {
    }

    /**
     * @return WelineTheme[]
     */
    public function getThemeChain(?WelineTheme $theme = null, ?string $area = null): array
    {
        $theme = $this->getResolvedTheme($theme, $area);
        if (!$theme || !$theme->getId()) {
            return [];
        }

        $cacheKey = (string)$theme->getId();
        if (isset($this->themeChainCache[$cacheKey])) {
            return $this->themeChainCache[$cacheKey];
        }

        $chain = $theme->getThemeChain();
        if (empty($chain)) {
            $chain = [$theme];
        }

        $chain = array_values(array_reverse($chain));
        $this->themeChainCache[$cacheKey] = $chain;

        return $chain;
    }

    public function getAreaDirectories(string $area, ?WelineTheme $theme = null): array
    {
        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        $theme = $this->getResolvedTheme($theme, $area);
        $themeId = $theme?->getId() ?: 0;
        $cacheKey = $themeId . ':' . $area;

        if (isset($this->areaDirectoriesCache[$cacheKey])) {
            return $this->areaDirectoriesCache[$cacheKey];
        }

        $directories = [];
        $seen = [];
        foreach ($this->getThemeChain($theme, $area) as $layerTheme) {
            $basePath = rtrim($layerTheme->getPath(), '\\/');
            if ($basePath === '') {
                continue;
            }

            $layerKey = 'theme:' . $layerTheme->getId();
            $candidates = [
                ['path' => $basePath . DS . $area, 'structure' => 'modern'],
                ['path' => $basePath . DS . 'theme' . DS . $area, 'structure' => 'compat_theme'],
                ['path' => $basePath . DS . 'view' . DS . 'theme' . DS . $area, 'structure' => 'compat_view_theme'],
            ];

            foreach ($candidates as $candidate) {
                $path = $this->normalizePath($candidate['path']);
                if (isset($seen[$path]) || !is_dir($path)) {
                    continue;
                }
                $seen[$path] = true;
                $directories[] = [
                    'path' => $path,
                    'area' => $area,
                    'layer_key' => $layerKey,
                    'layer_type' => 'theme',
                    'theme_id' => (int)$layerTheme->getId(),
                    'theme_name' => (string)$layerTheme->getName(),
                    'theme_path' => $layerTheme->getOriginPath(),
                    'structure' => $candidate['structure'],
                ];
            }
        }

        $modules = Env::getInstance()->getModuleList();
        $defaultPath = $modules['Weline_Theme']['base_path'] ?? '';
        if (is_string($defaultPath) && $defaultPath !== '') {
            $defaultAreaPath = $this->normalizePath(rtrim($defaultPath, '\\/') . DS . 'view' . DS . 'theme' . DS . $area);
            if (!isset($seen[$defaultAreaPath]) && is_dir($defaultAreaPath)) {
                $directories[] = [
                    'path' => $defaultAreaPath,
                    'area' => $area,
                    'layer_key' => 'default:Weline_Theme',
                    'layer_type' => 'default',
                    'theme_id' => 0,
                    'theme_name' => 'Weline_Theme',
                    'theme_path' => 'Weline_Theme',
                    'structure' => 'module_default',
                ];
            }
        }

        $this->areaDirectoriesCache[$cacheKey] = $directories;

        return $directories;
    }

    public function extractAreaRelativePath(string $path): ?array
    {
        $normalized = str_replace(['/', '\\'], DS, $path);
        $patterns = [
            '/[\\\\\/]view[\\\\\/]theme[\\\\\/](frontend|backend)[\\\\\/](.+)$/i',
            '/[\\\\\/]theme[\\\\\/](frontend|backend)[\\\\\/](.+)$/i',
            '/[\\\\\/]app[\\\\\/]design[\\\\\/].+?[\\\\\/](frontend|backend)[\\\\\/](.+)$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return [
                    'area' => strtolower((string)$matches[1]),
                    'relative_path' => str_replace(['/', '\\'], DS, (string)$matches[2]),
                ];
            }
        }

        return null;
    }

    public function resolveThemeTemplatePath(string $modulePath, WelineTheme $theme): string
    {
        $pathInfo = $this->extractAreaRelativePath($modulePath);
        if ($pathInfo === null) {
            return $modulePath;
        }

        foreach ($this->getAreaDirectories($pathInfo['area'], $theme) as $directory) {
            $candidate = $this->normalizePath($directory['path'] . DS . $pathInfo['relative_path']);
            if (is_file($candidate)) {
                return $candidate;
            }

            if (str_ends_with($candidate, DS . 'default.phtml')) {
                $fallback = dirname($candidate, 2) . DS . basename(dirname($candidate)) . '.phtml';
                if (is_file($fallback)) {
                    return $fallback;
                }
            }
        }

        return $modulePath;
    }

    public function clearCache(): void
    {
        $this->themeChainCache = [];
        $this->areaDirectoriesCache = [];
    }

    private function getResolvedTheme(?WelineTheme $theme = null, ?string $area = null): ?WelineTheme
    {
        if ($theme && $theme->getId()) {
            return $theme;
        }

        $area = strtolower(trim((string)$area)) === 'backend' ? 'backend' : 'frontend';
        $activeTheme = clone $this->welineTheme;
        $activeTheme->clearData()->clearQuery();
        $activeTheme->getActiveTheme($area);

        return $activeTheme->getId() ? $activeTheme : null;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DS, $path);
        return rtrim($path, DS);
    }
}
