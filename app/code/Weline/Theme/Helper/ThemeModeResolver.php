<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Frontend\Block\ThemeConfig;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题模式解析器
 * 负责解析当前应该使用的主题模式（包括预览模式的处理）
 */
class ThemeModeResolver
{
    /**
     * 获取当前主题模式
     * 
     * @param string $area 区域（frontend/backend）
     * @return string 主题模式（如 'default', 'dark', 'light' 等）
     */
    public static function getThemeMode(string $area = 'frontend'): string
    {
        $area = strtolower($area);
        
        // 获取Session实例
        $session = ObjectManager::getInstance(Session::class);
        $previewThemeId = $session->getData('preview_theme_id');
        
        // 检查是否是预览模式
        if ($previewThemeId) {
            // 预览模式：使用预览主题的颜色配置
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($previewThemeId);
            
            if ($theme->getId()) {
                // 从预览主题获取颜色配置
                $welineThemeColorMode = LayoutScanner::getColorConfig($theme, $area);
                return $welineThemeColorMode ?: 'default';
            }
        }
        
        // 正常模式：使用用户配置的主题颜色模式
        /** @var ThemeConfig $themeConfig */
        $themeConfig = ObjectManager::getInstance(ThemeConfig::class);
        $welineThemeColorMode = $themeConfig->getThemeModel();
        
        return $welineThemeColorMode ?: 'default';
    }
}

