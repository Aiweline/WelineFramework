<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Order\Api\Data\OrderReadResult;
use Weline\Payment\Model\PaymentCheckoutSession;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Builds shopper-facing payment return context: totals breakdown, status, shipping label.
 */
final class PaymentReturnPresentationService
{
    /**
     * @return array{
     *   totals_lines:list<array{key:string,label:string,amount_minor:int,emphasis?:bool}>,
     *   status_label:string,
     *   shipping_method_label:string,
     *   currency:string
     * }
     */
    public function build(
        PaymentTransaction $transaction,
        ?OrderReadResult $order = null,
        ?PaymentCheckoutSession $session = null,
    ): array {
        $money = $this->resolveMoneySnapshot($transaction, $order, $session);
        $currency = strtoupper(trim((string) ($money['currency'] ?? $transaction->getData(PaymentTransaction::schema_fields_CURRENCY) ?? 'CNY')));
        $discountLines = $this->resolveDiscountLines($transaction, $session, $money);

        return [
            'totals_lines' => $this->buildTotalsLines($money, $discountLines),
            'status_label' => $this->resolveStatusLabel($transaction, $order),
            'shipping_method_label' => $this->resolveShippingMethodLabel($transaction, $order, $session),
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMoneySnapshot(
        PaymentTransaction $transaction,
        ?OrderReadResult $order,
        ?PaymentCheckoutSession $session,
    ): array {
        if ($session !== null) {
            $amountSnapshot = $session->getAmountSnapshot();
            if ($this->hasTotals($amountSnapshot)) {
                return $amountSnapshot;
            }
        }

        if ($order !== null && $this->hasTotals($order->money)) {
            return $order->money + ['currency' => $order->currency];
        }

        $requestData = $transaction->getRequestData();
        $totals = is_array($requestData['totals'] ?? null) ? $requestData['totals'] : [];
        if ($this->hasTotals($totals)) {
            return $totals;
        }

        $amountMinor = (int) round(((float) $transaction->getData(PaymentTransaction::schema_fields_AMOUNT)) * 100);

        return [
            'currency' => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
            'grand_total_minor' => $amountMinor,
        ];
    }

    /**
     * @param array<string, mixed> $money
     */
    private function hasTotals(array $money): bool
    {
        return array_key_exists('grand_total_minor', $money)
            || array_key_exists('subtotal_minor', $money);
    }

    /**
     * @param array<string, mixed> $money
     * @param list<array{key?:string,label?:string,amount_minor?:int}> $discountLines
     * @return list<array{key:string,label:string,amount_minor:int,emphasis?:bool}>
     */
    private function buildTotalsLines(array $money, array $discountLines): array
    {
        $lines = [];
        if (array_key_exists('subtotal_minor', $money)) {
            $lines[] = [
                'key' => 'subtotal',
                'label' => (string) __('商品小计'),
                'amount_minor' => (int) $money['subtotal_minor'],
            ];
        }
        if (array_key_exists('shipping_amount_minor', $money)) {
            $lines[] = [
                'key' => 'shipping',
                'label' => (string) __('运费'),
                'amount_minor' => (int) $money['shipping_amount_minor'],
            ];
        }
        if (array_key_exists('tax_amount_minor', $money)) {
            $lines[] = [
                'key' => 'tax',
                'label' => (string) __('税费'),
                'amount_minor' => (int) $money['tax_amount_minor'],
            ];
        }

        if ($discountLines !== []) {
            foreach ($discountLines as $discountLine) {
                if (!is_array($discountLine)) {
                    continue;
                }
                $amountMinor = (int) ($discountLine['amount_minor'] ?? 0);
                if ($amountMinor === 0) {
                    continue;
                }
                $lines[] = [
                    'key' => (string) ($discountLine['key'] ?? 'discount'),
                    'label' => (string) ($discountLine['label'] ?? __('优惠')),
                    'amount_minor' => $amountMinor,
                ];
            }
        } elseif (array_key_exists('discount_amount_minor', $money) && (int) $money['discount_amount_minor'] > 0) {
            $lines[] = [
                'key' => 'discount',
                'label' => (string) __('优惠'),
                'amount_minor' => -1 * (int) $money['discount_amount_minor'],
            ];
        }

        if (array_key_exists('grand_total_minor', $money)) {
            $lines[] = [
                'key' => 'grand_total',
                'label' => (string) __('订单总额'),
                'amount_minor' => (int) $money['grand_total_minor'],
                'emphasis' => true,
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $money
     * @return list<array{key?:string,label?:string,amount_minor?:int}>
     */
    private function resolveDiscountLines(
        PaymentTransaction $transaction,
        ?PaymentCheckoutSession $session,
        array $money,
    ): array {
        if ($session !== null) {
            $snapshot = $session->getContextSnapshot();
            $stored = $snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_DISCOUNT_LINES] ?? null;
            if (is_array($stored) && $stored !== []) {
                return $stored;
            }

            $amountSnapshot = $session->getAmountSnapshot();
            $fromAmount = $amountSnapshot['discount_lines'] ?? null;
            if (is_array($fromAmount) && $fromAmount !== []) {
                return $fromAmount;
            }
        }

        $requestData = $transaction->getRequestData();
        $fromRequest = $requestData['discount_lines'] ?? null;
        if (is_array($fromRequest) && $fromRequest !== []) {
            return $fromRequest;
        }

        $couponCode = trim((string) ($requestData['coupon_code'] ?? ''));
        $discountMinor = (int) ($money['discount_amount_minor'] ?? 0);
        if ($discountMinor <= 0) {
            return [];
        }

        return [[
            'key' => 'discount',
            'label' => $couponCode !== ''
                ? (string) __('优惠券 (%1)', [$couponCode])
                : (string) __('优惠'),
            'amount_minor' => -1 * $discountMinor,
        ]];
    }

    private function resolveStatusLabel(PaymentTransaction $transaction, ?OrderReadResult $order): string
    {
        if ($transaction->isSuccess()) {
            return (string) __('已支付');
        }
        if ($transaction->isPending() || $transaction->isProcessing()) {
            return (string) __('支付处理中');
        }

        $status = strtolower(trim((string) ($order->status ?? '')));
        $labels = [
            'pending' => (string) __('待处理'),
            'processing' => (string) __('处理中'),
            'paid' => (string) __('已支付'),
            'fulfilled' => (string) __('已发货'),
            'completed' => (string) __('已完成'),
            'cancelled' => (string) __('已取消'),
            'refunded' => (string) __('已退款'),
        ];

        return $labels[$status] ?? ($status !== '' ? $status : (string) __('支付未完成'));
    }

    private function resolveShippingMethodLabel(
        PaymentTransaction $transaction,
        ?OrderReadResult $order,
        ?PaymentCheckoutSession $session,
    ): string {
        $storedLabel = '';
        $methodCode = '';

        if ($session !== null) {
            $snapshot = $session->getContextSnapshot();
            $storedLabel = trim((string) ($snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_SHIPPING_METHOD_LABEL] ?? ''));
            $methodCode = trim((string) ($snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_SHIPPING_METHOD_CODE] ?? ''));
        }

        if ($methodCode === '' && $order !== null) {
            $methodCode = trim((string) ($order->shipping['method'] ?? ''));
        }

        if ($methodCode === '') {
            $requestData = $transaction->getRequestData();
            $shipping = is_array($requestData['shipping_snapshot'] ?? null) ? $requestData['shipping_snapshot'] : [];
            $methodCode = trim((string) ($shipping['method'] ?? ''));
            if ($storedLabel === '') {
                $storedLabel = trim((string) ($requestData['shipping_method_label'] ?? ''));
            }
        }

        return $this->humanizeShippingMethodLabel($methodCode, $storedLabel);
    }

    private function humanizeShippingMethodLabel(string $methodCode, string $storedLabel = ''): string
    {
        if ($storedLabel !== '') {
            return $storedLabel;
        }

        $methodCode = trim($methodCode);
        if ($methodCode === '') {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $methodCode) === 1) {
            return (string) __('标准配送');
        }

        return $methodCode;
    }
}
