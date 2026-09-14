<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface;
use Weline\Marketing\Api\Deal\ExternalDealDiscountRequest;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Promotion\Model\PromotionActivityTheme;

/**
 * Activity-theme adapter: resolves SKUs then upserts deal discounts via Marketing provider.
 */
final class PromotionThemeDealDiscountSyncService
{
    public const SOURCE_MODULE = 'Weline_Promotion';
    public const SOURCE_TYPE = 'promotion_activity_theme';

    public const DISCOUNT_NONE = ExternalDealDiscountRequest::DISCOUNT_NONE;
    public const DISCOUNT_PERCENTAGE = ExternalDealDiscountRequest::DISCOUNT_PERCENTAGE;
    public const DISCOUNT_FIXED = ExternalDealDiscountRequest::DISCOUNT_FIXED;

    /**
     * @param array<string, mixed> $themeRow
     * @param list<int> $productIds
     * @return array{rule_id:int,skus:list<string>}
     */
    public function sync(array $themeRow, array $productIds): array
    {
        $themeId = (int)($themeRow[PromotionActivityTheme::schema_fields_ID] ?? $themeRow['id'] ?? 0);
        $themeKey = trim((string)($themeRow[PromotionActivityTheme::schema_fields_THEME_KEY] ?? $themeRow['theme_key'] ?? ''));
        $pageSlug = trim((string)($themeRow[PromotionActivityTheme::schema_fields_PAGE_SLUG] ?? $themeRow['page_slug'] ?? ''));
        $status = trim((string)($themeRow[PromotionActivityTheme::schema_fields_STATUS] ?? $themeRow['status'] ?? ''));
        $type = $this->normalizeType((string)($themeRow[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_TYPE] ?? $themeRow['deal_discount_type'] ?? self::DISCOUNT_NONE));
        $value = round(max(0, (float)($themeRow[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_VALUE] ?? $themeRow['deal_discount_value'] ?? 0)), 2);
        $ruleId = (int)($themeRow[PromotionActivityTheme::schema_fields_MARKETING_RULE_ID] ?? $themeRow['marketing_rule_id'] ?? 0);

        $skus = $this->resolveSkus($productIds);
        if ($skus === []) {
            $skus = $this->skusFromThemeFilter($themeRow);
        }

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ExternalDealDiscountProviderInterface::class);
        if (!$provider instanceof ExternalDealDiscountProviderInterface) {
            // Keep existing binding when Marketing provides are not compiled yet.
            return ['rule_id' => $ruleId, 'skus' => $skus];
        }

        $result = $provider->upsert(new ExternalDealDiscountRequest(
            sourceModule: self::SOURCE_MODULE,
            sourceType: self::SOURCE_TYPE,
            sourceId: (string)max(0, $themeId),
            sourceKey: $themeKey !== '' ? $themeKey : $pageSlug,
            displayName: sprintf('活动折扣·%s', $themeKey !== '' ? $themeKey : ('theme-' . $themeId)),
            discountType: $type,
            discountValue: $value,
            skus: $skus,
            active: $themeId > 0 && $status === PromotionActivityTheme::STATUS_ACTIVE,
            existingRuleId: $ruleId,
            priority: 80,
            metadata: [
                'theme_id' => $themeId,
                'page_slug' => $pageSlug,
            ],
            startsAtUtc: $this->nullableUtc((string)($themeRow[PromotionActivityTheme::schema_fields_STARTS_AT] ?? $themeRow['starts_at'] ?? '')),
            endsAtUtc: $this->nullableUtc((string)($themeRow[PromotionActivityTheme::schema_fields_ENDS_AT] ?? $themeRow['ends_at'] ?? '')),
        ));

        $syncedId = max(0, $result->ruleId);
        if ($themeId > 0 && $syncedId > 0) {
            /** @var PromotionActivityTheme $theme */
            $theme = ObjectManager::getInstance(PromotionActivityTheme::class);
            $theme->clear()->where(PromotionActivityTheme::schema_fields_ID, $themeId)->find()->fetch();
            if ($theme->getId()) {
                $theme->setData(PromotionActivityTheme::schema_fields_MARKETING_RULE_ID, $syncedId);
                $theme->setData(PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_TYPE, $type);
                $theme->setData(PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_VALUE, $value);
                $theme->save();
            }
        }

        return [
            'rule_id' => $syncedId,
            'skus' => $result->skus !== [] ? $result->skus : $skus,
        ];
    }

    /**
     * @param array<string, mixed> $themeRow
     * @return list<string>
     */
    private function skusFromThemeFilter(array $themeRow): array
    {
        $raw = (string)($themeRow[PromotionActivityTheme::schema_fields_PRODUCT_FILTER_JSON] ?? $themeRow['product_filter_json'] ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $sku = trim((string)($decoded['sku'] ?? $decoded['filter_sku'] ?? ''));

        return $sku !== '' ? [$sku] : [];
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            self::DISCOUNT_PERCENTAGE, 'percent', 'pct' => self::DISCOUNT_PERCENTAGE,
            self::DISCOUNT_FIXED, 'fixed', 'amount' => self::DISCOUNT_FIXED,
            default => self::DISCOUNT_NONE,
        };
    }

    /**
     * @param list<int> $productIds
     * @return list<string>
     */
    public function resolveSkus(array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === [] || !class_exists(StorefrontCatalogViewService::class)) {
            return [];
        }

        try {
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            $offers = $catalog->publishedOffersForProductIds(array_keys($ids), max(8, count($ids) * 8));
        } catch (\Throwable) {
            return [];
        }

        $skus = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $sku = trim((string)($offer['sku'] ?? ''));
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        return array_keys($skus);
    }

    /**
     * @param array<string, mixed> $deal
     * @return array{price:float,original_price:float,has_deal:bool}
     */
    public function applyDealToPrice(float $catalogPrice, array $deal): array
    {
        $catalogPrice = round(max(0, $catalogPrice), 2);
        $type = $this->normalizeType((string)($deal['deal_discount_type'] ?? self::DISCOUNT_NONE));
        $value = round(max(0, (float)($deal['deal_discount_value'] ?? 0)), 2);
        if ($type === self::DISCOUNT_NONE || $value <= 0 || $catalogPrice <= 0) {
            return [
                'price' => $catalogPrice,
                'original_price' => $catalogPrice,
                'has_deal' => false,
            ];
        }

        if ($type === self::DISCOUNT_PERCENTAGE) {
            $dealPrice = round($catalogPrice * (1 - min(100, $value) / 100), 2);
        } else {
            $dealPrice = round(max(0, $catalogPrice - $value), 2);
        }

        return [
            'price' => $dealPrice,
            'original_price' => $catalogPrice,
            'has_deal' => $dealPrice < $catalogPrice,
        ];
    }

    private function nullableUtc(string $raw): ?string
    {
        $raw = trim($raw);

        return $raw !== '' ? $raw : null;
    }
}
