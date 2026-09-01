<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class InventoryStockProjectionCacheContractTest extends TestCase
{
    public function testProductListensToInventoryStockProjectionChanged(): void
    {
        $eventXml = (string)file_get_contents(BP . 'app/code/Weline/Product/etc/event.xml');
        self::assertStringContainsString('Weline_Inventory::stock_projection_changed', $eventXml);
        self::assertStringContainsString(
            'Weline\\Product\\Observer\\InventoryStockProjectionChangedObserver',
            $eventXml,
        );

        $observer = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Observer/InventoryStockProjectionChangedObserver.php',
        );
        self::assertStringContainsString('notifyCatalogChanged', $observer);
        self::assertStringContainsString('website_id', $observer);
    }

    public function testStorefrontCatalogCacheInvalidatorAllowsDefaultWebsiteZero(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Observer/StorefrontCatalogCacheInvalidator.php',
        );
        self::assertStringNotContainsString('if ($websiteId <= 0)', $source);
        self::assertStringContainsString('website_id=0 is the default storefront scope', $source);
    }
}
