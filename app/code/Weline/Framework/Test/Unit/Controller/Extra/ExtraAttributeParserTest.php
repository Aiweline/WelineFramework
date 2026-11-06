<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Extra\ExtraAttributeParser;
use Weline\Framework\Controller\Extra\ExtraCollector;
use Weline\Framework\Extends\Module\Weline_Framework\Extra\Type\FpcExtraType;

/**
 * `@Extra` 属性值来自 docblock 文本，永远是字符串；用 PHP 的 `(bool)` 直接转换会把
 * `'false'` / `'off'` / `'no'` 判成 true（`(bool)'false' === true`），
 * 导致 `@Extra type=fpc enabled=false` 静默变成「允许缓存」。
 *
 * 这里把「字符串 → bool/int/list」的唯一口径钉住：解析器是唯一实现，
 * `ExtraCollector::parseExtraDoc()` 与 `FpcExtraType::normalize()` 都必须调用它。
 */
final class ExtraAttributeParserTest extends TestCase
{
    public function testToBoolRejectsPhpTruthyFalseWords(): void
    {
        foreach (['false', 'FALSE', ' False ', 'off', 'OFF', 'no', 'n', '0', '0.0', '00', ''] as $raw) {
            self::assertFalse(ExtraAttributeParser::toBool($raw), '字符串 "' . $raw . '" 必须为假');
        }
        foreach ([false, null, 0, 0.0] as $raw) {
            self::assertFalse(ExtraAttributeParser::toBool($raw));
        }
    }

