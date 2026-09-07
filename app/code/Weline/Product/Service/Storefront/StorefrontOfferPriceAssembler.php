<?php

declare(strict_types=1);

namespace Weline\Product\Service\Storefront;

use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Product\Api\Data\StorefrontOfferPriceView;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;

/**
 * Merges provider adjustments into one unit-price view.
 *
 * Policy (v1):
 * - Non-stackable adjustments compete inside exclusive_group (default unit): strongest savings wins.
 * - Stackable adjustments apply after the exclusive winners, in priority then code order.
 * - Cart/checkout coupons remain Marketing DiscountQuote — not assembled here.
 */
final class StorefrontOfferPriceAssembler implements StorefrontOfferPriceAssemblerInterface
{
    public function __construct(
        private readonly StorefrontPriceAdjustmentProviderRegistry $providers,
    ) {
    }

    /** @var list<\Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface>|null */
    private ?array $resolvedProviders = null;

    public function assemble(StorefrontPriceContext $context): StorefrontOfferPriceView
    {
        $catalogMinor = max(0, $context->catalogPriceMinor);
        $currency = strtoupper(trim($context->currency)) ?: 'CNY';

        $candidates = [];
        foreach ($this->resolvedProviders ??= $this->providers->all() as $provider) {
            $providerCode = strtolower(trim($provider->getCode()));
            $providerLabel = preg_replace('/[^a-z0-9_.-]+/', '_', $providerCode) ?: 'unknown';
            try {
                $adjustments = RequestLifecycleTrace::measurePhase(
                    'product.price.adjustment.' . $providerLabel,
                    fn(): array => $provider->collectAdjustments($context),
                    ['provider' => $providerCode, 'product_id' => $context->productId],
                );
                foreach ($adjustments as $adjustment) {
                    if (!$adjustment instanceof StorefrontPriceAdjustment) {
                        continue;
                    }
                    if (trim($adjustment->code) === '') {
                        continue;
                    }
                    if ($adjustment->isAbsoluteMinor()) {
                        if ($adjustment->value < 0) {
                            continue;
                        }
                    } elseif ($adjustment->value <= 0) {
                        continue;
                    }
                    if (
                        !$adjustment->isPercentage()
                        && !$adjustment->isFixed()
                        && !$adjustment->isAbsoluteMinor()
                    ) {
                        continue;
                    }
                    $candidates[] = $adjustment;
                }
            } catch (\Throwable $e) {
                if (function_exists('w_log_error')) {
                    w_log_error('Storefront price adjustment collect failed: ' . $provider->getCode() . ' ' . $e->getMessage());
                }
            }
        }

        $applied = [];
        $workingMinor = $catalogMinor;

        $exclusive = [];
        $stackable = [];
        foreach ($candidates as $adjustment) {
            if ($adjustment->stackable) {
                $stackable[] = $adjustment;
            } else {
                $group = trim($adjustment->exclusiveGroup) !== ''
                    ? $adjustment->exclusiveGroup
                    : StorefrontPriceAdjustment::GROUP_UNIT;
                $exclusive[$group][] = $adjustment;
            }
        }

        foreach ($exclusive as $groupAdjustments) {
            $winner = $this->pickStrongest($workingMinor, $groupAdjustments);
            if ($winner === null) {
                continue;
            }
            $next = $this->applyAdjustment($workingMinor, $winner);
            if ($winner->isAbsoluteMinor()) {
                $workingMinor = $next;
                $applied[] = $winner;
                continue;
            }
            if ($next >= $workingMinor) {
                continue;
            }
            $workingMinor = $next;
            $applied[] = $winner;
        }

        usort(
            $stackable,
            static function (StorefrontPriceAdjustment $left, StorefrontPriceAdjustment $right): int {
                $byPriority = $right->priority <=> $left->priority;
                if ($byPriority !== 0) {
                    return $byPriority;
                }

                return strcmp($left->code, $right->code);
            },
        );
        foreach ($stackable as $adjustment) {
            $next = $this->applyAdjustment($workingMinor, $adjustment);
            if ($next >= $workingMinor) {
                continue;
            }
            $workingMinor = $next;
            $applied[] = $adjustment;
        }

        $hasDeal = $workingMinor < $catalogMinor;
        $primary = null;
        if ($hasDeal && $applied !== []) {
            $first = $applied[0];
            $primary = [
                'label' => trim($first->label),
                'url' => trim($first->url),
                'badge' => trim($first->badge) !== '' ? trim($first->badge) : trim($first->label),
                'code' => $first->code,
                'source_module' => $first->sourceModule,
                'source_type' => $first->sourceType,
                'source_id' => $first->sourceId,
            ];
        }

        return new StorefrontOfferPriceView(
            currency: $currency,
            catalogPriceMinor: $catalogMinor,
            finalPriceMinor: $workingMinor,
            compareAtMinor: $hasDeal ? $catalogMinor : $workingMinor,
            hasDeal: $hasDeal,
            appliedAdjustments: $applied,
            primaryCampaign: $primary,
        );
    }

    /**
     * @param list<StorefrontPriceAdjustment> $adjustments
     */
    private function pickStrongest(int $baseMinor, array $adjustments): ?StorefrontPriceAdjustment
    {
        $absolutes = [];
        foreach ($adjustments as $adjustment) {
            if ($adjustment->isAbsoluteMinor()) {
                $absolutes[] = $adjustment;
            }
        }
        if ($absolutes !== []) {
            usort(
                $absolutes,
                static function (StorefrontPriceAdjustment $left, StorefrontPriceAdjustment $right): int {
                    $byPriority = $right->priority <=> $left->priority;
                    if ($byPriority !== 0) {
                        return $byPriority;
                    }

                    return strcmp($left->code, $right->code);
                },
            );

            return $absolutes[0];
        }

        $winner = null;
        $bestSavings = -1;
        $bestPriority = PHP_INT_MIN;
        foreach ($adjustments as $adjustment) {
            $next = $this->applyAdjustment($baseMinor, $adjustment);
            $savings = $baseMinor - $next;
            if ($savings <= 0) {
                continue;
            }
            if (
                $savings > $bestSavings
                || ($savings === $bestSavings && $adjustment->priority > $bestPriority)
            ) {
                $bestSavings = $savings;
                $bestPriority = $adjustment->priority;
                $winner = $adjustment;
            }
        }

        return $winner;
    }

    private function applyAdjustment(int $baseMinor, StorefrontPriceAdjustment $adjustment): int
    {
        $baseMinor = max(0, $baseMinor);
        if ($adjustment->isAbsoluteMinor()) {
            return max(0, (int) round($adjustment->value));
        }
        if ($baseMinor <= 0) {
            return 0;
        }
        if ($adjustment->isPercentage()) {
            $pct = min(100.0, max(0.0, $adjustment->value));

            return (int) round($baseMinor * (1 - $pct / 100));
        }
        if ($adjustment->isFixed()) {
            $cut = (int) round(max(0.0, $adjustment->value) * 100);

            return max(0, $baseMinor - $cut);
        }

        return $baseMinor;
    }
}
