<?php

declare(strict_types=1);

/**
 * `template` / `@template` S1：
 * - 元素形态是 include 语句；
 * - 内联形态是 `file_get_contents()` 已编译子模板（会带子模板自己的 compile_id/hash 头）。
 *
 * 这两种产物不同，必须分别钉住，不能用一个实现“统一优化”。
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
use Weline\Framework\View\Compiler\Tag\Builtin\TemplateTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Taglib\TemplateTranslationWords;
use Weline\Framework\View\Template;

final class TemplateTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '元素-子内容路径' => ['<div><template>Weline_Admin::common/head.phtml</template></div>'],
            '元素-name 自闭合' => ['<div><w:template name="Weline_Admin::common/head.phtml"/></div>'],
            '内联' => ['<div>@template(Weline_Admin::common/head.phtml)</div>'],
            '元素-缺路径' => ['<div><template></template></div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'template-tag-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([TemplateTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['template']));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return TemplateTranslationWords::withRuntimePrefetch((new Emitter())->emit($statements));
    }
}
