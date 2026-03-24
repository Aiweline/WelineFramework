<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeWishlistPageTest extends TestCase
{
    public function testWishlistPageUsesPageDataUrlsAndInteractiveContracts(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/wishlist/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getData('wishlist_url')", $template);
        $this->assertStringContainsString("getData('browse_url')", $template);
        $this->assertStringContainsString("getData('remove_url')", $template);
        $this->assertStringContainsString("item['product_url']", $template);
        $this->assertStringContainsString("product['product_url']", $template);
        $this->assertStringContainsString('data-remove-wishlist-item', $template);
        $this->assertStringContainsString('fetch(', $template);
    }
}
