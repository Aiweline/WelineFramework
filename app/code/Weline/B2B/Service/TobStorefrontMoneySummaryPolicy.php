<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Checkout\Api\StorefrontMoneySummaryPolicyInterface;

/**
 * ToB storefront money policy: ban marketing/payment incentives, DAP tax disclosure,
 * deposit payable — owned by B2B so Checkout stays retail-generic.
 */
final class TobStorefrontMoneySummaryPolicy implements StorefrontMoneySummaryPolicyInterface
{
    public function adjustSsrPayload(array $payload): array
    {
        if (!$this->isTobPayload($payload)) {
            return $payload;
        }

        // Unified channel: cart.grand_total is baseline (no payment incentive).
        // Payable lives on money_summary from StorefrontMoneyTotalsBuilder::adjustDto —
        // do NOT "add back" incentive into cart (legacy SSR deducted-then-restored path).
        $payload['incentive_text'] = '';
        $payload['incentive_minor'] = 0;
        $moneySummary = \is_array($payload['money_summary'] ?? null) ? $payload['money_summary'] : [];
        if ($moneySummary !== []) {
            $moneySummary['payment_incentive_minor'] = 0;
            $moneySummary['incentive_minor'] = 0;
            $moneySummary['incentive_text'] = '';
            $payload['money_summary'] = $moneySummary;
            if (isset($moneySummary['payable_text'])) {
                $payload['payable_text'] = (string)$moneySummary['payable_text'];
            }
        }
        $payload['payment_methods'] = $this->stripIncentiveFromMethods(
            \is_array($payload['payment_methods'] ?? null) ? $payload['payment_methods'] : []
        );
        $payload['payment_methods_html'] = $this->stripIncentiveFromHtml(
            (string)($payload['payment_methods_html'] ?? '')
        );
        $payload['money_summary_note'] = $this->phrase('关税与进口税费未计入本次定金，到港由买家另付');

        return $payload;
    }

    public function adjustDto(array $dto, array $ctx = []): array
    {
        $shell = strtolower(trim((string)($ctx['shell_cart_type'] ?? '')));
        $cartType = strtolower(trim((string)($ctx['cart_type'] ?? $dto['cart_type'] ?? '')));
        // Shell toc wins — strip sticky deposit chrome (same contract as checkout-tob.js).
        if ($shell === 'toc' || ($shell !== 'tob' && $cartType !== 'tob')) {
            return $this->normalizeRetailDto($dto, $ctx);
        }
        if ($shell === 'tob') {
            $cartType = 'tob';
        }

        $dto['commerce_deposit_allowed'] = true;
        $dto['discount_minor'] = 0;
        $dto['discount_label'] = '';
        $dto['payment_incentive_minor'] = 0;
        $dto['sales_tax_minor'] = 0;
        $dto['customs_duty_minor'] = 0;
        $dto['duty_minor'] = 0;
        $dto['import_tax_minor'] = 0;
        $dto['tax_minor'] = 0;
        $dto['cod_fee_minor'] = 0;
        $dto['note'] = $this->phrase('关税与进口税费未计入本次定金，到港由买家另付');
        $goodsMinor = max(0, (int)($dto['goods_subtotal_minor'] ?? 0));
        $shippingMinor = max(0, (int)($dto['shipping_minor'] ?? 0));
        $dto['order_total_minor'] = $goodsMinor + $shippingMinor;
        $dto['order_total_label'] = $this->phrase('本单共计');
        if (isset($ctx['deposit_minor'])) {
            $dto['deposit_minor'] = max(0, (int)$ctx['deposit_minor']);
        }
        if (isset($ctx['credit_minor'])) {
            $dto['credit_minor'] = max(0, (int)$ctx['credit_minor']);
        }
        if (isset($ctx['payable_minor'])) {
            $dto['payable_minor'] = max(0, (int)$ctx['payable_minor']);
        }
        $depositLabel = trim((string)($ctx['payable_label_deposit'] ?? ''));
        if ($depositLabel !== '') {
            $dto['payable_label'] = $depositLabel;
        }

        return $dto;
    }

    /**
     * Retail / toc: clear ToB deposit rows and restore a retail payable label.
     *
     * @param array<string, mixed> $dto
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function normalizeRetailDto(array $dto, array $ctx = []): array
    {
        $dto['cart_type'] = 'toc';
        $dto['commerce_deposit_allowed'] = false;
        $dto['deposit_minor'] = 0;
        $dto['credit_minor'] = 0;
        $dto['order_total_minor'] = 0;
        $dto['order_total_label'] = '';
        $retailLabel = trim((string)($ctx['payable_label_retail'] ?? ''));
        if ($retailLabel === '') {
            $retailLabel = $this->phrase('小计');
        }
        $dto['payable_label'] = $retailLabel;

        return $dto;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isTobPayload(array $payload): bool
    {
        $cart = \is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        foreach ([
            $payload['cart_type'] ?? null,
            $payload['selling_mode'] ?? null,
            $cart['cart_type'] ?? null,
            $cart['selling_mode'] ?? null,
        ] as $raw) {
            if (strtolower(trim((string)$raw)) === 'tob') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function stripIncentiveFromMethods(array $methods): array
    {
        $out = [];
        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }
            $method['incentive_available'] = false;
            $method['incentive_savings_minor'] = 0;
            $method['incentive_display'] = '';
            $out[] = $method;
        }

        return $out;
    }

    private function stripIncentiveFromHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $html = (string)preg_replace(
            '/<span[^>]*\bdata-payment-incentive\b[^>]*>.*?<\/span>/si',
            '',
            $html
        );
        $html = (string)preg_replace(
            '/\s*weline-checkout__option--has-incentive\b/',
            '',
            $html
        );
        $html = (string)preg_replace(
            '/\sdata-incentive-savings-minor="[^"]*"/i',
            '',
            $html
        );
        $html = (string)preg_replace(
            '/\sdata-incentive-available="[^"]*"/i',
            '',
            $html
        );

        return $html;
    }

    private function phrase(string $source): string
    {
        return \function_exists('__') ? (string)\__($source) : $source;
    }
}
