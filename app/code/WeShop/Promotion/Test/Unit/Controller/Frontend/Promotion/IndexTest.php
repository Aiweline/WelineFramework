<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Frontend\Promotion;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Controller\Frontend\Promotion\Index;

/**
 * 活动优惠页控制器单元测试
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
     * 测试：layoutType 属性设置为 'promotion'
     */
    public function testLayoutTypeIsPromotion(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('promotion', $property->getValue($controller));
    }
}
