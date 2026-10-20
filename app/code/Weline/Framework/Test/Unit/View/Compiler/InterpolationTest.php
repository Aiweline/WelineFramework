<?php

declare(strict_types=1);

/**
 * 插值层（`{{expr}}` 与 `<var>`）的 S1 对照：产物必须与旧 `Taglib::compile()` 逐字节相同。
 *
 * 这两层是 `lang` 与控制流语料 S1 的共同前置，语义差异（`{{x}}` 有 `?? getData` 兜底、
 * `<var>` 没有）必须被钉住，否则会在真实模板的边界值上出问题。
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
use Weline\Framework\View\Compiler\Tag\Builtin\VarTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class InterpolationTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '简单变量' => ['<div>{{title}}</div>'],
            '点分路径' => ['<div>{{ group_set.name }}</div>'],
            '已有 $ 的点分' => ['<div>{{$user.name}}</div>'],
            '混合文本' => ['<div>A {{x}} B {{y.z}} C</div>'],
            'var 标签' => ['<div><var>title</var></div>'],
            'var 带 $' => ['<div><var>$title</var></div>'],
            // 单竖线是默认值回退（旧 parseVariablePath），不是按位或。
            '管道数字默认值' => ['<div>{{attribute.type_id|0}}</div>'],
            '管道裸标识默认值' => ['<div>{{attribute.compare_mode|none}}</div>'],
            '管道字符串默认值' => ['<div>{{attribute.progress|"progress-select-entity"}}</div>'],
            '管道路径默认值' => ['<div>{{group.entity_local_name|group.entity_name}}</div>'],
            '管道已有 $ 默认值' => ['<div>{{$theme_preference|default:"system"}}</div>'],
            '管道 default 过滤器' => ['<div>{{x|default:0}}</div>'],
            // 双竖线是逻辑或，旧实现不拆；必须保持同样的怪癖。
            '双竖线不拆分' => ['<div>{{x||y}}</div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'interpolation-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([VarTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['var']));
        $statements = (new Lower($registry, null, new Interpolation()))->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
