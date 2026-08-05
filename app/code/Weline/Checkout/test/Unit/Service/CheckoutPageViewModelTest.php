<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutPageViewModel;

final class CheckoutPageViewModelTest extends TestCase
{
    public function testNormalizesTrustedCartSummary(): void
    {
        $data = (new CheckoutPageViewModel())->fromQueryResult([
            'data' => [
                'currency' => 'cny',
                'subtotal_minor' => 2100,
                'grand_total_minor' => 2100,
                'items' => [
                    [
                        'name' => 'Trusted Offer',
                        'qty' => 2,
                        'unit_price_minor' => 1050,
                        'row_total_minor' => 2100,
                    ],
                    'not-an-item',
                ],
            ],
        ]);

        self::assertSame('CNY', $data['currency']);
        self::assertSame('Trusted Offer', $data['items'][0]['name']);
        self::assertSame(10.5, $data['items'][0]['price']);
        self::assertSame(21.0, $data['items'][0]['row_total']);
        self::assertSame(21.0, $data['subtotal']);
        self::assertSame(21.0, $data['grand_total']);
        self::assertSame(1, $data['item_count']);
        self::assertFalse($data['is_empty']);
    }

    public function testInvalidResultProducesDeterministicEmptyState(): void
    {
        $data = (new CheckoutPageViewModel())->fromQueryResult(null);

        self::assertSame([], $data['items']);
        self::assertSame('CNY', $data['currency']);
        self::assertSame(0, $data['item_count']);
        self::assertTrue($data['is_empty']);
        self::assertSame(0.0, $data['subtotal']);
        self::assertSame(0.0, $data['grand_total']);
    }

    public function testFallsBackToLegacyCartWhenV2CartIsEmpty(): void
    {
        $data = (new CheckoutPageViewModel())->fromPreferredQueryResults(
            ['success' => true, 'data' => ['items' => [], 'currency' => 'CNY']],
            [
                'success' => true,
                'data' => [
                    'currency' => 'CNY',
                    'subtotal' => 19.9,
                    'grand_total' => 19.9,
                    'items' => [[
                        'product_id' => 42,
                        'offer_id' => 7,
                        'name' => 'Legacy catalog item',
                        'qty' => 1,
                        'price' => 19.9,
                    ]],
                ],
            ],
        );

        self::assertFalse($data['is_empty']);
        self::assertSame(42, $data['items'][0]['product_id']);
        self::assertSame(7, $data['items'][0]['offer_id']);
        self::assertSame(19.9, $data['grand_total']);
    }
}
