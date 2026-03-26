<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Test\Unit\Api\Rest\V1\Weshop;

use PHPUnit\Framework\TestCase;

class AuthBridgeAliasTest extends TestCase
{
    public function testAuthBridgeExtendsPrimaryWeShopAuthController(): void
    {
        $this->assertSame(
            \WeShop\Auth\Api\Rest\V1\Auth::class,
            get_parent_class(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Auth::class)
        );
    }

    public function testChallengeBridgeExtendsPrimaryWeShopChallengeController(): void
    {
        $this->assertSame(
            \WeShop\Auth\Api\Rest\V1\Auth\Challenge::class,
            get_parent_class(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Auth\Challenge::class)
        );
    }
}
