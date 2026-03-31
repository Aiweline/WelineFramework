<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Frontend\Page;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Controller\Frontend\Page\View;
use WeShop\Cms\Model\Page;

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

    /**
     * 测试：页面数据映射使用 CMS 模型真实字段常量
     */
    public function testBuildPageDataMapsSupportedSchemaFields(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(15);
        $page->method('getData')->willReturnMap([
            [Page::schema_fields_TITLE, null, 'About us'],
            [Page::schema_fields_HANDLE, null, 'about-us'],
            [Page::schema_fields_CONTENT, null, '<p>Welcome</p>'],
            [Page::schema_fields_STATUS, null, Page::STATUS_PUBLISHED],
            [Page::schema_fields_CREATE_TIME, null, '2026-03-31 10:00:00'],
            [Page::schema_fields_UPDATE_TIME, null, '2026-03-31 11:00:00'],
        ]);

        $method = new \ReflectionMethod(View::class, 'buildPageData');
        $method->setAccessible(true);
        $data = $method->invoke(null, $page);

        $this->assertSame(15, $data['page_id']);
        $this->assertSame('About us', $data['title']);
        $this->assertSame('about-us', $data['identifier']);
        $this->assertSame('<p>Welcome</p>', $data['content']);
        $this->assertTrue($data['is_active']);
        $this->assertSame('2026-03-31 10:00:00', $data['created_at']);
        $this->assertSame('2026-03-31 11:00:00', $data['updated_at']);
    }
}
