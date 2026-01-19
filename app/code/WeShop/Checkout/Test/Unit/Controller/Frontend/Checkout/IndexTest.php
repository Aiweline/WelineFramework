<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\Index;

/**
 * 结账页控制器单元测试
 */
class IndexTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    /**
     * 测试：控制器继承 BaseController
     */
    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Frontend\Controller\BaseController::class));
    }

    /**
     * 测试：layoutType 属性设置为 'checkout'
     */
    public function testLayoutTypeIsCheckout(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('checkout', $property->getValue($controller));
    }
}
