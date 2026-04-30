<?php

declare(strict_types=1);

/**
 * 布局配置格式标准化服务
 * 
 * 将各种格式的布局配置统一为标准格式：
 * - 统一使用 `code` 字段名（而非 `component`）
 * - 统一使用数组格式（header/footer/content 都是数组）
 * - 确保必要字段存在
 * 
 * 标准格式：
 * {
 *   "header": [{"code": "header-nav", "enabled": true, "config": {...}}],
 *   "content": [{"code": "content-hero", "enabled": true, "config": {...}}],
 *   "footer": [{"code": "footer-links", "enabled": true, "config": {...}}]
 * }
 * 
 * @author GuoLaiRen
 * @since 1.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Layout;

use Weline\Framework\Manager\ObjectManager;

class LayoutConfigNormalizer
{
    /**
     * 标准区域列表
     */
    private const STANDARD_REGIONS = ['header', 'content', 'footer'];
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * 将任何格式的布局配置转换为标准格式
     * 
     * @param array $config 原始配置
     * @return array 标准化后的配置
     */
    public function normalize(array $config): array
    {
        $normalized = [];
        
        foreach (self::STANDARD_REGIONS as $region) {
            $regionConfig = $config[$region] ?? [];
            $normalized[$region] = $this->normalizeRegion($regionConfig, $region);
        }
        
        return $normalized;
    }
    
    /**
     * 标准化单个区域的配置
     * 
     * 处理以下格式：
     * 1. 空值 -> []
     * 2. 对象格式 {component: "xxx", config: {...}} -> [{code: "xxx", enabled: true, config: {...}}]
     * 3. 数组格式 [{component: "xxx", ...}] -> [{code: "xxx", ...}]
     * 4. 数组格式 [{code: "xxx", ...}] -> [{code: "xxx", ...}]（已是标准格式）
     * 
     * @param mixed $regionConfig 区域配置
     * @param string $region 区域名称
     * @return array 标准化后的组件数组
     */
    public function normalizeRegion($regionConfig, string $region = ''): array
    {
        // 空值处理
        if (empty($regionConfig)) {
            return [];
        }
        
        // 对象格式（非数组或关联数组）
        if ($this->isAssociativeArray($regionConfig)) {
            return [$this->normalizeComponent($regionConfig, $region)];
        }
        
        // 数组格式
        if (is_array($regionConfig)) {
            $normalized = [];
            foreach ($regionConfig as $component) {
                if (is_array($component)) {
                    $normalized[] = $this->normalizeComponent($component, $region);
                }
            }
            return $normalized;
        }
        
        return [];
    }
    
    /**
     * 标准化单个组件配置
     * 
     * @param array $component 组件配置
     * @param string $region 所属区域
     * @return array 标准化后的组件配置
     */
    public function normalizeComponent(array $component, string $region = ''): array
    {
        // 获取组件代码（优先使用 code，回退到 component）
        $code = $component['code'] ?? $component['component'] ?? $component['component_code'] ?? '';
        
        // 标准化组件代码格式（移除模板前缀，如果有的话）
        $code = $this->normalizeComponentCode($code);
        
        return [
            'code' => $code,
            'enabled' => $component['enabled'] ?? true,
            'config' => $component['config'] ?? [],
            'instance_id' => $component['instance_id'] ?? $component['id'] ?? '',
            'style_code' => $component['style_code'] ?? $component['template_code'] ?? $component['from_template'] ?? '',
            'sort_order' => $component['sort_order'] ?? 0,
        ];
    }
    
    /**
     * 标准化组件代码
     * 
     * 处理以下格式：
     * - sattaking_header_nav -> header-nav
     * - tpmst_content_hero -> content-hero
     * - header-nav -> header-nav（已是标准格式）
     * 
     * @param string $code 原始组件代码
     * @return string 标准化后的组件代码
     */
    public function normalizeComponentCode(string $code): string
    {
        if (empty($code)) {
            return '';
        }
        
        // 如果已经是标准格式（包含破折号），直接返回
        if (strpos($code, '-') !== false && strpos($code, '_') === false) {
            return $code;
        }
        
        // 处理下划线格式：{styleCode}_{category}_{name}
        if (preg_match('/^[a-z0-9]+_([a-z]+)_(.+)$/i', $code, $matches)) {
            // 将下划线转换为破折号
            $category = strtolower($matches[1]);
            $name = str_replace('_', '-', strtolower($matches[2]));
            return "{$category}-{$name}";
        }
        
        // 如果只有下划线，转换为破折号
        if (strpos($code, '_') !== false) {
            return str_replace('_', '-', strtolower($code));
        }
        
        return strtolower($code);
    }
    
    /**
     * 从标准化配置转换为数据库存储格式
     * 
     * @param array $normalizedConfig 标准化配置
     * @return array 数据库存储格式
     */
    public function toStorageFormat(array $normalizedConfig): array
    {
        $storage = [];
        
        foreach (self::STANDARD_REGIONS as $region) {
            $components = $normalizedConfig[$region] ?? [];
            
            if ($region === 'header' || $region === 'footer') {
                // header/footer 存储为单个组件
                if (!empty($components)) {
                    $first = $components[0] ?? null;
                    if ($first) {
                        $storage[$region] = [
                            'component' => $first['code'],
                            'config' => $first['config'] ?? [],
                        ];
                    } else {
                        $storage[$region] = null;
                    }
                } else {
                    $storage[$region] = null;
                }
            } else {
                // content 存储为数组
                $storage[$region] = array_map(function ($comp) {
                    return [
                        'component' => $comp['code'],
                        'enabled' => $comp['enabled'] ?? true,
                        'config' => $comp['config'] ?? [],
                        'instance_id' => $comp['instance_id'] ?? '',
                        'style_code' => $comp['style_code'] ?? '',
                        'sort_order' => $comp['sort_order'] ?? 0,
                    ];
                }, $components);
            }
        }
        
        return $storage;
    }
    
    /**
     * 从 PageLayout 的 exportConfig 格式转换为标准格式
     * 
     * exportConfig 格式：
     * {
     *   "header": {"component": "xxx", "config": {...}},
     *   "content": [{"code": "xxx", ...}],
     *   "footer": {"component": "xxx", "config": {...}}
     * }
     * 
     * @param array $exportedConfig exportConfig 格式
     * @return array 标准格式
     */
    public function fromExportFormat(array $exportedConfig): array
    {
        return $this->normalize($exportedConfig);
    }
    
    /**
     * 从布局文件配置格式转换为标准格式
     * 
     * 布局文件格式（layouts/default/*.json）：
     * {
     *   "layout_config": {
     *     "header": [{"code": "header-nav", ...}],
     *     "content": [...],
     *     "footer": [...]
     *   }
     * }
     * 
     * @param array $fileConfig 文件配置
     * @return array 标准格式
     */
    public function fromFileFormat(array $fileConfig): array
    {
        $layoutConfig = $fileConfig['layout_config'] ?? $fileConfig;
        return $this->normalize($layoutConfig);
    }
    
    /**
     * 验证配置是否为标准格式
     * 
     * @param array $config 配置
     * @return bool
     */
    public function isNormalized(array $config): bool
    {
        foreach (self::STANDARD_REGIONS as $region) {
            if (!isset($config[$region])) {
                return false;
            }
            
            if (!is_array($config[$region])) {
                return false;
            }
            
            foreach ($config[$region] as $component) {
                // 必须有 code 字段，不能是 component
                if (!isset($component['code']) || isset($component['component'])) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 合并两个布局配置
     * 
     * @param array $base 基础配置
     * @param array $override 覆盖配置
     * @param bool $replaceRegions 是否整体替换区域（true）还是合并组件（false）
     * @return array 合并后的配置
     */
    public function merge(array $base, array $override, bool $replaceRegions = true): array
    {
        $base = $this->normalize($base);
        $override = $this->normalize($override);
        
        $merged = $base;
        
        foreach (self::STANDARD_REGIONS as $region) {
            if (!empty($override[$region])) {
                if ($replaceRegions) {
                    // 整体替换区域
                    $merged[$region] = $override[$region];
                } else {
                    // 合并组件（去重）
                    $merged[$region] = $this->mergeComponents(
                        $base[$region] ?? [],
                        $override[$region]
                    );
                }
            }
        }
        
        return $merged;
    }
    
    /**
     * 合并组件数组
     * 
     * @param array $base 基础组件数组
     * @param array $override 覆盖组件数组
     * @return array 合并后的组件数组
     */
    private function mergeComponents(array $base, array $override): array
    {
        $merged = [];
        $codes = [];
        
        // 先添加 override 中的组件
        foreach ($override as $component) {
            $code = $component['code'] ?? '';
            if ($code) {
                $merged[] = $component;
                $codes[$code] = true;
            }
        }
        
        // 再添加 base 中不存在于 override 的组件
        foreach ($base as $component) {
            $code = $component['code'] ?? '';
            if ($code && !isset($codes[$code])) {
                $merged[] = $component;
            }
        }
        
        return $merged;
    }
    
    /**
     * 判断是否为关联数组（对象格式）
     * 
     * @param mixed $arr
     * @return bool
     */
    private function isAssociativeArray($arr): bool
    {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }
        
        // 检查是否有 component 或 code 键（表示是单个组件对象）
        if (isset($arr['component']) || isset($arr['code'])) {
            return true;
        }
        
        // 检查键是否为字符串（关联数组）
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    
    /**
     * 获取配置中指定区域的第一个组件代码
     * 
     * @param array $config 标准化配置
     * @param string $region 区域名称
     * @return string|null
     */
    public function getFirstComponentCode(array $config, string $region): ?string
    {
        $components = $config[$region] ?? [];
        if (!empty($components) && isset($components[0]['code'])) {
            return $components[0]['code'];
        }
        return null;
    }
    
    /**
     * 检查配置中是否有有效组件
     * 
     * @param array $config 配置（可以是任何格式）
     * @return bool
     */
    public function hasValidComponents(array $config): bool
    {
        $normalized = $this->normalize($config);
        
        foreach (self::STANDARD_REGIONS as $region) {
            if (!empty($normalized[$region])) {
                foreach ($normalized[$region] as $component) {
                    if (!empty($component['code']) && ($component['enabled'] ?? true)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 获取实例（单例模式）
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
