<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Frontend\Order\Cancel;

/**
 * 取消订单控制器单元测试
 */
class CancelTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Cancel::class));
    }

    /**
     * 测试：控制器有 postIndex 方法
     */
    public function testControllerHasPostIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Cancel::class);
        $this->assertTrue($reflection->hasMethod('postIndex'));
    }
}
