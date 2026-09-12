<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Service\B2BCartQtyPolicy;
use Weline\B2B\Service\PriceListStore;
use Weline\B2B\Service\ProductWholesaleEligibility;
use Weline\B2B\Service\SellingModePolicy;

final class B2BCartQtyPolicyEligibilityTest extends TestCase
{
    public function testIneligibleTobSkuSkipsMoq(): void
    {
        $lists = PriceListStore::forTesting();
        $policy = SellingModePolicy::forTesting(['website:1' => true]);
        $gate = new ProductWholesaleEligibility($policy, $lists);
        $qtyPolicy = new B2BCartQtyPolicy($policy, $gate);

        $result = $qtyPolicy->assertQty([
            'cart_type' => 'tob',
            'qty' => 1,
            'sku' => 'SKU-RETAIL-ONLY',
            'website_id' => 1,
            'product_id' => 0,
            'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
        ]);
        self::assertTrue($result['ok'], 'ineligible SKU must allow retail qty in tob cart');
    }

    public function testEligibleTobSkuStillEnforcesMoq(): void
    {
        $lists = PriceListStore::forTesting();
        $lists->put(new PriceList('pl-a', 'g-a', 1, 1, ['SKU-W' => [5 => 800]]));
        $policy = SellingModePolicy::forTesting(['website:1' => true]);
        $gate = new ProductWholesaleEligibility($policy, $lists);
        $qtyPolicy = new B2BCartQtyPolicy($policy, $gate);

        $fail = $qtyPolicy->assertQty([
            'cart_type' => 'tob',
            'qty' => 1,
            'sku' => 'SKU-W',
            'website_id' => 1,
            'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
        ]);
        self::assertFalse($fail['ok']);
        self::assertSame(B2BCartQtyPolicy::ERROR_BELOW_MOQ, $fail['error_code'] ?? null);

        $ok = $qtyPolicy->assertQty([
            'cart_type' => 'tob',
            'qty' => 10,
            'sku' => 'SKU-W',
            'website_id' => 1,
            'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
        ]);
        self::assertTrue($ok['ok']);
    }
}
