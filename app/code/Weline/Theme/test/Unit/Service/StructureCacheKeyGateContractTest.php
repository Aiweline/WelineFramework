<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * Gate: presentation policies keep lang; structure-adjacent search types drop currency only.
 */
final class StructureCacheKeyGateContractTest extends TestCase
{
    public function testChromeAndNavigationHtmlKeepLangVary(): void
    {
        $chrome = StorefrontThemeCacheCoordinator::storefrontChromePolicy();
        $nav = StorefrontThemeCacheCoordinator::headerNavigationPolicy();

        self::assertContains('lang', $chrome->vary);
        self::assertContains('lang', $nav->vary);
    }

    public function testSearchTypesKeepLangAndDropCurrency(): void
    {
        $policy = StorefrontThemeCacheCoordinator::headerSearchTypesPolicy();

        self::assertSame(['lang'], $policy->vary);
        self::assertNotContains('currency', $policy->vary);
    }

    public function testLayoutSlotDocStatesTheGate(): void
    {
        $doc = (string)\file_get_contents(BP . 'app/code/Weline/Theme/doc/layout-slot-cache-keys.md');
        self::assertStringContainsString('入选闸门', $doc);
        self::assertStringContainsString('只有载荷不依赖某维', $doc);
        self::assertStringContainsString('先拆分', $doc);
    }
}
