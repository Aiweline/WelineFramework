<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Order\Cancel;
use WeShop\Frontend\Controller\Order\List\Index;
use WeShop\Frontend\Controller\Order\RetryPayment;
use WeShop\Frontend\Controller\Order\View;

class OrderCleanRouteControllersTest extends TestCase
{
    public function testOrderListAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Order\Controller\Frontend\Order\OrderList::class));
    }

    public function testOrderViewAliasExists(): void
    {
        $reflection = new \ReflectionClass(View::class);

        $this->assertTrue(class_exists(View::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Order\Controller\Frontend\Order\View::class));
    }

    public function testOrderRetryPaymentAliasExists(): void
    {
        $reflection = new \ReflectionClass(RetryPayment::class);

        $this->assertTrue(class_exists(RetryPayment::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Order\Controller\Frontend\Order\RetryPayment::class));
    }

    public function testOrderCancelAliasProvidesSafeEntryPoints(): void
    {
        $reflection = new \ReflectionClass(Cancel::class);

        $this->assertTrue(class_exists(Cancel::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Order\Controller\Frontend\Order\Cancel::class));
    }
}
