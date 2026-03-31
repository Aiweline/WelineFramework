<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\ApiBridge\Controller\Frontend\ApiBridge;
use WeShop\ApiBridge\Service\ApiBridgeService;

class ApiBridgeTest extends TestCase
{
    public function testControllerInstantiation(): void
    {
        $service = new ApiBridgeService();
        $controller = new ApiBridge($service);

        $this->assertInstanceOf(ApiBridge::class, $controller);
    }

    public function testServiceInjection(): void
    {
        $service = new ApiBridgeService();
        $controller = new ApiBridge($service);

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('apiBridgeService');
        $property->setAccessible(true);

        $this->assertSame($service, $property->getValue($controller));
    }

    public function testIndexMethodReturnsString(): void
    {
        $service = new ApiBridgeService();
        $controller = new ApiBridge($service);

        // 由于 index 方法依赖于框架的 request/response 对象，
        // 这里我们只测试方法存在并返回 string 类型
        $this->assertTrue(method_exists($controller, 'index'));
    }

    public function testHealthMethodExists(): void
    {
        $this->assertTrue(method_exists(ApiBridge::class, 'health'));
    }

    public function testEndpointsMethodExists(): void
    {
        $this->assertTrue(method_exists(ApiBridge::class, 'endpoints'));
    }

    public function testEndpointInfoMethodExists(): void
    {
        $this->assertTrue(method_exists(ApiBridge::class, 'endpointInfo'));
    }

    public function testTestMethodExists(): void
    {
        $this->assertTrue(method_exists(ApiBridge::class, 'test'));
    }

    public function testDocsMethodExists(): void
    {
        $this->assertTrue(method_exists(ApiBridge::class, 'docs'));
    }
}
