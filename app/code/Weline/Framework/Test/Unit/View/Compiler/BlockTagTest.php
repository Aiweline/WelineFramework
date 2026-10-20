<?php

declare(strict_types=1);

/**
 * `block` 标签 S1：每个形状都必须与旧 `Taglib::compile()` 逐字节相同。
 *
 * 重点钉住三件事（都是旧编译器的既成产物，不能“顺手优化”）：
 * 1. 成对空内容会多出 `'0' => ''`；
 * 2. 自闭合的 `$vars` 是**按引用**的变量名（`'model'=>&$model`），不是值；
 * 3. 成对但空内容 + 属性里有内联 `@lang` 时，旧 AST 走自闭合分支（多 `$vars`、少 `'0'`）。
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
use Weline\Framework\View\Compiler\Tag\Builtin\BlockTag;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Builtin\LangTag;
use Weline\Framework\View\Compiler\Tag\Builtin\UrlTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class BlockTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function shapes(): array
    {
        return [
            '自闭合无属性' => ['<div><block class="X"/></div>'],
            '自闭合带 vars' => ['<div><block class="X" vars="model"/></div>'],
            '自闭合 vars 多个' => ['<div><block class="X" vars="item, pageSize"/></div>'],
            '成对空内容' => ['<div><block class="X"></block></div>'],
            '成对空内容带 vars' => ['<div><block class="X" vars="req"></block></div>'],
            '成对带子内容' => ['<div><block class="X">A|B</block></div>'],
            '成对带 lang 属性（旧 AST 走自闭合）' => ['<div><block class="X" title="@lang(测试)"></block></div>'],
            '成对带 lang 属性且有子内容' => ['<div><block class="X" title="@lang(测试)">child</block></div>'],
            '内联 @block' => ['<div>@block(Weline\\Backend\\Block\\Version)</div>'],
            '内联 @block 带管道' => ['<div>@block(Weline\\Backend\\Block\\Version|cache=300)</div>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'block-tag-test.phtml');

        $registry = new Registry([BlockTag::descriptor(), LangTag::descriptor(), UrlTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['block', 'lang', 'url']));
        $v3 = (new Emitter())->emit(
            (new Lower($registry, null, new Interpolation(), $template))->lower($parser->parse($source))
        );

        self::assertSame($legacy, $v3);
    }

    public function testVarsArePassedByReference(): void
    {
        $v3 = $this->compileV3('<div><block class="X" vars="model"/></div>');
        self::assertStringContainsString("'model'=>&\$model,", $v3, 'vars 必须按引用传局部变量，不能按值搬');
    }

    public function testPairedEmptyBlockKeepsLegacyZeroKey(): void
    {
        $v3 = $this->compileV3('<div><block class="X"></block></div>');
        self::assertStringContainsString("'0' => ''", $v3, '成对空内容的多余 0 键是旧产物的一部分');
    }

    public function testInlineLangAttributeUsesAttributeMarkerMode(): void
    {
        $v3 = $this->compileV3('<div><block class="X" title="@lang(测试)"></block></div>');
        self::assertStringContainsString("InlinePhraseMarker::emit('测试', true)", $v3);
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([BlockTag::descriptor(), LangTag::descriptor(), UrlTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['block', 'lang', 'url']));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
