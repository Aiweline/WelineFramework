<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Controller\Frontend\Wishlist;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Wishlist\Controller\Frontend\Wishlist\Add;
use WeShop\Wishlist\Model\Wishlist;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class AddTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadForGuestAjaxRequest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->never())->method('addToWishlist');
        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Add::class)
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

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsJsonSuccessForAjaxAdd(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $wishlistItem = $this->getMockBuilder(Wishlist::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $wishlistItem->method('getId')->willReturn(11);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('addToWishlist')
            ->with(7, 501)
            ->willReturn($wishlistItem);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlist')
            ->with(7)
            ->willReturn([['wishlist_id' => 11]]);
        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['product_id', null, 501],
        ]);
        $request->method('getPost')->willReturnMap([
            ['product_id', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, null],
        ]);

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $wishlistService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['wishlist_count'] ?? 0) === 1;
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
