<?php
declare(strict_types=1);

/**
 * Weline Framework
 *
 * @DESC         | head 资源顺序编译通道单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Ast\AttrNode;
use Weline\Framework\View\Taglib\Ast\ProgramNode;
use Weline\Framework\View\Taglib\Ast\TagNode;
use Weline\Framework\View\Taglib\Ast\TextNode;
use Weline\Framework\View\Taglib\Compiler\CompilePipeline;
use Weline\Framework\View\Taglib\Compiler\Pass\HeadAssetOrderPass;

/**
 * HeadAssetOrderPass 测试
 *
 * 覆盖：按 data-order 稳定排序、占位集合不变、无属性时 no-op、
 * 非资源标签/非数字值不参与、以及默认管道注册顺序。
 */
class HeadAssetOrderPassTest extends TestCase
{
    private function assetTag(string $name, ?string $order, bool $defer = false, string $orderAttr = 'data-order'): TagNode
    {
        $attrs = [];
        if ($order !== null) {
            $attrs[] = new AttrNode(1, $orderAttr, $order, $order);
        }
        if ($defer) {
            $attrs[] = new AttrNode(1, 'defer', '', '');
        }
        return new TagNode(1, $name, $attrs, [], '', false, TagNode::STAGE_COMPILE);
    }

    private function names(array $children): array
    {
        $out = [];
        foreach ($children as $node) {
            $out[] = $node instanceof TagNode ? $node->name : ($node instanceof TextNode ? 'text:' . $node->value : 'other');
        }
        return $out;
    }

    /**
     * 资源标签按 data-order 重排，且**占位集合不变**：非资源节点原地不动。
     */
    public function testOrdersAssetsByDataOrderAndKeepsSlots(): void
    {
        $ast = new ProgramNode(1, [
            new TextNode(1, '<head>'),
            $this->assetTag('theme:js', '60'),
            $this->assetTag('theme:css', '40'),
            new TextNode(1, '<title>x</title>'),
            $this->assetTag('js', '70'),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame(
            ['text:<head>', 'theme:css', 'theme:js', 'text:<title>x</title>', 'js'],
            $this->names($out->children),
            '资源标签应在原占位内按 data-order 升序排列，非资源节点不动'
        );
    }

    /**
     * 无任何 data-order 时必须是 no-op：子节点数组逐项相同。
     */
    public function testNoOpWithoutOrderAttribute(): void
    {
        $ast = new ProgramNode(1, [
            new TextNode(1, '<head>'),
            $this->assetTag('theme:js', null, true),
            $this->assetTag('theme:css', null),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame($ast->children, $out->children, '无 data-order 时不应改动 AST');
    }

    /**
     * 只有一个可排序标签时也是 no-op（没有可重排的兄弟）。
     */
    public function testNoOpWithSingleOrderedAsset(): void
    {
        $ast = new ProgramNode(1, [
            new TextNode(1, '<head>'),
            $this->assetTag('theme:css', '40'),
            new TextNode(1, '</head>'),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame($ast->children, $out->children);
    }

    /**
     * 相同 order 值按文档序稳定排列。
     */
    public function testStableForEqualOrder(): void
    {
        $ast = new ProgramNode(1, [
            $this->assetTag('css', '50'),
            $this->assetTag('js', '50'),
            $this->assetTag('theme:css', '50'),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame(['css', 'js', 'theme:css'], $this->names($out->children));
    }

    /**
     * 非资源标签带 data-order 不参与；资源标签值非数字不参与。
     */
    public function testIgnoresNonAssetTagsAndNonNumericOrder(): void
    {
        $ast = new ProgramNode(1, [
            $this->assetTag('js', '70'),
            new TagNode(1, 'title', [new AttrNode(1, 'data-order', '1', '1')]),
            $this->assetTag('css', 'abc'),
            $this->assetTag('theme:js', '10'),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame(
            ['theme:js', 'title', 'css', 'js'],
            $this->names($out->children),
            '仅 theme:js(10) 与 js(70) 参与排序，title 与非法值 css 原地不动'
        );
    }

    /**
     * 支持 order 简写与负数。
     */
    public function testAcceptsOrderAliasAndNegative(): void
    {
        $ast = new ProgramNode(1, [
            $this->assetTag('css', '10'),
            $this->assetTag('js', '-5', false, 'order'),
        ]);

        $out = (new HeadAssetOrderPass())->process($ast);

        self::assertSame(['js', 'css'], $this->names($out->children));
    }

    /**
     * 通道元数据与默认管道注册顺序（防止两处管道定义漂移）。
     */
    public function testPassMetadataAndDefaultPipelineRegistration(): void
    {
        $pass = new HeadAssetOrderPass();
        self::assertSame('head-asset-order', $pass->getName());
        self::assertSame(40, $pass->getPriority());

        $names = CompilePipeline::createDefault()->getPassNames();
        self::assertContains('head-asset-order', $names, '默认管道应注册 HeadAssetOrderPass');

        $deadCode = array_search('dead-code-elimination', $names, true);
        $order = array_search('head-asset-order', $names, true);
        $inline = array_search('inline-optimization', $names, true);
        self::assertLessThan($order, $deadCode, 'HeadAssetOrderPass 应在 DeadCodeElimination 之后');
        self::assertLessThan($inline, $order, 'HeadAssetOrderPass 应在 InlineOptimization 之前');
    }
}
