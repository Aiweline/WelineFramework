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





    public function testRuntimeCleanerPurgesLayoutEntityProjectionPool(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeRuntimeCacheCleaner.php'
        );

        self::assertStringContainsString('purgeLayoutEntityPublishedProjectionHotCachePool', $src);
        self::assertStringContainsString('LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL', $src);
        self::assertStringContainsString('layout_entity_published_projection_hot_cache', $src);
    }


}
