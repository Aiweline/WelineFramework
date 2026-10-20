<?php

declare(strict_types=1);

/**
 * `admin-url`（B1）S1 对照。
 *
 * 钉住一个历史行为：admin-url 的元素与内联形态在旧实现里都调用 `getBackendUrl`，
 * 不是 `getAdminUrl`。按名字“修正”会让 S1 红，也会改变现有 URL。
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

final class AdminUrlTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '内联-带引号' => ['<a href="@admin-url{\'*/admin/index\'}">x</a>'],
            '内联-路径+参数' => ['<a href="@admin-url{\'*/admin/x\'|[\'id\'=>1]}">x</a>'],
            '元素-文本' => ["<div><admin-url>'*/admin/z'</admin-url></div>"],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'admin-url-test.phtml');

        self::assertSame($legacy, $this->compileV3($source), '新旧编译产物必须逐字节相同');
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([BackendUrlTag::descriptor('admin-url')]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['admin-url']));
        $statements = (new Lower($registry, null, new Interpolation()))->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
