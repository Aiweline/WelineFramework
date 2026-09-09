<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

/**
 * Resolves active activity-theme deals for a storefront product.
 *
 * Default: earliest theme sort_order (admin activity order). Optional preferred
 * theme_id when the shopper picks among overlapping eligible campaigns.
 */
final class PromotionStorefrontActiveDealResolver
{
    public function __construct(
        private readonly PromotionActivityThemeService $themeService,
        private readonly PromotionThemeProductService $themeProductService,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionThemeDealDiscountSyncService $dealDiscountSync,
    ) {
    }

    /**
     * @return array{
     *     deal_discount_type:string,
     *     deal_discount_value:float,
     *     theme_id:int,
     *     page_slug:string,
     *     marketing_rule_id:int,
     *     campaign_label:string,
     *     campaign_url:string,
     *     sort_order:int
     * }|null
     */
    public function resolveForProduct(
        int $productId,
        ?float $catalogPrice = null,
        ?int $preferredThemeId = null,
    ): ?array {
        $eligible = $this->listEligibleDealsForProduct($productId, $catalogPrice);
        if ($eligible === []) {
            return null;
        }

        $preferredThemeId = $preferredThemeId !== null ? max(0, $preferredThemeId) : 0;
        $picked = null;
        if ($preferredThemeId > 0) {
            foreach ($eligible as $deal) {
                if ((int)($deal['theme_id'] ?? 0) === $preferredThemeId) {
                    $picked = $deal;
                    break;
                }
            }
        }
        $picked ??= $eligible[0];

        return [
            'deal_discount_type' => (string)($picked['deal_discount_type'] ?? ''),
            'deal_discount_value' => (float)($picked['deal_discount_value'] ?? 0),
            'theme_id' => (int)($picked['theme_id'] ?? 0),
            'page_slug' => (string)($picked['page_slug'] ?? ''),
            'marketing_rule_id' => (int)($picked['marketing_rule_id'] ?? 0),
            'campaign_label' => (string)($picked['campaign_label'] ?? ''),
            'campaign_url' => (string)($picked['campaign_url'] ?? ''),
            'sort_order' => (int)($picked['sort_order'] ?? 0),
        ];
    }

    /**
     * All real-discount themes that include this product, admin sort_order first.
     *
     * @return list<array{
     *     deal_discount_type:string,
     *     deal_discount_value:float,
     *     theme_id:int,
     *     page_slug:string,
     *     marketing_rule_id:int,
     *     campaign_label:string,
     *     campaign_url:string,
     *     sort_order:int,
     *     score:float
     * }>
     */
    public function listEligibleDealsForProduct(int $productId, ?float $catalogPrice = null): array
    {
        if ($productId <= 0) {
            return [];
        }

        $scope = $this->scopeResolver->resolve();
        $cache = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
        );
        $eligible = [];

        foreach ($cache->rememberForRequest(
            'promotion.active_themes',
            serialize($scope),
            fn(): array => $this->activeThemes($scope),
        ) as $theme) {
            $type = $this->dealDiscountSync->normalizeType((string)($theme['deal_discount_type'] ?? ''));
            $value = round(max(0, (float)($theme['deal_discount_value'] ?? 0)), 2);
            if ($type === PromotionThemeDealDiscountSyncService::DISCOUNT_NONE || $value <= 0) {
                continue;
            }

            $themeId = (int)($theme['id'] ?? 0);
            $productIds = $themeId > 0
                ? $cache->rememberForRequest(
                    'promotion.theme_selection',
                    serialize([$theme, $scope]),
                    fn(): array => $this->themeProductService->resolveStorefrontProductIds($theme, $scope),
                )
                : [];
            $isEligible = in_array($productId, $productIds, true);
            if (!$isEligible && $productIds === [] && $catalogPrice !== null
                && ($theme['product_pick_mode'] ?? PromotionThemeProductService::PICK_MODE_MANUAL)
                    === PromotionThemeProductService::PICK_MODE_MANUAL
            ) {
                // Manual themes with empty bindings still shelf by price_band — keep deal eligibility aligned.
                $isEligible = $this->matchesPriceBand($catalogPrice, (string)($theme['price_band'] ?? ''));
            }
            if (!$isEligible) {
                continue;
            }

            $pageSlug = strtolower(trim((string)($theme['page_slug'] ?? '')));
            $sortOrder = (int)($theme['sort_order'] ?? 0);
            $meta = [
                'campaign_label' => '',
                'campaign_url' => $pageSlug !== '' ? $this->themeService->storefrontUrl($pageSlug) : '',
                'page_title' => '',
            ];
            if ($themeId > 0) {
                try {
                    $meta = $cache->rememberForRequest(
                        'promotion.storefront_campaign_meta',
                        serialize([$themeId, $pageSlug]),
                        fn(): array => $this->themeService->resolveStorefrontCampaignMeta($themeId, [
                            'page_slug' => $pageSlug,
                            'id' => $themeId,
                        ]),
                    );
                } catch (\Throwable) {
                    // Unit stubs / degraded LocalModel: fall back below.
                }
            }
            $label = trim((string)($meta['campaign_label'] ?? ''));
            if ($label === '') {
                $label = trim((string)($meta['page_title'] ?? ''));
            }
            $label = $this->themeService->resolveCampaignDisplayLabel(
                $pageSlug,
                $label,
                trim((string)($meta['page_title'] ?? '')),
            );
            $url = trim((string)($meta['campaign_url'] ?? ''));
            if ($url === '' && $pageSlug !== '') {
                $url = $this->themeService->storefrontUrl($pageSlug);
            }

            $score = $type === PromotionThemeDealDiscountSyncService::DISCOUNT_PERCENTAGE
                ? min(100.0, $value)
                : $value;

            $eligible[] = [
                'deal_discount_type' => $type,
                'deal_discount_value' => $value,
                'theme_id' => $themeId,
                'page_slug' => $pageSlug,
                'marketing_rule_id' => (int)($theme['marketing_rule_id'] ?? 0),
                'campaign_label' => $label,
                'campaign_url' => $url,
                'sort_order' => $sortOrder,
                'score' => $score,
            ];
        }

        usort(
            $eligible,
            static function (array $left, array $right): int {
                $byOrder = ((int)$left['sort_order']) <=> ((int)$right['sort_order']);
                if ($byOrder !== 0) {
                    return $byOrder;
                }

                return ((int)$left['theme_id']) <=> ((int)$right['theme_id']);
            },
        );

        return array_values($eligible);
    }

    private function matchesPriceBand(float $price, string $priceBand): bool
    {
        return match ($priceBand) {
            'under_200' => $price > 0 && $price < 200,
            '300_plus' => $price >= 300,
            default => true,
        };
    }

    /**
     * @param array{deal_discount_type?:string,deal_discount_value?:float}|null $deal
     * @return array{price:float,original_price:float,has_deal:bool}
     */
    public function applyToCatalogPrice(float $catalogPrice, ?array $deal): array
    {
        if ($deal === null) {
            $catalogPrice = round(max(0, $catalogPrice), 2);

            return [
                'price' => $catalogPrice,
                'original_price' => $catalogPrice,
                'has_deal' => false,
            ];
        }

        return $this->dealDiscountSync->applyDealToPrice($catalogPrice, $deal);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function activeThemes(array $scope): array
    {
        try {
            return $this->themeService->listActiveThemesForStorefront($scope);
        } catch (\Throwable) {
            return [];
        }
    }
}
