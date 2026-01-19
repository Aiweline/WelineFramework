<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Controller\Frontend\Review;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Controller\Frontend\Review\Index;

/**
 * 评论页控制器单元测试
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
     * 测试：layoutType 属性设置为 'review'
     */
    public function testLayoutTypeIsReview(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('review', $property->getValue($controller));
    }
}
