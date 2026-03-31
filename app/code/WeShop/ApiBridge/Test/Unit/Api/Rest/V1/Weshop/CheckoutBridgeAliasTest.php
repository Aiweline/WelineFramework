<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Test\Unit\Api\Rest\V1\Weshop;

use PHPUnit\Framework\TestCase;

class CheckoutBridgeAliasTest extends TestCase
{
    public function testCheckoutBridgeExtendsPrimaryWeShopCheckoutController(): void
    {
        $this->assertSame(
            \WeShop\Checkout\Api\Rest\V1\Checkout::class,
            get_parent_class(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Checkout::class)
        );
    }
}
