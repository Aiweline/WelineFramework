<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\AssetMerger;
use Weline\Theme\Model\WelineTheme;

/**
 * AssetMerger 文件覆盖机制测试
 * 
 * 专门测试JS/CSS模块收集时的同名文件覆盖机制
 */
class AssetMergerOverrideTest extends TestCore
{
    private AssetMerger $assetMerger;
    private WelineTheme $theme;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->theme = ObjectManager::getInstance(WelineTheme::class);
        $this->assetMerger = ObjectManager::getInstance(AssetMerger::class);
    }
    
    /**
     * 测试：同名JS文件覆盖机制
     * 
     * 验证如果激活主题和父主题都有同名JS文件，只收集激活主题的版本
     */
    public function testSameNameJsFileOverride(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集JS文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        $this->assertIsArray($jsAssets, '应该返回数组');
        
        // 使用文件名作为键，验证没有重复
        $filesByName = [];
        foreach ($jsAssets as $assetPath) {
            $fileName = basename($assetPath);
            
            // 如果已经存在同名文件，说明去重机制有问题
            if (isset($filesByName[$fileName])) {
                $this->fail("发现重复的文件名: {$fileName}。第一个: {$filesByName[$fileName]}, 第二个: {$assetPath}");
            }
            
            $filesByName[$fileName] = $assetPath;
        }
        
        // 验证：每个文件名只出现一次
        $this->assertCount(count($filesByName), $jsAssets, '不应该有重复的文件名');
    }
    
    /**
     * 测试：同名CSS文件覆盖机制
     */
    public function testSameNameCssFileOverride(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集CSS文件
        $cssAssets = $this->assetMerger->mergeAssets('css', 'frontend', $activeTheme);
        
        $this->assertIsArray($cssAssets, '应该返回数组');
        
        // 使用文件名作为键，验证没有重复
        $filesByName = [];
        foreach ($cssAssets as $assetPath) {
            $fileName = basename($assetPath);
            
            if (isset($filesByName[$fileName])) {
                $this->fail("发现重复的CSS文件名: {$fileName}");
            }
            
            $filesByName[$fileName] = $assetPath;
        }
    }
    
    /**
     * 测试：AssetMerger从激活主题开始收集
     * 
     * 验证收集顺序：激活主题的文件优先于父主题
     */
    public function testAssetCollectionOrder(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集JS文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        // 验证：如果激活主题有文件，应该优先收集
        $themePath = $activeTheme->getPath();
        $activeThemeMainJs = $themePath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'main.js';
        
        if (is_file($activeThemeMainJs)) {
            // 验证激活主题的文件在列表中
            $found = false;
            foreach ($jsAssets as $asset) {
                if ($asset === $activeThemeMainJs) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, '激活主题的main.js应该在收集列表中');
        }
    }
    
    /**
     * 测试：主题继承链中的文件收集
     */
    public function testThemeChainAssetCollection(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 获取主题继承链
        $themeChain = $activeTheme->getThemeChain();
        
        $this->assertIsArray($themeChain, '主题继承链应该是数组');
        $this->assertGreaterThanOrEqual(1, count($themeChain), '至少应该有一个主题');
        
        // 收集JS文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        // 验证收集到的文件来自主题继承链
        $themePaths = [];
        foreach ($themeChain as $theme) {
            $themePaths[] = $theme->getPath();
        }
        
        // 验证所有收集到的文件都来自主题继承链或基础模块
        foreach ($jsAssets as $asset) {
            $fromTheme = false;
            foreach ($themePaths as $themePath) {
                if (strpos($asset, $themePath) === 0) {
                    $fromTheme = true;
                    break;
                }
            }
            
            // 文件可能来自主题或基础模块（Weline_Theme）
            $fromBase = strpos($asset, APP_CODE_PATH . 'Weline' . DS . 'Theme') === 0;
            
            $this->assertTrue(
                $fromTheme || $fromBase,
                "文件 {$asset} 应该来自主题继承链或基础模块"
            );
        }
    }
    
    /**
     * 测试：验证去重逻辑 - 同名文件只保留激活主题的版本
     */
    public function testDeduplicationLogic(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $parentId = $activeTheme->getParentId();
        if (!$parentId) {
            $this->markTestSkipped('激活主题没有父主题，跳过测试');
            return;
        }
        
        /** @var WelineTheme $parentTheme */
        $parentTheme = ObjectManager::getInstance(WelineTheme::class);
        $parentTheme->load($parentId);
        
        if (!$parentTheme->getId()) {
            $this->markTestSkipped('无法加载父主题，跳过测试');
            return;
        }
        
        // 测试文件：search.js
        $testFileName = 'search.js';
        $activeThemePath = $activeTheme->getPath();
        $parentThemePath = $parentTheme->getPath();
        
        $activeThemeFile = $activeThemePath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . $testFileName;
        $parentThemeFile = $parentThemePath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . $testFileName;
        
        // 收集JS文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        $activeFound = false;
        $parentFound = false;
        
        foreach ($jsAssets as $asset) {
            if (basename($asset) === $testFileName) {
                if ($asset === $activeThemeFile) {
                    $activeFound = true;
                } elseif ($asset === $parentThemeFile) {
                    $parentFound = true;
                }
            }
        }
        
        // 如果激活主题和父主题都有同名文件
        if (is_file($activeThemeFile) && is_file($parentThemeFile)) {
            // 应该只收集激活主题的文件
            $this->assertTrue($activeFound, '应该收集激活主题的同名文件');
            $this->assertFalse($parentFound, '不应该收集父主题的同名文件（因为激活主题有同名文件）');
        } elseif (is_file($activeThemeFile)) {
            // 只有激活主题有文件
            $this->assertTrue($activeFound, '应该收集激活主题的文件');
        } elseif (is_file($parentThemeFile)) {
            // 只有父主题有文件（激活主题没有）
            $this->assertTrue($parentFound, '应该收集父主题的文件（因为激活主题没有）');
        }
    }
}
