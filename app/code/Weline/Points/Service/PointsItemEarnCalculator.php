<?php

declare(strict_types=1);

namespace Weline\Points\Service;

/**
 * Pure per-item earning math: cash proportion, base-currency conversion and
 * product ratio/fixed policy.
 */
final class PointsItemEarnCalculator
{
    public function __construct(
        private readonly PointsBaseCurrencyResolver $baseCurrency = new PointsBaseCurrencyResolver(),
        private readonly EarnCalculator $calculator = new EarnCalculator(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param callable(int): array{ratio:?float,fixed:?int} $policyResolver
     * @return array{ok:bool,points:int,items:list<array<string,mixed>>,reason?:string}
     */
    public function calculate(
        array $items,
        int $totalCheckoutMinor,
        int $cashCheckoutMinor,
        string $checkoutCurrency,
        int $websiteId,
        int $checkoutPrecision,
        int $basePrecision,
        float $pointsRate,
        float $multiplier,
        float $defaultRatio,
        int $defaultFixedPoints,
        callable $policyResolver,
    ): array {
        $totalCheckoutMinor = max(0, $totalCheckoutMinor);
        $cashCheckoutMinor = max(0, $cashCheckoutMinor);
        $points = 0;
        $snapshot = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $qty = max(1, (int) ($item['qty_minor'] ?? 1));
            $rowTotal = max(0, (int) ($item['row_total_minor'] ?? 0));
            $lineCashCheckout = $totalCheckoutMinor > 0
                ? intdiv($rowTotal * $cashCheckoutMinor, $totalCheckoutMinor)
                : 0;
            $lineBase = $this->baseCurrency->convertMinorToBase(
                $lineCashCheckout,
                $checkoutCurrency,
                $websiteId,
                $checkoutPrecision,
            );
            if ($lineBase === null) {
                return ['ok' => false, 'points' => 0, 'items' => [], 'reason' => 'item_base_currency_fx_unavailable'];
            }

            $policy = $policyResolver($productId);
            $ratio = $policy['ratio'] ?? $defaultRatio;
            $fixed = $policy['fixed'];
            if ($fixed === null && $defaultFixedPoints > 0) {
                $fixed = $defaultFixedPoints;
            }
            $line = $this->calculator->linePoints(
                (int) $lineBase['amount_minor'],
                $basePrecision,
                $pointsRate,
                $multiplier,
                $ratio,
                $fixed,
                $qty,
            );
            $points += (int) $line['points'];
            $snapshot[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'row_total_minor' => $rowTotal,
                'line_cash_checkout_minor' => $lineCashCheckout,
                'line_cash_base_minor' => (int) $lineBase['amount_minor'],
                'ratio' => $ratio,
                'fixed' => $fixed,
                'points' => (int) $line['points'],
            ];
        }

        return ['ok' => true, 'points' => max(0, $points), 'items' => $snapshot];
    }
}
