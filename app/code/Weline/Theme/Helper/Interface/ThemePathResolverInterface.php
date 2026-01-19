<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper\Interface;

use Weline\Theme\Model\WelineTheme;

/**
 * 主题路径解析器接口
 * 
 * 职责：解析主题文件路径
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
interface ThemePathResolverInterface
{
    /**
     * 解析主题文件路径（支持多级继承链）
     * 
     * @param string $modulePath 模块文件路径
     * @param WelineTheme $theme 当前主题
     * @return string 解析后的文件路径
     */
    public function resolveThemeFile(string $modulePath, WelineTheme $theme): string;
    
    /**
     * 构建主题文件路径
     * 
     * @param string $modulePath 模块文件路径
     * @param string $themePath 主题路径
     * @return string 主题文件路径
     */
    public function buildThemePath(string $modulePath, string $themePath): string;
}
