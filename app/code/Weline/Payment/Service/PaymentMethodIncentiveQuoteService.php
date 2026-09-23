<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;

/**
 * SystemConfig incentive_* → 可兑现减免额（amount_minor）。
 */
final class PaymentMethodIncentiveQuoteService implements PaymentMethodIncentiveQuoteInterface
{
    public function quote(
        string $methodCode,
        array $runtimeConfig,
        int $baseAmountMinor,
        string $currencyCode,
        bool $methodAvailable = true,
    ): array {
        $methodCode = strtolower(trim($methodCode));
        $currencyCode = strtoupper(trim($currencyCode !== '' ? $currencyCode : 'CNY'));
        $baseAmountMinor = max(0, $baseAmountMinor);

        $empty = [
            'available' => false,
            'savings_minor' => 0,
            'display' => '',
            'line' => null,
        ];

        if ($methodCode === '' || !$methodAvailable || $baseAmountMinor <= 0) {
            return $empty;
        }

        if (!$this->toBool($runtimeConfig['incentive_enabled'] ?? false)) {
            return $empty;
        }

        if (!$this->isWithinValidityWindow($runtimeConfig)) {
            return $empty;
        }

        $type = strtolower(trim((string) ($runtimeConfig['incentive_type'] ?? 'fixed_amount')));
        if ($type !== 'fixed_amount' && $type !== 'percentage') {
            $type = 'fixed_amount';
        }

        $savings = 0;
        $percent = null;
        if ($type === 'percentage') {
            $percent = $this->parsePercent($runtimeConfig['incentive_percent'] ?? 0);
            if ($percent <= 0) {
                return $empty;
            }
            $savings = (int) round($baseAmountMinor * $percent / 100);
        } else {
            $savings = max(0, (int) ($runtimeConfig['incentive_amount_minor'] ?? 0));
        }

        $cap = max(0, (int) ($runtimeConfig['incentive_cap_minor'] ?? 0));
        if ($cap > 0) {
            $savings = min($savings, $cap);
        }
        $savings = min($savings, $baseAmountMinor);
        if ($savings <= 0) {
            return $empty;
        }

        $funding = strtolower(trim((string) ($runtimeConfig['incentive_funding_source'] ?? 'merchant')));
        if ($funding === '') {
            $funding = 'merchant';
        }
        $ruleVersion = trim((string) ($runtimeConfig['incentive_publish_version'] ?? ''));
        if ($ruleVersion === '') {
            $ruleVersion = $this->buildRuleVersion($runtimeConfig, $type, $savings, $percent);
        }

        $label = trim((string) ($runtimeConfig['incentive_label'] ?? ''));
        if ($label === '') {
            $label = (string) __('支付方式优惠');
        }

        $display = $type === 'percentage' && $percent !== null
            ? (string) __('减 %{1}%（约 %{2}）', [
                rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.'),
                $this->formatMoneyDisplay($savings, $currencyCode),
            ])
            : (string) __('减 %{1}', [$this->formatMoneyDisplay($savings, $currencyCode)]);

        $line = [
            'key' => 'pmi:' . $methodCode . ':' . $ruleVersion,
            'label' => $label,
            'amount_minor' => -1 * $savings,
            'source_type' => self::SOURCE_TYPE,
            'funding_source' => $funding,
            'method_code' => $methodCode,
            'rule_version' => $ruleVersion,
        ];

        $out = [
            'available' => true,
            'savings_minor' => $savings,
            'display' => $display,
            'type' => $type,
            'funding_source' => $funding,
            'rule_version' => $ruleVersion,
            'line' => $line,
        ];
        if ($percent !== null) {
            $out['percent'] = $percent;
        }

        return $out;
    }

    public function applyToOrderData(string $methodCode, array $orderData, array $runtimeConfig): array
    {
        $currency = strtoupper(trim((string) ($orderData['currency'] ?? $orderData['currency_code'] ?? 'CNY')));
        $lines = \is_array($orderData['discount_lines'] ?? null) ? $orderData['discount_lines'] : [];
        $stripped = [];
        $previousIncentiveAbs = 0;
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $source = strtolower(trim((string) ($line['source_type'] ?? '')));
            if ($source === self::SOURCE_TYPE) {
                $previousIncentiveAbs += abs((int) ($line['amount_minor'] ?? 0));
                continue;
            }
            $stripped[] = $line;
        }

        $amountMinor = (int) ($orderData['amount_minor'] ?? round(((float) ($orderData['amount'] ?? 0)) * 100));
        // 基价 = 当前应付 + 已剔除的旧激励（避免切换 method 时二次扣减）
        $baseMinor = max(0, $amountMinor + $previousIncentiveAbs);

