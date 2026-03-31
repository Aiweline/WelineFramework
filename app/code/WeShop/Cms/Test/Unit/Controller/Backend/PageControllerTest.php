<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use WeShop\Cms\Controller\Backend\Page;
use WeShop\Cms\Model\Page as PageModel;
use WeShop\Cms\Model\Page\LocalDescription;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

/**
 * CMS页面后端控制器单元测试
 */
class PageControllerTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Page::class));
    }

    /**
     * 测试：控制器继承自 BackendController
     */
    public function testControllerExtendsBackendController(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $parentClass = $reflection->getParentClass();

        $this->assertNotNull($parentClass);
        $this->assertEquals('Weline\Framework\App\Controller\BackendController', $parentClass->getName());
    }

    /**
     * 测试：控制器构造函数参数正确
     */
    public function testControllerConstructorParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();
        $this->assertCount(4, $parameters);

        $expectedTypes = [
            PageModel::class,
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
     * 测试：index 方法存在
     */
    public function testIndexMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    /**
     * 测试：getCreate 方法存在
     */
    public function testGetCreateMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('getCreate'));
    }

    /**
     * 测试：postCreate 方法存在
     */
    public function testPostCreateMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('postCreate'));
    }

    /**
     * 测试：getEdit 方法存在
     */
    public function testGetEditMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('getEdit'));
    }

    /**
     * 测试：postEdit 方法存在
     */
    public function testPostEditMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('postEdit'));
    }

    /**
     * 测试：postDelete 方法存在
     */
    public function testPostDeleteMethodExists(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $this->assertTrue($reflection->hasMethod('postDelete'));
    }

    /**
     * 测试：控制器方法数量正确
     */
    public function testControllerHasExpectedMethods(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $publicMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn($method) => !in_array($method->getName(), ['__construct'])
        );

        $methodNames = array_map(static fn($method) => $method->getName(), $publicMethods);

        $expectedMethods = ['index', 'getCreate', 'postCreate', 'getEdit', 'postEdit', 'postDelete'];

        foreach ($expectedMethods as $expectedMethod) {
            $this->assertContains($expectedMethod, $methodNames, "方法 $expectedMethod 应该存在");
        }
    }

    /**
     * 测试：index 方法没有参数
     */
    public function testIndexMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('index');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：getCreate 方法没有参数
     */
    public function testGetCreateMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('getCreate');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：postCreate 方法没有参数
     */
    public function testPostCreateMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postCreate');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：getEdit 方法没有参数
     */
    public function testGetEditMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('getEdit');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：postEdit 方法没有参数
     */
    public function testPostEditMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postEdit');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：postDelete 方法没有参数
     */
    public function testPostDeleteMethodHasNoParameters(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postDelete');

        $this->assertCount(0, $method->getParameters());
    }

    /**
     * 测试：控制器有 ACL 注解属性
     */
    public function testControllerHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $attributes = $reflection->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, '控制器应该有 ACL 属性');
    }

    /**
     * 测试：index 方法有 ACL 属性
     */
    public function testIndexMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('index');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'index 方法应该有 ACL 属性');
    }

    /**
     * 测试：getCreate 方法有 ACL 属性
     */
    public function testGetCreateMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('getCreate');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'getCreate 方法应该有 ACL 属性');
    }

    /**
     * 测试：postCreate 方法有 ACL 属性
     */
    public function testPostCreateMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postCreate');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'postCreate 方法应该有 ACL 属性');
    }

    /**
     * 测试：getEdit 方法有 ACL 属性
     */
    public function testGetEditMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('getEdit');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'getEdit 方法应该有 ACL 属性');
    }

    /**
     * 测试：postEdit 方法有 ACL 属性
     */
    public function testPostEditMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postEdit');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'postEdit 方法应该有 ACL 属性');
    }

    /**
     * 测试：postDelete 方法有 ACL 属性
     */
    public function testPostDeleteMethodHasAclAttribute(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $method = $reflection->getMethod('postDelete');
        $attributes = $method->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($attributes, 'postDelete 方法应该有 ACL 属性');
    }

    /**
     * 测试：控制器有私有属性
     */
    public function testControllerHasPrivateProperties(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE);

        $propertyNames = array_map(static fn($prop) => $prop->getName(), $properties);

        $this->assertContains('pageModel', $propertyNames);
        $this->assertContains('localDescriptionModel', $propertyNames);
        $this->assertContains('i18nModel', $propertyNames);
        $this->assertContains('localsModel', $propertyNames);
    }

    /**
     * 测试：控制器属性类型正确
     */
    public function testControllerPropertyTypes(): void
    {
        $reflection = new ReflectionClass(Page::class);

        $pageModelProperty = $reflection->getProperty('pageModel');
        $pageModelType = $pageModelProperty->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $pageModelType);
        $this->assertEquals(PageModel::class, $pageModelType->getName());

        $localDescProperty = $reflection->getProperty('localDescriptionModel');
        $localDescType = $localDescProperty->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $localDescType);
        $this->assertEquals(LocalDescription::class, $localDescType->getName());

        $i18nProperty = $reflection->getProperty('i18nModel');
        $i18nType = $i18nProperty->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $i18nType);
        $this->assertEquals(I18n::class, $i18nType->getName());

        $localsProperty = $reflection->getProperty('localsModel');
        $localsType = $localsProperty->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $localsType);
        $this->assertEquals(Locals::class, $localsType->getName());
    }

    /**
     * 测试：所有公共方法存在
     */
    public function testPublicMethodsExist(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $expectedMethods = ['index', 'getCreate', 'postCreate', 'getEdit', 'postEdit', 'postDelete', '__construct'];
        $actualMethods = array_map(static fn($method) => $method->getName(), $publicMethods);

        foreach ($expectedMethods as $expectedMethod) {
            $this->assertContains(
                $expectedMethod,
                $actualMethods,
                "方法 $expectedMethod 应该存在"
            );
        }
    }

    /**
     * 测试：ACL 属性包含正确的权限标识
     */
    public function testAclAttributesContainCorrectPermissions(): void
    {
        $reflection = new ReflectionClass(Page::class);
        $classAttributes = $reflection->getAttributes('Weline\Framework\Acl\Acl');

        $this->assertNotEmpty($classAttributes);
        $classAttribute = $classAttributes[0];
        $args = $classAttribute->getArguments();

        $this->assertArrayHasKey(0, $args);
        $this->assertEquals('WeShop_Cms::cms_page', $args[0]);
    }
}
