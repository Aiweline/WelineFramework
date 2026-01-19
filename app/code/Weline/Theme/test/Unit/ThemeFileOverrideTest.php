<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\AssetMerger;
use Weline\Theme\Helper\Interface\ThemePathResolverInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\TemplateFetchFile;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

/**
 * 主题文件覆盖机制测试
 * 
 * 验证同名文件以激活主题为准的规则
 */
class ThemeFileOverrideTest extends TestCore
{
    private WelineTheme $theme;
    private AssetMerger $assetMerger;
    private TemplateFetchFile $templateFetchFile;
    private ThemePathResolverInterface $themePathResolver;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->theme = ObjectManager::getInstance(WelineTheme::class);
        $this->assetMerger = ObjectManager::getInstance(AssetMerger::class);
        $this->templateFetchFile = ObjectManager::getInstance(TemplateFetchFile::class);
        $this->themePathResolver = ObjectManager::getInstance(ThemePathResolverInterface::class);
    }
    
    /**
     * 测试：模板文件覆盖机制 - 激活主题的同名文件优先
     */
    public function testTemplateFileOverrideMechanism(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 测试文件路径（使用一个实际存在的主题文件）
        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';
        
        if (!is_file($modulePath)) {
            $this->markTestSkipped('测试文件不存在: ' . $modulePath);
            return;
        }
        
        // 使用主题路径解析器解析文件路径
        $resolvedPath = $this->themePathResolver->resolveThemeFile($modulePath, $activeTheme);
        
        // 验证返回的路径是文件
        $this->assertTrue(is_file($resolvedPath), '应该返回一个存在的文件路径: ' . $resolvedPath);
        
        // 验证：如果激活主题存在同名文件，应该返回激活主题的文件路径
        $themePath = $activeTheme->getPath();
        $expectedThemePath = $themePath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';
        
        if (is_file($expectedThemePath)) {
            // 如果激活主题存在同名文件，应该返回激活主题的文件
            $this->assertEquals($expectedThemePath, $resolvedPath, '激活主题的同名文件应该优先');
        } else {
            // 如果激活主题不存在，应该返回父主题或默认主题的文件
            // 验证返回的路径确实存在
            $this->assertTrue(is_file($resolvedPath), '应该返回一个存在的文件路径');
        }
    }
    
    /**
     * 测试：JS模块文件覆盖机制 - 同名文件以激活主题为准
     */
    public function testJsModuleFileOverrideMechanism(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集JS模块文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        $this->assertIsArray($jsAssets, '应该返回数组');
        
        // 检查是否有同名文件（使用文件名作为键）
        $filesByName = [];
        foreach ($jsAssets as $assetPath) {
            $fileName = basename($assetPath);
            if (isset($filesByName[$fileName])) {
                // 如果发现同名文件，验证激活主题的文件优先
                $this->fail("发现同名文件 {$fileName}，应该只保留激活主题的版本");
            }
            $filesByName[$fileName] = $assetPath;
        }
        
        // 验证：如果激活主题有search.js，应该只收集激活主题的版本
        $themePath = $activeTheme->getPath();
        $activeThemeSearchJs = $themePath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'search.js';
        
        if (is_file($activeThemeSearchJs)) {
            // 验证激活主题的search.js在收集列表中
            $found = false;
            foreach ($jsAssets as $asset) {
                if (basename($asset) === 'search.js' && $asset === $activeThemeSearchJs) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, '激活主题的search.js应该在收集列表中');
            
            // 验证没有父主题的同名文件
            $parentId = $activeTheme->getParentId();
            if ($parentId) {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($parentId);
                
                if ($parentTheme->getId()) {
                    $parentPath = $parentTheme->getPath();
                    $parentSearchJs = $parentPath . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'search.js';
                    
                    if (is_file($parentSearchJs)) {
                        // 如果父主题也有同名文件，验证它不在收集列表中
                        $parentFound = false;
                        foreach ($jsAssets as $asset) {
                            if ($asset === $parentSearchJs) {
                                $parentFound = true;
                                break;
                            }
                        }
                        $this->assertFalse($parentFound, '父主题的同名文件不应该在收集列表中');
                    }
                }
            }
        } else {
            // 如果激活主题没有search.js，测试仍然通过（这是正常情况）
            $this->assertTrue(true, '激活主题没有search.js文件，这是正常情况');
        }
    }
    
    /**
     * 测试：CSS文件覆盖机制 - 同名文件以激活主题为准
     */
    public function testCssFileOverrideMechanism(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集CSS文件
        $cssAssets = $this->assetMerger->mergeAssets('css', 'frontend', $activeTheme);
        
        $this->assertIsArray($cssAssets, '应该返回数组');
        
        // 检查是否有同名文件
        $filesByName = [];
        foreach ($cssAssets as $assetPath) {
            $fileName = basename($assetPath);
            if (isset($filesByName[$fileName])) {
                $this->fail("发现同名CSS文件 {$fileName}，应该只保留激活主题的版本");
            }
            $filesByName[$fileName] = $assetPath;
        }
    }
    
    /**
     * 测试：主题继承链中的文件查找顺序
     */
    public function testThemeChainFileLookupOrder(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 获取主题继承链
        $themeChain = $activeTheme->getThemeChain();
        
        $this->assertIsArray($themeChain, '主题继承链应该是数组');
        $this->assertGreaterThanOrEqual(1, count($themeChain), '主题继承链至少应该包含激活主题');
        
        // 验证继承链的顺序：最后一个应该是激活主题
        $lastTheme = end($themeChain);
        $this->assertEquals($activeTheme->getId(), $lastTheme->getId(), '继承链的最后一个应该是激活主题');
    }
    
    /**
     * 测试：同名文件覆盖规则 - 激活主题优先于父主题
     */
    public function testSameNameFileOverrideRule(): void
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
            $this->assertFalse($parentFound, '不应该收集父主题的同名文件');
        } elseif (is_file($activeThemeFile)) {
            // 只有激活主题有文件
            $this->assertTrue($activeFound, '应该收集激活主题的文件');
        } elseif (is_file($parentThemeFile)) {
            // 只有父主题有文件
            $this->assertTrue($parentFound, '应该收集父主题的文件（因为激活主题没有）');
        }
    }
    
    /**
     * 测试：AssetMerger的去重机制
     */
    public function testAssetMergerDeduplication(): void
    {
        $activeTheme = $this->theme->getActiveTheme();
        
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 收集JS文件
        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        
        // 验证没有重复的文件名
        $fileNames = [];
        foreach ($jsAssets as $asset) {
            $fileName = basename($asset);
            $this->assertNotContains($fileName, $fileNames, "文件名 {$fileName} 不应该重复");
            $fileNames[] = $fileName;
        }
    }
}
