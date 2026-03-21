<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Theme\Helper\Interface\AssetMergerInterface;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Helper\Interface\AssetScannerInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题资源合并助手类
 * 
 * 职责：合并主题资源，实现同名文件以激活主题为准的机制
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
class AssetMerger implements AssetMergerInterface
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;
    
    /**
     * @var ThemeChainResolverInterface
     */
    private ThemeChainResolverInterface $themeChainResolver;
    
    /**
     * @var AssetScannerInterface
     */
    private AssetScannerInterface $assetScanner;

    /**
     * 依赖注入：遵循依赖倒置原则 (DIP)
     * 
     * @param WelineTheme $welineTheme
     * @param ThemeChainResolverInterface $themeChainResolver
     * @param AssetScannerInterface $assetScanner
     */
    public function __construct(
        WelineTheme $welineTheme,
        ThemeChainResolverInterface $themeChainResolver,
        AssetScannerInterface $assetScanner
    ) {
        $this->welineTheme = $welineTheme;
        $this->themeChainResolver = $themeChainResolver;
        $this->assetScanner = $assetScanner;
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
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';

        if ($theme === null) {
            $theme = $this->welineTheme->getActiveTheme($area);
        }

        // 1. 获取主题继承链（从基础到当前：父主题在前，激活主题在后）
        $themeChain = $this->themeChainResolver->getThemeChain($theme);

        // 2. 按继承链顺序收集资源，实现同名文件以激活主题为准的机制
        // 使用文件名作为键，确保同名文件只保留激活主题的版本
        $assetsByFileName = [];
        
        // 从激活主题开始收集（主题链中最后一个是最新的激活主题）
        // 这样如果激活主题有同名文件，会覆盖父主题的同名文件
        foreach (array_reverse($themeChain) as $chainTheme) {
            $themeAssetsPath = $chainTheme->getPath() . 'view' . DS . 'theme' . DS . $area . DS . 'assets' . DS . $assetType;
            if (is_dir($themeAssetsPath)) {
                $themeAssets = $this->assetScanner->scanDirectory($themeAssetsPath);
                // 遍历当前主题的资源文件
                foreach ($themeAssets as $assetPath) {
                    $fileName = basename($assetPath);
                    // 如果激活主题存在同名文件，直接使用，跳过父主题的同名文件
                    // 这里从激活主题开始遍历，所以先遍历到的（激活主题的）文件会保留
                    if (!isset($assetsByFileName[$fileName])) {
                        $assetsByFileName[$fileName] = $assetPath;
                    }
                }
            }
        }
        
        // 3. 将收集到的主题资源转换为数组
        $assets = array_values($assetsByFileName);
        
        // 4. 添加基础资源（Weline_Theme模块）中未被覆盖的文件
        $baseAssetsPath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'assets' . DS . $assetType;
        if (is_dir($baseAssetsPath)) {
            $baseAssets = $this->assetScanner->scanDirectory($baseAssetsPath);
            foreach ($baseAssets as $baseAsset) {
                $fileName = basename($baseAsset);
                // 如果主题资源中没有同名文件，添加基础资源
                if (!isset($assetsByFileName[$fileName])) {
                    $assets[] = $baseAsset;
                }
            }
        }

        return $assets;
    }
}

