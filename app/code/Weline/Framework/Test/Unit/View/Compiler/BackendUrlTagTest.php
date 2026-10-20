<?php

declare(strict_types=1);

/**
 * `backend-url`（B1）S1 对照：产物必须与旧 `Taglib::compile()` 逐字节相同。
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
use Weline\Framework\View\Compiler\Tag\Builtin\BackendUrlTag;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class BackendUrlTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '内联-带引号' => ["<a href=\"@backend-url{'*/backend/index'}\">x</a>"],
            '内联-路径+参数' => ["<a href=\"@backend-url{'*/backend/x'|['id'=>1]}\">x</a>"],
            '内联-圆括号' => ["<a href=\"@backend-url('*/backend/y')\">x</a>"],
            '元素-文本' => ["<div><backend-url>'*/backend/z'</backend-url></div>"],
            '元素-路径+参数' => ["<div><backend-url>'*/backend/z'|['a'=>2]</backend-url></div>"],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'backend-url-test.phtml');

        self::assertSame($legacy, $this->compileV3($source), '新旧编译产物必须逐字节相同');
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([BackendUrlTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['backend-url']));
        $statements = (new Lower($registry, null, new Interpolation()))->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
