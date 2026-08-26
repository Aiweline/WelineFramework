<?php

declare(strict_types=1);

namespace Weline\Location\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderLocationSelectorSoloContractTest extends TestCase
{
    public function testLocationHookIsTheOnlyHeaderLocationSelector(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $locationHook = $moduleRoot . '/view/hooks/header-location-selector.phtml';
        $geoHook = dirname($moduleRoot) . '/Geo/view/hooks/header-location-selector.phtml';
        $source = (string)file_get_contents($locationHook);

        self::assertFileExists($locationHook);
        self::assertFileDoesNotExist($geoHook);
        self::assertMatchesRegularExpression('/@hook-solo\s+true/', $source);
        self::assertStringContainsString('querySelectorAll(\'[data-geo-location-selector]\')', $source);
        self::assertStringContainsString('event.stopPropagation()', $source);
        self::assertStringContainsString('data-action="select-location"', $source);
        self::assertStringContainsString('location-selector-dropdown', $source);
    }
}
