<?php

declare(strict_types=1);

/**
 * 标签描述符 / 注册表契约测试。
 *
 * 只钉结构性契约：别名解析、重复注册必须失败、`all()` 去重、属性类型工厂。
 * 标签的具体 lower 语义在 G-A 组落地时另建测试。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class TagRegistryTest extends TestCase
{
    private function descriptor(string $name, array $aliases = []): TagDescriptor
    {
        return new TagDescriptor(
            name: $name,
            aliases: $aliases,
            attributes: ['name' => AttributeSpec::literal(true, '组件名')],
            paired: false,
            selfClosing: true,
        );
    }

    public function testAliasResolvesToSameDescriptor(): void
    {
        $descriptor = $this->descriptor('lang', ['w:lang', 'trans']);
        $registry = new Registry([$descriptor]);

        self::assertTrue($registry->has('w:lang'));
        self::assertTrue($registry->has('TRANS'));
        self::assertSame($descriptor, $registry->get('Lang'));
        self::assertNull($registry->get('unknown'));
        self::assertSame(1, $registry->count(), 'all() 必须按描述符去重');
        self::assertSame(['lang', 'w:lang', 'trans'], $descriptor->allNames());
    }

    public function testDuplicateNameThrows(): void
    {
        $registry = new Registry([$this->descriptor('lang')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/标签名冲突/');
        $registry->register($this->descriptor('Lang'));
    }

    public function testDuplicateAliasThrows(): void
    {
        $registry = new Registry([$this->descriptor('lang', ['w:lang'])]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register($this->descriptor('translate', ['w:lang']));
    }

    public function testAttributeSpecFactories(): void
    {
        self::assertSame(AttributeSpec::LITERAL, AttributeSpec::literal()->type);
        self::assertTrue(AttributeSpec::expr(true)->isRequired());
        self::assertSame(['a', 'b'], AttributeSpec::enum(['a', 'b'])->enum);
        self::assertFalse(AttributeSpec::any()->isRequired());
    }

    public function testDescriptorReportsLowerAvailability(): void
    {
        $withoutLower = $this->descriptor('noop');
        self::assertFalse($withoutLower->canLower());

        $withLower = new TagDescriptor(
            name: 'if',
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_COMPILE,
            lower: static fn (\Weline\Framework\View\Compiler\Parser\TagNode $node, mixed $ctx): array => [],
        );
        self::assertTrue($withLower->canLower());
        self::assertTrue($withLower->answersTo('IF'));
    }
}
