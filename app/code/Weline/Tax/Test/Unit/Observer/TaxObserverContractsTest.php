<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Tax\Observer\CheckoutShippingMethodsEnrichDutyObserver;
use Weline\Tax\Service\DutyEstimateService;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllTaxObserverContractsPass().
 */
final class TaxObserverContractsTest extends TestCase
{
    public function testAllTaxObserverContractsPass(): void
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
            'CartSummaryEnrichTaxObserver::testObserverRegisteredAndUsesAdvisorWithoutHardCartDependency' => function () {
            $this->assert_CartSummaryEnrichTaxObserver_testObserverRegisteredAndUsesAdvisorWithoutHardCartDependency();
        },
            'CheckoutFreezeQuoteBuyerTaxIdentityObserver::testEventXmlRegistersFreezeEnrichObserver' => function () {
            $this->assert_CheckoutFreezeQuoteBuyerTaxIdentityObserver_testEventXmlRegistersFreezeEnrichObserver();
        },
            'CheckoutFreezeQuoteBuyerTaxIdentityObserver::testObserverClearsIdentityOutsideEu' => function () {
            $this->assert_CheckoutFreezeQuoteBuyerTaxIdentityObserver_testObserverClearsIdentityOutsideEu();
        },
            'CheckoutShippingMethodsEnrichDutyObserver::testEnrichesMethodWithoutChangingShippingAmount' => function () {
            $this->assert_CheckoutShippingMethodsEnrichDutyObserver_testEnrichesMethodWithoutChangingShippingAmount();
        },
            'CheckoutShippingMethodsEnrichDutyObserver::testDomesticClearsSeedDduNotice' => function () {
            $this->assert_CheckoutShippingMethodsEnrichDutyObserver_testDomesticClearsSeedDduNotice();
        },
            'CheckoutShippingMethodsEnrichDutyObserver::testCollectDestinationTaxZerosImportVatOnDutyLane' => function () {
            $this->assert_CheckoutShippingMethodsEnrichDutyObserver_testCollectDestinationTaxZerosImportVatOnDutyLane();
        },
        ];
    }

private function assert_CartSummaryEnrichTaxObserver_testObserverRegisteredAndUsesAdvisorWithoutHardCartDependency(): void
{
    $eventXml = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/etc/event.xml');
    self::assertIsString($eventXml);
    self::assertStringContainsString('Weline_Cart::cart_summary::enrich', $eventXml);
    self::assertStringContainsString('CartSummaryEnrichTaxObserver', $eventXml);
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Observer/CartSummaryEnrichTaxObserver.php');
    self::assertIsString($src);
    self::assertStringContainsString('CheckoutTaxAdvisorInterface', $src);
    self::assertStringContainsString('tax_amount_minor', $src);
    self::assertStringNotContainsString('Weline\Cart\Service\CartService', $src);
}

private function assert_CheckoutFreezeQuoteBuyerTaxIdentityObserver_testEventXmlRegistersFreezeEnrichObserver(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Checkout::checkout::freeze_quote::enrich', $xml);
    self::assertStringContainsString('CheckoutFreezeQuoteBuyerTaxIdentityObserver', $xml);
}

private function assert_CheckoutFreezeQuoteBuyerTaxIdentityObserver_testObserverClearsIdentityOutsideEu(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Observer/CheckoutFreezeQuoteBuyerTaxIdentityObserver.php');
    self::assertStringContainsString('LEGACY_PAYLOAD_KEY', $src);
    self::assertStringContainsString('PAYLOAD_KEY', $src);
    self::assertStringContainsString('mergeIntoTaxSnapshot', $src);
    self::assertStringContainsString('prevCountry', $src);
    self::assertStringContainsString('buyer_tax_country', $src);
}

private function assert_CheckoutShippingMethodsEnrichDutyObserver_testEnrichesMethodWithoutChangingShippingAmount(): void
{
    $observer = new CheckoutShippingMethodsEnrichDutyObserver();
    $event = new Event('Weline_Checkout::checkout::shipping_methods::enrich', ['methods' => [['code' => 'domestic_std', 'amount_minor' => 0, 'amount' => 0, 'duty_notice' => DutyEstimateService::NOTICE_DDU]], 'lines' => [['row_total_minor' => 21000]], 'address' => ['country_code' => 'US'], 'scope' => ['origin_country' => 'CN'], 'currency' => 'CNY']);
    $observer->execute($event);
    $methods = $event->getData('methods');
    self::assertIsArray($methods);
    self::assertCount(1, $methods);
    self::assertSame(0, (int) $methods[0]['amount_minor']);
    self::assertSame(1680, (int) $methods[0]['tax_amount_minor']);
    self::assertSame(1680, (int) $methods[0]['duty_amount_minor']);
    self::assertSame(DutyEstimateService::NOTICE_DDU, (string) $methods[0]['duty_notice']);
}

private function assert_CheckoutShippingMethodsEnrichDutyObserver_testDomesticClearsSeedDduNotice(): void
{
    $observer = new CheckoutShippingMethodsEnrichDutyObserver();
    $event = new Event('Weline_Checkout::checkout::shipping_methods::enrich', ['methods' => [['code' => 'SEED_LANE_DOMESTIC', 'amount_minor' => 477, 'duty_notice' => DutyEstimateService::NOTICE_DDU]], 'lines' => [['row_total_minor' => 73606]], 'address' => ['country_code' => 'CN'], 'scope' => ['origin_country' => 'CN'], 'currency' => 'USD']);
    $observer->execute($event);
    $methods = $event->getData('methods');
    self::assertIsArray($methods);
    self::assertSame(0, (int) $methods[0]['tax_amount_minor']);
    self::assertSame(DutyEstimateService::REASON_DOMESTIC, (string) $methods[0]['duty_estimate_reason']);
    self::assertSame('', (string) $methods[0]['duty_notice']);
}

private function assert_CheckoutShippingMethodsEnrichDutyObserver_testCollectDestinationTaxZerosImportVatOnDutyLane(): void
{
    $observer = new CheckoutShippingMethodsEnrichDutyObserver(new DutyEstimateService(), new \Weline\Tax\Service\TaxDestinationCheckoutPolicy(), \Weline\Tax\Service\TaxScopeConfig::forTesting(['enabled' => true, 'prices_include_tax' => true, 'collect_sales_tax_countries' => 'DE']));
    $event = new Event('Weline_Checkout::checkout::shipping_methods::enrich', ['methods' => [['code' => 'PUBLIC_STD_DE', 'amount_minor' => 500, 'duty_notice' => DutyEstimateService::NOTICE_DDU]], 'lines' => [['row_total_minor' => 10000]], 'address' => ['country_code' => 'DE'], 'scope' => ['origin_country' => 'CN', 'website_id' => 0], 'currency' => 'USD']);
    $observer->execute($event);
    $methods = $event->getData('methods');
    self::assertIsArray($methods);
    self::assertGreaterThan(0, (int) $methods[0]['duty_amount_minor']);
    self::assertSame(0, (int) $methods[0]['import_tax_amount_minor']);
    self::assertSame((int) $methods[0]['duty_amount_minor'], (int) $methods[0]['tax_amount_minor']);
}
}
