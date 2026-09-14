<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Cart\Api\CommerceCartQtyPolicyInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * ToB cart qty gates: default moq=5 / step=5 from SellingModePolicy when available.
 * Ineligible wholesale SKUs (no product tob + active tiers) skip MOQ — legacy lines
 * already in tob carts fail soft; new adds are remapped to toc by Offer Routing.
 */
final class B2BCartQtyPolicy implements CommerceCartQtyPolicyInterface
{
    public const DEFAULT_MOQ = SellingModePolicy::DEFAULT_MOQ;
    public const DEFAULT_QTY_STEP = SellingModePolicy::DEFAULT_QTY_STEP;

    public const ERROR_BELOW_MOQ = 'b2b_qty_below_moq';
    public const ERROR_STEP_MISMATCH = 'b2b_qty_step_mismatch';

    public const CART_TYPE_TOB = 'tob';

    public function __construct(
        private readonly ?SellingModePolicy $sellingModePolicy = null,
        private readonly ?ProductWholesaleEligibility $eligibility = null,
    ) {
    }

    public function assertQty(array $params): array
    {
        $cartType = strtolower(trim((string)($params['cart_type'] ?? '')));
        if ($cartType !== self::CART_TYPE_TOB) {
            return ['ok' => true];
        }

        if (!$this->requiresTobQtyGate($params)) {
            return ['ok' => true, 'detail' => ['wholesale_qty_gate' => false]];
        }

        $qty = max(0, (int)($params['qty'] ?? 0));
        $moq = $this->resolveMoq($params);
        $step = $this->resolveStep($params);

        if ($qty < $moq) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_BELOW_MOQ,
                'message' => (string)__('批发起订量为 %{1} 件', [$moq]),
                'detail' => [
                    'qty' => $qty,
                    'moq' => $moq,
                    'qty_step' => $step,
                ],
            ];
        }

        if ($step > 0 && ($qty % $step) !== 0) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_STEP_MISMATCH,
                'message' => (string)__('批发数量须为 %{1} 的倍数', [$step]),
                'detail' => [
                    'qty' => $qty,
                    'moq' => $moq,
                    'qty_step' => $step,
                ],
            ];
        }

        return [
            'ok' => true,
            'detail' => [
                'qty' => $qty,
                'moq' => $moq,
                'qty_step' => $step,
                'wholesale_qty_gate' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requiresTobQtyGate(array $params): bool
    {
        $sku = trim((string)($params['sku'] ?? ''));
        // Without SKU we cannot prove ineligibility — keep legacy MOQ.
        if ($sku === '') {
            return true;
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
            // Fail soft: keep legacy MOQ when eligibility helper unavailable.
            return true;
        }

        return $gate->requiresTobQtyGate($websiteId, $storeId, $productFlags, $sku);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveMoq(array $params): int
    {
        if (isset($params['moq']) && (int)$params['moq'] > 0) {
            return (int)$params['moq'];
        }
        $websiteId = max(0, (int)($params['website_id'] ?? 0));
        $groupId = trim((string)($params['group_id'] ?? ''));
        try {
            $default = ObjectManager::getInstance(DefaultWholesalePolicy::class);
            if ($default instanceof DefaultWholesalePolicy) {
                if ($groupId !== '' && $default->groupCanInheritTemplate($groupId)) {
                    $tiers = $default->tiersForGroup($groupId, $websiteId);
                    if ($tiers !== []) {
                        return max(1, $default->lowestMinQty($tiers));
                    }
                }
                return max(1, $default->lowestMinQty($default->allTiers($websiteId)));
            }
        } catch (\Throwable) {
        }
        $policy = $this->policy();
        if ($policy !== null) {
            return max(1, $policy->defaultMoq());
        }

        return self::DEFAULT_MOQ;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStep(array $params): int
    {
        if (isset($params['qty_step']) && (int)$params['qty_step'] > 0) {
            return (int)$params['qty_step'];
        }
        $policy = $this->policy();
        if ($policy !== null) {
            return max(1, $policy->defaultQtyStep());
        }

        return self::DEFAULT_QTY_STEP;
    }

    private function policy(): ?SellingModePolicy
    {
        if ($this->sellingModePolicy instanceof SellingModePolicy) {
            return $this->sellingModePolicy;
        }
        if (!class_exists(SellingModePolicy::class)) {
            return null;
        }
        try {
            $policy = ObjectManager::getInstance(SellingModePolicy::class);
            return $policy instanceof SellingModePolicy ? $policy : null;
        } catch (\Throwable) {
            return null;
        }
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
