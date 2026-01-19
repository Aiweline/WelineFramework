<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\Account\Index;

/**
 * 用户账户首页控制器单元测试
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
     * 测试：layoutType 属性设置为 'account'
     */
    public function testLayoutTypeIsAccount(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('account', $property->getValue($controller));
    }
}
