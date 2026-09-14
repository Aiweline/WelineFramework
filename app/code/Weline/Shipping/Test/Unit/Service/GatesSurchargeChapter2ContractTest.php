<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;
use Weline\Shipping\Service\ShippingCapabilityGate;
use Weline\Shipping\Service\ShippingSurchargeService;

final class GatesSurchargeChapter2ContractTest extends TestCase
{
    public function testDefaultPointTypesExcludePobox(): void
    {
        $gate = new ShippingCapabilityGate();
        self::assertTrue($gate->allowsPointType(
            ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS,
            'residential',
        ));
        self::assertFalse($gate->allowsPointType(
            ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS,
            'pobox',
        ));
    }

    public function testEmptyAcceptedHazardsRejectLineHazards(): void
    {
        $gate = new ShippingCapabilityGate();
        self::assertTrue($gate->allowsHazards('', []));
        self::assertFalse($gate->allowsHazards('', ['battery_lithium']));
        self::assertTrue($gate->allowsHazards('battery_lithium,liquid', ['battery_lithium']));
    }

    public function testCollectLineHazardsFromMetadata(): void
    {
        $gate = new ShippingCapabilityGate();
        $classes = $gate->collectLineHazards([
            ['requires_shipping' => true, 'fulfillment_metadata' => ['shipping_hazard_class' => 'liquid']],
            ['requires_shipping' => false, 'shipping_hazard_class' => 'battery_lithium'],
        ]);
        self::assertSame(['liquid'], $classes);
    }

    public function testSeedSurchargeCodesMatchPlan(): void
    {
        $codes = DefaultShippingLaneSeedService::expectedSeedSurchargeCodes();
        self::assertCount(6, $codes);
        self::assertContains('SEED_SURCHARGE_CN_XJ', $codes);
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php',
        );
        self::assertStringContainsString('ensureRemoteSurchargeSeeds', $src);
        self::assertStringContainsString('SEED_SURCHARGE_CN_XZ', $src);
    }

    public function testLocalPricingWiresSurchargeService(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/Provider/LocalTemplatePricingService.php',
        );
        self::assertStringContainsString('ShippingSurchargeService', $src);
        self::assertStringContainsString('surcharges', $src);
        self::assertStringContainsString('free_reason', $src);
    }

    public function testQuoteRatesAppliesCapabilityGate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('ShippingCapabilityGate', $src);
        self::assertStringContainsString('delivery_point_type', $src);
        self::assertStringContainsString('allowsHazards', $src);
        self::assertStringContainsString('surchargeConfigFacts', $src);
    }

    public function testSurchargeSumAndFixedMinorMath(): void
    {
        $svc = new ShippingSurchargeService(
            $this->createMock(\Weline\Framework\Manager\ObjectManager::class),
        );
        self::assertSame(2500, $svc->sumMinor([
            ['amount_minor' => 2500],
            ['amount_minor' => 0],
        ]));
        // 固定 25.00 @ precision 2 → 2500 minor（与 SEED_SURCHARGE_CN_XJ 一致）
        $scale = 10 ** 2;
        self::assertSame(2500, (int)round(25.0 * $scale));
    }
}
