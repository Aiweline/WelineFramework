<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableContext;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Interface\PayableResolverInterface;
use Weline\Payment\Model\PaymentIntent;

final class DefaultPayableResolver implements PayableResolverInterface
{
    public const PAYABLE_TYPE = 'payment_default';

    private const DEFAULT_CURRENCY = 'CNY';
    private const DEFAULT_PRECISION = 2;

    public function getPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function resolve(string $payableId, ?Actor $actor = null): PayableContext
    {
        $payableId = trim($payableId);
        if ($payableId === '') {
            throw new \InvalidArgumentException('payment_default_payable_id_required');
        }

        return PayableContext::fromArray([
            PayableContext::FIELD_PAYABLE_TYPE => self::PAYABLE_TYPE,
            PayableContext::FIELD_PAYABLE_ID => $payableId,
            PayableContext::FIELD_ACTOR => $actor,
            PayableContext::FIELD_PAYLOAD => [
                'payable_type' => self::PAYABLE_TYPE,
                'payable_id' => $payableId,
                'status' => 'open',
                'exists' => true,
            ],
        ]);
    }

    public function snapshot(PayableContext $context): PayableSnapshot
    {
        $payableContext = $context->getPayload();
        $payableContext['payable_type'] = $context->getPayableType() ?: self::PAYABLE_TYPE;
        $payableContext['payable_id'] = $context->getPayableId();

        $payableId = trim((string) ($payableContext['payable_id'] ?? $payableContext['id'] ?? ''));
        if ($payableId === '') {
            throw new \InvalidArgumentException('payment_default_payable_id_required');
        }

        $precision = $this->normalizePrecision($payableContext['precision'] ?? self::DEFAULT_PRECISION);
        $currencyCode = $this->normalizeCurrency((string) ($payableContext['currency_code'] ?? $payableContext['currency'] ?? self::DEFAULT_CURRENCY));
        $baseAmount = $this->normalizeAmountMinor($payableContext['amount_minor'] ?? 0, 'amount_minor');
        $subtotalAmount = $this->normalizeAmountMinor($payableContext['subtotal_amount_minor'] ?? $baseAmount, 'subtotal_amount_minor');
        $taxAmount = $this->normalizeAmountMinor($payableContext['tax_amount_minor'] ?? 0, 'tax_amount_minor');
        $shippingAmount = $this->normalizeAmountMinor($payableContext['shipping_amount_minor'] ?? 0, 'shipping_amount_minor');
        $discountAmount = $this->normalizeAmountMinor($payableContext['discount_amount_minor'] ?? 0, 'discount_amount_minor');
        $assetAmount = $this->normalizeAmountMinor($payableContext['asset_amount_minor'] ?? 0, 'asset_amount_minor');
        $payableAmount = $this->normalizeAmountMinor(
            $payableContext['payable_amount_minor']
                ?? $payableContext['total_amount_minor']
                ?? ($subtotalAmount + $taxAmount + $shippingAmount - $discountAmount - $assetAmount),
            'payable_amount_minor'
        );

        if ($payableAmount < 0) {
            throw new \InvalidArgumentException('payment_default_payable_amount_negative');
        }

        $items = \is_array($payableContext['items'] ?? null) ? array_values($payableContext['items']) : [];

        return PayableSnapshot::fromArray([
            PayableSnapshot::FIELD_PAYABLE_TYPE => (string) ($payableContext['payable_type'] ?? self::PAYABLE_TYPE),
            PayableSnapshot::FIELD_PAYABLE_ID => $payableId,
            'payable_code' => (string) ($payableContext['payable_code'] ?? self::PAYABLE_TYPE . '-' . $payableId),
            PayableSnapshot::FIELD_VERSION => (string) ($payableContext['version'] ?? '1'),
            'status' => (string) ($payableContext['status'] ?? 'open'),
            PayableSnapshot::FIELD_OWNER => \is_array($payableContext['owner'] ?? null) ? $payableContext['owner'] : [],
            PayableSnapshot::FIELD_PAYER => \is_array($payableContext['payer'] ?? null) ? $payableContext['payer'] : [],
            PayableSnapshot::FIELD_ITEMS => $items,
            PayableSnapshot::FIELD_AMOUNT_MINOR => $payableAmount,
            PayableSnapshot::FIELD_CURRENCY_CODE => $currencyCode,
            PayableSnapshot::FIELD_PRECISION => $precision,
            'amounts' => [
                'subtotal_amount_minor' => $subtotalAmount,
                'tax_amount_minor' => $taxAmount,
                'shipping_amount_minor' => $shippingAmount,
                'discount_amount_minor' => $discountAmount,
                'asset_amount_minor' => $assetAmount,
                'payable_amount_minor' => $payableAmount,
            ],
            PayableSnapshot::FIELD_COUNTRY_CODE => strtoupper((string) ($payableContext['country_code'] ?? $payableContext['country'] ?? '')),
            PayableSnapshot::FIELD_LANGUAGE_CODE => (string) ($payableContext['locale'] ?? 'zh_Hans_CN'),
            PayableSnapshot::FIELD_TIMEZONE => (string) ($payableContext['timezone'] ?? date_default_timezone_get()),
            'scope' => (string) ($payableContext['scope'] ?? PaymentScopeConfigService::DEFAULT_SCOPE),
            'refundable' => ($payableContext['refundable'] ?? true) !== false,
            'metadata' => \is_array($payableContext['metadata'] ?? null) ? $payableContext['metadata'] : [],
        ]);
    }

