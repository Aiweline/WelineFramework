<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: JS stock label must keep %{1} for client replace.
 * __() defaults args='' and would otherwise strip the placeholder to empty.
 */
final class ProductStockLabelContractTest extends TestCase
{
    public function testRenderStockKeepsPlaceholderSentinel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml';
        $jsPath = dirname(__DIR__, 3) . '/view/statics/js/widgets/widget-product-info-0.js';
        self::assertFileExists($path);
        self::assertFileExists($jsPath);
        $src = (string) file_get_contents($path);
        $js = (string) file_get_contents($jsPath);

        self::assertStringContainsString("__('仅剩 %{1} 件', ['%{1}'])", $src);
        self::assertStringContainsString(".replace('%{1}', String(stock))", $js);
        self::assertStringNotContainsString(
            "json_encode((string)__('仅剩 %{1} 件'), JSON_UNESCAPED_UNICODE)",
            $src
        );
    }

    public function testBuyboxFailsafeAndAvailabilitySync(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('data-purchase-failsafe', $src);
        self::assertStringContainsString("product-native-detail__qty-row\"<?= \$sellable ? '' : ' hidden' ?>", $src);
        self::assertStringContainsString('product-native-detail__wishlist btn-wishlist', $src);
        self::assertStringContainsString('data-testid="product-add-wishlist"', $src);
        self::assertStringContainsString('data-weline-load="compareShopper,storefrontShopperToast"', $src);
        self::assertStringNotContainsString('if (!$quoteOnly && $sellable):', $src);
        self::assertStringContainsString("__('当前规格暂不可售')", $src);
    }
}
