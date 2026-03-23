<?php

declare(strict_types=1);

namespace WeShop\Promotion\Service;

use WeShop\Product\Service\ProductService;
use Weline\Framework\Http\Url;

class PromotionPageDataService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly Url $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $pageType = 'index', int $page = 1, int $pageSize = 24): array
    {
        $filters = [
            'status' => 'enabled',
            'order_by' => $pageType === 'sale' ? 'price' : 'created_at',
            'order_dir' => $pageType === 'sale' ? 'ASC' : 'DESC',
        ];

        $result = $this->productService->getProducts($filters, $page, $pageSize);
        $items = array_values(array_filter((array) ($result['items'] ?? []), 'is_array'));
        $normalized = array_map([$this, 'normalizeProduct'], $items);

        return [
            'page_type' => $pageType,
            'items' => $normalized,
            'total' => (int) ($result['total'] ?? count($normalized)),
            'promotions' => $this->buildPromotionCards(),
            'list_url' => $this->url->getUrl('promotion'),
            'deals_url' => $this->url->getUrl('promotion/deals'),
            'sale_url' => $this->url->getUrl('promotion/sale'),
            'coupon_apply_url' => $this->url->getUrl('promotion/coupon/apply'),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    protected function normalizeProduct(array $product): array
    {
        $productId = (int) ($product['product_id'] ?? $product['id'] ?? 0);
        $price = (float) ($product['price'] ?? 0);
        $original = (float) ($product['original_price'] ?? $product['market_price'] ?? $price);

        return [
            'product_id' => $productId,
            'name' => (string) ($product['name'] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'image' => (string) ($product['image'] ?? ''),
            'price' => $price,
            'original_price' => $original,
            'discount_percent' => $original > 0 && $original > $price
                ? (int) round((($original - $price) / $original) * 100)
                : 0,
            'url' => $this->url->getUrl('product/view', ['id' => $productId]),
            'in_stock' => (int) ($product['stock'] ?? 0) > 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildPromotionCards(): array
    {
        return [
            [
                'title' => (string) __('Flash Deal Spotlight'),
                'subtitle' => (string) __('Prices update daily based on inventory and campaign windows.'),
                'action_label' => (string) __('Browse Deals'),
                'action_url' => $this->url->getUrl('promotion/deals'),
            ],
            [
                'title' => (string) __('Seasonal Sale Picks'),
                'subtitle' => (string) __('Curated sale products chosen for checkout-ready savings.'),
                'action_label' => (string) __('Open Sale'),
                'action_url' => $this->url->getUrl('promotion/sale'),
            ],
        ];
    }
}
