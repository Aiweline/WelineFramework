<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Customer\Model\Customer as AuthCustomer;

class AccountDashboardDataService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly CompareService $compareService,
        private readonly WishlistService $wishlistService,
        private readonly RecentlyViewedService $recentlyViewedService,
        private readonly ProductRecommendationService $productRecommendationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(AuthCustomer $authUser, ?CustomerProfile $profile = null): array
    {
        $customerId = (int) $authUser->getId();
        $recentOrdersResult = $this->orderService->getCustomerOrders($customerId, 1, 5);
        $recentOrdersResult = is_array($recentOrdersResult) ? $recentOrdersResult : [];
        $recentOrders = $this->mapOrders(is_array($recentOrdersResult['items'] ?? null) ? $recentOrdersResult['items'] : []);

        $wishlistItems = $this->wishlistService->getCustomerWishlist($customerId);
        $compareItems = $this->compareService->getCompareList($customerId);
        $recentlyViewedItems = $this->recentlyViewedService->getRecentlyViewed($customerId, 4);
        $comparePreview = $this->mapProductPreviewItems($compareItems, 4);
        $wishlistPreview = $this->mapProductPreviewItems($wishlistItems, 4);
        $recentlyViewed = $this->mapProductPreviewItems($recentlyViewedItems, 4);
        $recommendationSeeds = array_values(array_unique(array_filter(array_merge(
            array_column($comparePreview, 'product_id'),
            array_column($wishlistPreview, 'product_id'),
            array_column($recentlyViewed, 'product_id')
        ))));

        return [
            'customer' => $this->normalizeCustomer($authUser, $profile),
            'recent_orders' => $recentOrders,
            'order_count' => (int) ($recentOrdersResult['total'] ?? count($recentOrders)),
            'unpaid_count' => (int) $this->orderService->getUnpaidOrderCount($customerId),
            'quick_links' => $this->buildQuickLinks(),
            'compare_count' => count($compareItems),
            'compare_preview' => $comparePreview,
            'wishlist_count' => count($wishlistItems),
            'wishlist_preview' => $wishlistPreview,
            'recently_viewed_count' => count($recentlyViewedItems),
            'recently_viewed' => $recentlyViewed,
            'recommendations' => $this->productRecommendationService->getRecommendations($recommendationSeeds, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCustomer(AuthCustomer $authUser, ?CustomerProfile $profile): array
    {
        $firstName = $profile ? (string) ($profile->getData(CustomerProfile::schema_fields_FIRST_NAME) ?? '') : '';
        $lastName = $profile ? (string) ($profile->getData(CustomerProfile::schema_fields_LAST_NAME) ?? '') : '';
        $email = $profile ? (string) ($profile->getData(CustomerProfile::schema_fields_EMAIL) ?? '') : '';
        $phone = $profile ? (string) ($profile->getData(CustomerProfile::schema_fields_PHONE) ?? '') : '';
        $createdAt = $profile ? (string) ($profile->getData(CustomerProfile::schema_fields_CREATED_AT) ?? '') : '';

        if ($email === '') {
            $email = (string) $authUser->getUsername();
        }

        return [
            'customer_id' => (int) $authUser->getId(),
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $email,
            'username' => (string) $authUser->getUsername(),
            'phone' => $phone,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param array<int, mixed> $orders
     * @return array<int, array<string, mixed>>
     */
    protected function mapOrders(array $orders): array
    {
        $mapped = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $mapped[] = [
                'order_id' => (int) ($order['order_id'] ?? $order[Order::schema_fields_ID] ?? 0),
                'increment_id' => (string) ($order['increment_id'] ?? $order[Order::schema_fields_increment_id] ?? ''),
                'status' => (string) ($order['status'] ?? $order[Order::schema_fields_status] ?? 'pending'),
                'total' => (float) ($order['total'] ?? $order[Order::schema_fields_total] ?? 0),
                'created_at' => (string) ($order['created_at'] ?? $order[Order::schema_fields_created_at] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapProductPreviewItems(array $items, int $limit): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $productId = (int) ($item['product_id'] ?? $product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $mapped[] = [
                'product_id' => $productId,
                'name' => (string) ($product['name'] ?? $item['name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => (float) ($product['price'] ?? $item['price'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['sku'] ?? ''),
            ];

            if (count($mapped) >= $limit) {
                break;
            }
        }

        return $mapped;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildQuickLinks(): array
    {
        return [
            [
                'title' => (string) __('Compare Products'),
                'url' => 'compare',
                'icon' => 'compare_arrows',
            ],
            [
                'title' => (string) __('My Orders'),
                'url' => 'weshop/order/list',
                'icon' => 'receipt_long',
            ],
            [
                'title' => (string) __('Recently Viewed'),
                'url' => 'recently-viewed',
                'icon' => 'history',
            ],
            [
                'title' => (string) __('My Wishlist'),
                'url' => 'wishlist',
                'icon' => 'favorite',
            ],
            [
                'title' => (string) __('Manage Addresses'),
                'url' => 'address',
                'icon' => 'location_on',
            ],
            [
                'title' => (string) __('Security Center'),
                'url' => 'weshop/frontend/auth/two-factor',
                'icon' => 'verified_user',
            ],
        ];
    }
}
