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
use Weline\Framework\Test\TestCore;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量注入器测试
 * 
 * 测试CSS变量从Meta正确注入
 */
class CssVariableInjectorTest extends TestCore
{
    private CssVariableInjector $injector;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->injector = ObjectManager::getInstance(CssVariableInjector::class);
    }
    
    /**
     * 测试生成CSS变量定义
     */
    public function testGenerateCssVariables(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        if (!$theme || !$theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $cssVariables = $this->injector->generateCssVariables('frontend', $theme);
        $marker = '/* Weline Theme variables v2: explicit non-palette tokens only */';
        
        $this->assertIsString($cssVariables);
        $this->assertStringContainsString($marker, $cssVariables);
        $this->assertStringNotContainsString('--color-bg-primary:', $cssVariables);

        if (str_contains($cssVariables, ':root')) {
            $this->assertStringContainsString('--', $cssVariables);
        } else {
            $this->assertSame($marker . "\n", $cssVariables);
        }
    }
    
    /**
     * 测试变量分组输出
     */
    public function testVariableGrouping(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        if (!$theme || !$theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $cssVariables = $this->injector->generateCssVariables('frontend', $theme);
        $marker = '/* Weline Theme variables v2: explicit non-palette tokens only */';
        
        $this->assertIsString($cssVariables);
        $this->assertStringContainsString($marker, $cssVariables);
        $this->assertStringNotContainsString('--color-bg-primary:', $cssVariables);

        if (str_contains($cssVariables, ':root')) {
            $this->assertMatchesRegularExpression('/^\s*--[A-Za-z0-9_-]+\s*:/m', $cssVariables);
            $this->assertStringContainsString('/* ==========', $cssVariables);
        } else {
            $this->assertSame($marker . "\n", $cssVariables);
        }
    }
    
    /**
     * 测试空变量处理
     */
    public function testEmptyVariables(): void
    {
        // 创建一个没有变量的主题场景
        $cssVariables = $this->injector->generateCssVariables('frontend', null);
        $marker = '/* Weline Theme variables v2: explicit non-palette tokens only */';
        
        $this->assertIsString($cssVariables);
        $this->assertStringContainsString($marker, $cssVariables);
        $this->assertStringNotContainsString('--color-bg-primary:', $cssVariables);
        if (str_contains($cssVariables, ':root')) {
            $this->assertStringContainsString('--', $cssVariables);
        } else {
            $this->assertSame($marker . "\n", $cssVariables);
        }
    }
}
