<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceCatalog;

class LayoutPathResolver
{
    public static function buildLayoutPath(string $originalPath, string $area, string $layoutType, string $layoutOption): string
    {
        return 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
    }

    public static function resolveLayoutTemplate(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        $resolved = self::resolveLayoutTemplateOnce($layoutPath, $theme, $area);
        if ($resolved !== null) {
            return $resolved;
        }

        // 例如 account.dashboard：若未命中 dashboard.phtml，则回退 account.default（模块内仍有完整页面骨架）
        $normalized = str_replace('\\', '/', $layoutPath);
        if (preg_match('#^theme/([^/]+)/layouts/([^/]+)/(.+)\.phtml$#', $normalized, $m)) {
            $areaFromPath = $m[1];
            $group = $m[2];
            $optionBase = $m[3];
            if ($optionBase !== '' && $optionBase !== 'default') {
                $fallbackPath = 'theme' . DS . $areaFromPath . DS . 'layouts' . DS . $group . DS . 'default.phtml';

                return self::resolveLayoutTemplateOnce($fallbackPath, $theme, $area);
            }
        }

        return null;
    }

    /**
     * 解析单层布局路径；先允许当前主题/父主题提供布局，继承链无可用文件时再回退 Weline_Theme 模块默认文件。
     */
    private static function resolveLayoutTemplateOnce(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        $defaultPath = self::getDefaultLayoutPath($layoutPath, $area);
        if ($defaultPath && is_file($defaultPath)) {
            try {
                /** @var ThemePathResolver $themePathResolver */
                $themePathResolver = ObjectManager::getInstance(ThemePathResolver::class);
                $resolvedPath = $themePathResolver->resolveThemeFile($defaultPath, $theme);
                if (is_file($resolvedPath)) {
                    return self::toWelineThemeModulePath($layoutPath);
                }
            } catch (\Throwable) {
                if (is_file($defaultPath)) {
                    return self::toWelineThemeModulePath($layoutPath);
                }
            }

            if (is_file($defaultPath)) {
                return self::toWelineThemeModulePath($layoutPath);
            }
        }

        $moduleContributedPath = self::resolveModuleContributedLayout($layoutPath, $theme, $area);
        if ($moduleContributedPath !== null) {
            return $moduleContributedPath;
        }

        if ($defaultPath) {
            try {
                /** @var ThemePathResolver $themePathResolver */
                $themePathResolver = ObjectManager::getInstance(ThemePathResolver::class);
                $resolvedPath = $themePathResolver->resolveThemeFile($defaultPath, $theme);
                if (is_file($resolvedPath)) {
                    return self::toWelineThemeModulePath($layoutPath);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function getDefaultLayoutPath(string $layoutPath, string $area): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return null;
        }

        $themeModule = $modules['Weline_Theme'];
        return rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . $layoutPath;
    }

    public static function convertToModulePath(string $fullPath, string $area): string
    {
        $themePos = strpos($fullPath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return $fullPath;
        }

        $themeRelativePath = substr($fullPath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        $themeRelativePath = str_replace('\\', '/', $themeRelativePath);

        return 'Weline_Theme::theme/' . $themeRelativePath;
    }

    public static function getLayoutFilePath(string $modulePath, WelineTheme $theme, string $area): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }

        [$moduleCode, $relativePath] = explode('::', $modulePath, 2);

        if ($moduleCode === 'Weline_Theme') {
            $defaultPath = self::getDefaultLayoutPath($relativePath, $area);
            if (!$defaultPath) {
                return null;
            }

            try {
                /** @var ThemePathResolver $themePathResolver */
                $themePathResolver = ObjectManager::getInstance(ThemePathResolver::class);
                $resolvedPath = $themePathResolver->resolveThemeFile($defaultPath, $theme);
                if (is_file($resolvedPath)) {
                    return $resolvedPath;
                }
            } catch (\Throwable) {
            }

            return is_file($defaultPath) ? $defaultPath : null;
        }

        $moduleDefaultPath = self::getModuleThemeFilePath($moduleCode, $relativePath);
        if (!$moduleDefaultPath) {
            return null;
        }

        try {
            /** @var ThemePathResolver $themePathResolver */
            $themePathResolver = ObjectManager::getInstance(ThemePathResolver::class);
            $resolvedPath = $themePathResolver->resolveThemeFile($moduleDefaultPath, $theme);
            if (is_file($resolvedPath)) {
                return $resolvedPath;
            }
        } catch (\Throwable) {
        }

        return is_file($moduleDefaultPath) ? $moduleDefaultPath : null;
    }

    public static function getCompiledLayoutPath(string $modulePath, string $lang): string
    {
        if (strpos($modulePath, '::') === false) {
            return '';
        }
        [, $relativePath] = explode('::', $modulePath, 2);
        $relativePath = str_replace('/', DS, trim($relativePath, DS));
        $parts = explode(DS, $relativePath);
        $fileName = array_pop($parts);
        $fileDir = $parts ? implode(DS, $parts) . DS : '';
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = $ext ? substr($fileName, 0, -strlen($ext) - 1) : $fileName;
        $comFileName = 'com_' . $baseName . ($ext ? '.' . $ext : '.phtml');

        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return '';
        }
        $themeModule = $modules['Weline_Theme'];
        if (PROD) {
            $modulePathStr = $themeModule['path'] ?? 'Weline' . DS . 'Theme';
            $compileDir = Env::path_framework_generated_complicate . $modulePathStr . DataInterface::dir . DS;
        } else {
            $basePath = rtrim($themeModule['base_path'], DS);
            $compileDir = $basePath . DS . DataInterface::dir . DS . DataInterface::dir_type_TEMPLATE_COMPILE . DS;
        }
        return $compileDir . $lang . DS . $fileDir . $comFileName;
    }

