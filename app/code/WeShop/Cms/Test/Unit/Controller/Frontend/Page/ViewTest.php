<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Frontend\Page;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Controller\Frontend\Page\View;

/**
 * CMS页面控制器单元测试
 */
class ViewTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    /**
     * 测试：layoutType 属性设置为 'cms'
     */
    public function testLayoutTypeIsCms(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new View();
        $this->assertEquals('cms', $property->getValue($controller));
    }
}
