<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Observer;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Observer\CheckoutAvailableMethodsIncentiveEnrichObserver;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;

final class CheckoutAvailableMethodsIncentiveEnrichObserverContractTest extends TestCase
{
    public function testResolveBaseMinorFromMajorAmountOnly(): void
    {
        $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(
            ['amount' => 10.13],
            'CNY',
        );
        self::assertSame(1013, $minor);
    }

    public function testResolveBaseMinorPrefersAmountMinorOverMajor(): void
    {
        $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(
            [
                'amount_minor' => 1013,
                'amount' => 99.99,
            ],
            'CNY',
        );
        self::assertSame(1013, $minor);
    }

    public function testResolveBaseMinorFromTotalsGrandTotalMinor(): void
    {
        $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(
            [
                'totals' => ['grand_total_minor' => 2278],
                'amount' => 1.00,
            ],
            'USD',
        );
        self::assertSame(2278, $minor);
    }

    public function testMajorAmountAloneStillYieldsIncentiveSavings(): void
    {
        $baseMinor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(
            ['amount' => 10.13],
            'CNY',
        );
        self::assertSame(1013, $baseMinor);

        $svc = new PaymentMethodIncentiveQuoteService();
        $quote = $svc->quote('fake_card', [
            'incentive_enabled' => 1,
            'incentive_type' => 'fixed_amount',
            'incentive_amount_minor' => 500,
            'incentive_publish_version' => 'v-test',
        ], $baseMinor, 'CNY', true);

        self::assertTrue($quote['available']);
        self::assertSame(500, $quote['savings_minor']);
        self::assertGreaterThan(0, $quote['savings_minor']);
    }

    public function testZeroDecimalCurrencyUsesUnitScale(): void
    {
        $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(
            ['amount' => 1500],
            'JPY',
        );
        self::assertSame(1500, $minor);
    }
}
