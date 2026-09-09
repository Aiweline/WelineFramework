<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;

final class ProductCardImageLoadingPolicyTest extends TestCase
{
    public function testListingIndexIsPreservedAndFirstViewportUsesHighPriorityImage(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 101,
            'name' => 'Demo',
            'image' => '/pub/media/demo.jpg',
        ], 0);

        self::assertSame(0, $product['card_index']);
        self::assertSame(
            ['loading' => 'eager', 'fetchpriority' => 'high'],
            ProductCardRenderer::imageLoadingAttributes($product),
        );
    }

    public function testImagesOutsideInitialViewportRemainNativeLazy(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 102,
            'name' => 'Demo',
            'image' => '/pub/media/demo.jpg',
        ], 8);

        self::assertSame(8, $product['card_index']);
        self::assertSame(
            ['loading' => 'lazy'],
            ProductCardRenderer::imageLoadingAttributes($product),
        );
    }
}
