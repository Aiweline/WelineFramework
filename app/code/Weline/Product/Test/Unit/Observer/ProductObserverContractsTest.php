<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductObserverContractsPass().
 */
final class ProductObserverContractsTest extends TestCase
{
    public function testAllProductObserverContractsPass(): void
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
            'InventoryStockProjectionCache::testProductListensToInventoryStockProjectionChanged' => function () {
            $this->assert_InventoryStockProjectionCache_testProductListensToInventoryStockProjectionChanged();
        },
            'InventoryStockProjectionCache::testStorefrontCatalogCacheInvalidatorAllowsDefaultWebsiteZero' => function () {
            $this->assert_InventoryStockProjectionCache_testStorefrontCatalogCacheInvalidatorAllowsDefaultWebsiteZero();
        },
        ];
    }

private function assert_InventoryStockProjectionCache_testProductListensToInventoryStockProjectionChanged(): void
{
    $eventXml = (string) file_get_contents(BP . 'app/code/Weline/Product/etc/event.xml');
    self::assertStringContainsString('Weline_Inventory::stock_projection_changed', $eventXml);
    self::assertStringContainsString('Weline\Product\Observer\InventoryStockProjectionChangedObserver', $eventXml);
    $observer = (string) file_get_contents(BP . 'app/code/Weline/Product/Observer/InventoryStockProjectionChangedObserver.php');
    self::assertStringContainsString('notifyCatalogChanged', $observer);
    self::assertStringContainsString('website_id', $observer);
}

private function assert_InventoryStockProjectionCache_testStorefrontCatalogCacheInvalidatorAllowsDefaultWebsiteZero(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/Observer/StorefrontCatalogCacheInvalidator.php');
    self::assertStringNotContainsString('if ($websiteId <= 0)', $source);
    self::assertStringContainsString('website_id=0 is the default storefront scope', $source);
}
}
