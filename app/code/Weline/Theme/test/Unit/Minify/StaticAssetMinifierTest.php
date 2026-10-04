<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Minify;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Minify\Css\CssMin;
use Weline\Theme\Minify\Js\JsMin;
use Weline\Theme\Minify\StaticAssetMinifier;

final class StaticAssetMinifierTest extends TestCase
{
    public function testCssMinStripsCommentsAndCollapsesWhitespace(): void
    {
        $css = "/* comment */\n.body  {\n  color:  #fff;\n}\n";
        $min = CssMin::minify($css);
        self::assertStringNotContainsString('comment', $min);
        self::assertStringNotContainsString("\n", $min);
        self::assertStringContainsString('color:#fff', $min);
        self::assertLessThan(strlen($css), strlen($min));
    }

    public function testCssMinPreservesStringsAndUrls(): void
    {
        $css = '.x{content:"a  b";background:url( "img.png" )}';
        $min = CssMin::minify($css);
        self::assertStringContainsString('"a  b"', $min);
        self::assertStringContainsString('url( "img.png" )', $min);
    }

    public function testJsMinRemovesWhitespaceAndComments(): void
    {
        $js = "function  hello ( ) {\n  // line\n  return  1 + 2;\n}\n";
        $min = JsMin::minify($js);
        self::assertStringNotContainsString('// line', $min);
        self::assertLessThan(strlen($js), strlen($min));
        self::assertStringContainsString('return', $min);
    }

    public function testCssMathKeepsRequiredWhitespaceAroundAddition(): void
    {
        $css = '.card { width: calc(100% + 2px); z-index: calc(var(--overlay, 900) + 1); padding: calc(1rem + env(safe-area-inset-bottom, 0px)); margin: calc(var(--gap) - (2px)); }';
        $min = (new StaticAssetMinifier())->minifyFileContent($css, 'widget.css');
        self::assertStringContainsString('calc(100% + 2px)', $min);
        self::assertStringContainsString('calc(var(--overlay,900) + 1)', $min);
        self::assertStringContainsString('calc(1rem + env(safe-area-inset-bottom,0px))', $min);
        self::assertStringContainsString('calc(var(--gap) - (2px))', $min);
        self::assertLessThan(strlen($css), strlen($min));
    }

    public function testTemplateLiteralsRetainTheirExactCookedAndRawText(): void
    {
        // The legacy scanner cannot distinguish a nested template from the end of its parent.
        $sources = [
            'const x = `outer ${`inner  text`} tail`;',
            'const x = String.raw`outer ${String.raw`a  b\\n`} tail`;',
            "const x = `first\n  second`;\n",
        ];
        $minifier = new StaticAssetMinifier();
        foreach ($sources as $source) {
            self::assertSame($source, JsMin::minify($source));
            self::assertSame($source, $minifier->minifyFileContent($source, 'widget.js'));
            self::assertSame($source, $minifier->minifyFileContent($source, 'widget.mjs'));
        }
    }

    public function testCssMinKeepsWhitespaceBetweenAdjacentFunctionsInValueList(): void
    {
        // 相邻 minmax() 之间的空白是 track-list 的分隔，不是可丢填充物。
        $css = '.g{grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);}'
            . '.t{transform: translate(1px, 2px) rotate(45deg);}'
            . '.b{background: url(a.png) no-repeat center;}';
        $min = CssMin::minify($css);
        self::assertStringContainsString('minmax(0,1fr) minmax(0,1fr)', $min);
        self::assertStringContainsString('translate(1px,2px) rotate(45deg)', $min);
        self::assertStringContainsString('url(a.png) no-repeat center', $min);
        self::assertStringNotContainsString(')minmax(', $min);
        self::assertStringNotContainsString(')rotate(', $min);
        self::assertStringNotContainsString(')no-repeat', $min);
    }

    public function testCssMinKeepsDescendantCombinatorAfterPseudoClassAndAttribute(): void
    {
        // `:not(.x) .body` / `[data-x] .item` 的后代空白被吞会变成复合选择器，命中集改变。
        $css = '.card:not(.x) .body{color:red}'
            . '[data-x="1"] .item{color:red}'
            . '.a :hover{color:red}'
            . '.a > .b{color:red}';
        $min = CssMin::minify($css);
        self::assertStringContainsString('.card:not(.x) .body{color:red}', $min);
        self::assertStringContainsString('[data-x="1"] .item{color:red}', $min);
        self::assertStringContainsString('.a :hover{color:red}', $min);
        // 组合符两侧的空白仍然是冗余的，必须继续收敛。
        self::assertStringContainsString('.a>.b{color:red}', $min);
    }

    public function testCssMinKeepsMediaQueryLogicalKeywordSeparated(): void
    {
        // `and(` 会被词法分析成函数 token，整条 @media 失效（Chromium 报 conditionText="not all"）。
        $css = '@media screen and (min-width: 40em) and (max-width: 60em){a{color:red}}';
        $min = CssMin::minify($css);
        self::assertStringContainsString('and (min-width:40em)', $min);
        self::assertStringContainsString('and (max-width:60em)', $min);
        self::assertStringNotContainsString('and(', $min);
        self::assertStringNotContainsString('or(', $min);
    }

    public function testCssMinStillCollapsesRedundantWhitespaceAroundStructuralDelimiters(): void
    {
        // 保守化不能退化成"完全不压缩"：结构分隔符两侧的空白仍须收敛。
        $css = 'a , b { color: red ; margin: 0 auto ; }';
        $min = CssMin::minify($css);
        self::assertSame('a,b{color:red;margin:0 auto;}', $min);
    }

    public function testShouldMinifySkipsAlreadyMinFiles(): void
    {
        $minifier = new StaticAssetMinifier();
        self::assertTrue($minifier->shouldMinify('/a/app.js'));
        self::assertTrue($minifier->shouldMinify('/a/style.css'));
        self::assertFalse($minifier->shouldMinify('/a/app.min.js'));
        self::assertFalse($minifier->shouldMinify('/a/style.min.css'));
        self::assertFalse($minifier->shouldMinify('/a/logo.png'));
    }

    public function testMinifyFileContentDispatchesByExtension(): void
    {
        $minifier = new StaticAssetMinifier();
        $css = $minifier->minifyFileContent("a { color: red; }\n", 'x.css');
        self::assertStringContainsString('color:red', $css);
        $js = $minifier->minifyFileContent("var  a = 1;\n", 'x.js');
        self::assertLessThan(strlen("var  a = 1;\n"), strlen($js));
    }
}
