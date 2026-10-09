<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Extends\Module\Weline_Websites\ScopeDisplayType\B2B as B2BDisplayType;
use Weline\Product\Api\StorefrontCatalogVisibilityFilterInterface;
use Weline\Product\Model\Shard\Offer;
use Weline\Websites\Service\ScopeDisplayTypeResolver;

/**
 * When effective display_type=b2b (and catalog_b2b_products_only is not opted out),
 * keep only wholesale-eligible offers. Does not touch tob carts or commerce routing.
 */
final class B2BStorefrontCatalogVisibilityFilter implements StorefrontCatalogVisibilityFilterInterface
{
    public function __construct(
        private readonly ScopeDisplayTypeResolver $displayTypes,
        private readonly CatalogB2bProductsOnlyPolicy $onlyPolicy,
        private readonly ProductWholesaleEligibility $eligibility,
        private readonly Offer $offerModel,
    ) {
    }

    public function filterSellableOfferIds(
        array $offerIds,
        int $websiteId,
        int $storeId = 0,
        int $channelId = 0,
    ): array {
        $offerIds = array_values(array_unique(array_filter(
            array_map('intval', $offerIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($offerIds === [] || !$this->shouldFilter($websiteId, $storeId, $channelId)) {
            return $offerIds;
        }

        $skuByOffer = $this->loadSkus($offerIds, $websiteId);
        $allowed = [];
        foreach ($offerIds as $offerId) {
            $sku = $skuByOffer[$offerId] ?? '';
            if ($sku !== '' && $this->eligibility->allowsWholesaleDisplay($websiteId, $storeId, null, $sku)) {
                $allowed[] = $offerId;
            }
        }

        return $allowed;
    }

    public function isOfferSellable(
        int $offerId,
        int $websiteId,
        int $storeId = 0,
        int $channelId = 0,
    ): bool {
        if ($offerId <= 0) {
            return false;
        }

        return in_array(
            $offerId,
            $this->filterSellableOfferIds([$offerId], $websiteId, $storeId, $channelId),
            true,
        );
    }

    private function shouldFilter(int $websiteId, int $storeId, int $channelId): bool
    {
        $effective = $this->displayTypes->resolveForScope(
            max(0, $websiteId),
            max(0, $storeId),
            max(0, $channelId),
        );
        if ($effective !== B2BDisplayType::CODE) {
            return false;
        }

        return $this->onlyPolicy->isEnabled($websiteId, $storeId, $channelId);
    }

    /**
     * @param list<int> $offerIds
     * @return array<int, string>
     */
    private function loadSkus(array $offerIds, int $websiteId): array
    {
        $out = [];
        if ($offerIds === []) {
            return $out;
        }
        try {
            $offer = clone $this->offerModel;
            $rows = $offer->forWebsite(max(0, $websiteId))
                ->clear()
                ->where(Offer::schema_fields_ID, $offerIds, 'IN')
                ->select()
                ->fetchArray();
            if (!is_array($rows) || $rows === []) {
                return $out;
            }
            if (!array_is_list($rows)) {
                $rows = [$rows];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $offerId = (int)($row[Offer::schema_fields_ID] ?? 0);
                $sku = trim((string)($row[Offer::schema_fields_SKU] ?? ''));
                if ($offerId > 0 && $sku !== '') {
                    $out[$offerId] = $sku;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }
}
