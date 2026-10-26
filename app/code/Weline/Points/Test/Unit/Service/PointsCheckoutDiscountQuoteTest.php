<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\Points\Service\PointsBaseCurrencyResolver;
use Weline\Points\Service\PointsCheckoutDiscountQuote;
use Weline\Points\Service\PointsConfig;

final class PointsCheckoutDiscountQuoteTest extends TestCase
{
    private function config(): PointsConfig
    {
        return new class extends PointsConfig {
            public function discountEnabled(): bool
            {
                return true;
            }

            public function paymentPointsEnabled(): bool
            {
                return true;
            }

            public function paymentExchangeRatio(): float
            {
                return 0.01;
            }

            public function discountMaxRatio(): float
            {
                return 0.30;
            }
        };
    }

    private function resolver(): PointsBaseCurrencyResolver
    {
        return new class extends PointsBaseCurrencyResolver {
            public function forWebsite(int $websiteId): string
            {
                return 'CNY';
            }
        };
    }

    public function testQuoteCapsByThirtyPercentAndBalance(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->method('getBalance')->willReturn(['available_minor' => 1000]);
        $quote = (new PointsCheckoutDiscountQuote($assets, $this->config(), $this->resolver()))
            ->quote('42', 0, 'CNY', 10000);

        self::assertTrue($quote['enabled']);
        self::assertSame(1000, $quote['points_available']);
        self::assertSame(1000, $quote['max_apply_checkout_minor']);
        self::assertSame(0.3, $quote['max_ratio']);
    }

    public function testQuoteUsesThirtyPercentCapWhenBalanceIsLarge(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->method('getBalance')->willReturn(['available_minor' => 100000]);
        $quote = (new PointsCheckoutDiscountQuote($assets, $this->config(), $this->resolver()))
            ->quote('42', 0, 'CNY', 10000);

        self::assertSame(3000, $quote['max_apply_checkout_minor']);
        self::assertSame(3000, $quote['points_max']);
    }

    public function testClampApplyCapsRequestToMax(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->method('getBalance')->willReturn(['available_minor' => 100000]);
        $service = new PointsCheckoutDiscountQuote($assets, $this->config(), $this->resolver());
        $quote = $service->quote('42', 0, 'CNY', 10000);
        $clamped = $service->clampApply($quote, 9000);

        self::assertSame(3000, $clamped['apply_checkout_minor']);
        self::assertSame(3000, $clamped['points']);
        self::assertTrue($clamped['adjusted']);
    }

    public function testGuestGetsUnavailableQuote(): void
    {
        $assets = $this->createMock(CustomerAssetFacadeInterface::class);
        $assets->expects(self::never())->method('getBalance');
        $quote = (new PointsCheckoutDiscountQuote($assets, $this->config(), $this->resolver()))
            ->quote('', 0, 'CNY', 10000);

        self::assertFalse($quote['enabled']);
        self::assertSame(PointsCheckoutDiscountQuote::REASON_NOT_LOGGED_IN, $quote['reason']);
    }
}
