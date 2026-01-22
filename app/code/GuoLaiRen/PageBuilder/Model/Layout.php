<?php

declare(strict_types=1);

/**
 * 布局类型管理模型
 * 
 * 布局定义了页面的整体结构，包括区域划分、组件放置规则等
 * 布局与模板（样式）是分离的概念：
 * - 布局(Layout): 页面结构（如博客布局、落地页布局）
 * - 模板(Template/Style): 视觉样式（如 tpmst、market-mastery）
 * 
 * 一个页面 = 布局 + 模板 + 组件配置
 */

namespace GuoLaiRen\PageBuilder\Model;

class Layout
{
    // 布局类型常量
    public const TYPE_DEFAULT = 'default';
    public const TYPE_BLOG = 'blog';
    public const TYPE_LANDING = 'landing';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_MINIMAL = 'minimal';
    
    // 区域常量
    public const REGION_HEADER = 'header';
    public const REGION_SIDEBAR = 'sidebar';
    public const REGION_CONTENT = 'content';
    public const REGION_FOOTER = 'footer';
    public const REGION_HERO = 'hero';
    public const REGION_CTA = 'cta';
    
    private static ?array $layoutsCache = null;
    private static ?array $layoutConfigCache = [];
    
    /**
     * 获取所有可用布局类型
     */
    public static function getAvailableLayouts(): array
    {
        if (self::$layoutsCache !== null) {
            return self::$layoutsCache;
        }
        
        $layoutsFile = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/_layouts/layout.json';
        if (!file_exists($layoutsFile)) {
            self::$layoutsCache = self::getDefaultLayouts();
            return self::$layoutsCache;
        }
        
        $content = file_get_contents($layoutsFile);
        $config = json_decode($content, true);
        
        if (empty($config['layouts'])) {
            self::$layoutsCache = self::getDefaultLayouts();
            return self::$layoutsCache;
        }
        
        self::$layoutsCache = $config['layouts'];
        return self::$layoutsCache;
    }
    
    /**
     * 获取默认布局定义
     */
    private static function getDefaultLayouts(): array
    {
        return [
            self::TYPE_DEFAULT => [
                'name' => '默认布局',
                'name_en' => 'Default Layout',
                'description' => '标准页面布局',
                'regions' => [self::REGION_HEADER, self::REGION_CONTENT, self::REGION_FOOTER],
                'supports_components' => true,
                'default' => true,
            ],
            self::TYPE_BLOG => [
                'name' => '博客布局',
                'name_en' => 'Blog Layout',
                'description' => '博客专用布局',
                'regions' => [self::REGION_HEADER, self::REGION_SIDEBAR, self::REGION_CONTENT, self::REGION_FOOTER],
                'supports_components' => true,
                'content_type' => 'blog',
            ],
        ];
    }
    
    /**
     * 获取布局详细配置
     * 
     * @param string $layoutCode 布局代码
     * @return array|null
     */
    public static function getLayoutConfig(string $layoutCode): ?array
    {
        if (isset(self::$layoutConfigCache[$layoutCode])) {
            return self::$layoutConfigCache[$layoutCode];
        }
        
        $layoutDir = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/_layouts/' . $layoutCode . '/';
        $layoutFile = $layoutDir . 'layout.json';
        
        if (!file_exists($layoutFile)) {
            // 返回基本布局信息
            $layouts = self::getAvailableLayouts();
            self::$layoutConfigCache[$layoutCode] = $layouts[$layoutCode] ?? null;
            return self::$layoutConfigCache[$layoutCode];
        }
        
        $content = file_get_contents($layoutFile);
        $config = json_decode($content, true);
        
        self::$layoutConfigCache[$layoutCode] = $config;
        return $config;
    }
    
