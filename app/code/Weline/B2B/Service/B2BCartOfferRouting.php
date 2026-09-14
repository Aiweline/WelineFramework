<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Cart\Api\CommerceCartOfferRoutingInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Remap tob add → toc when the offer is not wholesale-display eligible.
 */
final class B2BCartOfferRouting implements CommerceCartOfferRoutingInterface
{
    public const CART_TYPE_TOC = 'toc';
    public const CART_TYPE_TOB = 'tob';

    public const REASON_NOT_WHOLESALE_ELIGIBLE = 'not_wholesale_eligible';

    public function __construct(
        private readonly ?ProductWholesaleEligibility $eligibility = null,
    ) {
    }

    public function resolveAddCartType(array $params): array
    {
        $cartType = strtolower(trim((string)($params['cart_type'] ?? self::CART_TYPE_TOC)));
        if ($cartType !== self::CART_TYPE_TOB) {
            return ['cart_type' => $cartType === self::CART_TYPE_TOB ? self::CART_TYPE_TOB : self::CART_TYPE_TOC];
        }

        $sku = trim((string)($params['sku'] ?? ''));
        if ($sku === '') {
            // Without SKU we cannot prove ineligibility — keep requested type.
            return ['cart_type' => self::CART_TYPE_TOB];
        }

        $websiteId = max(0, (int)($params['website_id'] ?? 0));
        $storeId = max(0, (int)($params['store_id'] ?? 0));
        $productFlags = null;
        if (isset($params['product_flags']) && is_array($params['product_flags'])) {
            $productFlags = $params['product_flags'];
        } elseif (isset($params['product_id']) && (int)$params['product_id'] > 0) {
            $productFlags = ProductSellingModeFlags::fromOffer([
                'product_id' => (int)$params['product_id'],
                'sku' => $sku,
            ]);
        }

        $gate = $this->eligibilityGate();
        if ($gate === null) {
            return ['cart_type' => self::CART_TYPE_TOB];
        }

        if ($gate->allowsWholesaleDisplay($websiteId, $storeId, $productFlags, $sku)) {
            return ['cart_type' => self::CART_TYPE_TOB];
        }

        return [
            'cart_type' => self::CART_TYPE_TOC,
            'remapped' => true,
            'reason' => self::REASON_NOT_WHOLESALE_ELIGIBLE,
        ];
    }

    private function eligibilityGate(): ?ProductWholesaleEligibility
    {
        if ($this->eligibility instanceof ProductWholesaleEligibility) {
            return $this->eligibility;
        }
        if (!class_exists(ProductWholesaleEligibility::class)) {
            return null;
        }
        try {
            $gate = ObjectManager::getInstance(ProductWholesaleEligibility::class);

            return $gate instanceof ProductWholesaleEligibility ? $gate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
