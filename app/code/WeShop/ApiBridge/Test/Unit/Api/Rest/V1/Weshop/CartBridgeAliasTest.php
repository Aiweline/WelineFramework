<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Test\Unit\Api\Rest\V1\Weshop;

use PHPUnit\Framework\TestCase;

class CartBridgeAliasTest extends TestCase
{
    public function testCartBridgeExtendsPrimaryWeShopCartController(): void
    {
        $this->assertSame(
            \WeShop\Cart\Api\Rest\V1\Cart::class,
            get_parent_class(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Cart::class)
        );
    }
}
