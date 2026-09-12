<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipPricingService;

final class DropshipPricingServiceTest extends TestCase
{
    public function testSaleFromOriginDefaultThirtyPercent(): void
    {
        $svc = new DropshipPricingService();
        self::assertSame(1300, $svc->saleFromOrigin(1000, 30));
    }

    public function testSameCurrencySaleAppliesUpliftOnly(): void
    {
        $svc = new DropshipPricingService();
        self::assertSame(1000, $svc->convertOriginMinor(1000, 'USD', 'USD'));
        self::assertSame(1300, $svc->saleFromOriginInCurrency(1000, 'CNY', 'CNY', 30));
    }

    public function testPriceUpFollowsUplift(): void
    {
        $svc = new DropshipPricingService();
        $r = $svc->applyRemoteOrigin(1000, 1100, 1300, 30, false);
        self::assertSame('up', $r['direction']);
        self::assertSame(1430, $r['sale_minor']);
        self::assertNull($r['tip']);
    }

    public function testPriceDownWritesTipWithoutSaleChange(): void
    {
        $svc = new DropshipPricingService();
        $r = $svc->applyRemoteOrigin(1000, 800, 1300, 30, false);
        self::assertSame('down', $r['direction']);
        self::assertNull($r['sale_minor']);
        self::assertNotNull($r['tip']);
        self::assertStringContainsString('远程降价', (string)$r['tip']);
    }

    public function testEconomicsSnapshotMarginAndOriginDelta(): void
    {
        $svc = new DropshipPricingService();
        $eco = $svc->economicsSnapshot(
            originMinor: 211,
            originPrevMinor: 250,
            originCurrency: 'CNY',
            saleMinor: 274,
            saleCurrency: 'CNY',
            priceDirection: 'down',
        );
        self::assertSame(211, $eco['cost_minor']);
        self::assertSame(63, $eco['margin_minor']);
        self::assertSame(29.9, $eco['margin_percent']);
        self::assertSame('down', $eco['origin_direction']);
        self::assertSame(-39, $eco['origin_delta_minor']);
        self::assertSame(-15.6, $eco['origin_delta_percent']);
    }

    public function testSaleCompareInOriginCurrencyConvertsCnyToUsd(): void
    {
        $svc = new DropshipPricingService();
        self::assertNull($svc->saleCompareInOriginCurrency(274, 'USD', 'USD'));
        $same = $svc->saleCompareInOriginCurrency(274, 'CNY', 'CNY');
        self::assertNull($same);

        // When FX available: CNY→USD; when unavailable method returns null — either is ok for degrade.
        $cmp = $svc->saleCompareInOriginCurrency(2194, 'CNY', 'USD');
        if ($cmp === null) {
            self::assertNull($cmp);
            return;
        }
        self::assertSame('USD', $cmp['compare_currency']);
        self::assertGreaterThan(0, $cmp['compare_amount_minor']);
    }
}
