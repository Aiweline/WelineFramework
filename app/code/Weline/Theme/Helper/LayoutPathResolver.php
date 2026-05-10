<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Theme\Model\WelineTheme;

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
        if (preg_match('#^theme/([^/]+)/layouts/([^/]+)/([^/.]+)\.phtml$#', $normalized, $m)) {
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
     * 解析单层布局路径；主题链无可用文件时仍回退 Weline_Theme 模块下的默认文件，避免 layoutTemplate 为空导致整页不套布局。
     */
    private static function resolveLayoutTemplateOnce(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        $defaultPath = self::getDefaultLayoutPath($layoutPath, $area);
        if (!$defaultPath || !is_file($defaultPath)) {
            return null;
        }

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
        $relativePathForFile = str_replace('/', DS, $relativePath);

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

        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }

        if (strpos($relativePath, 'theme/') === 0) {
            $relativePath = substr($relativePath, 6);
        }

        $fullPath = rtrim($themePath, DS) . DS . 'view' . DS . str_replace('/', DS, $relativePath);
        if (is_file($fullPath)) {
            return $fullPath;
        }

        return null;
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
}
