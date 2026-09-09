<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: product share hook must resolve identity without relying on request product_id alone.
 */
final class AffiliateProductShareHookContractTest extends TestCase
{
    public function testAfterAddToCartResolvesFromStorefrontOffer(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('storefront_offer', $src);
        self::assertStringContainsString('StorefrontOfferResolver', $src);
        self::assertStringContainsString('currentOffer', $src);
        self::assertStringContainsString("\$offer['product_id']", $src);
        self::assertStringContainsString('data-affiliate-share-root', $src);
        self::assertStringContainsString('分销分享', $src);
    }
}
