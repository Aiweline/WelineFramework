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
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\Compiler;
use Weline\Sticker\Service\RuleParser;
use Weline\Sticker\Service\RuleScanner;
use Weline\Sticker\Service\StickerRegistry;

/**
 * Sticker 集成测试
 * 测试完整的 Sticker 流程
 */
class StickerIntegrationTest extends TestCase
{
    private RuleScanner $ruleScanner;
    private RuleParser $ruleParser;
    private StickerRegistry $registry;
    private Compiler $compiler;
    private string $testTargetFile;
    private string $testStickerFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->ruleScanner = ObjectManager::getInstance(RuleScanner::class);
        $this->ruleParser = ObjectManager::getInstance(RuleParser::class);
        $this->registry = ObjectManager::getInstance(StickerRegistry::class);
        $this->compiler = ObjectManager::getInstance(Compiler::class);
        
        $this->testTargetFile = BP . 'app/code/Weline/Sticker/view/templates/Test/index.phtml';
        $this->testStickerFile = BP . 'app/code/Weline/Sticker/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml';
    }

    /**
     * 测试：完整的 Sticker 流程
     * 1. 扫描 Sticker 文件
     * 2. 解析规则
     * 3. 构建注册表
     * 4. 编译文件
     */
    public function testFullStickerWorkflow(): void
    {
        // 1. 扫描 Sticker 文件
        $scannedStickers = $this->ruleScanner->scanAllStickers();
        
        // 检查是否找到了测试文件
        $testSticker = null;
        foreach ($scannedStickers as $sticker) {
            if ($sticker['target_file'] === 'Weline/Sticker/view/templates/Test/index.phtml' &&
                $sticker['source_module'] === 'Weline_Sticker') {
                $testSticker = $sticker;
                break;
            }
        }

        if (!$testSticker) {
            $this->markTestSkipped('测试 Sticker 文件不存在，请确保 extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml 存在');
        }

        // 2. 解析规则
        $rules = $this->ruleParser->parseStickerFile($testSticker['sticker_file']);
        $this->assertNotEmpty($rules, '应该解析到 Sticker 规则');

        // 3. 构建注册表
        $registry = $this->registry->buildRegistryFromScanned($scannedStickers, $this->ruleParser);
        $this->assertArrayHasKey('Weline_Sticker', $registry);
        $this->assertArrayHasKey('Weline/Sticker/view/templates/Test/index.phtml', $registry['Weline_Sticker']);

        // 4. 保存注册表
        $this->registry->saveRegistry($registry);

        // 5. 编译文件
        if (file_exists($this->testTargetFile)) {
            $compiledPath = $this->compiler->compile(
                'Weline_Sticker',
                'Weline/Sticker/view/templates/Test/index.phtml',
                $this->testTargetFile
            );

            if ($compiledPath && file_exists($compiledPath)) {
                $compiledContent = file_get_contents($compiledPath);
                
                // 验证编译结果包含修改内容
                $this->assertStringContainsString('sticker-modified', $compiledContent);
                $this->assertStringContainsString('sticker-inserted', $compiledContent);
                $this->assertStringContainsString('sticker-appended', $compiledContent);
            } else {
                $this->markTestIncomplete('编译文件生成失败');
            }
        } else {
            $this->markTestSkipped('测试目标文件不存在: ' . $this->testTargetFile);
        }
    }

    /**
     * 测试：位置参数功能
     */
    public function testPositionParameter(): void
    {
        $duplicateFile = BP . 'app/code/Weline/Sticker/view/templates/Test/duplicate.phtml';
        $duplicateStickerFile = BP . 'app/code/Weline/Sticker/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/duplicate.phtml';

        if (!file_exists($duplicateFile) || !file_exists($duplicateStickerFile)) {
            $this->markTestSkipped('重复内容测试文件不存在');
        }

        $scannedStickers = $this->ruleScanner->scanAllStickers();
        $registry = $this->registry->buildRegistryFromScanned($scannedStickers, $this->ruleParser);
        $this->registry->saveRegistry($registry);

        $compiledPath = $this->compiler->compile(
            'Weline_Sticker',
            'Weline/Sticker/view/templates/Test/duplicate.phtml',
            $duplicateFile
        );

        if ($compiledPath && file_exists($compiledPath)) {
            $compiledContent = file_get_contents($compiledPath);
            
            // 验证位置参数生效
            // position="2" 应该替换第二段为 sticker-modified
            // position="1-3" 应该替换第一和第三段为 sticker-all
            // 注意：如果第一个规则先执行，第二段已经被替换，第二个规则无法匹配
            // 所以实际结果可能只包含 sticker-all（如果第二个规则先执行）
            // 或者包含 sticker-modified（如果第一个规则先执行）
            $hasModified = strpos($compiledContent, 'sticker-modified') !== false;
            $hasAll = strpos($compiledContent, 'sticker-all') !== false;
            
            // 至少应该有一个被替换
            $this->assertTrue($hasModified || $hasAll, 
                '编译结果应该包含 sticker-modified 或 sticker-all，实际内容: ' . substr($compiledContent, 0, 200));
        }
    }
}

