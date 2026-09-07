<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

/**
 * Resolves the strongest active activity-theme deal for a storefront product.
 *
 * Used so PDP / cart-facing display can match /promotion/{slug} shelf pricing
 * while the theme remains active (until cancelled / deactivated).
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
     *     page_title?:string
     * }|null
     */
    public function resolveForProduct(int $productId, ?float $catalogPrice = null): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $scope = $this->scopeResolver->resolve();
        $cache = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
        );
        $best = null;
        $bestScore = -1.0;

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
            $eligible = in_array($productId, $productIds, true);
            if (!$eligible && $productIds === [] && $catalogPrice !== null
                && ($theme['product_pick_mode'] ?? PromotionThemeProductService::PICK_MODE_MANUAL)
                    === PromotionThemeProductService::PICK_MODE_MANUAL
            ) {
                // Manual themes with empty bindings still shelf by price_band — keep deal eligibility aligned.
                $eligible = $this->matchesPriceBand($catalogPrice, (string)($theme['price_band'] ?? ''));
            }
            if (!$eligible) {
                continue;
            }

            // Prefer stronger percent / larger fixed amount when multiple themes match.
            $score = $type === PromotionThemeDealDiscountSyncService::DISCOUNT_PERCENTAGE
                ? min(100.0, $value)
                : $value;
            if ($score <= $bestScore) {
                continue;
            }
            $bestScore = $score;
            $best = [
                'deal_discount_type' => $type,
                'deal_discount_value' => $value,
                'theme_id' => $themeId,
                'page_slug' => (string)($theme['page_slug'] ?? ''),
                'marketing_rule_id' => (int)($theme['marketing_rule_id'] ?? 0),
            ];
        }

        return $best;
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
