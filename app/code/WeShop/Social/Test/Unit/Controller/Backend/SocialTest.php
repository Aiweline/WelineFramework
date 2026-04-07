<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Social\Controller\Backend\Social;
use WeShop\Social\Service\SocialService;
use Weline\Framework\Http\Request;

final class SocialTest extends TestCase
{
    public function testIndexReturnsString(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getFooterSocialLinks')
            ->willReturn([
                ['platform' => 'facebook', 'label' => 'Facebook', 'url' => 'https://facebook.com/example'],
                ['platform' => 'x', 'label' => 'X', 'url' => 'https://x.com/example'],
            ]);

        $controller = $this->getMockBuilder(Social::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->setSocialService($socialService);

        $assignments = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Social::templates/Backend/Social/Config/index.phtml')
            ->willReturn('social-config-page');

        self::assertSame('social-config-page', $controller->index());
        self::assertSame('Social Configuration', $assignments['page_title'] ?? null);
        self::assertArrayHasKey('facebook', $assignments['platforms'] ?? []);
        self::assertCount(2, $assignments['footer_links'] ?? []);
    }

    public function testStatsReturnsPlatformCounts(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->exactly(5))
            ->method('getShareCount')
            ->willReturnOnConsecutiveCalls(10, 20, 15, 8, 5);

        $controller = new Social();
        $controller->setSocialService($socialService);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'product_id' => 123,
        ]));

        $result = $controller->stats();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame(123, $data['data']['product_id']);
        self::assertSame(58, $data['data']['total']);
        self::assertArrayHasKey('by_platform', $data['data']);
    }

    public function testStatsWithInvalidProductIdReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())->method('getShareCount');

        $controller = new Social();
        $controller->setSocialService($socialService);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'product_id' => 0,
        ]));

        $result = $controller->stats();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Invalid product ID', (string) ($data['message'] ?? ''));
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

        self::assertIsString($result);
        self::assertSame($data, json_decode($result, true, 512, JSON_THROW_ON_ERROR));
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }
}
