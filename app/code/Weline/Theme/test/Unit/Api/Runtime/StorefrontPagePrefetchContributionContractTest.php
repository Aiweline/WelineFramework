<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;

final class StorefrontPagePrefetchContributionContractTest extends TestCase
{
    public function testModuleProvidesStorefrontPagePrefetchContribution(): void
    {
        $module = require dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertArrayHasKey('storefront.page_prefetch.Weline_Theme', $provides);
        self::assertSame(
            \Weline\Theme\Api\Runtime\StorefrontPagePrefetchContribution::class,
            $provides['storefront.page_prefetch.Weline_Theme']
        );
    }

    public function testContributionDelegatesToThemePrefetchServices(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/StorefrontPagePrefetchContribution.php'
        );
        self::assertStringContainsString('ThemePathResolvePagePrefetch', $src);
        self::assertStringContainsString('StorefrontWidgetRuntimeAssetPrimer', $src);
        self::assertStringContainsString('StorefrontPagePrefetchContributionInterface', $src);
    }
}
