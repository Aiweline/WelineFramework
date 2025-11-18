<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题资源合并助手类
 * 
 * 支持CSS/JS资源的追加合并，按继承链顺序收集资源
 */
class AssetMerger
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;

    public function __construct(WelineTheme $welineTheme)
    {
        $this->welineTheme = $welineTheme;
    }

    /**
     * 合并主题资源（支持继承链）
     * 
     * @param string $assetType 资源类型（css/js）
     * @param string $area 区域（frontend/backend）
     * @param WelineTheme|null $theme 主题对象，如果为null则使用激活的主题
     * @return array 资源文件路径数组，按加载顺序排列
     */
    public function mergeAssets(string $assetType, string $area, ?WelineTheme $theme = null): array
    {
        if ($theme === null) {
            $theme = $this->welineTheme->getActiveTheme();
        }

        $assets = [];

        // 1. 基础资源（Weline_Theme模块）
        $baseAssetsPath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'assets' . DS . $assetType;
        if (is_dir($baseAssetsPath)) {
            $baseAssets = $this->scanAssetDirectory($baseAssetsPath);
            $assets = array_merge($assets, $baseAssets);
        }

        // 2. 获取主题继承链（从基础到当前）
        $themeChain = $this->getThemeChain($theme);

        // 3. 按继承链顺序收集资源（父主题在前，子主题在后）
        foreach ($themeChain as $chainTheme) {
            $themeAssetsPath = $chainTheme->getPath() . 'Weline_Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'assets' . DS . $assetType;
            if (is_dir($themeAssetsPath)) {
                $themeAssets = $this->scanAssetDirectory($themeAssetsPath);
                // 追加到数组末尾（子主题资源覆盖父主题同名资源）
                $assets = array_merge($assets, $themeAssets);
            }
        }

        // 4. 去重（保留最后一个出现的文件）
        $assets = $this->deduplicateAssets($assets);

        return $assets;
    }

    /**
     * 获取主题继承链（从基础到当前）
     * 
     * @param WelineTheme $theme 当前主题
     * @return WelineTheme[] 主题继承链数组
     */
    private function getThemeChain(WelineTheme $theme): array
    {
        $chain = [];
        $visited = [];
        $currentTheme = $theme;

        // 递归收集父主题
        while ($currentTheme && $currentTheme->getId()) {
            $themeId = $currentTheme->getId();
            
            // 防止循环引用
            if (in_array($themeId, $visited)) {
                break;
            }
            $visited[] = $themeId;

            // 将父主题添加到链的前面（保证顺序：基础 → 父 → 子）
            array_unshift($chain, $currentTheme);

            // 获取父主题
            $parentId = $currentTheme->getParentId();
            if ($parentId) {
                try {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        $currentTheme = $parentTheme;
                    } else {
                        break;
                    }
                } catch (\Exception $e) {
                    break;
                }
            } else {
                break;
            }
        }

        return $chain;
    }

    /**
     * 扫描资源目录，返回所有资源文件路径
     * 
     * @param string $directory 目录路径
     * @return array 文件路径数组
     */
    private function scanAssetDirectory(string $directory): array
    {
        $assets = [];
        
        if (!is_dir($directory)) {
            return $assets;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $assets[] = $file->getPathname();
            }
        }

        // 按文件名排序，确保加载顺序一致
        sort($assets);

        return $assets;
    }

    /**
     * 去重资源文件（保留最后一个出现的文件）
     * 
     * 如果多个主题中有同名文件，只保留最后一个（子主题的）
     * 
     * @param array $assets 资源文件路径数组
     * @return array 去重后的资源文件路径数组
     */
    private function deduplicateAssets(array $assets): array
    {
        $uniqueAssets = [];
        $seenFiles = [];

        // 从后往前遍历，保留最后一个出现的文件
        foreach (array_reverse($assets) as $assetPath) {
            $fileName = basename($assetPath);
            
            // 如果还没见过这个文件名，添加到结果中
            if (!isset($seenFiles[$fileName])) {
                $uniqueAssets[] = $assetPath;
                $seenFiles[$fileName] = true;
            }
        }

        // 反转回来，保持正确的加载顺序
        return array_reverse($uniqueAssets);
    }
}

