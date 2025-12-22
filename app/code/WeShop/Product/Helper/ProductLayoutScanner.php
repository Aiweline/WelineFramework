<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局扫描器 - 扫描产品模块的专属布局文件
 */

namespace WeShop\Product\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;

class ProductLayoutScanner
{
    /**
     * 扫描产品模块的专属布局文件
     * 
     * @param string $layoutType 布局类型
     * @param WelineTheme|null $theme 主题对象（可选，用于主题继承）
     * @return array 布局选项数组
     */
    public static function scanProductLayouts(string $layoutType, ?WelineTheme $theme = null): array
    {
        $layouts = [];
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules['WeShop_Product'])) {
            return $layouts;
        }

        $productModule = $modules['WeShop_Product'];
        $basePath = rtrim($productModule['base_path'], DS);
        
        // 扫描路径：app/code/WeShop/Product/view/theme/frontend/layouts/{layoutType}/
        $layoutsDir = $basePath . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'layouts' . DS . $layoutType;
        
        if (!is_dir($layoutsDir)) {
            return $layouts;
        }

        // 扫描布局文件
        $files = glob($layoutsDir . DS . '*.phtml');
        
        foreach ($files as $file) {
            $fileName = basename($file, '.phtml');
            $layoutPath = 'WeShop_Product::theme/frontend/layouts/' . $layoutType . '/' . $fileName;
            
            // 解析布局文件的元数据
            $meta = self::parseLayoutMeta($file);
            
            $layouts[$fileName] = [
                'name' => $meta['name'] ?? ucfirst($fileName),
                'description' => $meta['description'] ?? '',
                'template' => $layoutPath,
                'preview_image' => $meta['preview_image'] ?? '',
                'config' => $meta['config'] ?? []
            ];
        }

        // 如果提供了主题，尝试从主题继承中查找布局
        if ($theme) {
            $themeLayouts = self::scanThemeLayouts($layoutType, $theme);
            $layouts = array_merge($layouts, $themeLayouts);
        }

        return $layouts;
    }

    /**
     * 从主题继承中扫描布局
     */
    private static function scanThemeLayouts(string $layoutType, WelineTheme $theme): array
    {
        $layouts = [];
        $themePath = $theme->getPath();
        
        if (empty($themePath) || !is_dir($themePath)) {
            return $layouts;
        }

        // 主题布局路径：{theme_path}/view/theme/frontend/layouts/{layoutType}/
        $layoutsDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'layouts' . DS . $layoutType;
        
        if (!is_dir($layoutsDir)) {
            // 尝试父主题
            $parentTheme = $theme->getParentTheme();
            if ($parentTheme) {
                return self::scanThemeLayouts($layoutType, $parentTheme);
            }
            return $layouts;
        }

        $files = glob($layoutsDir . DS . '*.phtml');
        
        foreach ($files as $file) {
            $fileName = basename($file, '.phtml');
            // 主题布局使用主题路径格式
            $layoutPath = $theme->getCode() . '::theme/frontend/layouts/' . $layoutType . '/' . $fileName;
            
            $meta = self::parseLayoutMeta($file);
            
            $layouts[$fileName] = [
                'name' => $meta['name'] ?? ucfirst($fileName),
                'description' => $meta['description'] ?? '',
                'template' => $layoutPath,
                'preview_image' => $meta['preview_image'] ?? '',
                'config' => $meta['config'] ?? []
            ];
        }

        return $layouts;
    }

    /**
     * 解析布局文件的元数据
     */
    private static function parseLayoutMeta(string $filePath): array
    {
        $meta = [];
        
        if (!is_file($filePath)) {
            return $meta;
        }

        $content = file_get_contents($filePath);
        
        // 解析 @meta.name
        if (preg_match('/@meta\.name\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $matches)) {
            $meta['name'] = $matches[1];
        }
        
        // 解析 @meta.description
        if (preg_match('/@meta\.description\s*\{[^}]*description\s*=\s*"([^"]+)"/', $content, $matches)) {
            $meta['description'] = $matches[1];
        }
        
        // 解析 @preview_image
        if (preg_match('/@preview_image\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $matches)) {
            $meta['preview_image'] = $matches[1];
        }

        return $meta;
    }

    /**
     * 合并产品布局和默认布局选项
     */
    public static function mergeLayoutOptions(array $productLayouts, array $defaultLayouts): array
    {
        // 产品布局优先，如果冲突则覆盖默认布局
        return array_merge($defaultLayouts, $productLayouts);
    }
}

