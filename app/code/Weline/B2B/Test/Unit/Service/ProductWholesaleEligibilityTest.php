<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Service\PriceListStore;
use Weline\B2B\Service\ProductWholesaleEligibility;
use Weline\B2B\Service\SellingModePolicy;

final class ProductWholesaleEligibilityTest extends TestCase
{
    public function testRequiresTobFlagAndActiveTiers(): void
    {
        $lists = PriceListStore::forTesting();
        $lists->put(new PriceList('pl-a', 'g-a', 1, 1, ['SKU-W' => [5 => 800, 10 => 700]]));
        $policy = SellingModePolicy::forTesting(['website:1' => ['toc' => true, 'tob' => true]]);
        $gate = new ProductWholesaleEligibility($policy, $lists);

        self::assertTrue($gate->allowsWholesaleDisplay(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            'SKU-W',
        ));

        self::assertFalse($gate->allowsWholesaleDisplay(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => false],
            'SKU-W',
        ));

        self::assertFalse($gate->allowsWholesaleDisplay(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            'SKU-MISSING',
        ));
    }

    public function testDefaultTemplateAloneDoesNotUnlockDisplay(): void
    {
        $lists = PriceListStore::forTesting();
        $policy = SellingModePolicy::forTesting(['website:1' => ['toc' => true, 'tob' => true]]);
        // Even if a DefaultWholesalePolicy exists in DI historically, display must
        // require an active SKU price-list tier — construct without tiers.
        $gate = new ProductWholesaleEligibility($policy, $lists);

        self::assertFalse($gate->allowsWholesaleDisplay(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            'SKU-TEMPLATE-ONLY',
        ));
    }

    public function testRequiresTobQtyGateMatchesDisplay(): void
    {
        $lists = PriceListStore::forTesting();
        $lists->put(new PriceList('pl-a', 'g-a', 1, 1, ['SKU-W' => 900]));
        $policy = SellingModePolicy::forTesting(['website:1' => true]);
        $gate = new ProductWholesaleEligibility($policy, $lists);

        self::assertTrue($gate->requiresTobQtyGate(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            'SKU-W',
        ));
        self::assertFalse($gate->requiresTobQtyGate(
            1,
            0,
            [SellingModePolicy::PRODUCT_FLAG_TOB => true],
            'SKU-NONE',
        ));
    }

    public function testStorefrontTemplatesUseEligibilityGate(): void
    {
        $switcher = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/selling-mode-switcher.phtml';
        $tiers = dirname(__DIR__, 3) . '/view/templates/frontend/partials/qty-tiers.phtml';
        $switcherSrc = (string)file_get_contents($switcher);
        $tiersSrc = (string)file_get_contents($tiers);
        self::assertStringContainsString('ProductWholesaleEligibility', $switcherSrc);
        self::assertStringContainsString('allowsWholesaleDisplay', $switcherSrc);
        self::assertStringContainsString('ProductWholesaleEligibility', $tiersSrc);
        self::assertStringContainsString('allowsWholesaleDisplay', $tiersSrc);
    }
}
