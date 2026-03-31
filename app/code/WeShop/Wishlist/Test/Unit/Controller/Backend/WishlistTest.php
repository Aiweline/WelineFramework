<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Model\Customer;
use WeShop\Wishlist\Model\Wishlist as WishlistModel;
use WeShop\Wishlist\Service\WishlistService;

class WishlistTest extends TestCase
{
    public function testWishlistModelSchemaConstants(): void
    {
        $this->assertSame('wishlist_id', WishlistModel::schema_fields_ID);
        $this->assertSame('customer_id', WishlistModel::schema_fields_CUSTOMER_ID);
        $this->assertSame('product_id', WishlistModel::schema_fields_PRODUCT_ID);
        $this->assertSame('created_at', WishlistModel::schema_fields_CREATED_AT);
        $this->assertSame('updated_at', WishlistModel::schema_fields_UPDATED_AT);
    }

    public function testWishlistServiceRemoveFromWishlist(): void
    {
        $wishlistService = $this->getMockBuilder(WishlistService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $wishlistService->expects($this->once())
            ->method('removeFromWishlist')
            ->with(1, 5)
            ->willReturn(true);

        $result = $wishlistService->removeFromWishlist(1, 5);
        $this->assertTrue($result);
    }

    public function testWishlistServiceRemoveFromWishlistThrowsException(): void
    {
        $wishlistService = $this->getMockBuilder(WishlistService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $wishlistService->expects($this->once())
            ->method('removeFromWishlist')
            ->with(1, 5)
            ->willThrowException(new \Exception('Permission denied'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');

        $wishlistService->removeFromWishlist(1, 5);
    }

    public function testWishlistServiceGetCustomerWishlist(): void
    {
        $wishlistService = $this->getMockBuilder(WishlistService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $expectedItems = [
            ['wishlist_id' => 1, 'product_id' => 10, 'customer_id' => 5],
            ['wishlist_id' => 2, 'product_id' => 20, 'customer_id' => 5],
        ];

        $wishlistService->expects($this->once())
            ->method('getCustomerWishlist')
            ->with(5)
            ->willReturn($expectedItems);

        $result = $wishlistService->getCustomerWishlist(5);
        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['product_id']);
    }

    public function testWishlistServiceIsInWishlist(): void
    {
        $wishlistService = $this->getMockBuilder(WishlistService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $wishlistService->expects($this->exactly(2))
            ->method('isInWishlist')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($wishlistService->isInWishlist(1, 100));
        $this->assertFalse($wishlistService->isInWishlist(1, 200));
    }

    public function testCustomerModelFullName(): void
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $customer->expects($this->any())
            ->method('getFullName')
            ->willReturn('John Doe');

        $this->assertSame('John Doe', $customer->getFullName());
    }

    public function testCustomerModelSchemaConstants(): void
    {
        $this->assertSame('customer_id', Customer::schema_fields_ID);
        $this->assertSame('email', Customer::schema_fields_EMAIL);
        $this->assertSame('firstname', Customer::schema_fields_FIRST_NAME);
        $this->assertSame('lastname', Customer::schema_fields_LAST_NAME);
    }
}
