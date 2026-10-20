<?php

declare(strict_types=1);

/**
 * 模块标签回调桥契约测试。
 *
 * 用三个替身覆盖旧协议里最关键的三种返回形态：
 * - 编译期直接返回 HTML（Acl 这类）；
 * - 返回 PHP 源码（多数模块标签）；
 * - 成对标签分 `tag-start` / `tag-end` 两次调用再拼接。
 *
 * 同时钉住 `$tagKey` 的判定顺序（self-close-with-attrs → self-close → tag → tag-start）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Compiler\Lexer\Span;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\Adapter\ModuleTagBridge;

final class FakeHtmlTaglib implements TaglibInterface
{
    public static function name(): string
    {
        return 'fake-html';
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
            return '<section>' . ($tagData[2] ?? '') . '</section>';
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

final class FakeStartEndTaglib implements TaglibInterface
{
    public static function name(): string
    {
        return 'fake-start-end';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [];
    }

    public static function tag_start(): bool
    {
        return true;
    }

    public static function tag_end(): bool
    {
        return true;
    }

    public static function callback(): callable
    {
        return static function (string $tagKey, array $config, array $tagData, array $attributes): string {
            return $tagKey === 'tag-start' ? '<div>' : '</div>';
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

final class FakeSelfCloseTaglib implements TaglibInterface
{
    /** @var list<string> */
    public static array $seenTagKeys = [];

    public static function name(): string
    {
        return 'fake-self-close';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['src' => false];
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
            self::$seenTagKeys[] = $tagKey;

            return 'PHP-SOURCE:' . $tagKey;
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
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

final class ModuleTagBridgeTest extends TestCase
{
    private function tagNode(string $name, string $openRaw, string $attributesRaw, bool $selfClosing): TagNode
    {
        return new TagNode(
            $name,
            $attributesRaw,
            $attributesRaw === '' ? [] : ['src' => 'x'],
            $selfClosing,
            !$selfClosing,
            [],
            $openRaw,
            new Span(0, \strlen($openRaw), 1, 1),
            $openRaw,
        );
    }

    public function testCompileTimeHtmlIsPassedThroughVerbatim(): void
    {
        $node = $this->tagNode('fake-html', '<fake-html>', '', false);
        $stmt = (new ModuleTagBridge(FakeHtmlTaglib::class))->lower($node, 'inner');

        self::assertSame('<section>inner</section>', $stmt->code());
    }

    public function testStartEndPairIsConcatenated(): void
    {
        $node = $this->tagNode('fake-start-end', '<fake-start-end>', '', false);
        $stmt = (new ModuleTagBridge(FakeStartEndTaglib::class))->lower($node, 'inner');

        self::assertSame('<div>inner</div>', $stmt->code());
    }

    public function testTagKeyResolutionMatchesLegacyOrder(): void
    {
        FakeSelfCloseTaglib::$seenTagKeys = [];

        $withAttrs = $this->tagNode('fake-self-close', '<fake-self-close src="x"/>', 'src="x"', true);
        (new ModuleTagBridge(FakeSelfCloseTaglib::class))->lower($withAttrs, '');

        $withoutAttrs = $this->tagNode('fake-self-close', '<fake-self-close/>', '', true);
        (new ModuleTagBridge(FakeSelfCloseTaglib::class))->lower($withoutAttrs, '');

        self::assertSame(['tag-self-close-with-attrs', 'tag-self-close'], FakeSelfCloseTaglib::$seenTagKeys);
    }

    public function testTagDataCarriesOpenTagAndAttributes(): void
    {
        $node = $this->tagNode('fake-self-close', '<fake-self-close src="x"/>', 'src="x"', true);
        $stmt = (new ModuleTagBridge(FakeSelfCloseTaglib::class))->lower($node, 'ignored');

        self::assertSame('PHP-SOURCE:tag-self-close-with-attrs', $stmt->code());
    }
}