    /**
     * 获取布局的区域列表
     * 
     * @param string $layoutCode 布局代码
     * @return array 区域列表
     */
    public static function getLayoutRegions(string $layoutCode): array
    {
        $config = self::getLayoutConfig($layoutCode);
        
        if (isset($config['regions']) && is_array($config['regions'])) {
            // 详细配置格式
            if (isset($config['regions'][self::REGION_HEADER])) {
                return $config['regions'];
            }
            // 简单列表格式
            return array_combine($config['regions'], array_map(function($region) {
                return ['name' => ucfirst($region), 'required' => true];
            }, $config['regions']));
        }
        
        // 默认区域
        return [
            self::REGION_HEADER => ['name' => '头部', 'required' => true],
            self::REGION_CONTENT => ['name' => '内容', 'required' => true],
            self::REGION_FOOTER => ['name' => '底部', 'required' => true],
        ];
    }
    
    /**
     * 获取布局的设置项
     * 
     * @param string $layoutCode 布局代码
     * @return array 设置项定义
     */
    public static function getLayoutSettings(string $layoutCode): array
    {
        $config = self::getLayoutConfig($layoutCode);
        return $config['settings'] ?? [];
    }
    
    /**
     * 检查布局是否支持组件
     * 
     * @param string $layoutCode 布局代码
     * @return bool
     */
    public static function supportsComponents(string $layoutCode): bool
    {
        $layouts = self::getAvailableLayouts();
        return $layouts[$layoutCode]['supports_components'] ?? true;
    }
    
    /**
     * 获取布局的内容类型
     * 
     * @param string $layoutCode 布局代码
     * @return string|null 内容类型（如 blog、product）
     */
    public static function getContentType(string $layoutCode): ?string
    {
        $layouts = self::getAvailableLayouts();
        return $layouts[$layoutCode]['content_type'] ?? null;
    }
    
    /**
     * 获取布局对应的页面类型
     * 
     * @param string $layoutCode 布局代码
     * @return array 页面类型列表
     */
    public static function getPageTypes(string $layoutCode): array
    {
        $config = self::getLayoutConfig($layoutCode);
        
        if (isset($config['regions']['content']['content_types'])) {
            return array_keys($config['regions']['content']['content_types']);
        }
        
        // 默认页面类型
        return match($layoutCode) {
            self::TYPE_BLOG => ['blog_list', 'blog_post', 'blog_category', 'blog_tag'],
            self::TYPE_PRODUCT => ['product_detail', 'product_category'],
            default => ['page'],
        };
    }
    
    /**
     * 获取布局选择器数据（用于下拉选择）
     * 
     * @return array
     */
    public static function getLayoutSelectOptions(): array
    {
        $layouts = self::getAvailableLayouts();
        $options = [];
        
        foreach ($layouts as $code => $layout) {
            $options[$code] = [
                'value' => $code,
                'label' => $layout['name'] ?? ucfirst($code),
                'description' => $layout['description'] ?? '',
            ];
        }
        
        return $options;
    }
    
    /**
     * 验证布局配置
     * 
     * @param string $layoutCode 布局代码
     * @param array $regionComponents 区域组件配置
     * @return array 验证结果
     */
    public static function validateLayoutConfig(string $layoutCode, array $regionComponents): array
    {
        $errors = [];
        $regions = self::getLayoutRegions($layoutCode);
        
        foreach ($regions as $regionCode => $region) {
            $isRequired = $region['required'] ?? false;
            $hasComponent = !empty($regionComponents[$regionCode]);
            
            if ($isRequired && !$hasComponent) {
                $errors[] = sprintf('区域 "%s" 是必需的，请添加组件', $region['name'] ?? $regionCode);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取布局的数据源配置
     * 
     * @param string $layoutCode 布局代码
     * @return array 数据源配置
     */
    public static function getDataSources(string $layoutCode): array
    {
        $config = self::getLayoutConfig($layoutCode);
        return $config['data_sources'] ?? [];
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$layoutsCache = null;
        self::$layoutConfigCache = [];
    }
}
