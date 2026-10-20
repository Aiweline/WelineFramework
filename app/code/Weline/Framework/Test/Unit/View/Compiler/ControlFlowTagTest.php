<?php

declare(strict_types=1);

/**
 * 控制流标签（P2）的 S1 对照测试：产物必须与旧 `Taglib::compile()` 逐字节相同。
 *
 * 覆盖 `CodeGenerator` 里的每条分支：condition/value/子内容/默认 true、
 * else/elseif 混排、自闭合、以及 foreach 的 items+as(+key)、name+item(+key)、
 * value、子内容、空表达式六种取值路径。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\ControlFlowTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class ControlFlowTagTest extends TestCase
{
    private const NAMES = ['if', 'elseif', 'else', 'foreach'];

    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            'if-condition 变量' => ['<div><if condition="$x">A</if></div>'],
            'if-condition 裸标识' => ['<div><if condition="content">A</if></div>'],
            'if-condition 点分路径' => ['<div><if condition="meta.showHeader">A</if></div>'],
            'if-value 属性' => ['<div><if value="user.role === \'admin\'">A</if></div>'],
            'if-无条件' => ['<div><if>A</if></div>'],
            'if-自闭合' => ['<div><if condition="$x"/></div>'],
            'if-else' => ['<div><if condition="$x">A<else/>B</if></div>'],
            'if-elseif-else' => ['<div><if condition="$x">A<elseif condition="$y"/>B<else/>C</if></div>'],
            'foreach-items-as' => ['<div><foreach items="$items" as="$item">x</foreach></div>'],
            'foreach-items-as-key' => ['<div><foreach items="$items" as="item" key="k">x</foreach></div>'],
            'foreach-name-item' => ['<div><foreach name="items" item="item">x</foreach></div>'],
            'foreach-name-item-key' => ['<div><foreach name="items" item="item" key="k">x</foreach></div>'],
            'foreach-value' => ['<div><foreach value="$items as $item">x</foreach></div>'],
            'foreach-空表达式' => ['<div><foreach>x</foreach></div>'],
            'foreach-自闭合' => ['<div><foreach items="$items" as="$item"/></div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'control-flow-test.phtml');

        self::assertSame($legacy, $this->compileV3($source), '新旧编译产物必须逐字节相同');
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry(ControlFlowTag::descriptors());
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(self::NAMES));
        $statements = (new Lower($registry, null, new \Weline\Framework\View\Compiler\Tag\Builtin\Interpolation()))->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
