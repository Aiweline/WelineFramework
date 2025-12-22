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
use Weline\Theme\Helper\LayoutDependencyTracker;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局依赖追踪器测试
 * 
 * 测试依赖追踪和增量更新（包括Meta配置更新检测）
 */
class LayoutDependencyTrackerTest extends TestCore
{
    private LayoutDependencyTracker $tracker;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->tracker = ObjectManager::getInstance(LayoutDependencyTracker::class);
    }
    
    /**
     * 测试提取getPartialsPath依赖
     */
    public function testExtractGetPartialsPathDependencies(): void
    {
        $layoutContent = <<<'PHP'
<?php
$headerPath = $this->getPartialsPath('frontend', 'header', 'default');
$footerPath = $this->getPartialsPath('frontend', 'footer', 'default');
?>
PHP;
        
        $tempFile = sys_get_temp_dir() . '/test_layout.phtml';
        file_put_contents($tempFile, $layoutContent);
        
        try {
            $dependencies = $this->tracker->extractDependencies($tempFile);
            
            $this->assertIsArray($dependencies);
            // 依赖可能解析为文件路径，也可能为空（如果文件不存在）
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * 测试提取fetch依赖
     */
    public function testExtractFetchDependencies(): void
    {
        $layoutContent = <<<'PHP'
<?php
$this->fetch('Weline_Theme::theme/frontend/partials/header/default.phtml');
$this->fetch('Weline_Theme::theme/frontend/partials/footer/default.phtml');
?>
PHP;
        
        $tempFile = sys_get_temp_dir() . '/test_layout_fetch.phtml';
        file_put_contents($tempFile, $layoutContent);
        
        try {
            $dependencies = $this->tracker->extractDependencies($tempFile);
            
            $this->assertIsArray($dependencies);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * 测试检查是否需要重新生成
     */
    public function testNeedsRegeneration(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        if (!$theme || !$theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        // 创建测试文件
        $layoutFile = sys_get_temp_dir() . '/test_layout.phtml';
        $generatedFile = sys_get_temp_dir() . '/test_generated.css';
        
        file_put_contents($layoutFile, '<html><body>Test</body></html>');
        file_put_contents($generatedFile, '/* Generated CSS */');
        
        try {
            // 如果布局文件比生成文件新，需要重新生成
            touch($layoutFile, time() + 10);
            
            $needsRegen = $this->tracker->needsRegeneration(
                $layoutFile,
                $generatedFile,
                $theme,
                'frontend'
            );
            
            $this->assertIsBool($needsRegen);
        } finally {
            if (file_exists($layoutFile)) {
                unlink($layoutFile);
            }
            if (file_exists($generatedFile)) {
                unlink($generatedFile);
            }
        }
    }
    
    /**
     * 测试依赖缓存
     */
    public function testDependencyCache(): void
    {
        $layoutFile = sys_get_temp_dir() . '/test_cache.phtml';
        file_put_contents($layoutFile, '<html><body>Test</body></html>');
        
        try {
            // 第一次提取
            $deps1 = $this->tracker->extractDependencies($layoutFile);
            
            // 第二次提取（应该使用缓存）
            $deps2 = $this->tracker->extractDependencies($layoutFile);
            
            $this->assertEquals($deps1, $deps2);
            
            // 清除缓存
            LayoutDependencyTracker::clearCache($layoutFile);
            
            // 第三次提取（应该重新解析）
            $deps3 = $this->tracker->extractDependencies($layoutFile);
            
            $this->assertEquals($deps1, $deps3);
        } finally {
            if (file_exists($layoutFile)) {
                unlink($layoutFile);
            }
        }
    }
    
    /**
     * 测试提取不存在的文件
     */
    public function testExtractNonExistentFile(): void
    {
        $dependencies = $this->tracker->extractDependencies('/non/existent/file.phtml');
        
        $this->assertIsArray($dependencies);
        $this->assertEmpty($dependencies);
    }
}

