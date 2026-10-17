<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllDropshipObserverContractsPass().
 */
final class DropshipObserverContractsTest extends TestCase
{
    public function testAllDropshipObserverContractsPass(): void
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
            'CheckoutFreightOverlayObserver::testEventXmlRegistersCheckoutFreightObservers' => function () {
            $this->assert_CheckoutFreightOverlayObserver_testEventXmlRegistersCheckoutFreightObservers();
        },
            'OrderPaidObserverCatalogLines::testObserverReadsCatalogSnapshotAndShippingJson' => function () {
            $this->assert_OrderPaidObserverCatalogLines_testObserverReadsCatalogSnapshotAndShippingJson();
        },
        ];
    }

private function assert_CheckoutFreightOverlayObserver_testEventXmlRegistersCheckoutFreightObservers(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Checkout::checkout::shipping_quote::overlay', $xml);
    self::assertStringContainsString('CheckoutShippingQuoteOverlayObserver', $xml);
    self::assertStringContainsString('Weline_Checkout::checkout::shipping_methods::enrich', $xml);
    self::assertStringContainsString('CheckoutShippingMethodsEnrichObserver', $xml);
}

private function assert_OrderPaidObserverCatalogLines_testObserverReadsCatalogSnapshotAndShippingJson(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Observer/OrderPaidObserver.php');
    self::assertStringContainsString('catalog_snapshot_json', $src);
    self::assertStringContainsString('collectRawLines', $src);
    self::assertStringContainsString('OrderItem', $src);
    self::assertStringContainsString('qty_minor', $src);
    self::assertStringContainsString('shipping_snapshot_json', $src);
    self::assertStringContainsString('decodeJsonMap', $src);
    self::assertStringContainsString('extractShippingAddress', $src);
}
}