        $quote = $this->quote($methodCode, $runtimeConfig, $baseMinor, $currency, true);
        $savings = (int) ($quote['savings_minor'] ?? 0);
        if ($savings > 0 && \is_array($quote['line'] ?? null)) {
            $stripped[] = $quote['line'];
        }

        $newAmountMinor = max(0, $baseMinor - $savings);
        $orderData['discount_lines'] = $stripped;
        $orderData['amount_minor'] = $newAmountMinor;
        $orderData['amount'] = $newAmountMinor / 100;
        $orderData['currency'] = $currency;
        $orderData['currency_code'] = $currency;

        $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [];
        $nonIncentiveDiscountAbs = 0;
        foreach ($stripped as $line) {
            if (!\is_array($line)) {
                continue;
            }
            if (strtolower(trim((string) ($line['source_type'] ?? ''))) === self::SOURCE_TYPE) {
                continue;
            }
            $nonIncentiveDiscountAbs += abs((int) ($line['amount_minor'] ?? 0));
        }
        $totals['discount_amount_minor'] = $nonIncentiveDiscountAbs + $savings;
        $totals['grand_total_minor'] = $newAmountMinor;
        $totals['currency'] = $currency;
        if (!isset($totals['subtotal_minor']) && $newAmountMinor > 0) {
            $totals['subtotal_minor'] = $newAmountMinor + $nonIncentiveDiscountAbs + $savings
                - (int) ($totals['shipping_amount_minor'] ?? 0)
                - (int) ($totals['tax_amount_minor'] ?? 0);
        }
        $orderData['totals'] = $totals;
        $orderData['payment_method_incentive_amount_minor'] = $savings > 0 ? -1 * $savings : 0;

        return [
            'order_data' => $orderData,
            'savings_minor' => $savings,
            'quote' => $quote,
        ];
    }

    public function toListPayloadFields(array $quote): array
    {
        $available = !empty($quote['available']) && (int) ($quote['savings_minor'] ?? 0) > 0;
        $fields = [
            'incentive_savings_minor' => $available ? max(0, (int) ($quote['savings_minor'] ?? 0)) : 0,
            'incentive_display' => $available ? (string) ($quote['display'] ?? '') : '',
            'incentive_available' => $available,
        ];
        if ($available && isset($quote['type'])) {
            $fields['incentive_type'] = (string) $quote['type'];
        }
        if ($available && isset($quote['percent'])) {
            $fields['incentive_percent'] = $quote['percent'];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     */
    private function isWithinValidityWindow(array $runtimeConfig): bool
    {
        $now = time();
        $from = trim((string) ($runtimeConfig['incentive_valid_from'] ?? ''));
        $to = trim((string) ($runtimeConfig['incentive_valid_to'] ?? ''));
        if ($from !== '') {
            $ts = strtotime($from);
            if ($ts !== false && $now < $ts) {
                return false;
            }
        }
        if ($to !== '') {
            $ts = strtotime($to);
            if ($ts !== false && $now > $ts) {
                return false;
            }
        }

        return true;
    }

    private function parsePercent(mixed $raw): float
    {
        if (is_string($raw)) {
            $raw = trim(str_replace('%', '', $raw));
        }
        if (!is_numeric($raw)) {
            return 0.0;
        }
        $value = (float) $raw;
        if ($value < 0) {
            return 0.0;
        }
        if ($value > 100) {
            return 100.0;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     */
    private function buildRuleVersion(array $runtimeConfig, string $type, int $savings, ?float $percent): string
    {
        $seed = [
            't' => $type,
            'a' => (int) ($runtimeConfig['incentive_amount_minor'] ?? 0),
            'p' => $percent,
            'c' => (int) ($runtimeConfig['incentive_cap_minor'] ?? 0),
            'f' => (string) ($runtimeConfig['incentive_funding_source'] ?? 'merchant'),
            'vf' => (string) ($runtimeConfig['incentive_valid_from'] ?? ''),
            'vt' => (string) ($runtimeConfig['incentive_valid_to'] ?? ''),
            's' => $savings,
        ];

        return substr(hash('sha1', (string) json_encode($seed)), 0, 12);
    }

    private function formatMoneyDisplay(int $amountMinor, string $currencyCode): string
    {
        $currencyCode = strtoupper($currencyCode);
        if ($currencyCode === 'JPY') {
            return $currencyCode . ' ' . max(0, $amountMinor);
        }
        $major = number_format(max(0, $amountMinor) / 100, 2, '.', '');

        return $currencyCode . ' ' . $major;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
