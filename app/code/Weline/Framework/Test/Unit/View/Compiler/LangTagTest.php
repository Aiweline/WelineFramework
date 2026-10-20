<?php

declare(strict_types=1);

/**
 * `lang` 转正比对（S1 的源码级等价证明）。
 *
 * 判据不是「我觉得像」，而是：**同一段模板，旧 `Taglib::compile()` 与新管线 Emit
 * 出来的字节完全相同**。旧编译器在迁移期仍是唯一真相，所以这是最硬的比对。
 *
 * 覆盖元素形态（含 `args` 属性、空内容、自闭合）与内联形态（裸文本、加引号、
 * PHP 表达式、逗号分隔参数），这些分支正好对应旧回调里的每一段判断。
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
use Weline\Framework\View\Compiler\Tag\Builtin\LangTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Taglib\TemplateTranslationWords;
use Weline\Framework\View\Template;

final class LangTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '元素-纯文本' => ['<div><lang>你好</lang></div>'],
            '元素-首尾空白' => ["<div><lang>\n  hello world  \n</lang></div>"],
            '元素-args' => ['<div><lang args="\'Weline\'">你好 %s</lang></div>'],
            '元素-空内容' => ['<div><lang></lang></div>'],
            '元素-自闭合' => ['<div><lang/></div>'],
            '内联-花括号' => ['<div>@lang{你好}</div>'],
            '内联-圆括号' => ['<div>@lang(你好)</div>'],
            '内联-双引号' => ['<div>@lang("hello world")</div>'],
            '内联-PHP 表达式' => ['<div>@lang($word)</div>'],
            '内联-逗号参数' => ['<div>@lang(你好, \'Weline\')</div>'],
            '内联-函数调用' => ['<div>@lang(get_word())</div>'],
            // 旧编译器会消费成对引号并切到属性标记模式（JS 字符串里也会发生）。
            '内联-单引号包裹' => ["<div>'@lang(保存)'</div>"],
            '内联-双引号包裹' => ['<div>"@lang(保存)"</div>'],
            '内联-引号包裹+逗号参数' => ["<div>'@lang(保存, 1)'</div>"],
            '内联-引号包裹+PHP 表达式' => ["<div>'@lang(\$x)'</div>"],
            '内联-引号内混合文本不消费' => ["<div>'前缀@lang(保存)后缀'</div>"],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'lang-tag-test.phtml');

        self::assertSame($legacy, $this->compileV3($source), '新旧编译产物必须逐字节相同');
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([LangTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['lang']));
        $statements = (new Lower($registry, null, new \Weline\Framework\View\Compiler\Tag\Builtin\Interpolation()))->lower($parser->parse($source));
        $emitted = (new Emitter())->emit($statements);

        // 词条预取是**产物级后处理**（旧编译器同样在产物上做），不属于 LangTag 的职责：
        // 复用同一个 helper，保证两边得到完全相同的字节。
        return TemplateTranslationWords::withRuntimePrefetch($emitted);
    }
}
