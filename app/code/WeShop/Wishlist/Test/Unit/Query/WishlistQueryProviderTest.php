<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Wishlist\Extends\Module\Weline_Framework\Query\WishlistQueryProvider;
use WeShop\Wishlist\Model\Wishlist;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Http\Url;

class WishlistQueryProviderTest extends TestCase
{
    public function testAddFallsBackToCustomerSessionWhenContextIsEmpty(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(77);

        $wishlistItem = $this->getMockBuilder(Wishlist::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $wishlistItem->method('getId')->willReturn(11);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('addToWishlist')
            ->with(77, 652)
            ->willReturn($wishlistItem);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlistCount')
            ->with(77)
            ->willReturn(1);

        $provider = new WishlistQueryProvider(
            $customerContext,
            $wishlistService,
            $this->createMock(CartService::class),
            $this->createMock(Url::class),
            $customerSession
        );

        $result = $provider->execute('add', ['product_id' => 652]);

        $this->assertTrue($result['success']);
        $this->assertSame(11, $result['data']['item_id']);
        $this->assertSame(1, $result['data']['wishlist_count']);
    }

    public function testAddReturnsLoginRequiredWhenNoCustomerCanBeResolved(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(null);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->never())->method('addToWishlist');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $provider = new WishlistQueryProvider(
            $customerContext,
            $wishlistService,
            $this->createMock(CartService::class),
            $url,
            $customerSession
        );

        $result = $provider->execute('add', ['product_id' => 652]);

        $this->assertFalse($result['success']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }
}
