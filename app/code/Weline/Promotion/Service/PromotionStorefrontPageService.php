<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;
use Weline\Product\Helper\StorefrontOfferDetailQuery;
use Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler;
use Weline\Product\Service\StorefrontCatalogViewService;

final class PromotionStorefrontPageService
{
    public function __construct(
        private readonly PromotionActivityThemeService $themeService,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionThemeDealDiscountSyncService $dealDiscountSync,
        private readonly ?StorefrontOfferPriceAssemblerInterface $priceAssembler = null,
        private readonly ?PromotionSeoFactsBuilder $seoFactsBuilder = null,
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
        $pickMode = (string)($themePage['product_pick_mode'] ?? PromotionThemeProductService::PICK_MODE_MANUAL);
        $productIds = $pageType === 'index'
            ? $this->themeService->listHubStorefrontProductIds()
            : array_values(array_filter(array_map(
                'intval',
                is_array($themePage['product_ids'] ?? null) ? $themePage['product_ids'] : [],
            )));
        // Hub never shelves a generic catalog slice; only active-theme selections.
        $items = $pageType === 'index'
            ? $this->loadProductsByIds($productIds)
            : $this->loadProducts($priceBand, $productIds, $pickMode);
        $deal = [
            'deal_discount_type' => (string)($themePage['deal_discount_type'] ?? PromotionThemeDealDiscountSyncService::DISCOUNT_NONE),
            'deal_discount_value' => (float)($themePage['deal_discount_value'] ?? 0),
        ];
        $themeId = max(0, (int)($themePage['theme_id'] ?? $themePage['id'] ?? 0));
        $rawTitle = (string)($themePage['page_title'] ?? $themePage['title'] ?? $this->resolveTitle($pageType));
        $pageTitle = $this->translateStorefrontChrome($rawTitle);
        $heroLede = $this->translateStorefrontChrome(
            (string)($themePage['hero_lede'] ?? $this->defaultHeroLede($pageType))
        );
        $campaignFallback = [
            'label' => $pageTitle,
            'url' => $pageType !== 'index' ? $this->storefrontUrl($pageType) : '',
        ];
        $items = $this->applyStorefrontPricing($items, $deal, $campaignFallback, $themeId);
        if ($pageType === 'index') {
            // Activity homepage must not show catalog rows without a live deal badge.
            $items = $this->keepDealMarkedItemsOnly($items);
            $items = array_slice($items, 0, 12);
        }
        $items = $this->stampCampaignEntryLinks($items, $themeId, $pageType);

        $slugUrls = $this->slugUrlsFromNavTabs($navTabs);

        $pageData = [
            'title' => $pageTitle,
            'hero_lede' => $heroLede,
            'page_type' => $pageType,
            'items' => $items,
            'total' => count($items),
            'deal_discount_type' => $deal['deal_discount_type'],
            'deal_discount_value' => $deal['deal_discount_value'],
            'marketing_rule_id' => (int)($themePage['marketing_rule_id'] ?? 0),
            'promotions' => $this->themeService->listEntryCards($pageType),
            'nav_tabs' => $navTabs,
            'list_url' => $this->storefrontUrl(),
            'deals_url' => (string)($slugUrls['deals'] ?? ''),
            'sale_url' => (string)($slugUrls['sale'] ?? ''),
            'scope' => [
                'website_id' => (int)$requestScope['website_id'],
                'store_code' => (string)$requestScope['store_code'],
                'channel_code' => (string)$requestScope['channel_code'],
            ],
            'theme_scope' => is_array($themePage['scope'] ?? null) ? $themePage['scope'] : null,
        ];
        $pageData['seo'] = $this->seoFacts()->buildListingProfile($pageType, $pageData);

        return $pageData;
    }

    private function seoFacts(): PromotionSeoFactsBuilder
    {
        if ($this->seoFactsBuilder !== null) {
            return $this->seoFactsBuilder;
        }

        try {
            return ObjectManager::getInstance(PromotionSeoFactsBuilder::class);
        } catch (\Throwable) {
            return new PromotionSeoFactsBuilder(themeService: $this->themeService);
        }
    }

