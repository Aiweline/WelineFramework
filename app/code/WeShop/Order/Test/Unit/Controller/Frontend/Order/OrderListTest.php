<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Frontend\Order\OrderList;

/**
 * 订单列表控制器单元测试
 */
class OrderListTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(OrderList::class));
    }

    /**
     * 测试：layoutType 属性设置为 'account'
     */
    public function testLayoutTypeIsAccount(): void
    {
        $reflection = new \ReflectionClass(OrderList::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new OrderList();
        $this->assertEquals('account', $property->getValue($controller));
    }
}
