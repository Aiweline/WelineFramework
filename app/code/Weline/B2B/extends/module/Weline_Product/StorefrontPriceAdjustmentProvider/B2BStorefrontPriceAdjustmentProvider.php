<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Product\StorefrontPriceAdjustmentProvider;

use Weline\B2B\Api\B2BPriceCandidateInterface;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\DefaultWholesalePolicy;
use Weline\B2B\Service\SellingModePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;

/**
 * ToB storefront unit price from B2B list (exclusive absolute_minor in GROUP_UNIT).
 */
final class B2BStorefrontPriceAdjustmentProvider implements StorefrontPriceAdjustmentProviderInterface
{
    public function __construct(
        private readonly ?B2BPriceCandidateInterface $engine = null,
    ) {
    }

    public function getCode(): string
    {
        return 'b2b_list_price';
    }

    public function getPriority(): int
    {
        return 1000;
    }

    public function collectAdjustments(StorefrontPriceContext $context): array
    {
        if ($context->normalizedSellingMode() !== 'tob') {
            return [];
        }
        $customerId = $context->customerIdString();
        $sku = trim($context->sku);
        if ($customerId === '' || $sku === '' || $context->catalogPriceMinor < 0) {
            return [];
        }

        $engine = $this->engine;
        if (!$engine instanceof B2BPriceCandidateInterface) {
            try {
                $engine = ObjectManager::getInstance(B2BPriceCandidateInterface::class);
            } catch (\Throwable) {
                return [];
            }
        }
        if (!$engine instanceof B2BPriceCandidateInterface) {
            return [];
        }

        try {
            $moqQty = 1;
            try {
                $policy = ObjectManager::getInstance(DefaultWholesalePolicy::class);
                if ($policy instanceof DefaultWholesalePolicy) {
                    // Prefer template MOQ for unit price when inheriting; explicit lists still work at qty=1 if they have min_qty=1.
                    $moqQty = max(1, $policy->lowestMinQty($policy->allTiers(max(0, $context->websiteId))));
                }
            } catch (\Throwable) {
            }
            $result = $engine->resolve([
                'customer_id' => $customerId,
                'website_id' => max(0, $context->websiteId),
                'sku' => $sku,
                'qty' => $moqQty,
                'retail_amount_minor' => max(0, $context->catalogPriceMinor),
                'product_flags' => [
                    SellingModePolicy::PRODUCT_FLAG_TOB => true,
                ],
                'selling_mode_tob' => true,
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (($result['ok'] ?? false) !== true) {
            return [];
        }
        $source = (string)($result['source'] ?? '');
        if (!in_array($source, [
            B2BPriceEngine::SOURCE_B2B_WEBSITE,
            B2BPriceEngine::SOURCE_B2B_CHANNEL,
            B2BPriceEngine::SOURCE_B2B_DEFAULT_POLICY,
        ], true)) {
            return [];
        }
        $amount = (int)($result['amount_minor'] ?? -1);
        if ($amount < 0) {
            return [];
        }
        $listId = (string)($result['price_list_id'] ?? '');
        $groupId = (string)($result['group_id'] ?? '');
        if ($listId === '' || $groupId === '') {
            return [];
        }

        return [
            new StorefrontPriceAdjustment(
                code: 'b2b_list:' . $listId,
                sourceModule: 'Weline_B2B',
                sourceType: $source === B2BPriceEngine::SOURCE_B2B_DEFAULT_POLICY ? 'b2b_default_policy' : 'b2b_price_list',
                sourceId: $listId,
                label: (string)__('批发价'),
                type: StorefrontPriceAdjustment::TYPE_ABSOLUTE_MINOR,
                value: (float)$amount,
                priority: 1000,
                stackable: false,
                exclusiveGroup: StorefrontPriceAdjustment::GROUP_UNIT,
                badge: (string)__('批发价'),
            ),
        ];
    }
}
