<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Service;

/**
 * 部件数据服务（运行时专用）
 * 
 * 运行时只从 generated/widgets.php 读取数据，不执行扫描
 * 部件收集由 WidgetScanner 和 widget:refresh 命令负责
 */
class WidgetData
{
    private WidgetRegistry $registry;
    
    /**
     * 允许的部件类型列表（与 WidgetScanner 保持一致）
     */
    private const ALLOWED_TYPES = [
        'header', 'footer', 'sidebar', 'content', 'banner',
        'carousel', 'card', 'form', 'list', 'grid', 'navigation',
        'breadcrumb', 'pagination', 'modal', 'tabs', 'accordion',
        'slider', 'gallery', 'testimonial', 'pricing', 'team',
        'blog', 'product', 'category', 'search', 'filter', 'map',
        'video', 'audio', 'social', 'newsletter', 'faq', 'timeline',
        'stats', 'counter', 'progress', 'chart', 'table', 'calendar',
        'chat', 'comment'
    ];
    
    /**
     * 类型验证缓存（提升性能）
     */
    private static array $typeValidationCache = [];
    
    /**
     * 类型部件缓存（提升性能）
     */
    private static array $typeWidgetsCache = [];

    public function __construct(WidgetRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 获取所有部件（从注册表读取）
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getAllWidgets(bool $forceReload = false): array
    {
        return $this->registry->getRegistry($forceReload);
    }

    /**
     * 获取指定部件（从注册表读取）
     *
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @return array|null
     */
    public function getWidget(string $type, string $name): ?array
    {
        // 直接获取指定类型的部件，避免加载全部数据
        $widgetsByType = $this->getWidgetsByType($type);
        $widget = $widgetsByType[$name] ?? null;
        
        // 如果部件被禁用，返回 null
        if ($widget && !empty($widget['disabled'])) {
            return null;
        }
        
        return $widget;
    }

    /**
     * 验证部件类型是否有效
     *
     * @param string $type 部件类型
     * @return bool
     */
    public function isValidType(string $type): bool
    {
        // 使用缓存提升性能
        if (isset(self::$typeValidationCache[$type])) {
            return self::$typeValidationCache[$type];
        }
        
        $result = in_array($type, self::ALLOWED_TYPES, true);
        self::$typeValidationCache[$type] = $result;
        
        return $result;
    }

    /**
     * 获取允许的部件类型列表
     *
     * @return array
     */
    public function getAllowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /**
     * 按类型获取部件
     *
     * @param string $type 部件类型
     * @return array
     */
    public function getWidgetsByType(string $type): array
    {
        // 使用静态缓存，跨实例共享，避免每次都读取全部数据
        if (isset(self::$typeWidgetsCache[$type])) {
            return self::$typeWidgetsCache[$type];
        }
        
        $allWidgets = $this->getAllWidgets();
        $widgets = $allWidgets[$type] ?? [];
        
        // 过滤掉禁用的部件（disabled 为 true 的部件不返回）
        $result = [];
        foreach ($widgets as $name => $widget) {
            // 如果 disabled 字段为 true，则跳过该部件
            if (!empty($widget['disabled'])) {
                continue;
            }
            $result[$name] = $widget;
        }
        
        // 缓存结果（仅在 Web 运行时缓存，CLI 模式下不缓存）
        if (PHP_SAPI !== 'cli') {
            self::$typeWidgetsCache[$type] = $result;
        }

        return $result;
    }
    
    /**
     * 清除缓存（用于刷新后清理）
     */
    public static function clearCache(): void
    {
        self::$typeValidationCache = [];
        self::$typeWidgetsCache = [];
    }
}
