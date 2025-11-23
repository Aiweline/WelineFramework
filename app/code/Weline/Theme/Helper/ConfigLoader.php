<?php

/*
 * 配置加载器
 * 在渲染时加载配置（优先预览配置，其次保存的配置，最后默认配置）
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

class ConfigLoader
{
    /**
     * 加载配置（优先级：预览配置 > 保存配置 > 默认配置）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $type 配置类型
     * @param string $area 区域
     * @return mixed 配置值
     */
    public static function loadConfig(WelineTheme $theme, string $type, string $area, ?string $scope = null)
    {
        // 1. 优先检查预览配置
        if (PreviewManager::isPreviewMode()) {
            $previewArea = PreviewManager::getPreviewArea();
            if ($previewArea === $area) {
                $previewConfig = PreviewManager::getPreviewConfig($type, $area);
                if ($previewConfig !== null) {
                    return $previewConfig;
                }
            }
        }
        
        // 2. 读取保存的配置
        $savedConfig = ThemeConfigManager::getConfig($theme, $type, $area, $scope);
        if ($savedConfig !== null) {
            return $savedConfig;
        }
        
        // 3. 使用默认配置
        return self::getDefaultConfig($type, $area);
    }
    
    /**
     * 获取布局配置（返回完整布局配置数组）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return array 布局配置数组
     */
    public static function getLayoutConfig(WelineTheme $theme, string $area, ?string $scope = null): array
    {
        $layouts = self::loadConfig($theme, 'layouts', $area, $scope);
        return is_array($layouts) ? $layouts : [];
    }
    
    /**
     * 获取指定布局类型的配置值
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $layoutType 布局类型（如account, homepage）
     * @return string 布局选项值
     */
    public static function getLayoutConfigValue(WelineTheme $theme, string $area, string $layoutType = 'default', ?string $scope = null): string
    {
        $layouts = self::getLayoutConfig($theme, $area, $scope);
        
        if (isset($layouts[$layoutType])) {
            return $layouts[$layoutType];
        }
        
        // 如果没有配置，返回默认值
        return 'default';
    }
    
    /**
     * 获取Header配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return string Header选项值
     */
    public static function getHeaderConfig(WelineTheme $theme, string $area, ?string $scope = null): string
    {
        $header = self::loadConfig($theme, 'headers', $area, $scope);
        
        if (is_string($header) && !empty($header)) {
            return $header;
        }
        
        // 如果没有配置，返回默认值
        return 'default';
    }
    
    /**
     * 获取色系配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return string 色系值
     */
    public static function getColorConfig(WelineTheme $theme, string $area, ?string $scope = null): string
    {
        $color = self::loadConfig($theme, 'colors', $area, $scope);
        
        if (is_string($color) && !empty($color)) {
            return $color;
        }
        
        // 如果没有配置，返回默认值
        return 'light';
    }
    
    /**
     * 获取部件配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @param string $partialType 部件类型（如header, footer）
     * @return string 部件选项值
     */
    public static function getPartialConfig(WelineTheme $theme, string $area, string $partialType, ?string $scope = null): string
    {
        $partials = self::loadConfig($theme, 'partials', $area, $scope);
        
        if (is_array($partials) && isset($partials[$partialType])) {
            return $partials[$partialType];
        }
        
        // 如果没有配置，返回默认值
        return 'default';
    }
    
    /**
     * 获取变量配置
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return array 变量数组
     */
    public static function getVariablesConfig(WelineTheme $theme, string $area, ?string $scope = null): array
    {
        $variables = self::loadConfig($theme, 'variables', $area, $scope);
        
        if (is_array($variables)) {
            return $variables;
        }
        
        // 如果没有配置，返回空数组
        return [];
    }
    
    /**
     * 获取默认配置
     * 
     * @param string $type 配置类型
     * @param string $area 区域
     * @return mixed 默认配置值
     */
    private static function getDefaultConfig(string $type, string $area)
    {
        $defaults = [
            'layouts' => ['default' => 'default'],
            'headers' => 'default',
            'colors' => 'light',
            'partials' => ['header' => 'default', 'footer' => 'default', 'sidebar' => 'default'],
            'variables' => [],
            'components' => [],
        ];
        
        return $defaults[$type] ?? null;
    }
}

