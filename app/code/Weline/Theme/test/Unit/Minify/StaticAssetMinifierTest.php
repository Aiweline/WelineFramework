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
