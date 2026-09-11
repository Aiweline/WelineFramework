<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

/**
 * Asymmetric price follow: up follows uplift; down only tip.
 */
class DropshipPricingService
{
    public function saleFromOrigin(int $originMinor, int $upliftPercent): int
    {
        if ($originMinor < 0) {
            $originMinor = 0;
        }
        if ($upliftPercent < 0) {
            $upliftPercent = 0;
        }

        return (int)round($originMinor * (1 + $upliftPercent / 100));
    }

    /**
     * @return array{direction:string,sale_minor:?int,tip:?string,origin_prev:int,origin:int}
     */
    public function applyRemoteOrigin(
        int $previousOriginMinor,
        int $newOriginMinor,
        int $currentSaleMinor,
        int $upliftPercent,
        bool $priceLock
    ): array {
        $direction = 'same';
        if ($newOriginMinor > $previousOriginMinor) {
            $direction = 'up';
        } elseif ($newOriginMinor < $previousOriginMinor && $previousOriginMinor > 0) {
            $direction = 'down';
        }

        if ($direction === 'up' && !$priceLock) {
            return [
                'direction' => $direction,
                'sale_minor' => $this->saleFromOrigin($newOriginMinor, $upliftPercent),
                'tip' => null,
                'origin_prev' => $previousOriginMinor,
                'origin' => $newOriginMinor,
            ];
        }

        if ($direction === 'down') {
            $suggested = $this->saleFromOrigin($newOriginMinor, $upliftPercent);
            $dropPct = $previousOriginMinor > 0
                ? round((($previousOriginMinor - $newOriginMinor) / $previousOriginMinor) * 100, 2)
                : 0;

            return [
                'direction' => $direction,
                'sale_minor' => null,
                'tip' => sprintf(
                    '远程降价：原成本 %d → %d（降 %.2f%%）；建议售价 %d（当前售价 %d 未自动下调）',
                    $previousOriginMinor,
                    $newOriginMinor,
                    $dropPct,
                    $suggested,
                    $currentSaleMinor
                ),
                'origin_prev' => $previousOriginMinor,
                'origin' => $newOriginMinor,
            ];
        }

        return [
            'direction' => $direction,
            'sale_minor' => null,
            'tip' => null,
            'origin_prev' => $previousOriginMinor,
            'origin' => $newOriginMinor,
        ];
    }
}
