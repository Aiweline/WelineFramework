<?php

declare(strict_types=1);

/**
 * `static`（B1）S1 对照：编译期取源产物必须与旧 `Taglib::compile()` 逐字节相同。
 *
 * 这是第一个需要只读 `Template` 注入的标签；没有注入时 Lower 会显式抛错，
 * 不允许静默降级（否则产物会变成未解析的原始路径）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Builtin\StaticTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class StaticTagTest extends TestCase
{
    private const PATH = 'Weline_Promotion::css/promotion-hub.css';

    public function testInlineMatchesLegacy(): void
    {
        $this->assertMatchesLegacy('<div>@static(' . self::PATH . ')</div>');
    }

    public function testElementMatchesLegacy(): void
    {
        $this->assertMatchesLegacy('<div><static>' . self::PATH . '</static></div>');
    }

    private function assertMatchesLegacy(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'static-tag-test.phtml');

        $registry = new Registry([StaticTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['static']));
        $statements = (new Lower($registry, null, new Interpolation(), $template))->lower($parser->parse($source));
        $v3 = (new Emitter())->emit($statements);

        self::assertSame($legacy, $v3, '新旧编译产物必须逐字节相同');
    }
}
