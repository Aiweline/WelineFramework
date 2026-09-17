<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Router must reverse-infer layout via layout_resolve — no alias/path hardcoding tables.
 */
final class ThemeProductQueryYieldContractTest extends TestCase
{
    public function testThemeRouterUsesLayoutResolveNotHardcodedMaps(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Router.php',
        );

        self::assertStringNotContainsString('defaultPublicRouteMap', $source);
        self::assertStringNotContainsString('resolveDefaultPublicTarget', $source);
        self::assertStringNotContainsString('resolveThemeShellLayoutFromPublicPath', $source);
        self::assertStringContainsString('LayoutResolveService', $source);
        self::assertStringContainsString('resolveFromPath', $source);
        self::assertStringContainsString('Bare /product always belongs to Product', $source);
        self::assertFileExists(dirname(__DIR__, 2) . '/Observer/LayoutResolveObserver.php');
    }
}
