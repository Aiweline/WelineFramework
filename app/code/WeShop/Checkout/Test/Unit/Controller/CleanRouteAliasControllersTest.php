<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Index;
use WeShop\Checkout\Controller\PlaceOrder;
use WeShop\Checkout\Controller\Success;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testCheckoutIndexAliasExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue((new \ReflectionClass(Index::class))->hasMethod('index'));
        $this->assertTrue((new \ReflectionClass(Index::class))->isSubclassOf(\WeShop\Checkout\Controller\Frontend\Checkout\Index::class));
    }

    public function testCheckoutPlaceOrderAliasExists(): void
    {
        $reflection = new \ReflectionClass(PlaceOrder::class);

        $this->assertTrue(class_exists(PlaceOrder::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Checkout\Controller\Frontend\Checkout\PlaceOrder::class));
    }

    public function testCheckoutSuccessAliasExists(): void
    {
        $this->assertTrue(class_exists(Success::class));
        $this->assertTrue((new \ReflectionClass(Success::class))->hasMethod('index'));
        $this->assertTrue((new \ReflectionClass(Success::class))->isSubclassOf(\WeShop\Checkout\Controller\Frontend\Checkout\Success::class));
    }
}
