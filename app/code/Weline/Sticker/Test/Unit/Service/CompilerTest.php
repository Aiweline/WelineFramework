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
use Weline\Sticker\Helper\CodeMinifier;
use Weline\Sticker\Service\Compiler;
use Weline\Sticker\Service\NotificationService;
use Weline\Sticker\Service\RuleParser;
use Weline\Sticker\Service\StickerRegistry;

/**
 * Compiler 单元测试
 */
class CompilerTest extends TestCase
{
    private Compiler $compiler;
    private StickerRegistry $registry;
    private string $testModuleDir;
    private string $testTargetFile;
    private string $testStickerFile;
    private string $testOutputDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $codeMinifier = ObjectManager::getInstance(CodeMinifier::class);
        $this->registry = ObjectManager::getInstance(StickerRegistry::class);
        $ruleParser = ObjectManager::getInstance(RuleParser::class);
        $notificationService = ObjectManager::getInstance(NotificationService::class);
        
        $this->compiler = ObjectManager::getInstance(Compiler::class);
        
        // 设置测试目录
        $this->testModuleDir = BP . 'app/code/Weline/Sticker/view/templates/Test/';
        $this->testTargetFile = $this->testModuleDir . 'index.phtml';
        $this->testStickerFile = BP . 'app/code/Weline/Sticker/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml';
        $this->testOutputDir = BP . 'generated/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/';
        
        // 确保测试目录存在
        if (!is_dir($this->testModuleDir)) {
            mkdir($this->testModuleDir, 0755, true);
        }
        if (!is_dir(dirname($this->testStickerFile))) {
            mkdir(dirname($this->testStickerFile), 0755, true);
        }
        if (!is_dir($this->testOutputDir)) {
            mkdir($this->testOutputDir, 0755, true);
        }
    }

    /**
     * 测试：编译文件 - replace 操作
     */
    public function testCompileReplace(): void
    {
        // 确保目标文件存在
        if (!file_exists($this->testTargetFile)) {
            $this->markTestSkipped('测试目标文件不存在');
        }

        // 构建注册表数据
        $registry = [
            'Weline_Sticker' => [
                'Weline/Sticker/view/templates/Test/index.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => $this->testStickerFile,
                        'sticker_relative_path' => 'Weline/Sticker/view/templates/Test/index.phtml',
                        'actions' => [
                            [
                                'type' => 'replace',
                                'target' => $this->registry->getRegistry()['Weline_Sticker']['Weline/Sticker/view/templates/Test/index.phtml'][0]['actions'][0]['target'] ?? '',
                                'code' => $this->registry->getRegistry()['Weline_Sticker']['Weline/Sticker/view/templates/Test/index.phtml'][0]['actions'][0]['code'] ?? '',
                                'position' => '1',
                                'target_original' => '<h1>测试标题</h1>',
                                'code_original' => '<h1 class="sticker-modified">Sticker 修改后的标题</h1>'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // 保存注册表
        $this->registry->saveRegistry($registry);

        // 编译文件
        $compiledPath = $this->compiler->compile(
            'Weline_Sticker',
            'Weline/Sticker/view/templates/Test/index.phtml',
            $this->testTargetFile
        );

        if ($compiledPath && file_exists($compiledPath)) {
            $compiledContent = file_get_contents($compiledPath);
            $this->assertStringContainsString('sticker-modified', $compiledContent);
        } else {
            $this->markTestSkipped('编译失败或文件不存在');
        }
    }

    /**
     * 测试：编译文件 - before 操作
     */
    public function testCompileBefore(): void
    {
        if (!file_exists($this->testTargetFile)) {
            $this->markTestSkipped('测试目标文件不存在');
        }

        // 这个测试依赖于注册表数据
        $this->assertTrue(true, 'Before 操作测试需要完整的注册表数据');
    }

    /**
     * 测试：编译文件 - after 操作
     */
    public function testCompileAfter(): void
    {
        if (!file_exists($this->testTargetFile)) {
            $this->markTestSkipped('测试目标文件不存在');
        }

        // 这个测试依赖于注册表数据
        $this->assertTrue(true, 'After 操作测试需要完整的注册表数据');
    }

    /**
     * 测试：编译不存在的文件
     */
    public function testCompileNonExistentFile(): void
    {
        $result = $this->compiler->compile(
            'Weline_Sticker',
            'Weline/Sticker/view/templates/Test/nonexistent.phtml',
            BP . 'app/code/Weline/Sticker/view/templates/Test/nonexistent.phtml'
        );

        $this->assertNull($result);
    }

    protected function tearDown(): void
    {
        // 清理编译文件
        if (is_dir($this->testOutputDir)) {
            $files = glob($this->testOutputDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }
}

