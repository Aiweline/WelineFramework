<?php

declare(strict_types=1);

/**
 * `css` 标签 S1：元素 + 内联，属性顺序/单引号 href/固定 rel-type 必须与旧编译逐字节相同。
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
use Weline\Framework\View\Compiler\Tag\Builtin\CssTag;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class CssTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '元素' => ['<div><css>Weline_Theme::ui/weline-foundation.css</css></div>'],
            '元素-media' => ['<div><css media="screen">Weline_Theme::ui/weline-foundation.css</css></div>'],
            '内联' => ['<div>@css(Weline_Theme::ui/weline-foundation.css)</div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'css-tag-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([CssTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['css']));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
