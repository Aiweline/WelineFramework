<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Product\Service\StorefrontCatalogViewService;

final class PromotionStorefrontPageService
{
    private const DEFAULT_IMAGE = '';

    public function __construct(
        private readonly PromotionActivityThemeService $themeService,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionThemeDealDiscountSyncService $dealDiscountSync,
    ) {
    }

    public function isPageAvailable(string $pageType): bool
    {
        $pageType = $this->normalizePageType($pageType);
        if ($pageType === 'index') {
            return true;
        }

        return $this->themeService->hasActivePage($pageType);
    }

    /** @return array<string, mixed> */
    public function build(string $pageType): array
    {
        $pageType = $this->normalizePageType($pageType);
        $requestScope = $this->scopeResolver->resolve();
        $navTabs = $this->themeService->listNavTabs();
        $themePage = $pageType === 'index' ? [] : $this->themeService->buildStorefrontPage($pageType);

        $priceBand = (string)($themePage['price_band'] ?? '');
        $productIds = array_values(array_filter(array_map(
            'intval',
            is_array($themePage['product_ids'] ?? null) ? $themePage['product_ids'] : [],
        )));
        $items = $this->loadProducts($priceBand, $productIds, $requestScope);
        $deal = [
            'deal_discount_type' => (string)($themePage['deal_discount_type'] ?? PromotionThemeDealDiscountSyncService::DISCOUNT_NONE),
            'deal_discount_value' => (float)($themePage['deal_discount_value'] ?? 0),
        ];
        $items = $this->applyDealPricing($items, $deal);

        $slugUrls = $this->slugUrlsFromNavTabs($navTabs);

        return [
            'title' => (string)($themePage['page_title'] ?? $themePage['title'] ?? $this->resolveTitle($pageType)),
            'hero_lede' => (string)($themePage['hero_lede'] ?? $this->defaultHeroLede($pageType)),
            'page_type' => $pageType,
            'items' => $items,
            'total' => count($items),
            'deal_discount_type' => $deal['deal_discount_type'],
            'deal_discount_value' => $deal['deal_discount_value'],
            'marketing_rule_id' => (int)($themePage['marketing_rule_id'] ?? 0),
            'promotions' => $this->themeService->listEntryCards($pageType),
            'nav_tabs' => $navTabs,
            'list_url' => '/promotion',
            'deals_url' => (string)($slugUrls['deals'] ?? ''),
            'sale_url' => (string)($slugUrls['sale'] ?? ''),
            'scope' => [
                'website_id' => (int)$requestScope['website_id'],
                'store_code' => (string)$requestScope['store_code'],
                'channel_code' => (string)$requestScope['channel_code'],
            ],
            'theme_scope' => is_array($themePage['scope'] ?? null) ? $themePage['scope'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $navTabs
     * @return array<string, string>
     */
    private function slugUrlsFromNavTabs(array $navTabs): array
    {
        $urls = [];
        foreach ($navTabs as $tab) {
            $slug = strtolower(trim((string)($tab['active_key'] ?? $tab['slug'] ?? '')));
            $url = trim((string)($tab['url'] ?? ''));
            if ($slug === '' || $slug === 'index' || $url === '') {
                continue;
            }
            $urls[$slug] = $url;
        }

        return $urls;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{deal_discount_type:string,deal_discount_value:float} $deal
     * @return array<int, array<string, mixed>>
     */
    private function applyDealPricing(array $items, array $deal): array
    {
        $priced = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $applied = $this->dealDiscountSync->applyDealToPrice((float)($item['price'] ?? 0), $deal);
            $item['original_price'] = $applied['original_price'];
            $item['price'] = $applied['price'];
            $item['has_deal'] = $applied['has_deal'];
            $priced[] = $item;
        }

        return $priced;
    }

    private function normalizePageType(string $pageType): string
    {
        $pageType = strtolower(trim($pageType));

        return $pageType !== '' ? $pageType : 'index';
    }

    private function resolveTitle(string $pageType): string
    {
        return match ($pageType) {
            'index' => (string)__('活动中心'),
            'deals' => (string)__('今日搭配精选'),
            'sale' => (string)__('季节主题陈列'),
            'weekend' => (string)__('周末焕新专场'),
            'gifts' => (string)__('礼盒馈赠专场'),
            default => (string)__('活动主题'),
        };
    }

    private function defaultHeroLede(string $pageType): string
    {
        return match ($pageType) {
            'index' => (string)__('浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。'),
            'deals' => (string)__('从价格友好的配饰和日常单品开始，引导买家进入购物车和结账路径。'),
            'sale' => (string)__('围绕节庆、礼服和高客单穿搭做主题陈列，不改变商品原始成交价格。'),
            'weekend' => (string)__('围绕周末出行、居家放松和轻运动场景，展示真实可售商品，不做虚假折扣。'),
            'gifts' => (string)__('围绕送礼场景做主题陈列，只展示真实成交价，不虚构划线价或折扣比例。'),
            default => (string)__('浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。'),
        };
    }

    /** @return array<int, array<string, mixed>> */
    /** @param list<int> $productIds @param array{website_id:int,store_code:string,channel_code:string} $scope */
    private function loadProducts(string $priceBand, array $productIds = [], array $scope = []): array
    {
        if ($productIds !== []) {
            // Explicit theme selection must not fall back to a generic catalog page.
            return $this->loadProductsByIds($productIds);
        }

        if (!function_exists('w_query')) {
            return $this->stubProducts($priceBand);
        }

        $params = [
            'limit' => 12,
            'availability' => 'in_stock',
        ];
        if ((int)($scope['website_id'] ?? 0) > 0) {
            $params['website_id'] = (int)$scope['website_id'];
        }
        if (trim((string)($scope['store_code'] ?? '')) !== '') {
            $params['store_code'] = trim((string)$scope['store_code']);
        }
        if (trim((string)($scope['channel_code'] ?? '')) !== '') {
            $params['channel_code'] = trim((string)$scope['channel_code']);
        }
        if ($priceBand !== '') {
            $params['price'] = $priceBand;
        }

        $items = $this->queryProductItems($params);
        if ($items === [] && $priceBand !== '') {
            unset($params['price']);
            $items = $this->queryProductItems($params);
        }

        $normalized = $this->normalizeItems($items);

        return $normalized !== [] ? $normalized : $this->stubProducts($priceBand);
    }

    /** @param list<int> $productIds @return array<int, array<string, mixed>> */
    private function loadProductsByIds(array $productIds): array
    {
        if (!class_exists(StorefrontCatalogViewService::class)) {
            return [];
        }

        $orderedIds = [];
        $seen = [];
        foreach ($productIds as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $orderedIds[] = $id;
        }
        if ($orderedIds === []) {
            return [];
        }

        try {
            $catalog = \Weline\Framework\Manager\ObjectManager::getInstance(StorefrontCatalogViewService::class);
            // Catalog may emit duplicate offer rows per product; pull enough rows then keep one card per id.
            $offers = $catalog->publishedOffersForProductIds($orderedIds, max(count($orderedIds) * 8, count($orderedIds)));
        } catch (\Throwable) {
            return [];
        }

        $byProductId = [];
        foreach ($offers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId <= 0 || isset($byProductId[$productId])) {
                continue;
            }
            $handle = trim((string)($offer['handle'] ?? ''));
            $image = trim((string)($offer['image_url'] ?? $offer['image'] ?? ''));
            $url = $handle !== ''
                ? '/product/' . rawurlencode($handle)
                : '/product?id=' . $productId;
            $priceMinor = (int)($offer['unit_price_minor'] ?? 0);
            $price = $priceMinor > 0 ? round($priceMinor / 100, 2) : round(max(0, (float)($offer['price'] ?? 0)), 2);

            $byProductId[$productId] = [
                'product_id' => $productId,
                'url' => $url,
                'image' => $image !== '' ? $image : self::DEFAULT_IMAGE,
                'name' => (string)($offer['name'] ?? __('活动商品')),
                'short_description' => (string)($offer['short_description'] ?? ($offer['sku'] ?? '')),
                'price' => $price,
            ];
        }

        $items = [];
        foreach ($orderedIds as $productId) {
            if (isset($byProductId[$productId])) {
                $items[] = $byProductId[$productId];
            }
        }

        return $this->normalizeItems($items);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function queryProductItems(array $params): array
    {
        try {
            $payload = \w_query('product', 'list', $params, 'frontend');
        } catch (\Throwable) {
            return [];
        }

        $items = is_array($payload) ? ($payload['items'] ?? []) : [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? $item['id'] ?? $item['entity_id'] ?? 0);
            $handle = trim((string)($item['handle'] ?? ''));
            $image = trim((string)($item['image_url'] ?? $item['image'] ?? ''));
            $url = $handle !== ''
                ? '/product/' . rawurlencode($handle)
                : ($productId > 0 ? '/product?id=' . $productId : '/products');

            $normalized[] = [
                'product_id' => $productId,
                'url' => $url,
                'image' => $image !== '' ? $image : self::DEFAULT_IMAGE,
                'name' => (string)($item['name'] ?? __('活动商品')),
                'short_description' => (string)($item['short_description'] ?? __('已接入 Weline 商品、购物车和结账链路。')),
                'price' => round(max(0, (float)($item['price'] ?? $item['final_price'] ?? 0)), 2),
            ];
        }

        return $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    private function stubProducts(string $priceBand): array
    {
        $prefix = match ($priceBand) {
            'under_200' => (string)__('精选'),
            '300_plus' => (string)__('主题'),
            default => (string)__('活动'),
        };

        return [
            [
                'product_id' => 0,
                'url' => '/products',
                'image' => self::DEFAULT_IMAGE,
                'name' => $prefix . ' ' . (string)__('示例商品 A'),
                'short_description' => (string)__('Product 模块未就绪时展示占位商品，便于验收活动页布局。'),
                'price' => 199.0,
            ],
            [
                'product_id' => 0,
                'url' => '/products',
                'image' => self::DEFAULT_IMAGE,
                'name' => $prefix . ' ' . (string)__('示例商品 B'),
                'short_description' => (string)__('接入 w_query(product, list) 后会自动替换为真实可售商品。'),
                'price' => 299.0,
            ],
        ];
    }
}
