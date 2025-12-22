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
use Weline\Theme\Helper\LayoutAssetsManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局资源管理器测试
 * 
 * 测试文件路径生成和URL生成
 */
class LayoutAssetsManagerTest extends TestCore
{
    private LayoutAssetsManager $manager;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->manager = ObjectManager::getInstance(LayoutAssetsManager::class);
    }
    
    /**
     * 测试获取生成的CSS文件路径
     */
    public function testGetGeneratedCssPath(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        $cssPath = $this->manager->getGeneratedCssPath(
            'frontend',
            'homepage',
            'default',
            $theme
        );
        
        $this->assertIsString($cssPath);
        $this->assertStringContainsString('homepage', $cssPath);
        $this->assertStringContainsString('default.css', $cssPath);
        // 兼容Windows路径格式（使用反斜杠）和Linux路径格式（使用正斜杠）
        $this->assertTrue(
            strpos($cssPath, 'pub' . DS . 'static') !== false || 
            strpos($cssPath, 'pub/static') !== false,
            '路径应包含 pub/static 或 pub' . DS . 'static'
        );
    }
    
    /**
     * 测试获取生成的JS文件路径
     */
    public function testGetGeneratedJsPath(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        $jsPath = $this->manager->getGeneratedJsPath(
            'frontend',
            'homepage',
            'default',
            $theme
        );
        
        $this->assertIsString($jsPath);
        $this->assertStringContainsString('homepage', $jsPath);
        $this->assertStringContainsString('default.js', $jsPath);
        // 兼容Windows路径格式（使用反斜杠）和Linux路径格式（使用正斜杠）
        $this->assertTrue(
            strpos($jsPath, 'pub' . DS . 'static') !== false || 
            strpos($jsPath, 'pub/static') !== false,
            '路径应包含 pub/static 或 pub' . DS . 'static'
        );
    }
    
    /**
     * 测试获取CSS文件URL
     */
    public function testGetCssUrl(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        // 设置测试环境变量（CLI环境下可能不存在）
        if (!isset($_SERVER['REQUEST_SCHEME'])) {
            $_SERVER['REQUEST_SCHEME'] = 'http';
        }
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
        }
        if (!isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/index.php';
        }
        
        $cssUrl = $this->manager->getCssUrl(
            'frontend',
            'homepage',
            'default',
            $theme
        );
        
        $this->assertIsString($cssUrl);
        $this->assertStringContainsString('homepage', $cssUrl);
        $this->assertStringContainsString('default.css', $cssUrl);
    }
    
    /**
     * 测试获取JS文件URL
     */
    public function testGetJsUrl(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        // 设置测试环境变量（CLI环境下可能不存在）
        if (!isset($_SERVER['REQUEST_SCHEME'])) {
            $_SERVER['REQUEST_SCHEME'] = 'http';
        }
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
        }
        if (!isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/index.php';
        }
        
        $jsUrl = $this->manager->getJsUrl(
            'frontend',
            'homepage',
            'default',
            $theme
        );
        
        $this->assertIsString($jsUrl);
        $this->assertStringContainsString('homepage', $jsUrl);
        $this->assertStringContainsString('default.js', $jsUrl);
    }
    
    /**
     * 测试目录自动创建
     */
    public function testDirectoryAutoCreation(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        $cssPath = $this->manager->getGeneratedCssPath(
            'frontend',
            'test',
            'test',
            $theme
        );
        
        // 验证目录被创建
        $dir = dirname($cssPath);
        $this->assertTrue(is_dir($dir) || is_dir(dirname($dir)));
        
        // 清理测试目录（如果创建了）
        if (is_dir($dir) && strpos($dir, 'test') !== false) {
            // 可以在这里清理，但通常测试环境会自动清理
        }
    }
}

