<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionUnionType;
use WeShop\Cms\Model\Page;
use WeShop\Cms\Model\Page\LocalDescription;
use WeShop\Cms\Service\PageService;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

/**
 * CMS页面服务单元测试
 */
class PageServiceTest extends TestCase
{
    /**
     * 测试：服务类存在
     */
    public function testServiceClassExists(): void
    {
        $this->assertTrue(class_exists(PageService::class));
    }

    /**
     * 测试：服务类构造函数参数正确
     */
    public function testServiceConstructorParameters(): void
    {
        $reflection = new \ReflectionClass(PageService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();
        $this->assertCount(4, $parameters);

        $expectedTypes = [
            Page::class,
            LocalDescription::class,
            I18n::class,
            Locals::class,
        ];

        foreach ($parameters as $index => $parameter) {
            $type = $parameter->getType();
            $this->assertNotNull($type);
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertEquals($expectedTypes[$index], $type->getName());
        }
    }

    /**
     * 测试：getPage 方法返回 null 当页面不存在
     */
    public function testGetPageReturnsNullWhenPageNotExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getPage'));
    }

    /**
     * 测试：getPageById 方法存在
     */
    public function testGetPageByIdMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getPageById'));
    }

    /**
     * 测试：getPageList 方法存在
     */
    public function testGetPageListMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getPageList'));
    }

    /**
     * 测试：getPageList 方法签名正确
     */
    public function testGetPageListMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'getPageList');
        $params = $reflection->getParameters();

        $this->assertCount(5, $params);

        $this->assertEquals('page', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals(1, $params[0]->getDefaultValue());

        $this->assertEquals('size', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());
        $this->assertEquals(20, $params[1]->getDefaultValue());

        $this->assertEquals('filters', $params[2]->getName());
        $this->assertEquals('array', $params[2]->getType()->getName());
        $this->assertEquals([], $params[2]->getDefaultValue());

        $this->assertEquals('orderField', $params[3]->getName());
        $this->assertEquals('string', $params[3]->getType()->getName());
        $this->assertEquals(Page::schema_fields_CREATE_TIME, $params[3]->getDefaultValue());

        $this->assertEquals('orderDir', $params[4]->getName());
        $this->assertEquals('string', $params[4]->getType()->getName());
        $this->assertEquals('DESC', $params[4]->getDefaultValue());
    }

    /**
     * 测试：getPageList 返回类型是数组
     */
    public function testGetPageListReturnType(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'getPageList');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * 测试：createPage 方法存在
     */
    public function testCreatePageMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'createPage'));
    }

    /**
     * 测试：createPage 方法签名正确
     */
    public function testCreatePageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'createPage');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('pageData', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals(Page::class, $returnType->getName());
    }

    /**
     * 测试：updatePage 方法存在
     */
    public function testUpdatePageMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'updatePage'));
    }

    /**
     * 测试：updatePage 方法签名正确
     */
    public function testUpdatePageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'updatePage');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('pageId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $this->assertEquals('pageData', $params[1]->getName());
        $this->assertEquals('array', $params[1]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals(Page::class, $returnType->getName());
    }

    /**
     * 测试：updatePageStatus 方法存在
     */
    public function testUpdatePageStatusMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'updatePageStatus'));
    }

    /**
     * 测试：updatePageStatus 方法签名正确
     */
    public function testUpdatePageStatusMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'updatePageStatus');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('pageId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $this->assertEquals('status', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    /**
     * 测试：deletePage 方法存在
     */
    public function testDeletePageMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'deletePage'));
    }

    /**
     * 测试：deletePage 方法签名正确
     */
    public function testDeletePageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'deletePage');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('pageId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    /**
     * 测试：validateHandle 方法存在
     */
    public function testValidateHandleMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'validateHandle'));
    }

    /**
     * 测试：validateHandle 方法签名正确
     */
    public function testValidateHandleMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'validateHandle');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('handle', $params[0]->getName());
        $this->assertEquals('string', $params[0]->getType()->getName());

        $this->assertEquals('excludePageId', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());
        $this->assertEquals(0, $params[1]->getDefaultValue());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    /**
     * 测试：getActivePages 方法存在
     */
    public function testGetActivePagesMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getActivePages'));
    }

    /**
     * 测试：getChildPages 方法存在
     */
    public function testGetChildPagesMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getChildPages'));
    }

    /**
     * 测试：getChildPages 方法签名正确
     */
    public function testGetChildPagesMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'getChildPages');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('parentId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * 测试：savePage 方法存在（兼容旧接口）
     */
    public function testSavePageMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'savePage'));
    }

    /**
     * 测试：savePage 方法签名正确
     */
    public function testSavePageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'savePage');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('pageData', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals(Page::class, $returnType->getName());
    }

    /**
     * 测试：getPageTypes 方法存在
     */
    public function testGetPageTypesMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getPageTypes'));
    }

    /**
     * 测试：getPageTypes 返回正确类型
     */
    public function testGetPageTypesReturnType(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'getPageTypes');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * 测试：getTotalCount 方法存在
     */
    public function testGetTotalCountMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'getTotalCount'));
    }

    /**
     * 测试：getTotalCount 方法签名正确
     */
    public function testGetTotalCountMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'getTotalCount');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('filters', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
        $this->assertEquals([], $params[0]->getDefaultValue());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    /**
     * 测试：hasChildPages 方法存在
     */
    public function testHasChildPagesMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'hasChildPages'));
    }

    /**
     * 测试：hasChildPages 方法签名正确
     */
    public function testHasChildPagesMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'hasChildPages');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('pageId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    /**
     * 测试：batchUpdateStatus 方法存在
     */
    public function testBatchUpdateStatusMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'batchUpdateStatus'));
    }

    /**
     * 测试：batchUpdateStatus 方法签名正确
     */
    public function testBatchUpdateStatusMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'batchUpdateStatus');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('pageIds', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());

        $this->assertEquals('status', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    /**
     * 测试：duplicatePage 方法存在
     */
    public function testDuplicatePageMethodExists(): void
    {
        $this->assertTrue(method_exists(PageService::class, 'duplicatePage'));
    }

    /**
     * 测试：duplicatePage 方法签名正确
     */
    public function testDuplicatePageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(PageService::class, 'duplicatePage');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);

        $this->assertEquals('pageId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());

        $this->assertEquals('newHandle', $params[1]->getName());
        $this->assertEquals('string', $params[1]->getType()->getName());

        $this->assertEquals('newName', $params[2]->getName());
        $this->assertEquals('string', $params[2]->getType()->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals(Page::class, $returnType->getName());
    }

    /**
     * 测试：Page 模型常量定义正确
     */
    public function testPageModelConstants(): void
    {
        $this->assertEquals('page_id', Page::schema_fields_ID);
        $this->assertEquals('handle', Page::schema_fields_HANDLE);
        $this->assertEquals('type', Page::schema_fields_TYPE);
        $this->assertEquals('name', Page::schema_fields_NAME);
        $this->assertEquals('title', Page::schema_fields_TITLE);
        $this->assertEquals('content', Page::schema_fields_CONTENT);
        $this->assertEquals('parent_id', Page::schema_fields_PARENT_ID);
        $this->assertEquals('status', Page::schema_fields_STATUS);
        $this->assertEquals('create_time', Page::schema_fields_CREATE_TIME);
        $this->assertEquals('update_time', Page::schema_fields_UPDATE_TIME);
        $this->assertEquals(0, Page::STATUS_DRAFT);
        $this->assertEquals(1, Page::STATUS_PUBLISHED);
    }

    /**
     * 测试：Page 模型页面类型常量完整
     */
    public function testPageTypesConstants(): void
    {
        $this->assertEquals('home_page', Page::TYPE_HOME);
        $this->assertEquals('about_page', Page::TYPE_ABOUT);
        $this->assertEquals('contact_page', Page::TYPE_CONTACT);
        $this->assertEquals('privacy_policy', Page::TYPE_PRIVACY_POLICY);
        $this->assertEquals('terms_of_service', Page::TYPE_TERMS_OF_SERVICE);
        $this->assertEquals('refund_policy', Page::TYPE_REFUND_POLICY);
        $this->assertEquals('shipping_policy', Page::TYPE_SHIPPING_POLICY);
        $this->assertEquals('custom_page', Page::TYPE_CUSTOM);
    }

    /**
     * 测试：getPageTypes 返回完整类型列表
     */
    public function testGetPageTypesReturnsArray(): void
    {
        $types = Page::getPageTypes();

        $this->assertIsArray($types);
        $this->assertCount(8, $types);
        $this->assertArrayHasKey(Page::TYPE_HOME, $types);
        $this->assertArrayHasKey(Page::TYPE_ABOUT, $types);
        $this->assertArrayHasKey(Page::TYPE_CONTACT, $types);
        $this->assertArrayHasKey(Page::TYPE_PRIVACY_POLICY, $types);
        $this->assertArrayHasKey(Page::TYPE_TERMS_OF_SERVICE, $types);
        $this->assertArrayHasKey(Page::TYPE_REFUND_POLICY, $types);
        $this->assertArrayHasKey(Page::TYPE_SHIPPING_POLICY, $types);
        $this->assertArrayHasKey(Page::TYPE_CUSTOM, $types);
    }

    /**
     * 测试：Page 模型 schema_table 正确
     */
    public function testPageModelSchemaTable(): void
    {
        $this->assertEquals('weshop_cms_page', Page::schema_table);
        $this->assertEquals('page_id', Page::schema_primary_key);
    }

    /**
     * 测试：Page 模型主键字段存在
     */
    public function testPageModelHasPrimaryKeyConstant(): void
    {
        $this->assertEquals('page_id', Page::schema_fields_ID);
        $this->assertEquals('page_id', Page::schema_primary_key);
    }

    /**
     * 测试：Page 模型包含多语言相关字段
     */
    public function testPageModelHasI18nFields(): void
    {
        $this->assertEquals('locales', Page::schema_fields_LOCALES);
        $this->assertEquals('default_locale', Page::schema_fields_DEFAULT_LOCALE);
        $this->assertEquals('meta_title', Page::schema_fields_META_TITLE);
        $this->assertEquals('meta_description', Page::schema_fields_META_DESCRIPTION);
        $this->assertEquals('meta_keywords', Page::schema_fields_META_KEYWORDS);
    }

    /**
     * 测试：Page 模型包含样式相关字段
     */
    public function testPageModelHasStyleFields(): void
    {
        $this->assertEquals('style', Page::schema_fields_STYLE);
        $this->assertEquals('style_setting', Page::schema_fields_STYLE_SETTING);
    }

    /**
     * 测试：Page 模型包含SEO追踪字段
     */
    public function testPageModelHasTrackingFields(): void
    {
        $this->assertEquals('ga4_id', Page::schema_fields_GA4_ID);
        $this->assertEquals('gtm_id', Page::schema_fields_GTM_ID);
        $this->assertEquals('fb_pixel_id', Page::schema_fields_FB_PIXEL_ID);
    }

    /**
     * 测试：Page 模型包含品牌字段
     */
    public function testPageModelHasBrandFields(): void
    {
        $this->assertEquals('logo', Page::schema_fields_LOGO);
        $this->assertEquals('icon', Page::schema_fields_ICON);
    }

    /**
     * 测试：Page 模型包含重定向字段
     */
    public function testPageModelHasRedirectField(): void
    {
        $this->assertEquals('redirect_url', Page::schema_fields_REDIRECT_URL);
    }

    /**
     * 测试：LocalDescription 模型字段存在
     */
    public function testLocalDescriptionModelFields(): void
    {
        $this->assertEquals('name', LocalDescription::schema_fields_NAME);
        $this->assertEquals('title', LocalDescription::schema_fields_TITLE);
        $this->assertEquals('content', LocalDescription::schema_fields_CONTENT);
        $this->assertEquals('meta_title', LocalDescription::schema_fields_META_TITLE);
        $this->assertEquals('meta_description', LocalDescription::schema_fields_META_DESCRIPTION);
        $this->assertEquals('meta_keywords', LocalDescription::schema_fields_META_KEYWORDS);
    }
}
