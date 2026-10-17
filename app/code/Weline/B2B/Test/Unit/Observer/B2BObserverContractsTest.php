<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllB2BObserverContractsPass().
 */
final class B2BObserverContractsTest extends TestCase
{
    public function testAllB2BObserverContractsPass(): void
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
            'B2BCheckoutCreditEnrichObserver::testB2bNoLongerRegistersCheckoutCreditDataObservers' => function () {
            $this->assert_B2BCheckoutCreditEnrichObserver_testB2bNoLongerRegistersCheckoutCreditDataObservers();
        },
            'B2BCheckoutCreditEnrichObserver::testB2bCheckoutCreditQuoteIsThinPaymentDelegate' => function () {
            $this->assert_B2BCheckoutCreditEnrichObserver_testB2bCheckoutCreditQuoteIsThinPaymentDelegate();
        },
        ];
    }

private function assert_B2BCheckoutCreditEnrichObserver_testB2bNoLongerRegistersCheckoutCreditDataObservers(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/etc/event.xml');
    self::assertStringNotContainsString('Weline_Checkout::checkout::freeze_quote::enrich', $xml);
    self::assertStringNotContainsString('Weline_Checkout::checkout::asset_discount::apply', $xml);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Observer/CheckoutFreezeQuoteCreditEnrichObserver.php');
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Observer/CheckoutAssetDiscountApplyObserver.php');
}

private function assert_B2BCheckoutCreditEnrichObserver_testB2bCheckoutCreditQuoteIsThinPaymentDelegate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Service/B2BCheckoutCreditQuote.php');
    self::assertStringContainsString('AssetCheckoutDiscountQuote', $src);
    self::assertStringContainsString('薄委托', $src);
    self::assertStringNotContainsString('CustomerAssetFacadeInterface', $src);
    self::assertStringNotContainsString('getBalance', $src);
}
}
