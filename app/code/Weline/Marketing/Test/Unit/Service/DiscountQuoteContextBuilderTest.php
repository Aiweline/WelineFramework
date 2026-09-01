<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Service\DiscountQuoteContextBuilder;

final class DiscountQuoteContextBuilderTest extends TestCase
{
    public function testBuildMapsCheckoutMinorUnitsToMajorOrderContext(): void
    {
        $builder = new DiscountQuoteContextBuilder();
        $request = new DiscountQuoteRequest(
            scope: ['website_id' => 1, 'store_id' => 2],
            address: ['country' => 'CN'],
            lines: [
                ['qty_minor' => 2, 'unit_price_minor' => 1500, 'sku' => 'SKU-A', 'product_id' => 11],
            ],
            currency: 'CNY',
            currencyPrecision: 2,
            customerId: 9,
            shippingAmountMinor: 500,
            paymentMethod: 'offline',
        );

        $context = $builder->build($request);

        self::assertSame(9, $context['customer_id']);
        self::assertEquals(30.0, $context['subtotal']);
        self::assertEquals(5.0, $context['shipping_amount']);
        self::assertEquals(30.0, $context['order']['subtotal']);
        self::assertSame(2, $context['order']['item_count']);
        self::assertSame('offline', $context['payment_method']);
        self::assertSame('SKU-A', $context['products'][0]['sku'] ?? null);
        self::assertSame(11, $context['products'][0]['product_id'] ?? null);
        self::assertEquals(15.0, $context['products'][0]['price'] ?? null);
        self::assertSame(2, $context['products'][0]['qty'] ?? null);
        self::assertSame($context['products'], $context['items']);
    }
}
