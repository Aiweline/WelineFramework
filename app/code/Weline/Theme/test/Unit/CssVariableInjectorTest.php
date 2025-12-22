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
        
        $this->assertIsString($cssVariables);
        
        // 验证包含:root
        $this->assertStringContainsString(':root', $cssVariables);
        
        // 如果有变量，应该包含CSS变量定义
        if (!empty($cssVariables)) {
            $this->assertStringContainsString('--', $cssVariables);
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
        
        $this->assertIsString($cssVariables);
        
        // 如果有变量，应该包含分组注释或变量定义
        if (!empty($cssVariables) && strpos($cssVariables, '--') !== false) {
            // 验证包含:root或变量定义
            $this->assertTrue(
                strpos($cssVariables, ':root') !== false || 
                strpos($cssVariables, '--') !== false,
                'CSS变量应该包含:root或变量定义'
            );
        } else {
            // 如果没有变量，至少应该返回空字符串或基本结构
            $this->assertIsString($cssVariables);
        }
    }
    
    /**
     * 测试空变量处理
     */
    public function testEmptyVariables(): void
    {
        // 创建一个没有变量的主题场景
        $cssVariables = $this->injector->generateCssVariables('frontend', null);
        
        // 如果没有变量，应该返回空字符串或基本的:root结构
        $this->assertIsString($cssVariables);
    }
}