    public function canPay(PayableSnapshot $snapshot, Actor $actor): bool
    {
        $status = (string) ($snapshot->getData('status') ?? 'open');

        return \in_array($status, ['open', 'pending', 'partially_paid'], true)
            && $snapshot->getAmountMinor() >= 0;
    }

    public function canCancel(PayableSnapshot $snapshot): bool
    {
        return \in_array((string) ($snapshot->getData('status') ?? 'open'), ['open', 'pending'], true);
    }

    public function canRefund(RefundRequest $request): bool
    {
        $snapshot = $request->getData('snapshot');
        $refundable = true;
        if ($snapshot instanceof PayableSnapshot) {
            $refundable = ($snapshot->getData('refundable') ?? true) !== false;
        } elseif (\is_array($snapshot)) {
            $refundable = ($snapshot['refundable'] ?? true) !== false;
        }

        return $refundable && $request->getAmountMinor() > 0;
    }

    public function onPaid(PaymentIntent $intent): void
    {
    }

    public function onPartiallyPaid(PaymentIntent $intent): void
    {
    }

    public function onRefunded(RefundResult $result): void
    {
    }

    public function onExpired(PaymentIntent $intent): void
    {
    }

    public function onRiskReview(PaymentIntent $intent): void
    {
    }

    public function releaseResources(PaymentIntent $intent, string $reason): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayerPolicy(PayableSnapshot $snapshot): array
    {
        return [
            'allowed_actor_types' => ['*'],
            'requires_authenticated_actor' => false,
        ];
    }

    /**
     * @return string[]
     */
    public function getBusinessTags(PayableSnapshot $snapshot): array
    {
        $tags = $snapshot->getArray('business_tags');
        $tags[] = self::PAYABLE_TYPE;

        return array_values(array_unique(array_map('strval', $tags)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLineItems(PayableSnapshot $snapshot): array
    {
        $items = $snapshot->getItems();
        if ($items !== []) {
            return $items;
        }

        return [[
            'item_code' => (string) ($snapshot->getData('payable_code') ?? $snapshot->getPayableId() ?: $snapshot->getPayableType()),
            'name' => $snapshot->getPayableType() ?: self::PAYABLE_TYPE,
            'quantity' => 1,
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode() ?: self::DEFAULT_CURRENCY,
        ]];
    }

    private function normalizeCurrency(string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new \InvalidArgumentException('payment_currency_code_invalid');
        }

        return $currencyCode;
    }

    private function normalizePrecision(mixed $precision): int
    {
        if (!\is_int($precision) && !(is_string($precision) && ctype_digit($precision))) {
            throw new \InvalidArgumentException('payment_precision_invalid');
        }

        $precision = (int) $precision;
        if ($precision < 0 || $precision > 8) {
            throw new \InvalidArgumentException('payment_precision_invalid');
        }

        return $precision;
    }

    private function normalizeAmountMinor(mixed $amountMinor, string $field): int
    {
        if (\is_float($amountMinor)) {
            throw new \InvalidArgumentException($field . '_must_be_integer_minor_unit');
        }

        if (\is_int($amountMinor)) {
            return $amountMinor;
        }

        if (\is_string($amountMinor) && preg_match('/^-?\d+$/', $amountMinor)) {
            return (int) $amountMinor;
        }

        throw new \InvalidArgumentException($field . '_must_be_integer_minor_unit');
    }
}
