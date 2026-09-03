<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\ProductCardAddToCartParams;

final class ProductCardAddToCartParamsAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
    }

    public function testEmitOnceSkipsUntilReset(): void
    {
        $hits = 0;
        $emit = static function () use (&$hits): void {
            $hits++;
        };

        ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
        ProductCardAddToCartParams::emitPurchaseActionsAssetsOnce($emit);
        ProductCardAddToCartParams::emitPurchaseActionsAssetsOnce($emit);
        self::assertSame(1, $hits);
        self::assertTrue(RequestContext::has('theme.product_card_purchase_actions_assets_emitted'));

        ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
        ProductCardAddToCartParams::emitPurchaseActionsAssetsOnce($emit);
        self::assertSame(2, $hits);
    }

    public function testBuildPurchaseActionsStyleTagContainsMarkerAndThemeTokenRules(): void
    {
        $tag = ProductCardAddToCartParams::buildPurchaseActionsStyleTag();

        self::assertStringContainsString('data-weline-product-card-purchase-actions="1"', $tag);
        self::assertStringContainsString('.product-card-purchase-actions', $tag);
        self::assertStringContainsString('.btn-buy-now', $tag);
        self::assertStringContainsString('var(--weline-theme-primary)', $tag);
        self::assertStringContainsString('var(--weline-theme-surface-raised)', $tag);
        self::assertStringContainsString('var(--weline-theme-success-surface)', $tag);
        self::assertStringContainsString('.product-storefront__sku', $tag);
        self::assertStringContainsString('text-overflow: ellipsis', $tag);
        self::assertStringContainsString('white-space: nowrap', $tag);
        self::assertStringNotContainsString('#ffd814', $tag);
        self::assertStringNotContainsString('#ffa41c', $tag);
        self::assertStringNotContainsString('px', $tag);
        self::assertStringNotContainsString('rgb', $tag);
    }
}
