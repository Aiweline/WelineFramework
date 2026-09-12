<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';

final class SaveProductSkuTiersRouteContractTest extends TestCase
{
    public function testControlCenterDeclaresSaveProductSkuTiersAction(): void
    {
        $file = dirname(__DIR__, 3) . '/Controller/Backend/ControlCenter.php';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('function saveProductSkuTiers', $src);
        self::assertStringContainsString('upsertSkuQtyTiers', $src);
        self::assertStringContainsString("Weline_B2B::commerce:partner:price-lists", $src);
    }

    public function testPriceListFormKeepsAmountMinorAndAddsMinQty(): void
    {
        $file = dirname(__DIR__, 3) . '/view/templates/Backend/ControlCenter/index.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('name="amount_minor"', $src);
        self::assertStringContainsString('name="min_qty"', $src);
        self::assertStringContainsString('name="tiers[1][min_qty]"', $src);
        self::assertStringContainsString('prefillSku', $src);
    }

    public function testProductHooksUseAbsoluteB2bBackendUrls(): void
    {
        $hooks = [
            dirname(__DIR__, 3) . '/view/hooks/Weline_Product/backend/catalog/edit/basic-after.phtml',
            dirname(__DIR__, 3) . '/view/hooks/Weline_Product/backend/catalog/edit/offers-after.phtml',
        ];
        foreach ($hooks as $file) {
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            self::assertStringNotContainsString("getUrl('*/b2b/", $src, $file);
            self::assertStringNotContainsString('getUrl("*/b2b/', $src, $file);
            self::assertStringContainsString("getBackendUrl(", $src, $file);
            self::assertStringContainsString('b2b/backend/control-center/price-lists', $src, $file);
        }
        $offers = (string)file_get_contents($hooks[1]);
        self::assertStringContainsString('b2b/backend/control-center/save-product-sku-tiers', $offers);
    }
}
