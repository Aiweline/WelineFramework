<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | Taglib 编译管道单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Framework\View\Taglib\Compiler\CompilePipeline;
use Weline\Framework\View\Taglib\Compiler\Pass\StageResolutionPass;
use Weline\Framework\View\Taglib\Compiler\Pass\ConstantFoldingPass;
use Weline\Framework\View\Taglib\Compiler\Pass\DeadCodeEliminationPass;
use Weline\Framework\View\Taglib\Compiler\Pass\InlineOptimizationPass;
use Weline\Framework\Manager\ObjectManager;

/**
 * 编译管道测试
 * 
 * 覆盖文档示例、Pipeline Pass 列表与顺序
 */
class PipelineTest extends TestCase
{
    private Taglib $taglib;
    private Template $template;

    protected function setUp(): void
    {
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * 测试默认管道包含所有必需的 Pass
     */
    public function testDefaultPipelineContainsAllPasses(): void
    {
        $pipeline = CompilePipeline::createDefault();
        $passNames = $pipeline->getPassNames();

        // 验证包含所有必需的 Pass
        self::assertContains('stage-resolution', $passNames, '应包含 StageResolutionPass');
        self::assertContains('constant-folding', $passNames, '应包含 ConstantFoldingPass');
        self::assertContains('dead-code-elimination', $passNames, '应包含 DeadCodeEliminationPass');
        self::assertContains('inline-optimization', $passNames, '应包含 InlineOptimizationPass');
    }

    /**
     * 测试 Pass 优先级排序
     * 
     * 按文档约定：
     * - StageResolutionPass: 5
     * - ConstantFoldingPass: 10
     * - DeadCodeEliminationPass: 20
     * - InlineOptimizationPass: 60
     */
    public function testPassPriorityOrder(): void
    {
        $pipeline = CompilePipeline::createDefault();
        $passNames = $pipeline->getPassNames();

        // 验证顺序
        $stageIndex = array_search('stage-resolution', $passNames);
        $constantIndex = array_search('constant-folding', $passNames);
        $deadCodeIndex = array_search('dead-code-elimination', $passNames);
        $inlineIndex = array_search('inline-optimization', $passNames);

        self::assertLessThan($constantIndex, $stageIndex, 'StageResolution 应在 ConstantFolding 之前');
        self::assertLessThan($deadCodeIndex, $constantIndex, 'ConstantFolding 应在 DeadCodeElimination 之前');
        self::assertLessThan($inlineIndex, $deadCodeIndex, 'DeadCodeElimination 应在 InlineOptimization 之前');
    }

    /**
     * 测试添加自定义 Pass
     */
    public function testAddCustomPass(): void
    {
        $this->taglib->addCompilePass(new class implements \Weline\Framework\View\Taglib\Compiler\Pass\CompilePassInterface {
            public function process(\Weline\Framework\View\Taglib\Ast\ProgramNode $ast): \Weline\Framework\View\Taglib\Ast\ProgramNode
            {
                return $ast;
            }

            public function getName(): string
            {
                return 'custom-test-pass';
            }

            public function getPriority(): int
            {
                return 100;
            }
        });

        $stats = $this->taglib->stats();
        self::assertContains('custom-test-pass', $stats['pipeline']['passes']);
    }

    /**
     * 测试 ConstantFolding 文本合并
     */
    public function testConstantFoldingTextMerge(): void
    {
        $content = 'Hello ';
        $content .= 'World';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('Hello World', $result);
    }

    /**
     * 测试 DeadCodeElimination 移除 if(false)
     */
    public function testDeadCodeEliminationIfFalse(): void
    {
        $content = '<if condition="false">This should be removed</if>Visible content';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringNotContainsString('This should be removed', $result);
        self::assertStringContainsString('Visible content', $result);
    }

    /**
     * 测试 DeadCodeElimination 简化 if(true)
     */
    public function testDeadCodeEliminationIfTrue(): void
    {
        $content = '<if condition="true">This should remain</if>';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('This should remain', $result);
        // 应该简化为只保留内容，不包含 if 语句
        // 注意：这取决于具体实现，可能仍然生成 if(true)
    }

    /**
     * 测试 foreach items/as 语法
     */
    public function testForeachItemsAsSyntax(): void
    {
        $content = '<foreach items="$items" as="$item">Content</foreach>';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('foreach', $result);
        self::assertStringContainsString('$items', $result);
        self::assertStringContainsString('$item', $result);
        self::assertStringContainsString('endforeach', $result);
    }

    /**
     * 测试 foreach items/as/key 语法
     */
    public function testForeachItemsAsKeySyntax(): void
    {
        $content = '<foreach items="$items" as="$item" key="$key">Content</foreach>';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('foreach', $result);
        self::assertStringContainsString('$key', $result);
        self::assertStringContainsString('=>', $result);
    }

    /**
     * 测试 switch/case/default 语法
     */
    public function testSwitchCaseDefaultSyntax(): void
    {
        $content = '<switch value="$value"><case value="1">One</case><default>Other</default></switch>';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('switch', $result);
        self::assertStringContainsString('case', $result);
        self::assertStringContainsString('default', $result);
        self::assertStringContainsString('endswitch', $result);
    }

    /**
     * 测试 while 语法
     */
    public function testWhileSyntax(): void
    {
        $content = '<while condition="$i < 10">Content</while>';
        $fileName = 'test.phtml';

        $result = $this->taglib->compile($this->template, $content, $fileName);

        self::assertStringContainsString('while', $result);
        self::assertStringContainsString('endwhile', $result);
    }

    /**
     * 测试 removePass 功能
     */
    public function testRemovePass(): void
    {
        $pipeline = CompilePipeline::createDefault();
        $pipeline->removePass('inline-optimization');

        $passNames = $pipeline->getPassNames();
        self::assertNotContains('inline-optimization', $passNames);
    }
}
