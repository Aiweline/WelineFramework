<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\WidgetAssetSourceCacheWarmer;

/** Guard: Clear warmUp projection pool + Theme provides widget asset source warmer. */
final class WidgetAssetSourceCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesWidgetAssetSourceWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            WidgetAssetSourceCacheWarmer::class,
            $provides['cache.warmer.theme.widget_asset_sources'] ?? null,
        );
    }

    public function testWarmerTargetsLayoutEntityPublishedProjectionPool(): void
    {
        $warmer = new WidgetAssetSourceCacheWarmer();
        self::assertSame(
            StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            $warmer->getTargetPool(),
        );
        self::assertSame('theme.widget_asset_sources', $warmer->getName());
    }

    public function testClearWarmsLayoutEntityProjectionPoolWithoutThemeFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString(
            "warmUp('weline_theme_layout_entity_published_projection')",
            $src,
        );
        self::assertStringNotContainsString('WidgetAssetSourceCacheWarmer', $src);
    }
}
