<?php

declare(strict_types=1);

/**
 * `url`（B1）S1 对照：含编译期烘焙（`/en_US/x`）、`|` 参数、`path` 属性、元素与内联。
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
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Builtin\UrlTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class UrlTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '内联-纯路径' => ['<a href="@url{/x}">t</a>'],
            '内联-带参数' => ["<a href=\"@url{/x|['a'=>1]}\">t</a>"],
            '元素-子内容' => ['<div><url>/y</url></div>'],
            '元素-path 属性' => ['<div><url path="/z"/></div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'url-tag-test.phtml');

        $registry = new Registry([UrlTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['url']));
        $v3 = (new Emitter())->emit(
            (new Lower($registry, null, new Interpolation(), $template))->lower($parser->parse($source))
        );

        self::assertSame($legacy, $v3, '新旧编译产物必须逐字节相同');
    }
}
