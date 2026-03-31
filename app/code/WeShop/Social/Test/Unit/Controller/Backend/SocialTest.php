<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Social\Controller\Backend\Social;
use WeShop\Social\Service\SocialService;

class SocialTest extends TestCase
{
    public function testIndexReturnsString(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getFooterSocialLinks')
            ->willReturn([
                [
                    'platform' => 'facebook',
                    'label' => 'Facebook',
                    'icon' => 'facebook',
                    'url' => 'https://facebook.com/example',
                ],
                [
                    'platform' => 'x',
                    'label' => 'X',
                    'icon' => 'x',
                    'url' => 'https://x.com/example',
                ],
            ]);

        $controller = new Social();
        $controller->setSocialService($socialService);

        $result = $controller->index();

        $this->assertIsString($result);
        $this->assertStringContainsString('social-configuration', $result);
    }

    public function testStatsReturnsPlatformCounts(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->exactly(5))
            ->method('getShareCount')
            ->willReturnOnConsecutiveCalls(10, 20, 15, 8, 5);

        $controller = new Social();
        $controller->setSocialService($socialService);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'product_id' => 123,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->stats();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(123, $data['data']['product_id']);
        $this->assertEquals(58, $data['data']['total']);
        $this->assertArrayHasKey('by_platform', $data['data']);
    }

    public function testStatsWithInvalidProductIdReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())
            ->method('getShareCount');

        $controller = new Social();
        $controller->setSocialService($socialService);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'product_id' => 0,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->stats();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid product ID', $data['message']);
    }

    public function testJsonResponseReturnsValidJson(): void
    {
        $controller = new Social();

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('jsonResponse');
        $method->setAccessible(true);

        $data = [
            'success' => true,
            'message' => 'Test message',
            'data' => ['key' => 'value'],
        ];

        $result = $method->invoke($controller, $data);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertEquals($data, $decoded);
    }
}
