<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Product\StorefrontPriceAdjustmentProvider;

use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;
use Weline\Promotion\Service\PromotionStorefrontActiveDealResolver;
use Weline\Promotion\Service\PromotionThemeDealDiscountSyncService;

/**
 * Maps active activity-theme deals into Product storefront unit-price adjustments.
 */
final class PromotionThemeDealPriceAdjustmentProvider implements StorefrontPriceAdjustmentProviderInterface
{
    public function __construct(
        private readonly PromotionStorefrontActiveDealResolver $dealResolver,
    ) {
    }

    public function getCode(): string
    {
        return 'promotion_theme_deal';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function collectAdjustments(StorefrontPriceContext $context): array
    {
        $productId = max(0, $context->productId);
        if ($productId <= 0 || $context->catalogPriceMinor <= 0) {
            return [];
        }

        $preferredThemeId = (int)($context->selection['promotion_theme_id'] ?? 0);
        $deal = $this->dealResolver->resolveForProduct(
            $productId,
            $context->catalogPriceMinor > 0 ? $context->catalogPriceMinor / 100 : null,
            $preferredThemeId > 0 ? $preferredThemeId : null,
        );
        if ($deal === null) {
            return [];
        }

        $type = (string)($deal['deal_discount_type'] ?? '');
        $value = (float)($deal['deal_discount_value'] ?? 0);
        if (
            $type === PromotionThemeDealDiscountSyncService::DISCOUNT_NONE
            || $value <= 0
        ) {
            return [];
        }

        $adjustmentType = $type === PromotionThemeDealDiscountSyncService::DISCOUNT_FIXED
            ? StorefrontPriceAdjustment::TYPE_FIXED
            : StorefrontPriceAdjustment::TYPE_PERCENTAGE;

        $themeId = (int)($deal['theme_id'] ?? 0);
        $pageSlug = strtolower(trim((string)($deal['page_slug'] ?? '')));
        $label = trim((string)($deal['campaign_label'] ?? ''));
        $url = trim((string)($deal['campaign_url'] ?? ''));

        return [
            new StorefrontPriceAdjustment(
                code: 'promotion_theme_deal:' . ($themeId > 0 ? (string)$themeId : $pageSlug),
                sourceModule: 'Weline_Promotion',
                sourceType: 'promotion_activity_theme',
                sourceId: $themeId > 0 ? (string)$themeId : $pageSlug,
                label: $label,
                type: $adjustmentType,
                value: $value,
                priority: 100,
                stackable: false,
                exclusiveGroup: StorefrontPriceAdjustment::GROUP_UNIT,
                url: $url,
                badge: $label,
            ),
        ];
    }

    /**
     * Campaign choices for PDP when multiple real-discount themes overlap.
     *
     * @return list<array{
     *     theme_id:int,
     *     label:string,
     *     url:string,
     *     deal_discount_type:string,
     *     deal_discount_value:float,
     *     page_slug:string
     * }>
     */
    public function listEligibleCampaignChoices(StorefrontPriceContext $context): array
    {
        $productId = max(0, $context->productId);
        if ($productId <= 0 || $context->catalogPriceMinor <= 0) {
            return [];
        }

        $choices = [];
        foreach ($this->dealResolver->listEligibleDealsForProduct(
            $productId,
            $context->catalogPriceMinor / 100,
        ) as $deal) {
            $themeId = (int)($deal['theme_id'] ?? 0);
            $label = trim((string)($deal['campaign_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $choices[] = [
                'theme_id' => $themeId,
                'label' => $label,
                'url' => trim((string)($deal['campaign_url'] ?? '')),
                'deal_discount_type' => (string)($deal['deal_discount_type'] ?? ''),
                'deal_discount_value' => (float)($deal['deal_discount_value'] ?? 0),
                'page_slug' => (string)($deal['page_slug'] ?? ''),
            ];
        }

        return $choices;
    }
}
