<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Extends\Module\Weline_Product\StorefrontPriceAdjustmentProvider\B2BStorefrontPriceAdjustmentProvider;
use Weline\B2B\Service\B2BService;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler;
use Weline\Product\Service\Storefront\StorefrontPriceAdjustmentProviderRegistry;

final class B2BStorefrontPriceAdjustmentProviderTest extends TestCase
{
    public function testTobMembershipReturnsExclusiveAbsoluteListPrice(): void
    {
        $svc = B2BService::forTesting();
        $svc->enableShadow();
        $svc->seedGroup('g-dealer', 0, 'dealer');
        $svc->assignCustomer('cust-b2b', 'g-dealer');
        $svc->seedPriceList('pl-dealer', 'g-dealer', 0, 1, ['SKU-A' => 800]);

        $provider = new B2BStorefrontPriceAdjustmentProvider($svc->engine());
        $context = new StorefrontPriceContext(
            productId: 1,
            catalogPriceMinor: 1000,
            currency: 'CNY',
            websiteId: 0,
            sku: 'SKU-A',
            customerId: 'cust-b2b',
            sellingMode: 'tob',
        );
        $adjustments = $provider->collectAdjustments($context);
        self::assertCount(1, $adjustments);
        self::assertTrue($adjustments[0]->isAbsoluteMinor());
        self::assertSame(800.0, $adjustments[0]->value);
        self::assertSame(StorefrontPriceAdjustment::GROUP_UNIT, $adjustments[0]->exclusiveGroup);

        $deal = new class implements \Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface {
            public function getCode(): string
            {
                return 'promo';
            }

            public function getPriority(): int
            {
                return 100;
            }

            public function collectAdjustments(StorefrontPriceContext $context): array
            {
                return [
                    new StorefrontPriceAdjustment(
                        code: 'promo-50',
                        sourceModule: 'Test',
                        sourceType: 'deal',
                        sourceId: '1',
                        label: '半价',
                        type: StorefrontPriceAdjustment::TYPE_PERCENTAGE,
                        value: 50,
                    ),
                ];
            }
        };

        $assembler = new StorefrontOfferPriceAssembler(
            StorefrontPriceAdjustmentProviderRegistry::forTesting([$provider, $deal]),
        );
        $view = $assembler->assemble($context);
        self::assertSame(800, $view->finalPriceMinor);
        self::assertSame('b2b_list:pl-dealer', $view->appliedAdjustments[0]->code);
    }

    public function testTocOrNoMembershipReturnsEmpty(): void
    {
        $svc = B2BService::forTesting();
        $svc->enableShadow();
        $svc->seedGroup('g-dealer', 0, 'dealer');
        $svc->assignCustomer('cust-b2b', 'g-dealer');
        $svc->seedPriceList('pl-dealer', 'g-dealer', 0, 1, ['SKU-A' => 800]);
        $provider = new B2BStorefrontPriceAdjustmentProvider($svc->engine());

        self::assertSame([], $provider->collectAdjustments(new StorefrontPriceContext(
            productId: 1,
            catalogPriceMinor: 1000,
            sku: 'SKU-A',
            customerId: 'cust-b2b',
            sellingMode: 'toc',
        )));
        self::assertSame([], $provider->collectAdjustments(new StorefrontPriceContext(
            productId: 1,
            catalogPriceMinor: 1000,
            sku: 'SKU-A',
            customerId: 'cust-retail',
            sellingMode: 'tob',
        )));
    }
}
