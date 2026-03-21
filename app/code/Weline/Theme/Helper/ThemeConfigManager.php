<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class ThemeConfigManager
{
    private const DEFAULT_SCOPE = 'default';

    public static function getConfig(WelineTheme $theme, string $type, string $area = 'frontend', ?string $scope = null)
    {
        $area = self::normalizeArea($area);
        $scope = self::resolveScope($scope, $area);

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        return match ($type) {
            'layouts' => ThemeData::getLayoutConfig($area, $scope),
            'partials' => ThemeData::getPartialsConfig($area, $scope),
            'colors' => ThemeData::getColorConfig($area, $scope),
            'variables' => ThemeData::getVariablesConfig($area, $scope),
            default => self::getGenericConfig($theme, $type, $area, $scope),
        };
    }

    public static function saveConfig(WelineTheme $theme, string $type, string $area, $value, ?string $scope = null): bool
    {
        $area = self::normalizeArea($area);
        $scope = self::resolveScope($scope, $area);

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $identify = $type . '.value';
        $valueStr = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;

        return ThemeData::set($identify, $valueStr, $scope);
    }

    public static function saveConfigs(WelineTheme $theme, array $configs): bool
    {
        ThemeData::setCurrentTheme($theme);

        foreach ($configs as $type => $areas) {
            if (!is_array($areas)) {
                continue;
            }

            foreach ($areas as $areaKey => $value) {
                [$area, $scope] = self::parseAreaKey((string)$areaKey);

                ThemeData::setCurrentArea($area);

                $identify = $type . '.value';
                $valueStr = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
                ThemeData::set($identify, $valueStr, $scope);
            }
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

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        return ThemeData::getScopes($area);
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

    private static function getGenericConfig(WelineTheme $theme, string $type, string $area, string $scope)
    {
        $identify = $type . '.value';
        $value = ThemeData::get($identify);

        if ($value !== null) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return $value;
        }

        if ($scope !== self::DEFAULT_SCOPE) {
            $defaultValue = ThemeData::get($identify, null);
            if ($defaultValue !== null) {
                return $defaultValue;
            }
        }

        return self::legacyConfigFallback($theme, $type, $area);
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
                $optionValue = is_array($layoutOption) ? ($layoutOption['value'] ?? null) : $layoutOption;
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
            [$resolvedArea, $resolvedScope] = self::getThemeContext()->resolveAreaAndScope($area, $scope);
            $area = $resolvedArea;

            return $resolvedScope;
        }

        return self::getThemeContext()->resolveCurrentScope($area);
    }

    private static function parseAreaKey(string $areaKey): array
    {
        return self::getThemeContext()->resolveAreaAndScope('frontend', $areaKey);
    }

    private static function legacyConfigFallback(WelineTheme $theme, string $type, string $area)
    {
        // 兼容遗留 theme.config JSON 的只读回退，不再作为主链来源，也不再继续写入。
        $config = $theme->getConfig();
        if (isset($config[$type][$area])) {
            return $config[$type][$area];
        }

        return $config[$type] ?? null;
    }

    private static function normalizeArea(string $area): string
    {
        return self::getThemeContext()->normalizeArea($area, ThemeContextService::AREA_FRONTEND);
    }

    private static function getThemeContext(): ThemeContextService
    {
        return ObjectManager::getInstance(ThemeContextService::class);
    }
}
