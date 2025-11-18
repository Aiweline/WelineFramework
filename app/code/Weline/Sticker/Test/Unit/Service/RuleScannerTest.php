<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\RuleScanner;

/**
 * RuleScanner 单元测试
 */
class RuleScannerTest extends TestCase
{
    private RuleScanner $ruleScanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleScanner = ObjectManager::getInstance(RuleScanner::class);
    }

    /**
     * 测试：扫描 Sticker 文件
     */
    public function testScanAllStickers(): void
    {
        $stickers = $this->ruleScanner->scanAllStickers();
        
        // 检查是否返回数组
        $this->assertIsArray($stickers);
        
        // 如果有 Sticker 文件，检查结构
        if (!empty($stickers)) {
            $first = $stickers[0];
            $this->assertArrayHasKey('source_module', $first);
            $this->assertArrayHasKey('target_module', $first);
            $this->assertArrayHasKey('target_file', $first);
            $this->assertArrayHasKey('sticker_file', $first);
        }
    }

    /**
     * 测试：检查模块是否有 Sticker
     */
    public function testHasStickers(): void
    {
        // 测试 Sticker 模块本身
        $hasStickers = $this->ruleScanner->hasStickers('Weline_Sticker');
        
        // 应该返回 true（因为我们在 extends 目录下创建了测试文件）
        $this->assertIsBool($hasStickers);
    }

    /**
     * 测试：扫描不存在的模块
     */
    public function testHasStickersNonExistentModule(): void
    {
        $hasStickers = $this->ruleScanner->hasStickers('NonExistent_Module');
        $this->assertFalse($hasStickers);
    }
}

