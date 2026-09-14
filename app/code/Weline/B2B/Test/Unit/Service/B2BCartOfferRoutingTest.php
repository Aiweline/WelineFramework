<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Service\B2BCartOfferRouting;
use Weline\B2B\Service\PriceListStore;
use Weline\B2B\Service\ProductWholesaleEligibility;
use Weline\B2B\Service\SellingModePolicy;

final class B2BCartOfferRoutingTest extends TestCase
{
    public function testIneligibleTobAddRemapsToToc(): void
    {
        $lists = PriceListStore::forTesting();
        $policy = SellingModePolicy::forTesting(['website:1' => true]);
        $gate = new ProductWholesaleEligibility($policy, $lists);
        $routing = new B2BCartOfferRouting($gate);

        $result = $routing->resolveAddCartType([
            'cart_type' => 'tob',
            'sku' => 'SKU-RETAIL-ONLY',
            'website_id' => 1,
            'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
        ]);

        self::assertSame('toc', $result['cart_type'] ?? null);
        self::assertTrue((bool)($result['remapped'] ?? false));
        self::assertSame(B2BCartOfferRouting::REASON_NOT_WHOLESALE_ELIGIBLE, $result['reason'] ?? null);
    }

    public function testEligibleTobAddKeepsTob(): void
    {
        $lists = PriceListStore::forTesting();
        $lists->put(new PriceList('pl-a', 'g-a', 1, 1, ['SKU-W' => [5 => 800]]));
        $policy = SellingModePolicy::forTesting(['website:1' => true]);
        $gate = new ProductWholesaleEligibility($policy, $lists);
        $routing = new B2BCartOfferRouting($gate);

        $result = $routing->resolveAddCartType([
            'cart_type' => 'tob',
            'sku' => 'SKU-W',
            'website_id' => 1,
            'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
        ]);

        self::assertSame('tob', $result['cart_type'] ?? null);
        self::assertFalse((bool)($result['remapped'] ?? false));
    }

    public function testTocRequestUnchanged(): void
    {
        $routing = new B2BCartOfferRouting(
            new ProductWholesaleEligibility(
                SellingModePolicy::forTesting(['website:1' => true]),
                PriceListStore::forTesting(),
            ),
        );

        $result = $routing->resolveAddCartType([
            'cart_type' => 'toc',
            'sku' => 'SKU-ANY',
            'website_id' => 1,
        ]);

        self::assertSame('toc', $result['cart_type'] ?? null);
    }

    public function testModuleProvidesOfferRouting(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\B2B\Service\B2BCartOfferRouting::class,
            $module['provides'][\Weline\Cart\Api\CommerceCartOfferRoutingInterface::class] ?? null,
        );
    }
}
