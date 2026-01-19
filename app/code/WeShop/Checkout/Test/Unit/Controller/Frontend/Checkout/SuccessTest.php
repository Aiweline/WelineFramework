<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\Success;

/**
 * 订单确认页控制器单元测试
 */
class SuccessTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Success::class));
    }

    /**
     * 测试：layoutType 属性设置为 'checkout_success'
     */
    public function testLayoutTypeIsCheckoutSuccess(): void
    {
        $reflection = new \ReflectionClass(Success::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Success();
        $this->assertEquals('checkout_success', $property->getValue($controller));
    }
}
