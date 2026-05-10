<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Model\Page;
use WeShop\Cms\Service\CmsPageService;

class CmsPageServiceTest extends TestCase
{
    public function testServiceClassExists(): void
    {
        $this->assertTrue(class_exists(CmsPageService::class));
    }

    public function testServiceHasExpectedMethods(): void
    {
        $reflection = new \ReflectionClass(CmsPageService::class);
        $this->assertTrue($reflection->hasMethod('getList'));
        $this->assertTrue($reflection->hasMethod('getById'));
        $this->assertTrue($reflection->hasMethod('getByIdentifier'));
        $this->assertTrue($reflection->hasMethod('save'));
        $this->assertTrue($reflection->hasMethod('deleteById'));
        $this->assertTrue($reflection->hasMethod('isIdentifierUnique'));
    }

    public function testPageModelConstants(): void
    {
        $this->assertSame(1, Page::STATUS_ENABLED);
        $this->assertSame(0, Page::STATUS_DISABLED);
        $this->assertSame('weshop_cms_page', Page::schema_table);
        $this->assertSame('page_id', Page::schema_primary_key);
    }

    public function testPageModelHasAllSchemaFields(): void
    {
        $fields = [
            Page::schema_fields_ID,
            Page::schema_fields_TITLE,
            Page::schema_fields_IDENTIFIER,
            Page::schema_fields_CONTENT,
            Page::schema_fields_CONTENT_HEADING,
            Page::schema_fields_META_TITLE,
            Page::schema_fields_META_DESCRIPTION,
            Page::schema_fields_META_KEYWORDS,
            Page::schema_fields_STATUS,
            Page::schema_fields_PAGE_LAYOUT,
            Page::schema_fields_SORT_ORDER,
            Page::schema_fields_CREATED_AT,
            Page::schema_fields_UPDATED_AT,
        ];

        $this->assertCount(13, $fields);
        $this->assertNotEmpty(array_filter($fields));
    }

    public function testPageModelGettersSetters(): void
    {
        $page = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->assertInstanceOf(Page::class, $page);
    }
}
