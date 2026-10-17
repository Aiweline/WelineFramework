<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Observer\CheckoutAvailableMethodsIncentiveEnrichObserver;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentObserverContractsPass().
 */
final class PaymentObserverContractsTest extends TestCase
{
    public function testAllPaymentObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CheckoutAssetDiscountObserver::testPaymentEventXmlRegistersCheckoutAssetObservers' => function () {
            $this->assert_CheckoutAssetDiscountObserver_testPaymentEventXmlRegistersCheckoutAssetObservers();
        },
            'CheckoutAssetDiscountObserver::testPaymentObserversOwnAssetQuoteData' => function () {
            $this->assert_CheckoutAssetDiscountObserver_testPaymentObserversOwnAssetQuoteData();
        },
            'CheckoutAssetDiscountObserver::testB2bEventXmlDoesNotOwnCheckoutAssetObservers' => function () {
            $this->assert_CheckoutAssetDiscountObserver_testB2bEventXmlDoesNotOwnCheckoutAssetObservers();
        },
            'CheckoutAssetDiscountObserver::testAssetCheckoutDiscountQuoteUsesPaymentPolicySpi' => function () {
            $this->assert_CheckoutAssetDiscountObserver_testAssetCheckoutDiscountQuoteUsesPaymentPolicySpi();
        },
            'CheckoutAvailableMethodsIncentiveEnrichObserver::testResolveBaseMinorFromMajorAmountOnly' => function () {
            $this->assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorFromMajorAmountOnly();
        },
            'CheckoutAvailableMethodsIncentiveEnrichObserver::testResolveBaseMinorPrefersAmountMinorOverMajor' => function () {
            $this->assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorPrefersAmountMinorOverMajor();
        },
            'CheckoutAvailableMethodsIncentiveEnrichObserver::testResolveBaseMinorFromTotalsGrandTotalMinor' => function () {
            $this->assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorFromTotalsGrandTotalMinor();
        },
            'CheckoutAvailableMethodsIncentiveEnrichObserver::testMajorAmountAloneStillYieldsIncentiveSavings' => function () {
            $this->assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testMajorAmountAloneStillYieldsIncentiveSavings();
        },
            'CheckoutAvailableMethodsIncentiveEnrichObserver::testZeroDecimalCurrencyUsesUnitScale' => function () {
            $this->assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testZeroDecimalCurrencyUsesUnitScale();
        },
        ];
    }

private function assert_CheckoutAssetDiscountObserver_testPaymentEventXmlRegistersCheckoutAssetObservers(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Checkout::checkout::freeze_quote::enrich', $xml);
    self::assertStringContainsString('Weline_Checkout::checkout::asset_discount::apply', $xml);
    self::assertStringContainsString('Weline\Payment\Observer\CheckoutFreezeQuoteAssetEnrichObserver', $xml);
    self::assertStringContainsString('Weline\Payment\Observer\CheckoutAssetDiscountApplyObserver', $xml);
}

private function assert_CheckoutAssetDiscountObserver_testPaymentObserversOwnAssetQuoteData(): void
{
    $freeze = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Observer/CheckoutFreezeQuoteAssetEnrichObserver.php');
    $apply = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Observer/CheckoutAssetDiscountApplyObserver.php');
    self::assertStringContainsString('AssetCheckoutDiscountQuote', $freeze);
    self::assertStringContainsString('b2b_credit', $freeze);
    self::assertStringContainsString('AssetCheckoutDiscountQuote', $apply);
    self::assertStringContainsString('typePayloadFragment', $apply);
    self::assertStringContainsString('discount_kind', $apply);
    self::assertStringNotContainsString('Weline\B2B\\', $freeze);
    self::assertStringNotContainsString('Weline\B2B\\', $apply);
}

private function assert_CheckoutAssetDiscountObserver_testB2bEventXmlDoesNotOwnCheckoutAssetObservers(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/B2B/etc/event.xml');
    self::assertStringNotContainsString('Weline_Checkout::checkout::freeze_quote::enrich', $xml);
    self::assertStringNotContainsString('Weline_Checkout::checkout::asset_discount::apply', $xml);
}

private function assert_CheckoutAssetDiscountObserver_testAssetCheckoutDiscountQuoteUsesPaymentPolicySpi(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/AssetCheckoutDiscountQuote.php');
    self::assertStringContainsString('getPolicyForScope', $src);
    self::assertStringContainsString('CustomerAssetFacadeInterface', $src);
    self::assertStringContainsString('ASSET_B2B_CREDIT', $src);
    self::assertStringNotContainsString('B2BPaymentAssetPolicyProvider', $src);
}

private function assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorFromMajorAmountOnly(): void
{
    $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(['amount' => 10.13], 'CNY');
    self::assertSame(1013, $minor);
}

private function assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorPrefersAmountMinorOverMajor(): void
{
    $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(['amount_minor' => 1013, 'amount' => 99.98999999999999], 'CNY');
    self::assertSame(1013, $minor);
}

private function assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testResolveBaseMinorFromTotalsGrandTotalMinor(): void
{
    $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(['totals' => ['grand_total_minor' => 2278], 'amount' => 1.0], 'USD');
    self::assertSame(2278, $minor);
}

private function assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testMajorAmountAloneStillYieldsIncentiveSavings(): void
{
    $baseMinor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(['amount' => 10.13], 'CNY');
    self::assertSame(1013, $baseMinor);
    $svc = new PaymentMethodIncentiveQuoteService();
    $quote = $svc->quote('fake_card', ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_publish_version' => 'v-test'], $baseMinor, 'CNY', true);
    self::assertTrue($quote['available']);
    self::assertSame(500, $quote['savings_minor']);
    self::assertGreaterThan(0, $quote['savings_minor']);
}

private function assert_CheckoutAvailableMethodsIncentiveEnrichObserver_testZeroDecimalCurrencyUsesUnitScale(): void
{
    $minor = CheckoutAvailableMethodsIncentiveEnrichObserver::resolveBaseAmountMinor(['amount' => 1500], 'JPY');
    self::assertSame(1500, $minor);
}
}
