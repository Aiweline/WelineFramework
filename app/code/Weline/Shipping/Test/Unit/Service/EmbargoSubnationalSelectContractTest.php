<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class EmbargoSubnationalSelectContractTest extends TestCase
{
    public function testEmbargoServiceExposesActiveSubnationalRules(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/EmbargoService.php',
        );
        self::assertStringContainsString('function activeSubnationalRules', $src);
        self::assertStringContainsString('TYPE_COUNTRY', $src);
    }

    public function testRegionControllerServesEmbargoRegionsMode(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Region.php',
        );
        self::assertStringContainsString("mode === 'embargo_regions'", $src);
        self::assertStringContainsString('activeSubnationalRules', $src);
    }

    public function testAddressJsMarksAndBlocksSubnationalEmbargo(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/js/address.js',
        );
        self::assertStringContainsString('function embargoedSubnationalRules', $js);
        self::assertStringContainsString('function markChildrenSubnationalEmbargo', $js);
        self::assertStringContainsString("callRegion('embargo_regions', {})", $js);
        self::assertStringContainsString('SUBNATIONAL_EMBARGO_CACHE_KEY', $js);
        self::assertStringContainsString('function ensurePools', $js);
        self::assertStringContainsString('wAddressLazyArmed', $js);
        self::assertStringContainsString('data-address-lazy', $js);
        self::assertStringContainsString('if (hit && hit.region && hit.region.embargoed)', $js);
        self::assertStringContainsString('paintMarked', $js);
        self::assertStringContainsString('markHitsForMenu', $js);
    }

    public function testAddressLoaderBustsSubnationalEmbargoCache(): void
    {
        $loader = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/js/address-loader.js',
        );
        self::assertStringContainsString('20260921-keep-postal2', $loader);
    }

    public function testModuleVersionsBumped(): void
    {
        $shipping = include dirname(__DIR__, 3) . '/etc/module.php';
        $theme = include dirname(__DIR__, 4) . '/Theme/etc/module.php';
        self::assertSame('2.9.22', (string)($shipping['version'] ?? ''));
        self::assertSame('2.2.530', (string)($theme['version'] ?? ''));
    }

    public function testRegionJsonWritesFrameworkResponseHeaders(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Region.php',
        );
        self::assertStringContainsString("setHeader('Content-Type', 'application/json; charset=utf-8')", $src);
        self::assertStringContainsString("setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')", $src);
        self::assertStringNotContainsString("header('Content-Type: application/json; charset=utf-8')", $src);
    }
}
