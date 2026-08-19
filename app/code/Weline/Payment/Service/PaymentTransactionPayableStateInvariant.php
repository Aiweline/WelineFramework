<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Throwable;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Read-only consistency check between a successful legacy transaction and its
 * published payable snapshot. The owning payable module remains responsible
 * for all state transitions; this checker never repairs business facts.
 */
final class PaymentTransactionPayableStateInvariant
{
    public const CODE = 'successful_transaction_payable_not_paid';

    public function __construct(
        private readonly PayableResolverRegistry $resolverRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>|null
     */
    public function inspect(array $transaction): ?array
    {
        if ((string)($transaction[PaymentTransaction::schema_fields_STATUS] ?? '')
            !== PaymentTransaction::STATUS_SUCCESS
        ) {
            return null;
        }

        $request = $this->decodeRequest($transaction[PaymentTransaction::schema_fields_REQUEST_DATA] ?? null);
        $payableType = strtolower(trim((string)($request['payable_type'] ?? '')));
        $payableId = trim((string)(
            $request['payable_id']
            ?? $request['order_id']
            ?? $transaction[PaymentTransaction::schema_fields_ORDER_ID]
            ?? ''
        ));
        $base = [
            'code' => self::CODE,
            'transaction_id' => $transaction[PaymentTransaction::schema_fields_ID] ?? null,
            'transaction_code' => $transaction[PaymentTransaction::schema_fields_TRANSACTION_NO] ?? null,
            'payable_type' => $payableType !== '' ? $payableType : null,
            'payable_id' => $payableId !== '' ? $payableId : null,
            'repairable' => false,
        ];

        if ($payableType === '' || $payableId === '') {
            return $base + [
                'reason' => 'payable_identity_missing',
                'resolution_error' => 'payable_identity_missing',
            ];
        }

        try {
            $snapshot = $this->resolverRegistry->resolveSnapshot($payableType, $payableId);
        } catch (Throwable) {
            return $base + [
                'reason' => 'payable_unresolved',
                'resolution_error' => 'payable_resolution_failed',
            ];
        }

        if (!$snapshot->hasData('payment_status')) {
            return null;
        }

        $payableStatus = strtolower(trim((string)($snapshot->getData('status') ?? '')));
        $paymentStatus = strtolower(trim((string)($snapshot->getData('payment_status') ?? '')));
        if ($payableStatus === 'paid' || $paymentStatus === 'paid') {
            return null;
        }

        return $base + [
            'reason' => 'payable_not_paid',
            'payable_status' => $payableStatus !== '' ? $payableStatus : null,
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRequest(mixed $request): array
    {
        if (is_array($request)) {
            return $request;
        }
        if (!is_string($request) || trim($request) === '') {
            return [];
        }

        $decoded = json_decode($request, true);

        return is_array($decoded) ? $decoded : [];
    }
}
