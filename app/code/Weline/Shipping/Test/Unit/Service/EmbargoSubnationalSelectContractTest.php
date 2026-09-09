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
        self::assertStringContainsString('mode=embargo_regions', $js);
        self::assertStringContainsString('if (hit && hit.region && hit.region.embargoed)', $js);
        self::assertStringContainsString('paintMarked', $js);
        self::assertStringContainsString('markHitsForMenu', $js);
    }

    public function testAddressLoaderBustsSubnationalEmbargoCache(): void
    {
        $loader = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/js/address-loader.js',
        );
        self::assertStringContainsString('20260909-subnational-embargo-block4', $loader);
    }

    public function testModuleVersionsBumped(): void
    {
        $shipping = include dirname(__DIR__, 3) . '/etc/module.php';
        $theme = include dirname(__DIR__, 4) . '/Theme/etc/module.php';
        self::assertSame('2.4.73', (string)($shipping['version'] ?? ''));
        self::assertSame('2.2.285', (string)($theme['version'] ?? ''));
    }
}
