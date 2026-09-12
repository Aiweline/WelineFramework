<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Currency\Helper\CurrencyFormatter;

/**
 * Asymmetric price follow: up follows uplift; down only tip.
 * Sale amounts are always in the website (target) currency.
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
     * Convert remote origin minor units into target currency minor units.
     */
    public function convertOriginMinor(int $originMinor, string $originCurrency, string $targetCurrency): int
    {
        if ($originMinor < 0) {
            $originMinor = 0;
        }
        $from = strtoupper(trim($originCurrency));
        $to = strtoupper(trim($targetCurrency));
        if ($from === '' || $to === '' || $from === $to) {
            return $originMinor;
        }
        if (!class_exists(\Weline\Currency\Helper\CurrencyFormatter::class)) {
            throw new \RuntimeException('dropship_currency_unavailable');
        }
        $major = $originMinor / 100.0;
        $converted = \Weline\Currency\Helper\CurrencyFormatter::convert($major, $from, $to);

        return max(0, (int)round($converted * 100));
    }

    /**
     * Convert origin → target currency, then apply uplift percent.
     */
    public function saleFromOriginInCurrency(
        int $originMinor,
        string $originCurrency,
        string $targetCurrency,
        int $upliftPercent,
    ): int {
        return $this->saleFromOrigin(
            $this->convertOriginMinor($originMinor, $originCurrency, $targetCurrency),
            $upliftPercent,
        );
    }

    /**
     * @return array{direction:string,sale_minor:?int,tip:?string,origin_prev:int,origin:int}
     */
    public function applyRemoteOrigin(
        int $previousOriginMinor,
        int $newOriginMinor,
        int $currentSaleMinor,
        int $upliftPercent,
        bool $priceLock,
        string $originCurrency = '',
        string $targetCurrency = '',
    ): array {
        $direction = 'same';
        if ($newOriginMinor > $previousOriginMinor) {
            $direction = 'up';
        } elseif ($newOriginMinor < $previousOriginMinor && $previousOriginMinor > 0) {
            $direction = 'down';
        }

        $computeSale = function (int $originMinor) use ($upliftPercent, $originCurrency, $targetCurrency): int {
            if ($originCurrency !== '' && $targetCurrency !== '') {
                return $this->saleFromOriginInCurrency($originMinor, $originCurrency, $targetCurrency, $upliftPercent);
            }

            return $this->saleFromOrigin($originMinor, $upliftPercent);
        };

        if ($direction === 'up' && !$priceLock) {
            return [
                'direction' => $direction,
                'sale_minor' => $computeSale($newOriginMinor),
                'tip' => null,
                'origin_prev' => $previousOriginMinor,
                'origin' => $newOriginMinor,
            ];
        }

        if ($direction === 'down') {
            $suggested = $computeSale($newOriginMinor);
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

    /**
     * Listing economics for ops UI: converted cost, margin, origin delta.
     *
     * @return array{
     *   cost_minor:int,
     *   cost_currency:string,
     *   margin_minor:int,
     *   margin_percent:?float,
     *   origin_direction:string,
     *   origin_delta_minor:int,
     *   origin_delta_percent:?float,
     *   origin_prev_minor:int
     * }
     */
    public function economicsSnapshot(
        int $originMinor,
        int $originPrevMinor,
        string $originCurrency,
        int $saleMinor,
        string $saleCurrency,
        string $priceDirection = '',
    ): array {
        $saleCurrency = strtoupper(trim($saleCurrency)) ?: 'CNY';
        $originCurrency = strtoupper(trim($originCurrency)) ?: 'USD';
        if ($originMinor < 0) {
            $originMinor = 0;
        }
        if ($originPrevMinor < 0) {
            $originPrevMinor = 0;
        }
        if ($saleMinor < 0) {
            $saleMinor = 0;
        }

        try {
            $costMinor = $this->convertOriginMinor($originMinor, $originCurrency, $saleCurrency);
        } catch (\Throwable) {
            // 展示降级：换汇不可用时同币直接用原价，跨币暂记 0（避免打爆列表）
            $costMinor = ($originCurrency === $saleCurrency) ? $originMinor : 0;
        }
        $marginMinor = $saleMinor - $costMinor;
        $marginPercent = $costMinor > 0
            ? round(($marginMinor / $costMinor) * 100, 1)
            : null;

        $deltaMinor = $originPrevMinor > 0 ? ($originMinor - $originPrevMinor) : 0;
        $deltaPercent = $originPrevMinor > 0
            ? round((($originMinor - $originPrevMinor) / $originPrevMinor) * 100, 1)
            : null;

        $direction = strtolower(trim($priceDirection));
        if (!in_array($direction, ['up', 'down', 'same'], true)) {
            if ($deltaMinor > 0) {
                $direction = 'up';
            } elseif ($deltaMinor < 0) {
                $direction = 'down';
            } else {
                $direction = 'same';
            }
        }

        return [
            'cost_minor' => $costMinor,
            'cost_currency' => $saleCurrency,
            'margin_minor' => $marginMinor,
            'margin_percent' => $marginPercent,
            'origin_direction' => $direction,
            'origin_delta_minor' => $deltaMinor,
            'origin_delta_percent' => $deltaPercent,
            'origin_prev_minor' => $originPrevMinor,
        ];
    }

    /**
     * Convert local sale into origin currency for side-by-side compare (e.g. ¥ → US$).
     * Same currency → null (no secondary line). FX failure → null (UI degrades).
     *
     * @return array{compare_amount_minor:int,compare_currency:string}|null
     */
    public function saleCompareInOriginCurrency(
        int $saleMinor,
        string $saleCurrency,
        string $originCurrency,
    ): ?array {
        $saleCurrency = strtoupper(trim($saleCurrency)) ?: 'CNY';
        $originCurrency = strtoupper(trim($originCurrency)) ?: 'USD';
        if ($saleMinor < 0) {
            $saleMinor = 0;
        }
        if ($saleCurrency === $originCurrency) {
            return null;
        }
        try {
            $compareMinor = $this->convertOriginMinor($saleMinor, $saleCurrency, $originCurrency);
        } catch (\Throwable) {
            return null;
        }

        return [
            'compare_amount_minor' => $compareMinor,
            'compare_currency' => $originCurrency,
        ];
    }
}
