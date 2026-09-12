<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\ProductSellingModeFlags;
use Weline\B2B\Service\SellingModePolicy;

final class ProductSellingModeFlagsContractTest extends TestCase
{
    public function testFromOfferReadsTopLevelAndAttributes(): void
    {
        $flags = ProductSellingModeFlags::fromOffer([
            'product_id' => 0,
            SellingModePolicy::PRODUCT_FLAG_TOB => false,
        ]);
        self::assertIsArray($flags);
        self::assertArrayHasKey(SellingModePolicy::PRODUCT_FLAG_TOB, $flags);
        self::assertFalse((bool)$flags[SellingModePolicy::PRODUCT_FLAG_TOB]);

        $nested = ProductSellingModeFlags::fromOffer([
            'product_id' => 0,
            'attributes' => [
                SellingModePolicy::PRODUCT_FLAG_TOB => '0',
            ],
        ]);
        self::assertIsArray($nested);
        self::assertArrayHasKey(SellingModePolicy::PRODUCT_FLAG_TOB, $nested);
    }

    public function testExplicitFlagsWin(): void
    {
        $flags = ProductSellingModeFlags::fromOffer(
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            [SellingModePolicy::PRODUCT_FLAG_TOB => false],
        );
        self::assertSame(false, $flags[SellingModePolicy::PRODUCT_FLAG_TOB] ?? null);
    }

    public function testStorefrontTemplatesHydrateFlags(): void
    {
        $switcher = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/selling-mode-switcher.phtml';
        $tiers = dirname(__DIR__, 3) . '/view/templates/frontend/partials/qty-tiers.phtml';
        self::assertFileExists($switcher);
        self::assertFileExists($tiers);
        $switcherSrc = (string)file_get_contents($switcher);
        $tiersSrc = (string)file_get_contents($tiers);
        self::assertStringContainsString('ProductSellingModeFlags::fromOffer', $switcherSrc);
        self::assertStringContainsString('ProductSellingModeFlags::fromOffer', $tiersSrc);
        self::assertStringContainsString('ProductWholesaleEligibility', $switcherSrc);
        self::assertStringContainsString('allowsWholesaleDisplay', $tiersSrc);
    }
}
