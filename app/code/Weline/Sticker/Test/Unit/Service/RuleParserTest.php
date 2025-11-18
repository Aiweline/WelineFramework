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
use Weline\Sticker\Service\RuleParser;

/**
 * RuleParser 单元测试
 */
class RuleParserTest extends TestCase
{
    private RuleParser $ruleParser;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $codeMinifier = ObjectManager::getInstance(CodeMinifier::class);
        $this->ruleParser = ObjectManager::getInstance(RuleParser::class);
        $this->testDir = BP . 'app/code/Weline/Sticker/Test/Unit/Service/_files/';
        
        // 创建测试目录
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    /**
     * 测试：解析 Sticker 文件 - replace 操作
     */
    public function testParseReplaceAction(): void
    {
        $content = <<<'STICKER'
<w:sticker action="replace" position="1">
    <w:sticker:target>
        <h1>测试标题</h1>
    </w:sticker:target>
    <w:sticker:code>
        <h1>新标题</h1>
    </w:sticker:code>
</w:sticker>
STICKER;

        $testFile = $this->testDir . 'test_replace.phtml';
        file_put_contents($testFile, $content);

        $rules = $this->ruleParser->parseStickerFile($testFile);

        $this->assertCount(1, $rules);
        $this->assertEquals('replace', $rules[0]['type']);
        $this->assertEquals('1', $rules[0]['position']);
        $this->assertNotEmpty($rules[0]['target']);
        $this->assertNotEmpty($rules[0]['code']);
        
        // 清理
        unlink($testFile);
    }

    /**
     * 测试：解析 Sticker 文件 - before 操作
     */
    public function testParseBeforeAction(): void
    {
        $content = <<<'STICKER'
<w:sticker action="before" position="all">
    <w:sticker:target>
        <button>按钮</button>
    </w:sticker:target>
    <w:sticker:code>
        <div>前置内容</div>
    </w:sticker:code>
</w:sticker>
STICKER;

        $testFile = $this->testDir . 'test_before.phtml';
        file_put_contents($testFile, $content);

        $rules = $this->ruleParser->parseStickerFile($testFile);

        $this->assertCount(1, $rules);
        $this->assertEquals('before', $rules[0]['type']);
        $this->assertEquals('all', $rules[0]['position']);
        
        // 清理
        unlink($testFile);
    }

    /**
     * 测试：解析 Sticker 文件 - after 操作
     */
    public function testParseAfterAction(): void
    {
        $content = <<<'STICKER'
<w:sticker action="after" position="2-3">
    <w:sticker:target>
        <p>段落</p>
    </w:sticker:target>
    <w:sticker:code>
        <span>追加内容</span>
    </w:sticker:code>
</w:sticker>
STICKER;

        $testFile = $this->testDir . 'test_after.phtml';
        file_put_contents($testFile, $content);

        $rules = $this->ruleParser->parseStickerFile($testFile);

        $this->assertCount(1, $rules);
        $this->assertEquals('after', $rules[0]['type']);
        $this->assertEquals('2-3', $rules[0]['position']);
        
        // 清理
        unlink($testFile);
    }

    /**
     * 测试：解析多个规则
     */
    public function testParseMultipleRules(): void
    {
        $content = <<<'STICKER'
<w:sticker action="replace" position="1">
    <w:sticker:target><h1>标题1</h1></w:sticker:target>
    <w:sticker:code><h1>新标题1</h1></w:sticker:code>
</w:sticker>
<w:sticker action="before" position="all">
    <w:sticker:target><button>按钮</button></w:sticker:target>
    <w:sticker:code><div>前置</div></w:sticker:code>
</w:sticker>
STICKER;

        $testFile = $this->testDir . 'test_multiple.phtml';
        file_put_contents($testFile, $content);

        $rules = $this->ruleParser->parseStickerFile($testFile);

        $this->assertCount(2, $rules);
        
        // 清理
        unlink($testFile);
    }

    /**
     * 测试：规则验证
     */
    public function testValidateRule(): void
    {
        // 有效规则
        $validRule = [
            'type' => 'replace',
            'target' => 'test',
            'position' => '1'
        ];
        $this->assertTrue($this->ruleParser->validateRule($validRule));

        // 无效类型
        $invalidRule = [
            'type' => 'invalid',
            'target' => 'test',
            'position' => '1'
        ];
        $this->assertFalse($this->ruleParser->validateRule($invalidRule));

        // 缺少目标代码
        $invalidRule2 = [
            'type' => 'replace',
            'target' => '',
            'position' => '1'
        ];
        $this->assertFalse($this->ruleParser->validateRule($invalidRule2));
    }

    protected function tearDown(): void
    {
        // 清理测试目录
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }
}

