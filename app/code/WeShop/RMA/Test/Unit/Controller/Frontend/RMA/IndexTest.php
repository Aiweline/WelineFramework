<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Frontend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Frontend\RMA\Index;

/**
 * 退换货页控制器单元测试
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
     * 测试：layoutType 属性设置为 'rma'
     */
    public function testLayoutTypeIsRma(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('rma', $property->getValue($controller));
    }

    /**
     * 测试：控制器有 postCreate 方法
     */
    public function testControllerHasPostCreateMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('postCreate'));
    }
}
