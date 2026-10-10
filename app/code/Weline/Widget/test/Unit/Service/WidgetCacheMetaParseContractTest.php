<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Cache\WidgetOutputCache;
use Weline\Framework\View\Template;
use Weline\Widget\Service\WidgetTemplateParser;

final class WidgetCacheMetaParseContractTest extends TestCase
{
    public function testParserTreatsCacheValueAsTtlSecondsAndRejectsLegacy(): void
    {
        $parser = new WidgetTemplateParser(
            (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor(),
        );
        $method = new \ReflectionMethod(WidgetTemplateParser::class, 'normalizeCacheMeta');
        $method->setAccessible(true);

        $on = $method->invoke($parser, ['cache' => '300']);
        self::assertSame(300, $on['cache']);
        self::assertArrayNotHasKey('cache_ttl', $on);
        self::assertArrayNotHasKey('share', $on);

        $shared = $method->invoke($parser, ['cache' => '3600', 'share' => 'true']);
        self::assertSame(3600, $shared['cache']);
        self::assertTrue($shared['share']);

        $shareOnly = $method->invoke($parser, ['share' => true]);
        self::assertTrue($shareOnly['share']);
        self::assertArrayNotHasKey('cache', $shareOnly);

        $legacy = $method->invoke($parser, ['cache' => 'static']);
        self::assertArrayNotHasKey('cache', $legacy);

        $onWord = $method->invoke($parser, ['cache' => 'on']);
        self::assertArrayNotHasKey('cache', $onWord);

        $off = $method->invoke($parser, ['cache' => 'off']);
        self::assertArrayNotHasKey('cache', $off);

        $zero = $method->invoke($parser, ['cache' => 0]);
        self::assertArrayNotHasKey('cache', $zero);

        $poisoned = $method->invoke($parser, ['cache' => 60, 'cache_ttl' => 999]);
        self::assertSame(60, $poisoned['cache']);
        self::assertArrayNotHasKey('cache_ttl', $poisoned);

        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetTemplateParser.php',
        );
        self::assertStringContainsString('@widget.cache {300}', $src);
        self::assertStringContainsString('@widget.share', $src);
        self::assertStringNotContainsString('@widget.cache_ttl', $src);
        self::assertStringNotContainsString("token === 'static'", $src);
        self::assertStringContainsString('WidgetOutputCache::normalizeTtl', $src);
        self::assertSame(0, WidgetOutputCache::normalizeTtl('static'));
        self::assertSame(300, WidgetOutputCache::normalizeTtl(300));
    }

    public function testTaglibSpecCarriesCacheAndUsesSharedOutputCache(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/Widget.php',
        );
        self::assertStringContainsString("'cache' => \\Weline\\Widget\\Cache\\WidgetOutputCache::normalizeTtl", $src);
        self::assertStringContainsString('normalizeShare', $src);
        self::assertStringContainsString("'share'", $src);
        self::assertStringContainsString('WidgetOutputCache::remember', $src);
        self::assertStringNotContainsString('isCacheableWidgetHtml', $src);
        self::assertStringNotContainsString('function renderCache', $src);
        self::assertStringNotContainsString('function cacheRender', $src);
    }
}
