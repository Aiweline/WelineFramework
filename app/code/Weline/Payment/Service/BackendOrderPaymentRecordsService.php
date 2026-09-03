<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Payment\Model\PaymentAttempt;

/**
 * Backend order payment records from universal Payment Attempt rows.
 *
 * Checkout/PayPal writes payable_type=order + payable_id=order_uuid.
 */
final class BackendOrderPaymentRecordsService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<array{
     *     payment_method: string,
     *     amount: float,
     *     currency: string,
     *     transaction_id: string,
     *     status: string,
     *     paid_at: string,
     *     source: string
     * }>
     */
    public function listForOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $order = $this->objectManager->getInstance(Order::class)
            ->clear()
            ->reset()
            ->where(Order::schema_fields_ID, $orderId)
            ->find()
            ->fetch();
        if (!$order->getId()) {
            return [];
        }

        $orderUuid = trim((string)$order->getData(Order::schema_fields_ORDER_UUID));
        if ($orderUuid === '') {
            return [];
        }

        return $this->listForPayable('order', $orderUuid);
    }

    /**
     * @return list<array{
     *     payment_method: string,
     *     amount: float,
     *     currency: string,
     *     transaction_id: string,
     *     status: string,
     *     paid_at: string,
     *     source: string
     * }>
     */
    public function listForPayable(string $payableType, string $payableId): array
    {
        $payableType = strtolower(trim($payableType));
        $payableId = trim($payableId);
        if ($payableType === '' || $payableId === '') {
            return [];
        }

        $attempts = $this->objectManager->getInstance(PaymentAttempt::class)
            ->clear()
            ->reset()
            ->where(PaymentAttempt::schema_fields_PAYABLE_TYPE, $payableType)
            ->where(PaymentAttempt::schema_fields_PAYABLE_ID, $payableId)
            ->order(AbstractModel::schema_fields_CREATE_TIME, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $rows = [];
        foreach ($attempts as $attempt) {
            $data = $attempt instanceof PaymentAttempt ? $attempt->getData() : (array)$attempt;
            if (!\is_array($data)) {
                continue;
            }
            $precision = max(0, (int)($data[PaymentAttempt::schema_fields_PRECISION] ?? 2));
            $amountMinor = (int)($data[PaymentAttempt::schema_fields_AMOUNT_MINOR] ?? 0);
            $divisor = 10 ** $precision;
            $rows[] = [
                'payment_method' => $this->firstNonEmpty(
                    (string)($data[PaymentAttempt::schema_fields_METHOD_CODE] ?? ''),
                    (string)($data[PaymentAttempt::schema_fields_PROVIDER_CODE] ?? ''),
                ),
                'amount' => $divisor > 0 ? ($amountMinor / $divisor) : (float)$amountMinor,
                'currency' => $this->firstNonEmpty(
                    (string)($data[PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE] ?? ''),
                    'CNY',
                ),
                'transaction_id' => trim((string)($data[PaymentAttempt::schema_fields_PROVIDER_REFERENCE] ?? '')),
                'status' => $this->mapStatus((string)($data[PaymentAttempt::schema_fields_STATUS] ?? '')),
                'paid_at' => $this->formatDateTime(
                    $this->firstNonEmpty(
                        (string)($data[PaymentAttempt::schema_fields_STARTED_AT] ?? ''),
                        (string)($data[AbstractModel::schema_fields_CREATE_TIME] ?? ''),
                        (string)($data[PaymentAttempt::schema_fields_CREATED_AT] ?? ''),
                    )
                ),
                'source' => 'weline_payment_attempt',
            ];
        }

        return $rows;
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'succeeded', 'captured', 'paid' => 'paid',
            'failed', 'canceled', 'cancelled' => 'failed',
            'refunded' => 'refunded',
            'pending', 'requires_action', 'processing' => 'pending',
            default => $status !== '' ? $status : 'pending',
        };
    }

    private function formatDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $raw;
    }

    private function firstNonEmpty(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
