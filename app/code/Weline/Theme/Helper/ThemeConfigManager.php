<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;

class ThemeConfigManager
{
    private const DEFAULT_SCOPE = 'default';

    public static function getConfig(WelineTheme $theme, string $type, string $area = 'frontend', ?string $scope = null)
    {
        $area = self::normalizeArea($area);
        $scope = self::resolveScope($scope, $area);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 对于 layouts 类型，使用 ThemeData::getLayoutConfig() 读取所有布局配置
        if ($type === 'layouts') {
            $layoutConfig = ThemeData::getLayoutConfig($area, $scope);
            // 调试：记录读取到的布局配置
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->debug('ThemeConfigManager::getConfig layouts', [
                        'area' => $area,
                        'scope' => $scope,
                        'layoutConfig' => $layoutConfig
                    ]);
                }
            } catch (\Throwable $e) {
                // 忽略日志错误
            }
            return $layoutConfig;
        }
        
        // 对于 partials 类型，使用 ThemeData::getPartialsConfig() 读取所有部件配置
        if ($type === 'partials') {
            $partialsConfig = ThemeData::getPartialsConfig($area, $scope);
            // 调试：记录读取到的部件配置
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->debug('ThemeConfigManager::getConfig partials', [
                        'area' => $area,
                        'scope' => $scope,
                        'partialsConfig' => $partialsConfig
                    ]);
                }
            } catch (\Throwable $e) {
                // 忽略日志错误
            }
            return $partialsConfig;
        }
        
        // 对于 colors 类型，使用 ThemeData::getColorConfig() 读取色系配置
        if ($type === 'colors') {
            $colorConfig = ThemeData::getColorConfig($area, $scope);
            // 调试：记录读取到的色系配置
            try {
                $logger = \Weline\Framework\App\Env::getInstance()->getLogger();
                if ($logger) {
                    $logger->debug('ThemeConfigManager::getConfig colors', [
                        'area' => $area,
                        'scope' => $scope,
                        'colorConfig' => $colorConfig
                    ]);
                }
            } catch (\Throwable $e) {
                // 忽略日志错误
            }
            return $colorConfig;
        }

        // 使用 ThemeData 读取配置
        // 格式：theme.{area}.{type}.value
        $identify = "{$type}.value";
        $value = ThemeData::get($identify);
        
        // 如果当前 scope 没有配置，尝试从 default scope 读取
        // 注意：ThemeData内部使用MetaData，会自动处理scope和语言回退
        // 如果还是没有值，尝试使用默认scope
        if ($value === null && $scope !== self::DEFAULT_SCOPE) {
            // 重新尝试使用default scope
            $value = ThemeData::get($identify);
        }

        if ($value !== null) {
            // 如果是 JSON 字符串，尝试解析
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
            return $value;
        }

        return self::legacyConfigFallback($theme, $type, $area);
    }

    public static function saveConfig(WelineTheme $theme, string $type, string $area, $value, ?string $scope = null): bool
    {
        $area = self::normalizeArea($area);
        $scope = self::resolveScope($scope, $area);

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 使用 ThemeData::set() 保存配置
        // 格式：{type}.value
        $identify = "{$type}.value";
        
        // 如果 value 是数组，转换为 JSON 字符串
        $valueStr = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        
        $result = ThemeData::set($identify, $valueStr, $scope);

        if ($scope === self::DEFAULT_SCOPE) {
            self::syncLegacyConfig($theme, $type, $area, $value);
        }

        return $result;
    }

    public static function saveConfigs(WelineTheme $theme, array $configs): bool
    {
        $legacyConfig = $theme->getConfig();
        $hasLegacyUpdate = false;

        // 设置当前主题
        ThemeData::setCurrentTheme($theme);

        foreach ($configs as $type => $areas) {
            if (!is_array($areas)) {
                continue;
            }
            foreach ($areas as $areaKey => $value) {
                [$area, $scope] = self::parseAreaKey((string)$areaKey);
                
                // 设置当前区域
                ThemeData::setCurrentArea($area);
                
                // 使用 ThemeData::set() 保存配置
                $identify = "{$type}.value";
                $valueStr = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
                ThemeData::set($identify, $valueStr, $scope);

                if ($scope === self::DEFAULT_SCOPE) {
                    $legacyConfig[$type][$area] = $value;
                    $hasLegacyUpdate = true;
                }
            }
        }

        if ($hasLegacyUpdate) {
            $theme->setConfig($legacyConfig);
            $theme->save();
            self::clearThemeCache($theme);
        }

        return true;
    }

    public static function getAllConfig(WelineTheme $theme): array
    {
        return $theme->getConfig();
    }

    public static function getScopes(WelineTheme $theme, string $area): array
    {
        $area = self::normalizeArea($area);
        
        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 通过ThemeData的performanceLoad预加载配置
        // 然后通过MetaData的performanceCache获取scope信息
        $namespace = "theme.{$area}";
        ThemeData::performanceLoad($namespace, $namespace . ".*");
        
        // 由于MetaData不直接提供查询scope列表的公共方法
        // 我们通过MetaData的performanceLoad预加载后，需要从内部缓存获取
        // 这里暂时返回默认scope列表，如果需要完整功能，可以通过反射或扩展MetaData实现
        $scopeList = ['default'];
        
        // 注意：获取所有scope的功能需要直接查询MetaConfig表
        // 但根据要求统一使用MetaData，这里暂时返回默认值
        // 如果需要完整功能，可以考虑在ThemeData中添加getScopes方法，内部通过MetaData的performanceLoad获取
        
        return $scopeList;
    }

    public static function validateConfig(WelineTheme $theme, string $type, string $area, $value): bool
    {
        $area = self::normalizeArea($area);

        return match ($type) {
            'layouts' => self::validateLayouts($theme, $area, $value),
            'headers' => self::validateHeaders($theme, $area, $value),
            'colors' => self::validateColors($theme, $area, $value),
            'partials' => self::validatePartials($theme, $area, $value),
            'variables' => self::validateVariables($theme, $area, $value),
            default => true,
        };
    }

    private static function validateLayouts(WelineTheme $theme, string $area, $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $availableLayouts = LayoutScanner::scanLayouts($theme, $area);
        foreach ($value as $layoutType => $option) {
            if (!isset($availableLayouts[$layoutType])) {
                return false;
            }

            $found = false;
            foreach ($availableLayouts[$layoutType] as $layoutOption) {
                $optionValue = is_array($layoutOption) ? $layoutOption['value'] : $layoutOption;
                if ($optionValue === $option) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    private static function validateHeaders(WelineTheme $theme, string $area, $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $availableHeaders = LayoutScanner::scanHeaders($theme, $area);
        foreach ($availableHeaders as $header) {
            $headerValue = is_array($header) ? ($header['value'] ?? null) : $header;
            if ($headerValue === $value) {
                return true;
            }
        }

        return false;
    }

    private static function validateColors(WelineTheme $theme, string $area, $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $availableColors = LayoutScanner::scanColors($theme, $area);
        foreach ($availableColors as $color) {
            $colorValue = is_array($color) ? ($color['value'] ?? null) : $color;
            if ($colorValue === $value) {
                return true;
            }
        }

        return false;
    }

    private static function validatePartials(WelineTheme $theme, string $area, $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $availablePartials = LayoutScanner::scanPartials($theme, $area);
        foreach ($value as $partialType => $partialValue) {
            if (!isset($availablePartials[$partialType])) {
                return false;
            }

            $found = false;
            foreach ($availablePartials[$partialType] as $partialOption) {
                $optionValue = is_array($partialOption) ? ($partialOption['value'] ?? null) : $partialOption;
                if ($optionValue === $partialValue) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    private static function validateVariables(WelineTheme $theme, string $area, $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $availableVariables = LayoutScanner::scanVariables($theme, $area);
        $availableVarValues = [];
        foreach ($availableVariables as $varOption) {
            $availableVarValues[] = is_array($varOption) ? ($varOption['value'] ?? null) : $varOption;
        }

        foreach ($value as $variable) {
            if (!in_array($variable, $availableVarValues, true)) {
                return false;
            }
        }

        return true;
    }

    private static function resolveScope(?string $scope, string &$area): string
    {
        if ($scope !== null && $scope !== '') {
            return self::sanitizeScope($scope, $area);
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $paramName = 'scope_' . $area;
            $scopeParam = $request->getParam($paramName) ?? $request->getParam('scope');
            if ($scopeParam) {
                return self::sanitizeScope($scopeParam, $area);
            }
        } catch (\Throwable) {
            // ignore
        }

        if (PreviewManager::isPreviewMode()) {
            $previewScope = PreviewManager::getPreviewScope($area);
            if ($previewScope) {
                return self::sanitizeScope($previewScope, $area);
            }
        }

        return self::DEFAULT_SCOPE;
    }

    private static function sanitizeScope(string $scopeValue, string &$area): string
    {
        $scopeValue = trim($scopeValue);
        if ($scopeValue === '') {
            return self::DEFAULT_SCOPE;
        }

        if (str_contains($scopeValue, '/')) {
            [$maybeArea, $rest] = explode('/', $scopeValue, 2);
            if ($maybeArea) {
                $area = self::normalizeArea($maybeArea);
            }
            $scopeValue = $rest;
        }

        $scopeValue = trim($scopeValue);
        return $scopeValue !== '' ? $scopeValue : self::DEFAULT_SCOPE;
    }

    private static function parseAreaKey(string $areaKey): array
    {
        if (str_contains($areaKey, '/')) {
            [$area, $scope] = explode('/', $areaKey, 2);
            $area = self::normalizeArea($area ?: 'frontend');
            $scope = trim($scope) !== '' ? trim($scope) : self::DEFAULT_SCOPE;
            return [$area, $scope];
        }

        return [self::normalizeArea($areaKey), self::DEFAULT_SCOPE];
    }

    private static function legacyConfigFallback(WelineTheme $theme, string $type, string $area)
    {
        $config = $theme->getConfig();
        if (isset($config[$type][$area])) {
            return $config[$type][$area];
        }

        return $config[$type] ?? null;
    }

    private static function syncLegacyConfig(WelineTheme $theme, string $type, string $area, $value): void
    {
        $config = $theme->getConfig();
        $config[$type][$area] = $value;
        $theme->setConfig($config);
        $theme->save();
        self::clearThemeCache($theme);
    }

    private static function clearThemeCache(WelineTheme $theme): void
    {
        $theme->_cache->delete('theme');
        $theme->_cache->delete('theme_parent_' . $theme->getId());
        $theme->_cache->delete('theme_config_' . $theme->getId());
    }

    private static function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        return $area ?: 'frontend';
    }
}

