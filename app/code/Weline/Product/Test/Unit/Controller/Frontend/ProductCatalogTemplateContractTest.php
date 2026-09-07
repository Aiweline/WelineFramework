<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;

final class ProductCatalogTemplateContractTest extends TestCase
{
    public function testCatalogListingUsesUnifiedProductCardTag(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('ProductCardRenderer::fromStorefrontOffer', $source);
        self::assertStringContainsString('show-sku="true"', $source);
        self::assertStringContainsString('weline-product-card-shelf', $source);
        self::assertStringNotContainsString('product-storefront__card product-card', $source);
        self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionaryFromOffer', $source);
    }

    public function testFromStorefrontOfferMapsQuoteOnlyWithoutZeroPrice(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 48,
            'provider_code' => 'product',
            'global_offer_uuid' => 'offer-48',
            'name' => 'STN X3 YB300R',
            'sku' => 'STN-X3-YB300R',
            'image' => '/media/stn-x3.jpg',
            'currency' => 'USD',
            'unit_price_minor' => 0,
            'stock' => 0,
            'sellable' => false,
            'message' => '商品库存不足',
            'quote_only' => true,
        ]);

        self::assertSame(48, (int)$product['id']);
        self::assertTrue(!empty($product['quote_only']));
        self::assertSame(0.0, (float)$product['price']);
        self::assertSame('USD', $product['currency']);
    }
}
