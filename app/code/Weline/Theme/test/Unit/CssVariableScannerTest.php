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
use Weline\Theme\Helper\CssVariableScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量扫描器测试
 * 
 * 测试CSS变量扫描和Meta注册功能
 */
class CssVariableScannerTest extends TestCore
{
    private CssVariableScanner $scanner;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->scanner = ObjectManager::getInstance(CssVariableScanner::class);
    }
    
    /**
     * 测试从CSS文件提取变量
     */
    public function testExtractVariablesFromCss(): void
    {
        $cssContent = <<<'CSS'
:root {
    /* ========== 品牌色 ========== */
    --color-primary: #f0c14b;
    --color-primary-light: #f4d078;
    
    /* ========== 文本色 ========== */
    --color-text-primary: #111;
    --color-text-secondary: #767676;
}
CSS;
        
        // 创建临时文件
        $tempFile = sys_get_temp_dir() . '/test_colors.css';
        file_put_contents($tempFile, $cssContent);
        
        try {
            $variables = $this->scanner->extractVariablesFromCss($tempFile, 'colors');
            
            $this->assertIsArray($variables);
            $this->assertGreaterThan(0, count($variables));
            
            // 验证变量被正确提取
            $varNames = array_column($variables, 'name');
            $this->assertContains('color-primary', $varNames);
            $this->assertContains('color-text-primary', $varNames);
            
            // 验证变量值
            foreach ($variables as $variable) {
                $this->assertArrayHasKey('name', $variable);
                $this->assertArrayHasKey('value', $variable);
                $this->assertArrayHasKey('category', $variable);
                $this->assertArrayHasKey('type', $variable);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * 测试变量类型检测
     */
    public function testVariableTypeDetection(): void
    {
        $cssContent = <<<'CSS'
:root {
    --color-primary: #f0c14b;
    --spacing-small: 0.5rem;
    --font-size-base: 16px;
}
CSS;
        
        $tempFile = sys_get_temp_dir() . '/test_variables.css';
        file_put_contents($tempFile, $cssContent);
        
        try {
            $variables = $this->scanner->extractVariablesFromCss($tempFile, 'colors');
            
            // 验证颜色变量类型
            $colorVar = null;
            foreach ($variables as $variable) {
                if ($variable['name'] === 'color-primary') {
                    $colorVar = $variable;
                    break;
                }
            }
            
            if ($colorVar) {
                $this->assertEquals('color', $colorVar['type']);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * 测试分类注释提取
     */
    public function testCategoryExtraction(): void
    {
        $cssContent = <<<'CSS'
:root {
    /* ========== 品牌色 ========== */
    --color-primary: #f0c14b;
    
    /* ========== 文本色 ========== */
    --color-text-primary: #111;
}
CSS;
        
        $tempFile = sys_get_temp_dir() . '/test_categories.css';
        file_put_contents($tempFile, $cssContent);
        
        try {
            $variables = $this->scanner->extractVariablesFromCss($tempFile, 'colors');
            
            // 验证分类被正确提取
            $primaryVar = null;
            $textVar = null;
            
            foreach ($variables as $variable) {
                if ($variable['name'] === 'color-primary') {
                    $primaryVar = $variable;
                } elseif ($variable['name'] === 'color-text-primary') {
                    $textVar = $variable;
                }
            }
            
            if ($primaryVar) {
                $this->assertEquals('品牌色', $primaryVar['category']);
            }
            
            if ($textVar) {
                $this->assertEquals('文本色', $textVar['category']);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * 测试扫描变量（需要实际的主题和variables文件）
     */
    public function testScanVariables(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme = $theme->getActiveTheme();
        
        if (!$theme || !$theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $results = $this->scanner->scanVariables('frontend', $theme);
        
        $this->assertIsArray($results);
        // 如果有variables文件，应该有结果
        // 如果没有，结果为空数组也是正常的
    }
    
    /**
     * 测试提取不存在的文件
     */
    public function testExtractNonExistentFile(): void
    {
        $variables = $this->scanner->extractVariablesFromCss('/non/existent/file.css', 'colors');
        
        $this->assertIsArray($variables);
        $this->assertEmpty($variables);
    }
}

