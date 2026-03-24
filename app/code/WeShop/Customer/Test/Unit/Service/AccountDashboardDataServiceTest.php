<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\AccountDashboardDataService;
use WeShop\Order\Service\OrderService;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use WeShop\Subscription\Service\SubscriptionService;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Customer\Model\Customer as AuthCustomer;

class AccountDashboardDataServiceTest extends TestCase
{
    public function testBuildAggregatesOrdersWishlistRecentlyViewedAndRecommendations(): void
    {
        $authUser = $this->getMockBuilder(AuthCustomer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getUsername'])
            ->getMock();
        $authUser->method('getId')->willReturn(42);
        $authUser->method('getUsername')->willReturn('ada@example.com');

        $profile = $this->getMockBuilder(CustomerProfile::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $profile->method('getData')->willReturnMap([
            [CustomerProfile::schema_fields_FIRST_NAME, null, 'Ada'],
            [CustomerProfile::schema_fields_LAST_NAME, null, 'Lovelace'],
            [CustomerProfile::schema_fields_EMAIL, null, 'ada@example.com'],
            [CustomerProfile::schema_fields_PHONE, null, '+44 20 0000 0000'],
            [CustomerProfile::schema_fields_CREATED_AT, null, '2026-03-20 08:00:00'],
            ['username', null, null],
        ]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getCustomerOrders')
            ->willReturnMap([
                [42, 1, 5, [
                    'items' => [
                        [
                            'order_id' => 88,
                            'increment_id' => 'WS000088',
                            'status' => 'processing',
                            'total' => 120.5,
                            'created_at' => '2026-03-23 11:00:00',
                        ],
                    ],
                    'total' => 6,
                ]],
            ]);
        $orderService->expects($this->once())
            ->method('getUnpaidOrderCount')
            ->with(42)
            ->willReturn(2);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->once())
            ->method('getCompareList')
            ->with(42)
            ->willReturn([
                [
                    'compare_id' => 9,
                    'product_id' => 401,
                    'product' => [
                        'name' => 'Carry-On Spinner',
                        'image' => '/media/spinner.jpg',
                        'price' => 199.0,
                    ],
                ],
            ]);

        $wishlistService = $this->createMock(WishlistService::class);
        $wishlistService->expects($this->once())
            ->method('getCustomerWishlist')
            ->with(42)
            ->willReturn([
                [
                    'wishlist_id' => 1,
                    'product_id' => 501,
                    'product' => [
                        'name' => 'Travel Backpack',
                        'image' => '/media/backpack.jpg',
                        'price' => 99.9,
                    ],
                ],
                [
                    'wishlist_id' => 2,
                    'product_id' => 502,
                    'product' => [
                        'name' => 'Packing Cube',
                        'image' => '/media/cube.jpg',
                        'price' => 19.9,
                    ],
                ],
            ]);

        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->once())
            ->method('getRecentlyViewed')
            ->with(42, 4)
            ->willReturn([
                [
                    'product_id' => 601,
                    'product' => [
                        'name' => 'Passport Holder',
                        'image' => '/media/passport.jpg',
                        'price' => 29.9,
                    ],
                ],
            ]);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())
            ->method('getCustomerSubscriptions')
            ->with(42, 1, 1)
            ->willReturn([
                'items' => [],
                'total' => 3,
            ]);

        $recommendationService = $this->createMock(ProductRecommendationService::class);
        $recommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([401, 501, 502, 601], 4)
            ->willReturn([
                ['product_id' => 701, 'name' => 'Luggage Tag', 'price' => 12.5],
            ]);

        $service = new AccountDashboardDataService(
            $orderService,
            $compareService,
            $wishlistService,
            $recentlyViewedService,
            $recommendationService,
            $subscriptionService
        );
        $result = $service->build($authUser, $profile);

        $this->assertSame('Ada', $result['customer']['firstname']);
        $this->assertSame('Lovelace', $result['customer']['lastname']);
        $this->assertSame(6, $result['order_count']);
        $this->assertSame(2, $result['unpaid_count']);
        $this->assertSame(1, $result['compare_count']);
        $this->assertSame(2, $result['wishlist_count']);
        $this->assertSame(1, $result['recently_viewed_count']);
        $this->assertSame(3, $result['subscription_count']);
        $this->assertSame('Carry-On Spinner', $result['compare_preview'][0]['name']);
        $this->assertSame('Travel Backpack', $result['wishlist_preview'][0]['name']);
        $this->assertSame('Passport Holder', $result['recently_viewed'][0]['name']);
        $this->assertSame(701, $result['recommendations'][0]['product_id']);
    }
}
