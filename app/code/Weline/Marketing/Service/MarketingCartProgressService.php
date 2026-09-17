<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

/**
 * 店面满额进度：给定小计与门槛，返回进度与提示文案变量。
 */
final class MarketingCartProgressService
{
    /**
     * @return array{
     *   enabled:bool,
     *   subtotal:float,
     *   threshold:float,
     *   remaining:float,
     *   percent:float,
     *   qualified:bool,
     *   currency:string,
     *   message:string
     * }
     */
    public function build(
        float $subtotal,
        float $threshold,
        string $currency = '',
        string $qualifiedMessage = '',
        string $progressMessage = '',
    ): array {
        $threshold = max(0.0, $threshold);
        $subtotal = max(0.0, $subtotal);
        if ($threshold <= 0.0) {
            return [
                'enabled' => false,
                'subtotal' => $subtotal,
                'threshold' => $threshold,
                'remaining' => 0.0,
                'percent' => 0.0,
                'qualified' => false,
                'currency' => $currency,
                'message' => '',
            ];
        }

        $remaining = max(0.0, \round($threshold - $subtotal, 2));
        $percent = min(100.0, \round(($subtotal / $threshold) * 100, 1));
        $qualified = $subtotal >= $threshold;
        $cur = $currency !== '' ? $currency . ' ' : '';
        if ($qualified) {
            $message = $qualifiedMessage !== ''
                ? $qualifiedMessage
                : (\function_exists('__')
                    ? (string)\__('已满 %{1}%{2}，可享满额优惠', $cur, \number_format($threshold, 2, '.', ''))
                    : ('Qualified at ' . $cur . \number_format($threshold, 2, '.', '')));
        } else {
            $message = $progressMessage !== ''
                ? $progressMessage
                : (\function_exists('__')
                    ? (string)\__('再买 %{1}%{2} 即可满额', $cur, \number_format($remaining, 2, '.', ''))
                    : ('Need ' . $cur . \number_format($remaining, 2, '.', '') . ' more'));
        }

        return [
            'enabled' => true,
            'subtotal' => $subtotal,
            'threshold' => $threshold,
            'remaining' => $remaining,
            'percent' => $percent,
            'qualified' => $qualified,
            'currency' => $currency,
            'message' => $message,
        ];
    }

    /**
     * @param array{subtotal?:float|int|string,grand_total?:float|int|string,currency?:string} $cartTotals
     * @param list<array{threshold:float|int|string,label?:string,kind?:string}> $rules
     * @return array{threshold:float,current:float,remaining:float,currency:string,label:string,progress:float,met:bool}|null
     */
    public function progress(array $cartTotals, array $rules): ?array
    {
        $current = (float)($cartTotals['subtotal'] ?? $cartTotals['grand_total'] ?? 0);
        $currency = \trim((string)($cartTotals['currency'] ?? ''));
        $best = null;
        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $threshold = (float)($rule['threshold'] ?? 0);
            if ($threshold <= 0) {
                continue;
            }
            $built = $this->build($current, $threshold, $currency, (string)($rule['label'] ?? ''), '');
            $candidate = [
                'threshold' => $threshold,
                'current' => $current,
                'remaining' => (float)$built['remaining'],
                'currency' => $currency,
                'label' => \trim((string)($rule['label'] ?? '')),
                'progress' => min(1.0, $threshold > 0 ? $current / $threshold : 0.0),
                'met' => !empty($built['qualified']),
            ];
            if ($best === null) {
                $best = $candidate;
                continue;
            }
            $bestMet = !empty($best['met']);
            $candMet = !empty($candidate['met']);
            if (!$candMet && $bestMet) {
                $best = $candidate;
            } elseif ($candMet === $bestMet) {
                if (!$candMet && $candidate['remaining'] < $best['remaining']) {
                    $best = $candidate;
                } elseif ($candMet && $candidate['threshold'] > $best['threshold']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }
}
