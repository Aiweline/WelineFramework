<?php

declare(strict_types=1);

/**
 * `js` 标签 S1：元素 + 内联，属性顺序/布尔属性/单引号 src 必须与旧编译逐字节相同。
 *
 * 语料里有 2 个样本引用的静态文件本身不存在（旧链直接抛「资源不存在」），
 * `tpl-v3-tag-s1.php js` 会跳过并单列；这里只覆盖真实存在的静态资源。
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
use Weline\Framework\View\Compiler\Tag\Builtin\JsTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class JsTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '元素-defer' => ['<div><js defer>Weline_Framework::js/url-frontend.js</js></div>'],
            '元素-type-module' => ['<div><js type="module" async>Weline_Frontend::js/runtime.js</js></div>'],
            '元素-带 id' => ['<div><js id="app-js" defer>Weline_Framework::js/url-backend.js</js></div>'],
            '内联-圆括号' => ['<div>@js(Weline_Framework::js/url-frontend.js)</div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'js-tag-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([JsTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['js']));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
