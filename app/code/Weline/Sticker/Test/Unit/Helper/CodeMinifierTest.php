<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Helper\CodeMinifier;

/**
 * CodeMinifier 单元测试
 */
class CodeMinifierTest extends TestCase
{
    private CodeMinifier $codeMinifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codeMinifier = ObjectManager::getInstance(CodeMinifier::class);
    }

    /**
     * 测试：代码压缩 - 移除空白字符
     */
    public function testMinifyRemovesWhitespace(): void
    {
        // 测试 HTML 代码压缩
        $code = "<div class=\"test\">\n    <p>Content</p>\n</div>";
        $result = $this->codeMinifier->minify($code);
        
        // 压缩后应该移除换行和多余空白
        $this->assertStringNotContainsString("\n", $result);
        $this->assertStringNotContainsString("    ", $result);
        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    /**
     * 测试：代码压缩 - 保留字符串内容
     */
    public function testMinifyPreservesStrings(): void
    {
        $code = "echo 'hello world'; echo \"test string\";";
        $result = $this->codeMinifier->minify($code);
        
        $this->assertStringContainsString('hello world', $result);
        $this->assertStringContainsString('test string', $result);
    }

    /**
     * 测试：代码压缩 - 保留 HTML 标签
     */
    public function testMinifyPreservesHtmlTags(): void
    {
        $code = "<div class=\"test\">\n    <p>Content</p>\n</div>";
        $result = $this->codeMinifier->minify($code);
        
        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('</div>', $result);
        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('</p>', $result);
    }

    /**
     * 测试：查找匹配位置
     */
    public function testFindMatches(): void
    {
        $code = "<p>test</p><p>test</p><p>test</p>";
        $target = "<p>test</p>";
        $minified = $this->codeMinifier->minify($code);
        $targetMinified = $this->codeMinifier->minify($target);
        
        $matches = $this->codeMinifier->findMatches($minified, $target);
        
        $this->assertCount(3, $matches);
        $this->assertEquals(1, $matches[0]['index']);
        $this->assertEquals(2, $matches[1]['index']);
        $this->assertEquals(3, $matches[2]['index']);
    }

    /**
     * 测试：位置参数解析 - all
     */
    public function testGetPositionIndexesAll(): void
    {
        $indexes = $this->codeMinifier->getPositionIndexes('all', 5);
        $this->assertEquals([1, 2, 3, 4, 5], $indexes);
    }

    /**
     * 测试：位置参数解析 - 单个索引
     */
    public function testGetPositionIndexesSingle(): void
    {
        $indexes = $this->codeMinifier->getPositionIndexes('2', 5);
        $this->assertEquals([2], $indexes);
    }

    /**
     * 测试：位置参数解析 - 范围
     */
    public function testGetPositionIndexesRange(): void
    {
        $indexes = $this->codeMinifier->getPositionIndexes('2-4', 5);
        $this->assertEquals([2, 3, 4], $indexes);
    }
}

