<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testWishlistModuleProvidesExpectedHookTemplates(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';
        $this->assertIsArray($hooks);
        $this->assertSame([], $hooks);

        $this->assertFileExists(
            BP . '/app/code/WeShop/Wishlist/view/hooks/WeShop_Product/frontend/product/detail/after-add-to-cart.phtml'
        );
        $this->assertFileExists(
            BP . '/app/code/WeShop/Wishlist/view/hooks/WeShop_Customer/frontend/account/quick-links/cards.phtml'
        );
    }
}
