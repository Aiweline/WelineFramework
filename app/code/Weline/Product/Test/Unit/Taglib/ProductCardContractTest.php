<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Taglib\ProductCard;

final class ProductCardContractTest extends TestCase
{
    public function testTagNameAndOptionalAttrs(): void
    {
        self::assertSame('product:card', ProductCard::name());
        self::assertTrue(ProductCard::tag_self_close());
        self::assertArrayHasKey('product', ProductCard::attr());
        self::assertArrayHasKey('show-price', ProductCard::attr());
        self::assertArrayHasKey('density', ProductCard::attr());
        self::assertTrue(method_exists(ProductCard::class, 'runtimeCallback'));
    }

    public function testRendererNormalizesFlagsAndSkipsEmptyProduct(): void
    {
        $flags = ProductCardRenderer::normalizeOptions([
            'show_price' => 'false',
            'show_sku' => '1',
            'density' => 'shelf',
        ]);
        self::assertFalse($flags['show_price']);
        self::assertTrue($flags['show_sku']);
        self::assertSame('shelf', $flags['density']);
        self::assertSame('', ProductCardRenderer::render([]));
    }

    public function testFromStorefrontOfferMapsDealPriceAndSku(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 107,
            'name' => 'Demo',
            'sku' => 'SKU-107',
            'unit_price_minor' => 8910,
            'catalog_price_minor' => 9900,
            'has_deal' => true,
            'campaign_label' => '今日精选',
            'currency' => 'CNY',
            'sellable' => true,
            'global_offer_uuid' => 'offer-107',
        ]);
        self::assertSame(107, (int)$product['id']);
        self::assertSame('SKU-107', $product['sku']);
        self::assertSame(89.1, (float)$product['price']);
        self::assertSame(99.0, (float)$product['original_price']);
        self::assertTrue(!empty($product['is_sale']));
        self::assertSame('今日精选', $product['campaign_label']);
    }

    public function testAssetsAndPartialExist(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Taglib/ProductCard.php');
        self::assertFileExists($base . '/Service/ProductCardRenderer.php');
        self::assertFileExists($base . '/view/templates/frontend/partials/product-card.phtml');
        self::assertFileExists($base . '/view/statics/css/frontend/product-card.css');
        $css = (string)file_get_contents($base . '/view/statics/css/frontend/product-card.css');
        self::assertStringContainsString('.weline-product-card', $css);
        self::assertStringContainsString('--wpc-link', $css);
    }
}
