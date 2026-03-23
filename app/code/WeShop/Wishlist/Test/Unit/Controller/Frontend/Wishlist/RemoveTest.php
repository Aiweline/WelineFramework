<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Controller\Frontend\Wishlist;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Wishlist\Controller\Frontend\Wishlist\Remove;
use WeShop\Wishlist\Service\WishlistService;
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

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->never())->method('removeFromWishlist');
        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');

        $controller = $this->getMockBuilder(Remove::class)
            ->setConstructorArgs([$customerContext, $wishlistService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['data']['redirect_url'] ?? null) === 'https://example.com/customer/account/login';
            }))
            ->willReturn('json');

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsJsonSuccessForAjaxRemove(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('removeFromWishlist')
            ->with(11, 7)
            ->willReturn(true);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlist')
            ->with(7)
            ->willReturn([]);
        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['wishlist_id', null, 11],
            ['item_id', null, null],
        ]);
        $request->method('getPost')->willReturnMap([
            ['wishlist_id', null, null],
            ['item_id', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['wishlist_id', null, null],
            ['item_id', null, null],
        ]);

        $controller = $this->getMockBuilder(Remove::class)
            ->setConstructorArgs([$customerContext, $wishlistService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['wishlist_count'] ?? 999) === 0;
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
