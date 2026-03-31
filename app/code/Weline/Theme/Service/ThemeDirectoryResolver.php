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
        private readonly ?ThemeContextService $themeContextService = null,
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

        $normalizedArea = strtolower(trim((string)$area)) === 'backend' ? 'backend' : 'frontend';
        $cacheKey = (string)$theme->getId() . ':' . $normalizedArea;
        if (isset($this->themeChainCache[$cacheKey])) {
            return $this->themeChainCache[$cacheKey];
        }

        $chain = $theme->getThemeChain();
        if (empty($chain)) {
            $chain = [$theme];
        }

        $chain = array_values(array_filter(
            array_reverse($chain),
            fn(WelineTheme $chainTheme): bool => $this->themeHasAreaDirectory($chainTheme, $normalizedArea)
        ));
        if (empty($chain)) {
            $chain = [$theme];
        }
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

        // 处理模块路径格式：Weline_Customer::templates/frontend/account/login.phtml
        if (preg_match('/^(.+?)::(.+)$/', $normalized, $moduleMatches)) {
            $relativePath = $moduleMatches[2];
            // 提取 area 和相对路径：templates/frontend/account/login.phtml -> frontend/account/login.phtml
            if (preg_match('/templates[\\\\\/](frontend|backend)[\\\\\/](.+)$/i', $relativePath, $matches)) {
                return [
                    'area' => strtolower((string)$matches[1]),
                    'relative_path' => str_replace(['/', '\\'], DS, (string)$matches[2]),
                ];
            }
        }

        // 优先处理相对路径格式：theme/frontend/... 或 view/theme/frontend/...
        // 这种格式没有前导分隔符，直接匹配
        if (preg_match('/^theme[\\\\\/](frontend|backend)[\\\\\/](.+)$/i', $normalized, $matches)) {
            return [
                'area' => strtolower((string)$matches[1]),
                'relative_path' => str_replace(['/', '\\'], DS, (string)$matches[2]),
            ];
        }
        if (preg_match('/^view[\\\\\/]theme[\\\\\/](frontend|backend)[\\\\\/](.+)$/i', $normalized, $matches)) {
            return [
                'area' => strtolower((string)$matches[1]),
                'relative_path' => str_replace(['/', '\\'], DS, (string)$matches[2]),
            ];
        }

        // 处理 app/design/... 格式（无前导分隔符）
        if (preg_match('/^app[\\\\\/]design[\\\\\/](.+?)[\\\\\/](frontend|backend)[\\\\\/](.+)$/i', $normalized, $matches)) {
            return [
                'area' => strtolower((string)$matches[2]),
                'relative_path' => str_replace(['/', '\\'], DS, (string)$matches[3]),
            ];
        }

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
        // 标准化路径分隔符
        $normalizedModulePath = str_replace(['/', '\\'], DS, $modulePath);

        // 尝试从绝对路径提取模块路径格式
        // 例如：E:\...\app\code\Weline\Customer\view\templates\frontend\account\login.phtml
        // 转换为：Weline_Customer::templates/frontend/account/login.phtml
        $modulePathForMatch = $this->convertAbsolutePathToModulePath($normalizedModulePath);

        // 检查是否是模块路径格式（如 Weline_Customer::templates/frontend/account/login.phtml）
        if (preg_match('/^(.+?)::(.+)$/', $modulePathForMatch, $matches)) {
            $moduleName = $matches[1];
            $relativePath = $matches[2];

            // 构建主题模块覆盖路径
            // app/design/WeShop/motor/Weline_Customer/templates/frontend/account/login.phtml
            $themePath = rtrim($theme->getPath(), '\\/');

            if ($themePath !== '') {
                // 模块名中的下划线对应目录中的反斜杠或正斜杠
                // Weline_Customer -> Weline\Customer 或 Weline/Customer
                $modulePath1 = str_replace('_', DS, $moduleName);
                $modulePath2 = str_replace('_', '/', $moduleName);

                // 尝试两种路径格式（优先不带 view 的格式）
                // 格式1: .../Weline_Customer/templates/frontend/... （标准模块路径，下划线格式）
                $overridePath1 = $themePath . DS . $moduleName . DS . str_replace('/', DS, $relativePath);
                // 格式2: .../Weline\Customer/templates/frontend/... （反斜杠格式）
                $overridePath2 = $themePath . DS . $modulePath1 . DS . str_replace('/', DS, $relativePath);
                // 格式3: .../Weline/Customer/templates/frontend/... （正斜杠格式）
                $overridePath3 = $themePath . DS . $modulePath2 . DS . str_replace('/', DS, $relativePath);

                // 格式4-6: 带 view 前缀的变体
                $overridePath4 = $themePath . DS . $moduleName . DS . 'view' . DS . str_replace('/', DS, $relativePath);
                $overridePath5 = $themePath . DS . $modulePath1 . DS . 'view' . DS . str_replace('/', DS, $relativePath);
                $overridePath6 = $themePath . DS . $modulePath2 . DS . 'view' . DS . str_replace('/', DS, $relativePath);

                foreach ([$overridePath1, $overridePath2, $overridePath3, $overridePath4, $overridePath5, $overridePath6] as $overridePath) {
                    if (is_file($overridePath)) {
                        return $overridePath;
                    }
                }
            }
        }

        // 如果是 app/design 路径（主题布局/partials 等），直接使用 area directories 解析
        $pathInfo = $this->extractAreaRelativePath($normalizedModulePath);
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

    /**
     * 将绝对路径转换为模块路径格式
     *
     * 例如：
     * E:\...\app\code\Weline\Customer\view\templates\frontend\account\login.phtml
     * -> Weline_Customer::templates/frontend/account/login.phtml
     *
     * @param string $absolutePath 绝对路径
     * @return string 模块路径格式
     */
    private function convertAbsolutePathToModulePath(string $absolutePath): string
    {
        // 处理 app\code\Vendor\Module\view\templates\... 格式
        // 提取 Vendor_Module::templates/... 部分
        if (preg_match('/app[\\\\\/]code[\\\\\/]([A-Za-z0-9_]+[\\\\\/][A-Za-z0-9_]+)[\\\\\/]view[\\\\\/](templates[\\\\\/].+)$/i', $absolutePath, $matches)) {
            $moduleName = str_replace(DS, '_', $matches[1]);
            $templatePath = str_replace(DS, '/', $matches[2]);
            return $moduleName . '::' . $templatePath;
        }

        // 处理 app\code\Vendor\Module\templates\... 格式（无 view 目录）
        if (preg_match('/app[\\\\\/]code[\\\\\/]([A-Za-z0-9_]+[\\\\\/][A-Za-z0-9_]+)[\\\\\/](templates[\\\\\/].+)$/i', $absolutePath, $matches)) {
            $moduleName = str_replace(DS, '_', $matches[1]);
            $templatePath = str_replace(DS, '/', $matches[2]);
            return $moduleName . '::' . $templatePath;
        }

        return $absolutePath;
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

        if ($this->themeContextService) {
            try {
                $resolvedTheme = $this->themeContextService->resolveTheme($area);
                if ($resolvedTheme && $resolvedTheme->getId()) {
                    return $resolvedTheme;
                }
            } catch (\Throwable) {
                // Ignore and fallback to active theme lookup.
            }
        }

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

    private function themeHasAreaDirectory(WelineTheme $theme, string $area): bool
    {
        $basePath = rtrim((string)$theme->getPath(), '\\/');
        if ($basePath === '') {
            return false;
        }

        $candidates = [
            $basePath . DS . $area,
            $basePath . DS . 'theme' . DS . $area,
            $basePath . DS . 'view' . DS . 'theme' . DS . $area,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($this->normalizePath($candidate))) {
                return true;
            }
        }

        return false;
    }
}
