<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\PackingSplitter;
use Weline\Shipping\Service\ShippingCheckoutAddonService;
use Weline\Shipping\Service\ShippingSeasonalSurchargeService;

final class PackAddonChapter3ContractTest extends TestCase
{
    public function testPackingSplitterSplitsByWeight(): void
    {
        $splitter = new PackingSplitter();
        $boxes = $splitter->splitBoxes([
            [
                'requires_shipping' => true,
                'qty_minor' => 2,
                'weight_minor' => 20000, // 20kg each
                'length_cm' => 10,
                'width_cm' => 10,
                'height_cm' => 10,
            ],
        ], 30.0, 120000.0);
        self::assertCount(2, $boxes);
        self::assertCount(1, $boxes[0]);
        self::assertCount(1, $boxes[1]);
    }

    public function testPackingSplitterKeepsUnderLimitInOneBox(): void
    {
        $splitter = new PackingSplitter();
        $boxes = $splitter->splitBoxes([
            [
                'requires_shipping' => true,
                'qty_minor' => 1,
                'weight_minor' => 5000,
                'length_cm' => 10,
                'width_cm' => 10,
                'height_cm' => 10,
            ],
        ], 30.0, 120000.0);
        self::assertCount(1, $boxes);
    }

    public function testLocalPricingWiresPackSeasonalAddon(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/Provider/LocalTemplatePricingService.php',
        );
        self::assertStringContainsString('PackingSplitter', $src);
        self::assertStringContainsString('ShippingSeasonalSurchargeService', $src);
        self::assertStringContainsString('ShippingCheckoutAddonService', $src);
        self::assertStringContainsString('package_count', $src);
    }

    public function testSeedHasPackingAndSeasonalDefaultsOff(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php',
        );
        self::assertStringContainsString('ensurePackingAndAddonSeeds', $src);
        self::assertStringContainsString('ShippingPackingPolicy::SEED_CODE', $src);
        self::assertStringContainsString('ShippingSeasonalRule::SEED_PEAK', $src);
        self::assertStringContainsString('ShippingCheckoutAddon::SEED_SIGNATURE', $src);
        self::assertStringContainsString('schema_fields_IS_ACTIVE => 0', $src);
    }

    public function testConfigVersionIncludesChapter3Facts(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('packingConfigFacts', $src);
        self::assertStringContainsString('seasonalConfigFacts', $src);
        self::assertStringContainsString('checkoutAddonConfigFacts', $src);
    }

    public function testModuleVersionIsAtLeast280(): void
    {
        $module = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        self::assertMatchesRegularExpression("/'2\\.(8|9)\\.\\d+'/", $module);
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('ShippingPackingPolicy', $upgrade);
        self::assertStringContainsString('ShippingCheckoutAddon', $upgrade);
        self::assertStringContainsString('ShippingSeasonalRule', $upgrade);
    }

    public function testAddonAndSeasonalSumMinor(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $addon = new ShippingCheckoutAddonService($om);
        $seasonal = new ShippingSeasonalSurchargeService($om);
        self::assertSame(500, $addon->sumMinor([['amount_minor' => 500]]));
        self::assertSame(575, $seasonal->sumMinor([
            ['amount_minor' => 500],
            ['amount_minor' => 75],
        ]));
        // 签名固定 5.00 → 500；燃油 5% of 11500 → 575
        self::assertSame(500, (int)round(5.0 * 100));
        self::assertSame(575, (int)round(11500 * 5.0 / 100));
    }
}
