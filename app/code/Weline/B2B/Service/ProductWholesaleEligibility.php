<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Product-level wholesale display / qty-gate eligibility.
 *
 * Wholesale UI and tob MOQ apply only when site+product tob is allowed
 * AND the SKU has at least one active website price-list tier.
 * Site default wholesale templates alone MUST NOT unlock PDP wholesale UI
 * (templates seed price lists when wholesale is configured; display follows tiers).
 * Ineligible products MUST NOT enter a tob cart; Cart Offer Routing remaps tob→toc.
 */
final class ProductWholesaleEligibility
{
    public function __construct(
        private readonly ?SellingModePolicy $sellingModePolicy = null,
        private readonly ?PriceListStore $priceLists = null,
        /** @phpstan-ignore-next-line property.onlyWritten — DI may still inject; display ignores template-only. */
        private readonly ?DefaultWholesalePolicy $defaultPolicy = null,
    ) {
    }

    /**
     * @param array<string,mixed>|null $productFlags
     */
    public function allowsWholesaleDisplay(
        int $websiteId,
        int $storeId = 0,
        ?array $productFlags = null,
        string $sku = '',
    ): bool {
        if ($websiteId < 0 || $storeId < 0) {
            return false;
        }
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }
        $policy = $this->policy();
        if ($policy === null) {
            return false;
        }
        if (!$policy->isModeEnabled(SellingModePolicy::MODE_TOB, $websiteId, $storeId, $productFlags)) {
            return false;
        }
        $lists = $this->lists();
        if ($lists === null) {
            return false;
        }

        return $lists->skuHasActiveTiers($sku, $websiteId);
    }

    /**
     * Tob MOQ/step only when the product is wholesale-display eligible.
     *
     * @param array<string,mixed>|null $productFlags
     */
    public function requiresTobQtyGate(
        int $websiteId,
        int $storeId = 0,
        ?array $productFlags = null,
        string $sku = '',
    ): bool {
        return $this->allowsWholesaleDisplay($websiteId, $storeId, $productFlags, $sku);
    }

    private function policy(): ?SellingModePolicy
    {
        if ($this->sellingModePolicy instanceof SellingModePolicy) {
            return $this->sellingModePolicy;
        }
        try {
            $policy = ObjectManager::getInstance(SellingModePolicy::class);

            return $policy instanceof SellingModePolicy ? $policy : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lists(): ?PriceListStore
    {
        if ($this->priceLists instanceof PriceListStore) {
            return $this->priceLists;
        }
        try {
            $lists = ObjectManager::getInstance(PriceListStore::class);

            return $lists instanceof PriceListStore ? $lists : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
