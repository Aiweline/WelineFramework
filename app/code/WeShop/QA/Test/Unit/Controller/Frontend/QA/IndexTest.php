<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Controller\Frontend\QA;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Controller\Frontend\QA\Index;

/**
 * 商品问答页控制器单元测试
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
     * 测试：layoutType 属性设置为 'qa'
     */
    public function testLayoutTypeIsQa(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new Index();
        $this->assertEquals('qa', $property->getValue($controller));
    }
}
