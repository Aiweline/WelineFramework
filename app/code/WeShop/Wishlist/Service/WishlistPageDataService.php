<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Service;

use WeShop\Product\Service\ProductRecommendationService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class WishlistPageDataService
{
    public function __construct(
        private readonly ?WishlistService $wishlistService = null,
        private readonly ?ProductRecommendationService $productRecommendationService = null,
        private readonly ?Url $url = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $items = $this->mapItems($this->getWishlistService()->getCustomerWishlist($customerId));

        return [
            'wishlist_items' => $items,
            'wishlist_count' => count($items),
            'wishlist_url' => $this->getUrlService()->getUrl('wishlist'),
            'browse_url' => $this->getUrlService()->getUrl('catalog/category'),
            'remove_url' => $this->getUrlService()->getUrl('wishlist/remove'),
            'recommendations' => $this->mapRecommendationItems(
                $this->getProductRecommendationService()->getRecommendations(array_column($items, 'product_id'), 4)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAccountQuickLink(int $customerId): array
    {
        return [
            'wishlist_count' => $this->getWishlistService()->getCustomerWishlistCount($customerId),
            'wishlist_url' => $this->getUrlService()->getUrl('wishlist'),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $productId = (int) ($item['product_id'] ?? $product['product_id'] ?? 0);
            $mapped[] = [
                'wishlist_id' => (int) ($item['wishlist_id'] ?? 0),
                'product_id' => $productId,
                'name' => (string) ($product['name'] ?? $item['name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => (float) ($product['price'] ?? $item['price'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['sku'] ?? ''),
                'product_url' => $this->buildProductUrl($productId),
            ];
        }

        return $mapped;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapRecommendationItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $mapped[] = [
                'product_id' => $productId,
                'name' => (string) ($item['name'] ?? ''),
                'image' => (string) ($item['image'] ?? ''),
                'price' => (float) ($item['price'] ?? 0),
                'product_url' => $this->buildProductUrl($productId),
            ];
        }

        return $mapped;
    }

    private function buildProductUrl(int $productId): string
    {
        return $this->getUrlService()->getUrl('product/view', ['id' => $productId]);
    }

    private function getWishlistService(): WishlistService
    {
        return $this->wishlistService ?? ObjectManager::getInstance(WishlistService::class);
    }

    private function getProductRecommendationService(): ProductRecommendationService
    {
        return $this->productRecommendationService ?? ObjectManager::getInstance(ProductRecommendationService::class);
    }

    private function getUrlService(): Url
    {
        return $this->url ?? ObjectManager::getInstance(Url::class);
    }
}
