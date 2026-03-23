<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\Controller\Frontend\RecentlyViewed;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\RecentlyViewed\Controller\Frontend\RecentlyViewed\Remove;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class RemoveTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadForGuestAjaxRequest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->never())->method('removeView');
        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');
        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Remove::class)
            ->setConstructorArgs([$customerContext, $recentlyViewedService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['data']['redirect_url'] ?? null) === 'https://example.com/customer/account/login';
            }))
            ->willReturn('json');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsJsonSuccessForAjaxRemove(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->once())
            ->method('removeView')
            ->with(11, 7)
            ->willReturn(true);
        $recentlyViewedService->expects($this->once())
            ->method('getRecentlyViewedCount')
            ->with(7)
            ->willReturn(0);
        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['view_id', null, 11],
            ['item_id', null, null],
        ]);
        $request->method('getPost')->willReturnMap([
            ['view_id', null, null],
            ['item_id', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['view_id', null, null],
            ['item_id', null, null],
        ]);

        $controller = $this->getMockBuilder(Remove::class)
            ->setConstructorArgs([$customerContext, $recentlyViewedService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['recently_viewed_count'] ?? 999) === 0;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
