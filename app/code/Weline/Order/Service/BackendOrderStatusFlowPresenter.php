<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Model\Order;
use Weline\Order\Model\OrderHistory;

/**
 * 管理订单/详情顶栏状态轨：订单头 + 退款案例，不含渠道 API。
 */
final class BackendOrderStatusFlowPresenter
{
    /**
     * @param Order|array<string, mixed> $order
     * @param list<mixed> $history
     * @param list<array<string, mixed>> $refundCases
     * @param array<string, mixed> $paymentChrome
     * @return array{
     *   current_code: string,
     *   current_label: string,
     *   payment_method_label: string,
     *   payment_status_label: string,
     *   fulfillment_status_label: string,
     *   has_refund: bool,
     *   latest_comment: string,
     *   steps: list<array{code: string, label: string, state: string, tone: string}>,
     *   refunds: list<array{payment_method: string, provider_refund_id: string, channel_status: string, status: string, reason: string}>
     * }
     */
    public function present(
        Order|array $order,
        array $history = [],
        array $refundCases = [],
        array $paymentChrome = [],
    ): array {
        $data = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($data)) {
            $data = [];
        }

        $status = strtolower(trim((string)($data[Order::schema_fields_STATUS] ?? '')));
        $paymentStatus = strtolower(trim((string)($data[Order::schema_fields_PAYMENT_STATUS] ?? '')));
        $fulfillment = strtolower(trim((string)($data[Order::schema_fields_FULFILLMENT_STATUS] ?? '')));

        $refunds = $this->refundRows($refundCases);
        $hasRefund = $refunds !== []
            || $status === Order::STATUS_REFUNDED
            || $paymentStatus === Order::PAYMENT_STATUS_REFUNDED;

        $steps = [];
        $seenCurrent = $status === '';
        foreach ($this->pathFor($status) as $code) {
            $state = 'upcoming';
            if ($code === $status) {
                $state = 'current';
                $seenCurrent = true;
            } elseif (!$seenCurrent) {
                $state = 'done';
            }
            $steps[] = [
                'code' => $code,
                'label' => Order::getStatusLabel($code),
                'state' => $state,
                'tone' => $this->toneFor($code, $state),
            ];
        }

        $paymentMethodLabel = trim((string)($paymentChrome['payment_method_label'] ?? ''));
        if ($paymentMethodLabel === '') {
            $paymentMethodLabel = trim((string)($paymentChrome['payment_method'] ?? ($data[Order::schema_fields_PAYMENT_METHOD] ?? '')));
        }
        $paymentStatusLabel = trim((string)($paymentChrome['payment_status_label'] ?? ''));
        if ($paymentStatusLabel === '') {
            $paymentStatusLabel = Order::getPaymentStatusLabel($paymentStatus);
        }

        return [
            'current_code' => $status,
            'current_label' => $status !== '' ? Order::getStatusLabel($status) : '',
            'payment_method_label' => $paymentMethodLabel,
            'payment_status_label' => $paymentStatusLabel,
            'fulfillment_status_label' => Order::getFulfillmentStatusLabel($fulfillment),
            'has_refund' => $hasRefund,
            'latest_comment' => $this->latestComment($history, $status, $hasRefund),
            'steps' => $steps,
            'refunds' => $refunds,
        ];
    }

    /**
     * @return list<string>
     */
    private function pathFor(string $status): array
    {
        if ($status === Order::STATUS_CANCELLED) {
            return [Order::STATUS_PENDING, Order::STATUS_CANCELLED];
        }
        if ($status === Order::STATUS_REFUNDED) {
            return [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_PAID,
                Order::STATUS_REFUNDED,
            ];
        }
        if ($status === '') {
            return [Order::STATUS_PENDING];
        }

        return [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_PAID,
            Order::STATUS_FULFILLED,
            Order::STATUS_COMPLETED,
        ];
    }

    private function toneFor(string $code, string $state): string
    {
        if ($state === 'upcoming') {
            return '';
        }
        if ($state === 'done') {
            return 'success';
        }

        return match ($code) {
            Order::STATUS_CANCELLED => 'danger',
            Order::STATUS_REFUNDED => 'warning',
            Order::STATUS_COMPLETED, Order::STATUS_FULFILLED => 'success',
            default => 'info',
        };
    }

    /**
     * @param list<mixed> $history
     */
    private function latestComment(array $history, string $status, bool $hasRefund): string
    {
        $rows = [];
        foreach ($history as $item) {
            $row = $this->asRow($item);
            $comment = trim((string)($row[OrderHistory::schema_fields_COMMENT] ?? $row['comment'] ?? ''));
            if ($comment === '') {
                continue;
            }
            $rows[] = [
                'comment' => $comment,
                'status' => strtolower(trim((string)($row[OrderHistory::schema_fields_STATUS] ?? $row['status'] ?? ''))),
                'created_at' => (string)($row[OrderHistory::schema_fields_CREATED_AT] ?? $row['created_at'] ?? ''),
                'id' => (int)($row[OrderHistory::schema_fields_ID] ?? $row['history_id'] ?? 0),
            ];
        }
        if ($rows === []) {
            return '';
        }
        usort($rows, static function (array $a, array $b): int {
            $byTime = strcmp((string)$b['created_at'], (string)$a['created_at']);
            if ($byTime !== 0) {
                return $byTime;
            }

            return ((int)$b['id'] <=> (int)$a['id']);
        });
        if ($hasRefund || $status === Order::STATUS_REFUNDED) {
            foreach ($rows as $row) {
                if (
                    $row['status'] === Order::STATUS_REFUNDED
                    || str_contains(strtolower($row['comment']), 'refund')
                    || str_contains($row['comment'], '退款')
                ) {
                    return $row['comment'];
                }
            }
        }

        return $rows[0]['comment'];
    }

    /**
     * @param list<array<string, mixed>> $refundCases
     * @return list<array{payment_method: string, provider_refund_id: string, channel_status: string, status: string, reason: string}>
     */
    private function refundRows(array $refundCases): array
    {
        $out = [];
        foreach ($refundCases as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out[] = [
                'payment_method' => trim((string)($row['payment_method'] ?? '')),
                'provider_refund_id' => trim((string)($row['provider_refund_id'] ?? '')),
                'channel_status' => trim((string)($row['channel_status'] ?? '')),
                'status' => trim((string)($row['status'] ?? '')),
                'reason' => trim((string)($row['reason'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function asRow(mixed $item): array
    {
        if (\is_object($item) && method_exists($item, 'getData')) {
            $data = $item->getData();

            return \is_array($data) ? $data : [];
        }

        return \is_array($item) ? $item : [];
    }
}
