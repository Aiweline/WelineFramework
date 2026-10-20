<?php

declare(strict_types=1);

/**
 * 模块标签适配器契约测试。
 *
 * 用真实模块标签（`Weline_Acl` / `Weline_Meta`）+ 一个测试替身，
 * 钉住「声明 → 描述符」的翻译规则与形态判定。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Compiler\Tag\Adapter\CompiledTagRegistry;
use Weline\Framework\View\Compiler\Tag\Adapter\ModuleTagAdapter;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;

/**
 * 测试替身：成对 + 自闭合 + 两个属性（一个必填）。
 */
final class FakePairedTaglib implements TaglibInterface
{
    public static function name(): string
    {
        return 'fake-paired';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['src' => true, 'alt' => false];
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
        return static fn (): string => '';
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

final class ModuleTagAdapterTest extends TestCase
{
    public function testAdaptsMetadataFromDeclaration(): void
    {
        $descriptor = (new ModuleTagAdapter())->adapt(FakePairedTaglib::class);

        self::assertSame('fake-paired', $descriptor->name);
        self::assertTrue($descriptor->paired);
        self::assertTrue($descriptor->selfClosing);
        self::assertTrue($descriptor->elementForm);
        self::assertTrue($descriptor->inlineForm);
        self::assertSame(['src', 'alt'], \array_keys($descriptor->attributes));
        self::assertTrue($descriptor->attributes['src']->isRequired());
        self::assertFalse($descriptor->attributes['alt']->isRequired());
        self::assertTrue($descriptor->canLower(), 'lower 由 ModuleTagBridge 提供');
    }

    public function testRejectsNonTaglibClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModuleTagAdapter())->adapt(\stdClass::class);
    }

    public function testRealPairedModuleTag(): void
    {
        $descriptor = (new ModuleTagAdapter())->adapt(\Weline\Acl\Taglib\Acl::class);

        self::assertSame('acl', $descriptor->name);
        self::assertTrue($descriptor->elementForm, 'acl 声明了 tag()=true');
        self::assertArrayHasKey('source', $descriptor->attributes);
        self::assertTrue($descriptor->attributes['source']->isRequired());
    }

    public function testInlineOnlyModuleTagHasNoElementForm(): void
    {
        $descriptor = (new ModuleTagAdapter())->adapt(\Weline\Meta\Taglib\Meta::class);

        self::assertSame('meta', $descriptor->name);
        self::assertFalse($descriptor->elementForm, 'Meta 四项形态全假，只支持 @meta{...}');
        self::assertTrue($descriptor->inlineForm);
    }

    public function testBuildRegistryFromCompiledIndex(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP 未定义');
        }

        $registry = (new CompiledTagRegistry(BP . 'generated/taglibs.php'))->build();

        self::assertGreaterThan(60, $registry->count(), '编译索引里应有大量模块标签');
        self::assertTrue($registry->has('acl'));

        $matcher = new RegistryNameMatcher($registry, ['lang', 'if'], ['backend-url']);
        self::assertTrue($matcher->matchesElement('acl'));
        self::assertFalse($matcher->matchesElement('meta'), '<meta> 不是模板标签');
        self::assertTrue($matcher->matchesInline('meta'));
        self::assertTrue($matcher->matchesElement('lang'), '内置标签由 extras 注入');
        self::assertTrue($matcher->matchesInline('backend-url'));
        self::assertFalse($matcher->matchesElement('backend-url'));
    }
}
