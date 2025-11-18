<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sticker 模块扩展配置测试
 */
class StickerExtendsTest extends TestCase
{
    private string $stickerModulePath;

    protected function setUp(): void
    {
        parent::setUp();
        // 从 Test/Unit 向上2级到模块根目录 (Test/Unit -> Test -> Sticker)
        $this->stickerModulePath = dirname(__DIR__, 2);
    }

    /**
     * 测试 extends.php 文件存在性
     */
    public function testExtendsPhpFileExists(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $this->assertFileExists($extendsFile, 'extends.php 文件应该存在');
    }

    /**
     * 测试 extends.php 文件内容结构
     */
    public function testExtendsPhpContentStructure(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $content = file_get_contents($extendsFile);
        
        // 检查文件内容基本结构
        $this->assertStringContainsString('return [', $content, '应该返回数组');
        $this->assertStringContainsString('type', $content, '应该包含 type 字段');
        $this->assertStringContainsString('extends', $content, '应该包含 extends 字段');
        $this->assertStringContainsString('documentation', $content, '应该包含 documentation 字段');
    }

    /**
     * 测试 extends.php 文件可正确包含
     */
    public function testExtendsPhpCanBeIncluded(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        
        $this->assertFileExists($extendsFile);
        
        $config = include $extendsFile;
        
        $this->assertIsArray($config, '包含的配置文件应该返回数组');
        $this->assertNotEmpty($config, '配置文件不应该为空');
    }

    /**
     * 测试配置文件结构完整性
     */
    public function testConfigStructureIntegrity(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $config = include $extendsFile;

        // 验证顶层结构
        $this->assertArrayHasKey('type', $config, '应该包含 type 字段');
        $this->assertArrayHasKey('documentation', $config, '应该包含 documentation 字段');
        $this->assertArrayHasKey('extends', $config, '应该包含 extends 字段');

        // 验证 type 字段
        $this->assertEquals('module', $config['type'], 'type 应该是 module');

        // 验证 documentation 字段
        $this->assertEquals('extends.md', $config['documentation'], 'documentation 应该是 extends.md');

        // 验证 extends 字段结构
        $this->assertArrayHasKey('Sticker', $config['extends'], '应该包含 Sticker 扩展点');
        
        $stickerExtends = $config['extends']['Sticker'];
        $this->assertArrayHasKey('path', $stickerExtends, 'Sticker 扩展点应该包含 path');
        $this->assertArrayHasKey('type', $stickerExtends, 'Sticker 扩展点应该包含 type');
        $this->assertArrayHasKey('description', $stickerExtends, 'Sticker 扩展点应该包含 description');
        $this->assertArrayHasKey('required', $stickerExtends, 'Sticker 扩展点应该包含 required');
        $this->assertArrayHasKey('multiple', $stickerExtends, 'Sticker 扩展点应该包含 multiple');
    }

    /**
     * 测试 Sticker 扩展点配置
     */
    public function testStickerExtensionPointConfig(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $config = include $extendsFile;

        $stickerExtends = $config['extends']['Sticker'];

        // 验证路径配置
        $this->assertEquals('extends/module/Weline_Sticker', $stickerExtends['path'], '路径配置应该正确');

        // 验证类型配置（应该是数组）
        $this->assertIsArray($stickerExtends['type'], 'type 应该是数组');
        $this->assertContains('module', $stickerExtends['type'], '应该支持 module 类型');
        $this->assertContains('theme', $stickerExtends['type'], '应该支持 theme 类型');

        // 验证描述
        $this->assertStringContainsString('Sticker 扩展点', $stickerExtends['description'], '描述应该包含关键词');
        $this->assertStringContainsString('非侵入式', $stickerExtends['description'], '描述应该说明非侵入式特性');
        $this->assertStringContainsString('模块级和主题级', $stickerExtends['description'], '描述应该说明支持的模式');

        // 验证必需和多重配置
        $this->assertFalse($stickerExtends['required'], 'Sticker 扩展不是必需的');
        $this->assertTrue($stickerExtends['multiple'], 'Sticker 允许多个实现');
    }

