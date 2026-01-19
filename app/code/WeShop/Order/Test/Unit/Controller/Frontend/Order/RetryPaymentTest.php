<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Frontend\Order\RetryPayment;

/**
 * 继续支付控制器单元测试
 */
class RetryPaymentTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(RetryPayment::class));
    }
}