    private function storefrontUrl(string $pageSlug = ''): string
    {
        return $this->themeService->storefrontUrl($pageSlug);
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
     * @param array{deal_discount_type:string,deal_discount_value:float} $pageDeal
     * @param array{label?:string,url?:string} $campaignFallback
     * @return array<int, array<string, mixed>>
     */
    private function applyStorefrontPricing(
        array $items,
        array $pageDeal,
        array $campaignFallback = [],
        int $preferredThemeId = 0,
    ): array {
        $assembler = $this->priceAssembler();
        $preferredThemeId = max(0, $preferredThemeId);
        $priced = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = max(0, (int)($item['product_id'] ?? 0));
            $currency = trim((string)($item['currency'] ?? 'CNY')) ?: 'CNY';
            $catalogPrice = max(0, (float)($item['price'] ?? 0));
            $catalogMinor = (int) round($catalogPrice * 100);
            $applied = null;
            if ($assembler instanceof StorefrontOfferPriceAssemblerInterface && $productId > 0 && $catalogMinor > 0) {
                $selection = $preferredThemeId > 0
                    ? ['promotion_theme_id' => $preferredThemeId]
                    : [];
                $view = $assembler->assemble(new StorefrontPriceContext(
                    productId: $productId,
                    catalogPriceMinor: $catalogMinor,
                    currency: $currency,
                    selection: $selection,
                ));
                if ($view->hasDeal) {
                    $applied = $view->toArray();
                } else {
                    // Assembler is authoritative with PDP/cart — do not re-apply pageDeal.
                    $applied = [
                        'price' => $catalogPrice,
                        'original_price' => $catalogPrice,
                        'has_deal' => false,
                        'campaign_label' => '',
                        'campaign_url' => '',
                    ];
                }
            }
            // Legacy only when Assembler is unavailable (provides not compiled yet).
            if ($applied === null) {
                $applied = $this->dealDiscountSync->applyDealToPrice($catalogPrice, $pageDeal);
                $applied['campaign_label'] = (string)($campaignFallback['label'] ?? '');
                $applied['campaign_url'] = (string)($campaignFallback['url'] ?? '');
            }
            $item['original_price'] = $applied['original_price'];
            $item['price'] = $applied['price'];
            $item['has_deal'] = !empty($applied['has_deal']);
            $item['campaign_label'] = (string)($applied['campaign_label'] ?? $campaignFallback['label'] ?? '');
            $item['campaign_url'] = (string)($applied['campaign_url'] ?? $campaignFallback['url'] ?? '');
            if ($item['has_deal'] && $item['campaign_label'] === '') {
                $item['campaign_label'] = (string)($campaignFallback['label'] ?? '');
            }
            if ($item['has_deal'] && $item['campaign_url'] === '') {
                $item['campaign_url'] = (string)($campaignFallback['url'] ?? '');
            }
            if ($preferredThemeId > 0) {
                $item['promotion_theme_id'] = $preferredThemeId;
            } elseif ($item['has_deal']) {
                $sourceThemeId = (int)($applied['primary_campaign']['source_id'] ?? 0);
                if ($sourceThemeId > 0) {
                    $item['promotion_theme_id'] = $sourceThemeId;
                }
            }
            $priced[] = $item;
        }

        return $priced;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function keepDealMarkedItemsOnly(array $items): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['has_deal'])) {
                continue;
            }
            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * Append campaign entry query so PDP prefers this activity when eligible.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function stampCampaignEntryLinks(array $items, int $themeId, string $pageSlug): array
    {
        $themeId = max(0, $themeId);
        $pageSlug = strtolower(trim($pageSlug));
        $isHub = $pageSlug === '' || $pageSlug === 'index';
        if ($themeId <= 0 && $isHub) {
            // Hub may still stamp per-card theme ids resolved by the price assembler.
            $hasPerItemTheme = false;
            foreach ($items as $probe) {
                if (is_array($probe) && (int)($probe['promotion_theme_id'] ?? 0) > 0) {
                    $hasPerItemTheme = true;
                    break;
                }
            }
            if (!$hasPerItemTheme) {
                return $items;
            }
        }

        $stamped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemThemeId = $themeId > 0
                ? $themeId
                : max(0, (int)($item['promotion_theme_id'] ?? 0));
            $itemSlug = $isHub ? '' : $pageSlug;
            $url = trim((string)($item['url'] ?? ''));
            if ($url !== '' && $url !== '#' && ($itemThemeId > 0 || $itemSlug !== '')) {
                $parts = parse_url($url);
                $path = (string)($parts['path'] ?? $url);
                $query = [];
                if (!empty($parts['query'])) {
                    parse_str((string)$parts['query'], $query);
                }
                $query = \Weline\Product\Helper\StorefrontCampaignEntry::mergeIntoQuery(
                    array_map('strval', $query),
                    $itemThemeId,
                    $itemSlug,
                );
                $item['url'] = $path . ($query !== [] ? ('?' . http_build_query($query)) : '');
            }
            if ($itemThemeId > 0) {
                $item['promotion_theme_id'] = $itemThemeId;
            }
            $stamped[] = $item;
        }

        return $stamped;
    }

    private function priceAssembler(): ?StorefrontOfferPriceAssemblerInterface
    {
        if ($this->priceAssembler instanceof StorefrontOfferPriceAssemblerInterface) {
            return $this->priceAssembler;
        }
        try {
            $resolved = ObjectManager::getInstance(StorefrontOfferPriceAssemblerInterface::class);
            if ($resolved instanceof StorefrontOfferPriceAssemblerInterface) {
                return $resolved;
            }
        } catch (\Throwable) {
            // Fall through to concrete class when provides registry lags setup:upgrade.
        }
        try {
            $concrete = ObjectManager::getInstance(StorefrontOfferPriceAssembler::class);
            return $concrete instanceof StorefrontOfferPriceAssemblerInterface ? $concrete : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizePageType(string $pageType): string
    {
        $pageType = strtolower(trim($pageType));

        return $pageType !== '' ? $pageType : 'index';
    }

    private function resolveTitle(string $pageType): string
    {
        return match ($pageType) {
            'index' => $this->translateStorefrontChrome('活动中心'),
            'deals' => $this->translateStorefrontChrome('今日特价专场'),
            'sale' => $this->translateStorefrontChrome('节令主题陈列'),
            'weekend' => $this->translateStorefrontChrome('出游常服专场'),
            'wedding' => $this->translateStorefrontChrome('婚嫁礼服陈列'),
            default => $this->translateStorefrontChrome('活动主题'),
        };
    }

    /**
     * DB/local theme copy is Chinese source; translate for non-zh storefront locales.
     */
    private function translateStorefrontChrome(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/\p{Han}/u', $text) !== 1) {
            return $text;
        }
        $translated = trim(\Weline\Theme\Helper\WidgetI18n::label($text));

        return $translated !== '' ? $translated : $text;
    }

    private function defaultHeroLede(string $pageType): string
    {
        return match ($pageType) {
            'index' => (string)__('浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。'),
            'deals' => (string)__('从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。'),
            'sale' => (string)__('围绕传统节令与仪式场景，陈列节庆礼服与主题套装，展示真实成交价，不配置额外优惠。'),
            'weekend' => (string)__('围绕踏青、市集与日常出游，陈列常服套装与轻便搭配，只展示真实可售商品，不虚构折扣。'),
            'wedding' => (string)__('围绕婚礼、订婚与敬酒仪式，陈列嫁衣与礼服套装，只展示真实成交价，不虚构折扣。'),
            default => (string)__('浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。'),
        };
    }

    /** @return array<int, array<string, mixed>> */
    /** @param list<int> $productIds */
    private function loadProducts(
        string $priceBand,
        array $productIds = [],
        string $pickMode = PromotionThemeProductService::PICK_MODE_MANUAL,
    ): array
    {
        if ($productIds !== []) {
            // Explicit theme selection must not fall back to a generic catalog page.
            return $this->loadProductsByIds($productIds);
        }

        // Filter themes with zero matches must not shelf generic catalog rows under a page deal.
        if ($pickMode === PromotionThemeProductService::PICK_MODE_FILTER) {
            return [];
        }

        $catalog = $this->resolveCatalog();
        if ($catalog === null) {
            return [];
        }
        try {
            // Promotion cards only need the summary projection. Loading full
            // localized specifications for the whole catalog makes this page
            // pay the EAV projection cost before it slices to twelve cards.
            $normalized = $this->normalizeItems($catalog->publishedOfferSummaries(48));
        } catch (\Throwable) {
            return [];
        }
        if ($normalized === []) {
            return [];
        }

        $matching = array_values(array_filter(
            $normalized,
            fn (array $item): bool => $this->matchesPriceBand((float)($item['price'] ?? 0), $priceBand),
        ));

        return array_slice($matching !== [] ? $matching : $normalized, 0, 12);
    }

    /** @param list<int> $productIds @return array<int, array<string, mixed>> */
    private function loadProductsByIds(array $productIds): array
    {
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

        $catalog = $this->resolveCatalog();
        if ($catalog === null) {
            return [];
        }
        try {
            // Catalog may emit duplicate offer rows per product; pull enough rows then keep one card per id.
            $offers = $catalog->publishedOffersForProductIds(
                $orderedIds,
                max(count($orderedIds) * 8, count($orderedIds)),
                false,
            );
        } catch (\Throwable) {
            return [];
        }

        $byProductId = [];
        foreach ($offers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId <= 0 || isset($byProductId[$productId])) {
                continue;
            }
            $byProductId[$productId] = $offer;
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
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? $item['id'] ?? $item['entity_id'] ?? 0);
            if ($productId <= 0 || (array_key_exists('sellable', $item) && !$item['sellable'])) {
                continue;
            }
            $handle = trim((string)($item['handle'] ?? $item['slug'] ?? ''));
            $image = trim((string)($item['image_url'] ?? $item['image'] ?? ''));
            $url = $handle !== ''
                ? '/product/' . rawurlencode($handle)
                : '/product?id=' . $productId;
            // Card price must open the same offer on PDP (axis codes or offer uuid).
            if (class_exists(StorefrontOfferDetailQuery::class)) {
                $detailQuery = StorefrontOfferDetailQuery::params($item);
                if ($detailQuery !== []) {
                    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($detailQuery);
                }
            }
            $priceMinor = (int)($item['catalog_price_minor'] ?? $item['unit_price_minor'] ?? 0);
            $price = $priceMinor > 0
                ? round($priceMinor / 100, 2)
                : round(max(0, (float)($item['price'] ?? $item['final_price'] ?? 0)), 2);
            $sku = trim((string)($item['sku'] ?? ''));

            $normalized[] = [
                'product_id' => $productId,
                'url' => $url,
                'image' => $image,
                'name' => (string)($item['name'] ?? __('活动商品')),
                'short_description' => (string)($item['short_description'] ?? ($sku !== '' ? 'SKU: ' . $sku : '')),
                'price' => $price,
                'currency' => trim((string)($item['currency'] ?? 'CNY')) ?: 'CNY',
                'sku' => $sku,
                'sellable' => true,
                'global_offer_uuid' => trim((string)($item['global_offer_uuid'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function resolveCatalog(): ?StorefrontCatalogViewService
    {
        if (!class_exists(StorefrontCatalogViewService::class)) {
            return null;
        }

        try {
            return ObjectManager::getInstance(StorefrontCatalogViewService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchesPriceBand(float $price, string $priceBand): bool
    {
        return match ($priceBand) {
            'under_200' => $price > 0 && $price < 200,
            '300_plus' => $price >= 300,
            default => true,
        };
    }
}

