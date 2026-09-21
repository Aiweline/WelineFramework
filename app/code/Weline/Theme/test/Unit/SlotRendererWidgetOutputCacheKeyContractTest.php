<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Widget process-output keys must not shard by product page path / path-bearing base URL.
 */
final class SlotRendererWidgetOutputCacheKeyContractTest extends TestCase
{
    public function testBuildWidgetOutputCacheKeyOmitsRequestPathAndGetBaseUrl(): void
    {
        $path = \dirname(__DIR__, 2) . '/Service/SlotRendererService.php';
        $src = \file_get_contents($path);
        self::assertIsString($src);

        $pos = \strpos($src, 'private function buildWidgetOutputCacheKey');
        self::assertNotFalse($pos);
        $body = \substr($src, $pos, 1200);

        self::assertStringContainsString("'area_route' => false", $body);
        self::assertStringContainsString('20260921-widget-origin-base', $body);
        self::assertStringNotContainsString('getRequest()->getBaseUrl()', $body);
        self::assertStringNotContainsString('getPathInfo()', $body);
        self::assertDoesNotMatchRegularExpression("/'path'\\s*=>/", $body);
    }
}