    /**
     * 测试详细配置信息
     */
    public function testDetailedConfiguration(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $config = include $extendsFile;

        $stickerExtends = $config['extends']['Sticker'];

        // 验证 details 字段存在
        $this->assertArrayHasKey('details', $stickerExtends, '应该包含详细配置');

        $details = $stickerExtends['details'];

        // 验证模块模式配置
        $this->assertArrayHasKey('module_mode', $details, '应该包含模块模式配置');
        $this->assertArrayHasKey('theme_mode', $details, '应该包含主题模式配置');
        $this->assertArrayHasKey('rule_syntax', $details, '应该包含规则语法配置');

        // 验证模块模式详情
        $moduleMode = $details['module_mode'];
        $this->assertArrayHasKey('path', $moduleMode, '模块模式应该包含路径');
        $this->assertArrayHasKey('description', $moduleMode, '模块模式应该包含描述');
        $this->assertArrayHasKey('example', $moduleMode, '模块模式应该包含示例');

        // 验证主题模式详情
        $themeMode = $details['theme_mode'];
        $this->assertArrayHasKey('path', $themeMode, '主题模式应该包含路径');
        $this->assertArrayHasKey('description', $themeMode, '主题模式应该包含描述');
        $this->assertArrayHasKey('example', $themeMode, '主题模式应该包含示例');

        // 验证规则语法详情
        $ruleSyntax = $details['rule_syntax'];
        $this->assertArrayHasKey('tags', $ruleSyntax, '规则语法应该包含标签');
        $this->assertArrayHasKey('target', $ruleSyntax, '规则语法应该包含目标');
        $this->assertArrayHasKey('code', $ruleSyntax, '规则语法应该包含代码');
        $this->assertArrayHasKey('description', $ruleSyntax, '规则语法应该包含描述');
    }

    /**
     * 测试 extends.md 文档文件存在性
     */
    public function testExtendsMdDocumentationExists(): void
    {
        $docFile = $this->stickerModulePath . '/extends.md';
        $this->assertFileExists($docFile, 'extends.md 文档文件应该存在');
    }

    /**
     * 测试 extends.md 文档内容结构
     */
    public function testExtendsMdContentStructure(): void
    {
        $docFile = $this->stickerModulePath . '/extends.md';
        $content = file_get_contents($docFile);
        
        // 检查基本章节
        $this->assertStringContainsString('# Weline_Sticker', $content, '应该包含标题');
        $this->assertStringContainsString('## 概述', $content, '应该包含概述章节');
        $this->assertStringContainsString('## 快速开始', $content, '应该包含快速开始章节');
        $this->assertStringContainsString('## 详细说明', $content, '应该包含详细说明章节');
        
        // 检查 Sticker 特定内容
        $this->assertStringContainsString('Sticker', $content, '应该包含 Sticker 关键词');
        $this->assertStringContainsString('w:sticker', $content, '应该包含 w:sticker 标签');
        $this->assertStringContainsString('extends/module/Weline_Sticker', $content, '应该包含模块路径');
    }

    /**
     * 测试配置文件语法正确性
     */
    public function testConfigSyntaxValid(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        
        // 尝试解析配置文件
        try {
            $config = include $extendsFile;
            $this->assertIsArray($config, '配置文件应该返回有效数组');
        } catch (ParseError $e) {
            $this->fail('配置文件语法错误: ' . $e->getMessage());
        } catch (\Error $e) {
            $this->fail('配置文件包含致命错误: ' . $e->getMessage());
        }
    }

    /**
     * 测试扩展点类型支持验证
     */
    public function testExtensionTypesSupport(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $config = include $extendsFile;

        $stickerExtends = $config['extends']['Sticker'];
        $supportedTypes = $stickerExtends['type'];

        // 验证支持类型的完整性
        $this->assertContains('module', $supportedTypes, '应该支持 module 类型');
        $this->assertContains('theme', $supportedTypes, '应该支持 theme 类型');
        
        // 确保没有重复的类型
        $uniqueTypes = array_unique($supportedTypes);
        $this->assertCount(count($supportedTypes), $uniqueTypes, '支持的类型应该唯一');
    }

    /**
     * 测试路径格式正确性
     */
    public function testPathFormats(): void
    {
        $extendsFile = $this->stickerModulePath . '/extends.php';
        $config = include $extendsFile;

        $stickerExtends = $config['extends']['Sticker'];
        $details = $stickerExtends['details'];

        // 验证主路径格式
        $mainPath = $stickerExtends['path'];
        $this->assertStringStartsWith('extends/', $mainPath, '主路径应该以 extends/ 开头');
        
        // 验证模块模式路径
        $moduleModePath = $details['module_mode']['path'];
        $this->assertStringStartsWith('extends/module/Weline_Sticker/', $moduleModePath, '模块模式路径应该正确');
        $this->assertStringContainsString('{目标模块名}', $moduleModePath, '模块模式路径应该包含占位符');
        
        // 验证主题模式路径
        $themeModePath = $details['theme_mode']['path'];
        $this->assertStringStartsWith('extends/theme/', $themeModePath, '主题模式路径应该正确');
        $this->assertStringContainsString('{主题名}', $themeModePath, '主题模式路径应该包含主题占位符');
    }
}
