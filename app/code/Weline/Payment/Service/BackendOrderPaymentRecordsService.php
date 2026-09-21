<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

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
     *     method_label: string,
     *     method_icon_url: string,
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
     *     method_label: string,
     *     method_icon_url: string,
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

        try {
            $this->objectManager->getInstance(PaymentCaptureReaderEnsureService::class)
                ->ensureFromPayable($payableType, $payableId);
        } catch (\Throwable) {
        }

        $rows = [];
        $attemptRows = [];
        foreach (PaymentCaptureReaderEnsureService::payableTypeAliases($payableType) as $type) {
            foreach ($this->attemptsForPayable($type, $payableId) as $row) {
                $attemptRows[] = $row;
            }
        }
        $transactionRows = $this->transactionsForPayable($payableId);
        foreach (self::mergeAttemptAndTransactionRows($attemptRows, $transactionRows) as $row) {
            $rows[] = $this->withMethodChrome($row);
        }

        return $rows;
    }

    /**
     * Attempt ∪ Transaction: prefer Attempt on matching transaction_id;
     * unmatched Transaction rows (including failed) are retained.
     * Sorted paid_at DESC, then transaction_id DESC (stable newest-first).
     *
     * @param list<array<string, mixed>> $attemptRows
     * @param list<array<string, mixed>> $transactionRows
     * @return list<array<string, mixed>>
     */
    public static function mergeAttemptAndTransactionRows(
        array $attemptRows,
        array $transactionRows,
    ): array {
        $merged = [];
        $seenTxnIds = [];

        foreach ($attemptRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $txnId = trim((string)($row['transaction_id'] ?? ''));
            if ($txnId !== '') {
                if (isset($seenTxnIds[$txnId])) {
                    continue;
                }
                $seenTxnIds[$txnId] = true;
            }
            $merged[] = $row;
        }

        foreach ($transactionRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $txnId = trim((string)($row['transaction_id'] ?? ''));
            if ($txnId !== '' && isset($seenTxnIds[$txnId])) {
                continue;
            }
            if ($txnId !== '') {
                $seenTxnIds[$txnId] = true;
            }
            $merged[] = $row;
        }

        usort($merged, static function (array $a, array $b): int {
            $paidAtCmp = ((string)($b['paid_at'] ?? '')) <=> ((string)($a['paid_at'] ?? ''));
            if ($paidAtCmp !== 0) {
                return $paidAtCmp;
            }

            return ((string)($b['transaction_id'] ?? '')) <=> ((string)($a['transaction_id'] ?? ''));
        });

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withMethodChrome(array $row): array
    {
        $code = strtolower(trim((string)($row['payment_method'] ?? '')));
        $chrome = $this->resolveMethodChrome($code);
        $row['payment_method'] = $code;
        $row['method_label'] = $chrome['label'];
        $row['method_icon_url'] = $chrome['icon_url'];

        return $row;
    }

    /**
     * @return array{label: string, icon_url: string}
     */
    private function resolveMethodChrome(string $code): array
    {
        if ($code === '') {
            return [
                'label' => (string)\__('未指定'),
                'icon_url' => '',
            ];
        }

        $iconResolver = $this->objectManager->getInstance(PaymentMethodIconResolver::class);
        try {
            $methods = $this->objectManager->getInstance(PaymentMethodManager::class);
            $method = $methods->getMethodByCode($code);
            if ($method !== null) {
                $display = $methods->getEffectiveDisplayMetadata($method);
                $label = trim((string)$method->getData(PaymentMethod::schema_fields_NAME));
                $icon = trim((string)($display['icon_url'] ?? ''));
                if ($label === '') {
                    $label = $this->fallbackMethodLabel($code);
                }
                if ($icon === '') {
                    $icon = $iconResolver->toPublicUrl($this->fallbackMethodIconRaw($code));
                }

                return [
                    'label' => $label,
                    'icon_url' => $icon,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'label' => $this->fallbackMethodLabel($code),
            'icon_url' => $iconResolver->toPublicUrl($this->fallbackMethodIconRaw($code)),
        ];
    }

    private function fallbackMethodLabel(string $code): string
    {
        return match ($code) {
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
            'fake_card' => (string)\__('本地测试支付'),
            'cash_on_delivery', 'cod' => (string)\__('货到付款'),
            default => $code,
        };
    }

    private function fallbackMethodIconRaw(string $code): string
    {
        return match ($code) {
            'paypal' => 'Weline_Payment::img/payment/paypal.svg',
            'stripe' => 'Weline_Payment::img/payment/stripe.svg',
            'fake_card' => 'Weline_Payment::img/payment/fake-card.svg',
            default => '',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attemptsForPayable(string $payableType, string $payableId): array
    {
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

    /**
     * @return list<array<string, mixed>>
     */
    private function transactionsForPayable(string $payableId): array
    {
        $items = $this->objectManager->getInstance(PaymentTransaction::class)
            ->clear()
            ->reset()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $payableId)
            ->order(PaymentTransaction::schema_fields_ID, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        $rows = [];
        foreach ($items as $item) {
            $data = $item instanceof PaymentTransaction ? $item->getData() : (array)$item;
            if (!\is_array($data)) {
                continue;
            }
            $rows[] = [
                'payment_method' => (string)($data[PaymentTransaction::schema_fields_METHOD_CODE] ?? ''),
                'amount' => (float)($data[PaymentTransaction::schema_fields_AMOUNT] ?? 0),
                'currency' => $this->firstNonEmpty(
                    (string)($data[PaymentTransaction::schema_fields_CURRENCY] ?? ''),
                    'CNY',
                ),
                'transaction_id' => trim((string)($data[PaymentTransaction::schema_fields_TRANSACTION_NO] ?? '')),
                'status' => $this->mapStatus((string)($data[PaymentTransaction::schema_fields_STATUS] ?? '')),
                'paid_at' => $this->formatDateTime(
                    (string)($data[PaymentTransaction::schema_fields_PAID_AT] ?? ''),
                ),
                'source' => 'weline_payment_transaction',
            ];
        }

        return $rows;
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'succeeded', 'captured', 'paid', 'success' => 'paid',
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
