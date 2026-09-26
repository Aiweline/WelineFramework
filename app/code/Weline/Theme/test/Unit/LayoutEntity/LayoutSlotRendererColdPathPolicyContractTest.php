<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * wave6-6s / wave7-7s：LayoutSlotRenderer 冷路径已发布 layout/slot/chrome 投影
 * CachePolicy；chrome 首冷 peek+disk（袋写延后）；槽投影同请求二次 HIT；
 * 禁平行 static；禁假 HIT / 删 Slot。
 */
final class LayoutSlotRendererColdPathPolicyContractTest extends TestCase
{
    public function testPointerResolverDropsParallelProcessStatic(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPointerResolver.php'
        );

        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringContainsString('pointerCachePolicy()', $src);
        self::assertStringNotContainsString('private static array $processCache', $src);
        self::assertStringNotContainsString('self::$processCache', $src);
        self::assertStringContainsString('StorefrontScopeHotCache::resetProcessCache', $src);
    }

    public function testChromeRenderedUsesPublishedPolicy(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php'
        );

        // wave7-7s: peek + durable disk on miss; rememberPolicy only post-response seed.
        self::assertStringContainsString('publishedChromeRenderedPolicy()', $src);
        self::assertStringContainsString('peekPolicy(', $src);
        self::assertStringContainsString('loadOrRenderPublished', $src);
        self::assertStringContainsString('queuePublishedChromePolicySeed', $src);
        self::assertStringContainsString('PostResponseTaskQueue::enqueue', $src);
        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringNotContainsString('private static', $src);

        $policy = StorefrontThemeCacheCoordinator::publishedChromeRenderedPolicy();
        self::assertSame('theme.layout_entity.chrome_rendered', $policy->resource);
        self::assertSame(
            StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            $policy->pool
        );
        self::assertSame('channel', $policy->scope);
        self::assertSame(['lang'], $policy->vary);
        self::assertContains('theme', $policy->dependencies);
    }

    public function testSlotFillerCachesChromeSlotAndPageLocationProjections(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );

        self::assertStringContainsString('rememberPublishedChromeSlotProjection', $src);
        self::assertStringContainsString('publishedChromeSlotProjectionPolicy()', $src);
        // v5 logical keys include ThemeVersionIdentity cache fragments (draft/formal/history).
        self::assertStringContainsString('chrome.slot.projection.v5|', $src);
        self::assertStringContainsString('chromeSlotProjectionLogicalKey', $src);
        self::assertStringContainsString('peekPolicy(', $src);
        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringContainsString('rememberPublishedPageEntityLocation', $src);
        self::assertStringContainsString('publishedPageEntityLocationPolicy()', $src);
        self::assertStringContainsString('page.location.v4|', $src);
        self::assertStringContainsString('buildChromeSlotProjection', $src);
        self::assertStringNotContainsString('private static array $', $src);

        $slotPolicy = StorefrontThemeCacheCoordinator::publishedChromeSlotProjectionPolicy();
        self::assertSame('theme.layout_entity.chrome_slot_projection', $slotPolicy->resource);
        self::assertSame('website', $slotPolicy->scope);
        self::assertSame(['lang'], $slotPolicy->vary);

        $pagePolicy = StorefrontThemeCacheCoordinator::publishedPageEntityLocationPolicy();
        self::assertSame('theme.layout_entity.page_location', $pagePolicy->resource);
        self::assertSame([], $pagePolicy->vary);
        self::assertSame(
            StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            $pagePolicy->pool
        );
    }

    public function testRuntimeCleanerPurgesLayoutEntityProjectionPool(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeRuntimeCacheCleaner.php'
        );

        self::assertStringContainsString('purgeLayoutEntityPublishedProjectionHotCachePool', $src);
        self::assertStringContainsString('LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL', $src);
        self::assertStringContainsString('layout_entity_published_projection_hot_cache', $src);
    }

    public function testLayoutSlotRendererStillHardCutsToEntityFiller(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('renderFromLayoutEntities', $src);
        self::assertStringContainsString('ThemeLayoutEntitySlotFiller::class', $src);
        self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_preview_mode')", $src);
        self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_editor_canvas')", $src);
        // wave8-8s2: published no-marker early return remains the zero-fill gate.
        self::assertStringContainsString('zero-runtime-fill', $src);
    }
}
