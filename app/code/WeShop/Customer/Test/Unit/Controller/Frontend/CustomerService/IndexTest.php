<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\CustomerService;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\CustomerService\Index;

/**
 * 客户服务页控制器单元测试
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
     * 测试：layoutType 属性设置为 'customer_service'
     */
    public function testLayoutTypeIsCustomerService(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('customer_service', $property->getValue($controller));
    }
}
