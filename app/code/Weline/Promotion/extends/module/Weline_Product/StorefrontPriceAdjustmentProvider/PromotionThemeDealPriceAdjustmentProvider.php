<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Product\StorefrontPriceAdjustmentProvider;

use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Promotion\Service\PromotionActivityThemeService;
use Weline\Promotion\Service\PromotionStorefrontActiveDealResolver;
use Weline\Promotion\Service\PromotionThemeDealDiscountSyncService;

/**
 * Maps active activity-theme deals into Product storefront unit-price adjustments.
 */
final class PromotionThemeDealPriceAdjustmentProvider implements StorefrontPriceAdjustmentProviderInterface
{
    public function __construct(
        private readonly PromotionStorefrontActiveDealResolver $dealResolver,
        private readonly PromotionActivityThemeService $themeService,
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

        $deal = $this->dealResolver->resolveForProduct(
            $productId,
            $context->catalogPriceMinor > 0 ? $context->catalogPriceMinor / 100 : null,
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
        $meta = $themeId > 0
            ? ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
                'promotion.storefront_campaign_meta',
                serialize([$themeId, $pageSlug]),
                fn(): array => $this->themeService->resolveStorefrontCampaignMeta($themeId, [
                    'page_slug' => $pageSlug,
                ]),
            )
            : [
                'campaign_label' => '',
                'campaign_url' => $pageSlug !== '' ? $this->themeService->storefrontUrl($pageSlug) : '',
                'page_title' => '',
            ];

        $label = trim((string)($meta['campaign_label'] ?? ''));
        if ($label === '') {
            $label = trim((string)($meta['page_title'] ?? ''));
        }
        if ($label === '' && $pageSlug !== '') {
            $label = $pageSlug;
        }

        $url = trim((string)($meta['campaign_url'] ?? ''));
        if ($url === '' && $pageSlug !== '') {
            $url = $this->themeService->storefrontUrl($pageSlug);
        }

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
}
