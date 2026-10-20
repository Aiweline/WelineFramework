<?php

declare(strict_types=1);

/**
 * Lower 接线测试：Registry 里有能 lower 的描述符就走新路径，没有就显式降级。
 *
 * 这里用真实链路（Parser → Lower → Emitter）跑一遍，而不是直接调描述符，
 * 为的是钉住「子节点先 Emit 成字符串再交给旧协议」这条语义。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Adapter\ModuleTagAdapter;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;

final class FakeWrapTaglib implements TaglibInterface
{
    public static function name(): string
    {
        return 'wrap';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return static function (string $tagKey, array $config, array $tagData, array $attributes): string {
            return '<wrap>' . ($tagData[2] ?? '') . '</wrap>';
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return '';
    }
}

final class LowerTest extends TestCase
{
    private function compile(string $source, Registry $registry, array $names): string
    {
        $document = (new Parser(new Lexer(), new StaticTagNameMatcher($names)))->parse($source);
        $statements = (new Lower($registry, null, new \Weline\Framework\View\Compiler\Tag\Builtin\Interpolation()))->lower($document);

        return (new Emitter())->emit($statements);
    }

    public function testRegisteredDescriptorLowersThroughLegacyCallback(): void
    {
        $registry = new Registry([(new ModuleTagAdapter())->adapt(FakeWrapTaglib::class)]);
        $output = $this->compile('<wrap>hi</wrap>', $registry, ['wrap']);

        self::assertSame('<wrap>hi</wrap>', $output);
    }

    public function testNestedChildrenAreEmittedBeforeCallback(): void
    {
        $registry = new Registry([(new ModuleTagAdapter())->adapt(FakeWrapTaglib::class)]);
        $output = $this->compile('<wrap>a<?php echo 1; ?>b</wrap>', $registry, ['wrap']);

        self::assertSame('<wrap>a<?php echo 1; ?>b</wrap>', $output);
    }

    public function testUnknownTagFallsBackToVerbatim(): void
    {
        $output = $this->compile('<other>x</other>', new Registry(), ['other']);

        self::assertSame('<other>x</other>', $output, '未注册标签必须原样保留，不能静默改写');
    }

    public function testInlineNodeStaysVerbatim(): void
    {
        $output = $this->compile('@lang{你好}', new Registry(), ['lang']);

        self::assertSame('@lang{你好}', $output);
    }
}
