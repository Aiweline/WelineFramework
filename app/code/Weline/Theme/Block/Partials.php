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
use Weline\Theme\Helper\LayoutScanner;
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
        
        // 检查是否有预览主题
        $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $previewThemeId = $session->getData('preview_theme_id');
        if ($previewThemeId) {
            $theme->load($previewThemeId);
        } else {
            $theme->getActiveTheme();
        }
        
        if (!$theme->getId()) {
            return null;
        }
        
        // 获取配置的选项（支持预览配置）
        $config = LayoutScanner::getPartialsConfig($theme, $area);
        $option = $config[$type] ?? $defaultOption;
        
        // 构建部件文件路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }
        
        // 构建模块路径格式：Module_Name::theme/{area}/partials/{type}/{option}.phtml
        // 首先尝试当前主题
        $themeModuleName = $theme->getModuleName();
        $partialsPath = $themeModuleName . '::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
        
        // 检查文件是否存在（通过尝试获取绝对路径）
        $absolutePath = $this->resolveModulePath($partialsPath);
        if ($absolutePath && is_file($absolutePath)) {
            return $partialsPath; // 返回模块路径格式
        }
        
        // 如果当前主题没有，尝试父主题
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentModuleName = $parentTheme->getModuleName();
            $parentPartialsPath = $parentModuleName . '::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
            $parentAbsolutePath = $this->resolveModulePath($parentPartialsPath);
            if ($parentAbsolutePath && is_file($parentAbsolutePath)) {
                return $parentPartialsPath;
            }
        }
        
        // 如果还是没有，尝试默认主题（Weline_Theme）
        $defaultPartialsPath = 'Weline_Theme::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
        $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
        if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
            return $defaultPartialsPath;
        }
        
        return null;
    }
    
    /**
     * 解析模块路径为绝对路径（用于检查文件是否存在）
     * @param string $modulePath 模块路径格式（如 Weline_Theme::theme/frontend/partials/header/default.phtml）
     * @return string|null 绝对路径，如果无法解析则返回null
     */
    private function resolveModulePath(string $modulePath): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        list($moduleName, $relativePath) = explode('::', $modulePath, 2);
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName])) {
            return null;
        }
        
        $module = $modules[$moduleName];
        $basePath = rtrim($module['base_path'], DS);
        $relativePath = str_replace('/', DS, $relativePath);
        
        return $basePath . DS . 'view' . DS . $relativePath;
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

