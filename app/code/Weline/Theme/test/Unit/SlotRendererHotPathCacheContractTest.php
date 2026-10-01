<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * SlotRenderer is a slot fill orchestrator — no layout/widget process L1.
 * Published structure HotCache lives on ThemeRuntimeLayoutResolver (read-model).
 */
final class SlotRendererHotPathCacheContractTest extends TestCase
{
    public function testSlotRendererHasNoLayoutOrWidgetProcessCaches(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/SlotRendererService.php');
        self::assertStringNotContainsString('private array $layoutCache', $src);
        self::assertStringNotContainsString('private array $widgetCache', $src);
        self::assertStringNotContainsString('$publishedLayoutDataCache', $src);
        self::assertStringNotContainsString('$widgetOutputCache', $src);
        self::assertStringNotContainsString('CACHEABLE_WIDGET_OUTPUTS', $src);
        self::assertStringNotContainsString('runtimeCacheGet', $src);
        self::assertStringNotContainsString('buildWidgetOutputCacheKey', $src);
        self::assertStringNotContainsString('rememberWidgetOutput', $src);
        self::assertStringNotContainsString('StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy()', $src);
        self::assertStringContainsString('No request/process layout memo on SlotRenderer', $src);
        self::assertStringContainsString('function purgeRuntimeCacheNamespace(): void', $src);
        self::assertStringContainsString("clearNamespace('theme_runtime')", $src);
    }

    public function testPublishedLayoutStructureHotCacheLivesOnRuntimeResolver(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/ThemeRuntimeLayoutResolver.php');
        self::assertStringContainsString('StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy()', $src);
        self::assertStringContainsString('publishedLayoutStructureLogicalKey(', $src);
        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringContainsString('pub_layout|', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('hasTargetIdentity', $src);

        $policy = StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy();
        self::assertSame('theme.layout.published', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::PUBLISHED_LAYOUT_STRUCTURE_POOL, $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertSame(['theme'], $policy->dependencies);
        self::assertSame(0, $policy->staleTtlSeconds);
    }
}
