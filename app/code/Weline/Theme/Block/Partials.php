<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;
use Weline\Theme\Helper\PartialsScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * Partials Block
 * 用于在模板中加载配置的 partials
 */
class Partials extends Block
{
    /**
     * 获取 partials 模板路径
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $defaultOption 默认选项（如果配置中没有指定）
     * @return string|null
     */
    public function getPartialsPath(string $area, string $type, string $defaultOption = 'default'): ?string
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->getActiveTheme();
        
        if (!$theme->getId()) {
            return null;
        }
        
        // 获取配置的选项
        $config = PartialsScanner::getPartialsConfig($theme, $area);
        $option = $config[$type] ?? $defaultOption;
        
        return PartialsScanner::getPartialsPath($theme, $area, $type, $option);
    }
    
    /**
     * 渲染 partials
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param array $data 传递给模板的数据
     * @param string $defaultOption 默认选项
     * @return string
     */
    public function renderPartials(string $area, string $type, array $data = [], string $defaultOption = 'default'): string
    {
        $path = $this->getPartialsPath($area, $type, $defaultOption);
        
        if (!$path) {
            return '';
        }
        
        // 设置数据
        foreach ($data as $key => $value) {
            $this->assign($key, $value);
        }
        
        return $this->fetch($path);
    }
}