    public static function formatParsedParams(array $parsedParams): array
    {
        $params = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? null;
            if (!$key) {
                continue;
            }
            $params[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
            ];
        }
        return $params;
    }

    private static function toWelineThemeModulePath(string $layoutPath): string
    {
        return 'Weline_Theme::' . str_replace('\\', '/', $layoutPath);
    }

    private static function resolveModuleContributedLayout(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        $layoutInfo = self::parseLayoutPath($layoutPath, $area);
        if ($layoutInfo === null) {
            return null;
        }

        try {
            /** @var ThemeResourceCatalog $resourceCatalog */
            $resourceCatalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            $resource = $resourceCatalog->getLayoutResource(
                $layoutInfo['area'],
                $theme,
                $layoutInfo['type'],
                $layoutInfo['option']
            );
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($resource) || ($resource['layer_type'] ?? '') !== 'module') {
            return null;
        }

        $moduleName = (string)($resource['module_name'] ?? '');
        $relativePath = str_replace('\\', '/', (string)($resource['relative_path'] ?? ''));
        if ($moduleName === '' || $relativePath === '') {
            return null;
        }

        return $moduleName . '::theme/' . $layoutInfo['area'] . '/layouts/' . ltrim($relativePath, '/');
    }

    private static function parseLayoutPath(string $layoutPath, string $fallbackArea): ?array
    {
        $normalized = str_replace('\\', '/', $layoutPath);
        if (!preg_match('#^theme/([^/]+)/layouts/([^/]+)/(.+)\.phtml$#', $normalized, $matches)) {
            return null;
        }

        return [
            'area' => $matches[1] !== '' ? $matches[1] : $fallbackArea,
            'type' => $matches[2],
            'option' => $matches[3] === '' ? 'default' : $matches[3],
        ];
    }

    private static function getModuleThemeFilePath(string $moduleCode, string $relativePath): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        $basePath = $modules[$moduleCode]['base_path'] ?? '';
        if (!is_string($basePath) || $basePath === '') {
            return null;
        }

        $relativePath = str_replace(['/', '\\'], DS, trim($relativePath, '/\\'));
        if (stripos($relativePath, 'view' . DS . 'theme' . DS) === 0) {
            return rtrim($basePath, DS) . DS . $relativePath;
        }

        if (stripos($relativePath, 'theme' . DS) === 0) {
            return rtrim($basePath, DS) . DS . 'view' . DS . $relativePath;
        }

        return rtrim($basePath, DS) . DS . 'view' . DS . 'theme' . DS . $relativePath;
    }
}
