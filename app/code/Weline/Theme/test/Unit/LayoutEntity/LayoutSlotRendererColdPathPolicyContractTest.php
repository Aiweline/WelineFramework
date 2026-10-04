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
    public function testRetiredPointersCannotReadDependenciesOrReuseStaleCache(): void
    {
        $resolver = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver::class))
            ->newInstanceWithoutConstructor();
        $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
            1, 'default.default.default', 'normal', 'frontend', 1, 'formal', 1,
        );
        // Any dependency access fails: retired resolution must not consult
        // database versions, bindings, structure paths, or cached pointers.
        self::assertNull($resolver->resolvePublishedChrome(1, 'default.default.default'));
        self::assertNull($resolver->resolveCurrentChrome(1, 'default.default.default'));
        self::assertNull($resolver->resolvePageEntity($identity, \str_repeat('a', 64), \str_repeat('b', 64)));
        $runtime = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRuntime::class))
            ->newInstanceWithoutConstructor();
        self::assertNull($runtime->tryResolvePageLayoutPath(1, 'default.default.default', \str_repeat('a', 64), \str_repeat('b', 64)));
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
