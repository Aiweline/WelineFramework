<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutQuoteLineWeightResolver;
use Weline\HelpPay\Service\HelpPayQuickShippingQuoteService;

final class HelpPayQuickShippingQuoteServiceTest extends TestCase
{
    public function testMissingWeightHidesOptions(): void
    {
        $svc = HelpPayQuickShippingQuoteService::forTesting(
            CheckoutQuoteLineWeightResolver::forTesting(static fn (): int => 0),
            [[
                'service_code' => 'SEED_LANE_AMERICAS',
                'label' => '美洲',
                'amount_minor' => 7300,
            ]],
        );
        $out = $svc->listOptions([
            'product_id' => 192,
            'qty' => 1,
            'goods_amount_minor' => 1000,
            'currency_code' => 'CNY',
            'shipping_address' => [
                'name' => 'A', 'line1' => '1', 'phone' => '1', 'country' => 'US',
            ],
        ]);
        self::assertTrue($out['missing_weight']);
        self::assertSame([], $out['options']);
        self::assertSame(0, (int) ($out['lines'][0]['weight_minor'] ?? -1));
    }

    public function testQuotedLaneUsesCatalogWeightNotInventedHalfKg(): void
    {
        $svc = HelpPayQuickShippingQuoteService::forTesting(
            CheckoutQuoteLineWeightResolver::forTesting(static fn (): int => 300),
            [[
                'service_code' => 'SEED_LANE_AMERICAS',
                'label' => '美洲',
                'amount_minor' => 200,
            ]],
        );
        $out = $svc->listOptions([
            'product_id' => 321,
            'qty' => 2,
            'goods_amount_minor' => 2000,
            'currency_code' => 'CNY',
            'shipping_address' => [
                'name' => 'A', 'line1' => '1', 'phone' => '1', 'country' => 'US',
            ],
        ]);
        self::assertFalse($out['missing_weight']);
        self::assertSame(300, (int) ($out['lines'][0]['weight_minor'] ?? 0));
        self::assertNotSame(500, (int) ($out['lines'][0]['weight_minor'] ?? 0));
        $svc->assertSelectedShipping(
            [
                'product_id' => 321,
                'qty' => 2,
                'goods_amount_minor' => 2000,
                'currency_code' => 'CNY',
                'shipping_address' => [
                    'name' => 'A', 'line1' => '1', 'phone' => '1', 'country' => 'US',
                ],
            ],
            'SEED_LANE_AMERICAS',
            200,
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helppay_shipping_mismatch');
        $svc->assertSelectedShipping(
            [
                'product_id' => 321,
                'qty' => 2,
                'goods_amount_minor' => 2000,
                'currency_code' => 'CNY',
                'shipping_address' => [
                    'name' => 'A', 'line1' => '1', 'phone' => '1', 'country' => 'US',
                ],
            ],
            'SEED_LANE_AMERICAS',
            1,
        );
    }
}