    public function testToBoolAcceptsTruthyForms(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', ' On '] as $raw) {
            self::assertTrue(ExtraAttributeParser::toBool($raw), '字符串 "' . $raw . '" 必须为真');
        }
        foreach ([true, 1, 2, -1, 0.5] as $raw) {
            self::assertTrue(ExtraAttributeParser::toBool($raw));
        }
    }

    /**
     * 非标量输入不得抛异常（构件侧车时一个坏注解不该阻断整个 setup:upgrade），
     * 但必须 fail-closed 到假。
     */
    public function testToBoolFailsClosedOnNonScalar(): void
    {
        self::assertFalse(ExtraAttributeParser::toBool([]));
        self::assertFalse(ExtraAttributeParser::toBool(['true']));
        self::assertFalse(ExtraAttributeParser::toBool(new \stdClass()));
    }

    public function testToIntFallsBackWhenNotNumeric(): void
    {
        self::assertSame(10, ExtraAttributeParser::toInt('10', 10));
        self::assertSame(12, ExtraAttributeParser::toInt(' 12 ', 10));
        self::assertSame(10, ExtraAttributeParser::toInt('abc', 10));
        self::assertSame(10, ExtraAttributeParser::toInt('', 10));
        self::assertSame(10, ExtraAttributeParser::toInt(null, 10));
        self::assertSame(10, ExtraAttributeParser::toInt([], 10));
        self::assertSame(10, ExtraAttributeParser::toInt('0x1A', 10));
        self::assertSame(3, ExtraAttributeParser::toInt('3.9', 0));
        self::assertSame(0, ExtraAttributeParser::toInt('0', 10));
        self::assertSame(-5, ExtraAttributeParser::toInt('-5', 10));
    }

    public function testToStringListTrimsAndDropsEmptyEntries(): void
    {
        self::assertSame(['a', 'b', 'c'], ExtraAttributeParser::toStringList('a, b ,,c'));
        self::assertSame(['x', 'y'], ExtraAttributeParser::toStringList([' x ', 'y']));
        self::assertSame([], ExtraAttributeParser::toStringList(''));
        self::assertSame([], ExtraAttributeParser::toStringList(' , , '));
        self::assertSame([], ExtraAttributeParser::toStringList(null));
        self::assertSame([], ExtraAttributeParser::toStringList([]));
    }

    /**
     * 数组入参逐项处理、不整体 explode：`public_path_patterns` 可能含正则量词 `{1,3}`，
     * 被逗号拆开就会静默改写成另一条 pattern。
     */
    public function testToStringListDoesNotSplitLeadingCommaInsideArrayEntries(): void
    {
        self::assertSame(
            ['/blog/[0-9]{1,3}'],
            ExtraAttributeParser::toStringList(['/blog/[0-9]{1,3}']),
        );
        self::assertSame(['123'], ExtraAttributeParser::toStringList(123));
        self::assertSame(['a'], ExtraAttributeParser::toStringList(['a', ['nested'], null, '']));
    }

    /**
     * 回归本体：`enabled=false` 必须是 false。修复前 `(bool)'false'` 返回 true，
     * 于是禁用声明被当成允许缓存。
     */
    public function testFpcExtraTypeHonoursStringFalse(): void
    {
        $n = (new FpcExtraType())->normalize(['type' => 'fpc', 'enabled' => 'false']);
        self::assertFalse($n['enabled']);

        $n = (new FpcExtraType())->normalize(['type' => 'fpc', 'enabled' => '0']);
        self::assertFalse($n['enabled']);

        $n = (new FpcExtraType())->normalize(['type' => 'fpc', 'enabled' => 'true']);
        self::assertTrue($n['enabled']);

        // 缺省仍为允许（默认 allow 口径不变）。
        self::assertTrue((new FpcExtraType())->normalize(['type' => 'fpc'])['enabled']);
    }

    public function testFpcExtraTypeNormalizesCommaStringLists(): void
    {
        $n = (new FpcExtraType())->normalize([
            'type' => 'fpc',
            'namespaces' => 'website/default/theme, global/storefront/blog',
            'public_path_patterns' => '/blog,/blog/*',
            'query_keys' => 'lang, page',
        ]);
        self::assertSame(['website/default/theme', 'global/storefront/blog'], $n['namespaces']);
        self::assertSame(['/blog', '/blog/*'], $n['public_path_patterns']);
        self::assertSame(['lang', 'page'], $n['query_keys']);
    }

    public function testFpcExtraTypeTtlIsZeroWhenAbsentButNegativeClamped(): void
    {
        self::assertSame(31536000, (new FpcExtraType())->normalize(['type' => 'fpc'])['ttl']);
        self::assertSame(3600, (new FpcExtraType())->normalize(['type' => 'fpc', 'ttl' => '3600'])['ttl']);
        self::assertSame(0, (new FpcExtraType())->normalize(['type' => 'fpc', 'ttl' => '-9'])['ttl']);
        self::assertSame(0, (new FpcExtraType())->normalize(['type' => 'fpc', 'ttl' => 'abc'])['ttl']);
    }

    /**
     * 注解文本解析与类型归一必须同口径：这是把「改前改后侧车零差异」从推断变成可执行证据的钉子。
     * 不走 ObjectManager、不碰数据库——只把 `parseExtraDoc()` 当纯函数调。
     */
    public function testParseExtraDocUsesSameContractAsNormalize(): void
    {
        $ref = new \ReflectionClass(ExtraCollector::class);
        $collector = $ref->newInstanceWithoutConstructor();
        $parse = $ref->getMethod('parseExtraDoc');
        $parse->setAccessible(true);

        $get = fn (string $raw): array => $parse->invoke($collector, " * @Extra " . $raw . "\n */");

        self::assertSame(['type' => 'fpc', 'enabled' => false], $get('type=fpc enabled=false'));
        self::assertSame(['type' => 'fpc', 'enabled' => false], $get('type=fpc enabled=off'));
        self::assertSame(['type' => 'fpc', 'enabled' => true], $get('type=fpc enabled=true'));
        self::assertSame(['type' => 'fpc', 'enabled' => false], $get('type=fpc enabled="false"'));
        self::assertSame(['type' => 'fpc', 'ttl' => 3600], $get('type=fpc ttl=3600'));
        self::assertSame(
            ['type' => 'fpc', 'public_path_patterns' => ['/blog', '/blog/*']],
            $get('type=fpc public_path_patterns=/blog,/blog/*'),
        );
        self::assertSame(
            ['type' => 'fpc', 'namespaces' => ['website/default/theme', 'global/storefront/blog']],
            $get('type=fpc namespaces=website/default/theme,global/storefront/blog'),
        );
    }
}
