<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

/**
 * Reject wholesale absolute prices that breach max retail discount or optional cost margin.
 */
final class WholesalePricingGuard
{
    public const ERROR_MAX_DISCOUNT = 'b2b_wholesale_max_discount_exceeded';
    public const ERROR_MIN_MARGIN = 'b2b_wholesale_min_margin_breached';

    /**
     * @throws \InvalidArgumentException
     */
    public function assertAmountAllowed(
        int $retailAmountMinor,
        int $wholesaleAmountMinor,
        int $maxDiscountBps,
        int $minMarginBps = 0,
        ?float $costMajor = null,
    ): void {
        if ($retailAmountMinor < 0 || $wholesaleAmountMinor < 0) {
            throw new \InvalidArgumentException(__('B2B 批发价或零售价非法'));
        }
        $maxDiscountBps = max(0, min(10000, $maxDiscountBps));
        $minMarginBps = max(0, min(10000, $minMarginBps));

        if ($retailAmountMinor === 0) {
            if ($wholesaleAmountMinor > 0) {
                return;
            }
            return;
        }

        $floorAmount = (int) floor($retailAmountMinor * (10000 - $maxDiscountBps) / 10000);
        if ($wholesaleAmountMinor < $floorAmount) {
            throw new \InvalidArgumentException(
                (string) __(
                    'B2B 批发价超过最大折扣护栏（上限 %{1}%）：零售 %{2} 分，最低允许 %{3} 分，实际 %{4} 分',
                    [number_format($maxDiscountBps / 100, 2, '.', ''), $retailAmountMinor, $floorAmount, $wholesaleAmountMinor]
                )
            );
        }

        if ($minMarginBps <= 0 || $costMajor === null || $costMajor <= 0) {
            return;
        }
        $costMinor = (int) round($costMajor * 100);
        if ($costMinor <= 0) {
            return;
        }
        $minSell = (int) ceil($costMinor * (10000 + $minMarginBps) / 10000);
        if ($wholesaleAmountMinor < $minSell) {
            throw new \InvalidArgumentException(
                (string) __(
                    'B2B 批发价低于成本毛利护栏（最低毛利 %{1}%）：成本 %{2} 分，最低售价 %{3} 分，实际 %{4} 分',
                    [number_format($minMarginBps / 100, 2, '.', ''), $costMinor, $minSell, $wholesaleAmountMinor]
                )
            );
        }
    }

    public function discountBps(int $retailAmountMinor, int $wholesaleAmountMinor): int
    {
        if ($retailAmountMinor <= 0) {
            return 0;
        }
        if ($wholesaleAmountMinor >= $retailAmountMinor) {
            return 0;
        }

        return (int) floor((($retailAmountMinor - $wholesaleAmountMinor) * 10000) / $retailAmountMinor);
    }
}
