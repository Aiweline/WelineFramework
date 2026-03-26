<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemePixelMarkerTest extends TestCase
{
    public function testAddToCartAndWishlistMarkersExistInCatalogAndProductTemplates(): void
    {
        $productTemplate = $this->readTemplate('design/WeShop/default/frontend/pages/product/view.phtml');
        $categoryTemplate = $this->readTemplate('design/WeShop/default/frontend/pages/catalog/category.phtml');

        $this->assertStringContainsString('weline-pixel::add_to_cart', $productTemplate);
        $this->assertStringContainsString('weline-pixel::add_to_wishlist', $productTemplate);

        $this->assertStringContainsString('weline-pixel::add_to_cart', $categoryTemplate);
        $this->assertStringContainsString('weline-pixel::add_to_wishlist', $categoryTemplate);
    }

    public function testBeginCheckoutMarkersExistInCheckoutEntryTemplates(): void
    {
        $cartTemplate = $this->readTemplate('design/WeShop/default/frontend/pages/cart/index.phtml');
        $headerTemplate = $this->readTemplate('design/WeShop/default/frontend/partials/header/default.phtml');
        $checkoutTemplate = $this->readTemplate('design/WeShop/default/frontend/pages/checkout/index.phtml');

        $this->assertStringContainsString('weline-pixel::begin_checkout', $cartTemplate);
        $this->assertStringContainsString('weline-pixel::begin_checkout', $headerTemplate);
        $this->assertStringContainsString('weline-pixel::begin_checkout', $checkoutTemplate);
    }

    private function readTemplate(string $relativePath): string
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../' . ltrim($relativePath, '/'));
        $this->assertIsString($template);

        return $template;
    }
}
