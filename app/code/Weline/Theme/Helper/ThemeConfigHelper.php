<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

/**
 * 主题配置辅助类
 * 用于处理主题相关的配置获取，统一使用ThemeData获取配置值
 */
class ThemeConfigHelper
{
    /**
     * 获取主题配置值
     * 
     * @param string $layout 布局标识（如 partials.header）
     * @param string|null $area 区域（frontend/backend），如果为 null 则自动判断
     * @param string|null $locale 语言代码，如果为 null 则使用当前语言
     * @param string $field 字段名（如 value, file_path），默认 'value'
     * @return string|null 配置值，如果不存在返回 null
     */
    public static function getConfigValue(string $layout, ?string $area = null, ?string $locale = null, string $field = 'value'): ?string
    {
        // 构建identify：theme.{area}.{layout}.{field}
        $identify = $layout;
        if ($field !== 'value') {
            $identify .= '.' . $field;
        } else {
            $identify .= '.value';
        }
        
        // 如果指定了area，设置当前区域
        if ($area !== null) {
            ThemeData::setCurrentArea($area);
        }
        
        // 统一使用ThemeData获取配置值
        return ThemeData::get($identify);
    }

    /**
     * 获取主题配置的模板路径
     * 
     * @param string $layout 布局标识（如 partials.header）
     * @param string|null $area 区域（frontend/backend），如果为 null 则自动判断
     * @param string|null $locale 语言代码，如果为 null 则使用当前语言
     * @param string $defaultValue 默认值，如果配置不存在时使用，默认 'default'
     * @return string 模板路径（如 Weline_Theme::theme/frontend/partials/header/minimal.phtml）
     */
    public static function getTemplatePath(string $layout, ?string $area = null, ?string $locale = null, string $defaultValue = 'default'): string
    {
        // 获取配置值
        $configValue = self::getConfigValue($layout, $area, $locale);
        
        // 如果配置不存在，使用默认值
        if ($configValue === null) {
            $configValue = $defaultValue;
        }
        
        // 获取当前区域（如果未指定，使用ThemeData自动识别的区域）
        if ($area === null) {
            $area = ThemeData::getCurrentArea() ?? 'frontend';
        }

        // 配置键使用点号命名（如 partials.header），模板路径需要目录分隔。
        $layoutPath = trim(str_replace(['\\', '.'], '/', $layout), '/');

        // 构建模板路径：Weline_Theme::theme/{area}/{layoutPath}/{configValue}.phtml
        return "Weline_Theme::theme/{$area}/{$layoutPath}/{$configValue}.phtml";
    }
}

