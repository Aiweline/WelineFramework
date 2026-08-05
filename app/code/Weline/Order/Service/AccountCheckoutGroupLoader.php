<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\RefundCase;

/**
 * 顾客账户 CheckoutGroup 聚合加载（MOD-P2F-006 / TEST-BROWSER-02）。
 *
 * 从 Order.customer_id + website_id 反查 checkout_group_uuid，再附退款/发票语义。
 * 不读 Session；调用方必须先用 AccountSidebarProjection 取可信身份。
 */
final class AccountCheckoutGroupLoader
{
    public function __construct(
        private readonly Order $orderModel,
        private readonly CheckoutGroup $checkoutGroupModel,
        private readonly RefundCase $refundCaseModel,
        private readonly OrderInvoice $orderInvoiceModel,
    ) {
    }

    /**
     * @return list<array{
     *   group_uuid: string,
     *   display_number: string,
     *   status: string,
     *   grand_total_minor: int,
     *   currency: string,
     *   orders: list<array<string, mixed>>
     * }>
     */
    public function loadForCustomer(int $customerId, int $websiteId, int $limit = 20): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $orderModel = clone $this->orderModel;
        $query = $orderModel->reset()
            ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, null, 'is not null')
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, '', '!=')
            ->order(Order::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit * 5);

        // website_id=0 是系统默认站，必须纳入过滤；NULL 行（未切 Scope 列）不归当前站。
        if ($websiteId >= 0) {
            $query->where(Order::schema_fields_WEBSITE_ID, $websiteId);
        }

        $orderRows = $query->select()->fetchArray();
        if ($orderRows === []) {
            return [];
        }

        $byGroup = [];
        $orderIds = [];
        $orderUuids = [];
        foreach ($orderRows as $row) {
            $groupUuid = trim((string) ($row[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? ''));
            if ($groupUuid === '') {
                continue;
            }
            $byGroup[$groupUuid][] = $row;
            $orderIds[] = (int) ($row[Order::schema_fields_ID] ?? 0);
            $orderUuid = trim((string)($row[Order::schema_fields_ORDER_UUID] ?? ''));
            if ($orderUuid !== '') {
                $orderUuids[] = $orderUuid;
            }
        }
        if ($byGroup === []) {
            return [];
        }

        $groupUuids = array_slice(array_keys($byGroup), 0, $limit);
        $groupMeta = $this->loadGroupMeta($groupUuids);
        $refundByOrder = $this->loadLatestRefundStatus($orderUuids);
        $invoiceByOrder = $this->loadLatestInvoiceStatus($orderIds);

        $result = [];
        foreach ($groupUuids as $groupUuid) {
            $orders = [];
            foreach ($byGroup[$groupUuid] as $row) {
                $orderId = (int) ($row[Order::schema_fields_ID] ?? 0);
                $orderUuid = (string)($row[Order::schema_fields_ORDER_UUID] ?? '');
                $orders[] = [
                    'order_uuid' => $orderUuid,
                    'display_number' => (string) ($row[Order::schema_fields_ORDER_NUMBER] ?? ''),
                    'status' => (string) ($row[Order::schema_fields_STATUS] ?? ''),
                    'amount_minor' => $this->orderAmountMinor($row),
                    'refund_status' => $refundByOrder[$orderUuid] ?? 'none',
                    'invoice_status' => $invoiceByOrder[$orderId] ?? 'none',
                    'fulfillment_status' => (string) ($row[Order::schema_fields_FULFILLMENT_STATUS] ?? 'none'),
                ];
            }

            $meta = $groupMeta[$groupUuid] ?? null;
            $result[] = [
                'group_uuid' => $groupUuid,
                'display_number' => $meta['display_number'] ?? ('G-' . substr($groupUuid, 0, 8)),
                'status' => (string) ($meta['status'] ?? ($orders[0]['status'] ?? '')),
                'grand_total_minor' => (int) ($meta['grand_total_minor'] ?? array_sum(array_column($orders, 'amount_minor'))),
                'currency' => (string) ($meta['currency'] ?? 'CNY'),
                'orders' => $orders,
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $groupUuids
     * @return array<string, array{display_number:string,status:string,grand_total_minor:int,currency:string}>
     */
    private function loadGroupMeta(array $groupUuids): array
    {
        if ($groupUuids === []) {
            return [];
        }

        $model = clone $this->checkoutGroupModel;
        $rows = $model->reset()
            ->where(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, $groupUuids, 'IN')
            ->select()
            ->fetchArray();

        $out = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row[CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID] ?? '');
            if ($uuid === '') {
                continue;
            }
            $out[$uuid] = [
                'display_number' => 'G-' . substr($uuid, 0, 8),
                'status' => (string) ($row[CheckoutGroup::schema_fields_STATUS] ?? ''),
                'grand_total_minor' => (int) ($row[CheckoutGroup::schema_fields_GRAND_TOTAL_MINOR] ?? 0),
                'currency' => (string) ($row[CheckoutGroup::schema_fields_CURRENCY] ?? 'CNY'),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $orderUuids
     * @return array<string, string>
     */
    private function loadLatestRefundStatus(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $uuid): string => trim((string)$uuid), $orderUuids),
            static fn (string $uuid): bool => $uuid !== '',
        )));
        if ($orderUuids === []) {
            return [];
        }

        $model = clone $this->refundCaseModel;
        $rows = $model->reset()
            ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuids, 'IN')
            ->order(RefundCase::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        $out = [];
        foreach ($rows as $row) {
            $orderUuid = trim((string)($row[RefundCase::schema_fields_ORDER_UUID] ?? ''));
            if ($orderUuid === '' || isset($out[$orderUuid])) {
                continue;
            }
            $out[$orderUuid] = $this->mapRefundStatus(
                (string)($row[RefundCase::schema_fields_CUSTOMER_VIEW] ?? ''),
                (string)($row[RefundCase::schema_fields_STATUS] ?? ''),
            );
        }

        return $out;
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, string>
     */
    private function loadLatestInvoiceStatus(array $orderIds): array
    {
        $orderIds = array_values(array_filter($orderIds, static fn (int $id): bool => $id > 0));
        if ($orderIds === []) {
            return [];
        }

        $model = clone $this->orderInvoiceModel;
        $rows = $model->reset()
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderIds, 'IN')
            ->order(OrderInvoice::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        $out = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row[OrderInvoice::schema_fields_ORDER_ID] ?? 0);
            if ($orderId <= 0 || isset($out[$orderId])) {
                continue;
            }
            $out[$orderId] = $this->mapInvoiceStatus((string) ($row[OrderInvoice::schema_fields_STATUS] ?? ''));
        }

        return $out;
    }

    private function mapRefundStatus(string $customerView, string $status): string
    {
        return match ($customerView) {
            OrderRefundCoordinator::CUSTOMER_VIEW_PROCESSING => $status
                === RefundCase::STATUS_LATE_SUCCESS_REVIEW
                ? RefundCase::STATUS_LATE_SUCCESS_REVIEW
                : 'processing',
            OrderRefundCoordinator::CUSTOMER_VIEW_SUCCEEDED => 'succeeded',
            OrderRefundCoordinator::CUSTOMER_VIEW_FAILED => 'failed',
            default => match ($status) {
                RefundCase::STATUS_OPEN, RefundCase::STATUS_SUBMITTED => 'processing',
                RefundCase::STATUS_SUCCEEDED => 'succeeded',
                RefundCase::STATUS_FAILED => 'failed',
                RefundCase::STATUS_LATE_SUCCESS_REVIEW
                    => RefundCase::STATUS_LATE_SUCCESS_REVIEW,
                RefundCase::STATUS_CANCELLED, '' => 'none',
                default => 'processing',
            },
        };
    }

    private function mapInvoiceStatus(string $status): string
    {
        return match ($status) {
            OrderInvoice::STATUS_PENDING => 'pending',
            OrderInvoice::STATUS_ISSUED => 'issued',
            OrderInvoice::STATUS_CANCELLED, '' => 'none',
            default => 'pending',
        };
    }

    /** @param array<string, mixed> $row */
    private function orderAmountMinor(array $row): int
    {
        $snapshot = json_decode(
            (string)($row[Order::schema_fields_MONEY_SNAPSHOT_JSON] ?? ''),
            true,
        );
        $minor = \is_array($snapshot) ? ($snapshot['grand_total_minor'] ?? null) : null;
        if (\is_int($minor) && $minor >= 0) {
            return $minor;
        }
        if (\is_string($minor) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $minor) === 1) {
            $normalized = (int)$minor;
            if ((string)$normalized === $minor) {
                return $normalized;
            }
        }

        return $this->decimalToMinor(
            (string)($row[Order::schema_fields_GRAND_TOTAL] ?? '0'),
        );
    }

    private function decimalToMinor(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $match) !== 1) {
            return 0;
        }
        $major = (int)$match[1];
        if ((string)$major !== $match[1] || $major > intdiv(PHP_INT_MAX, 100)) {
            return 0;
        }
        $fraction = str_pad((string)($match[2] ?? ''), 2, '0');

        return $major * 100 + (int)$fraction;
    }
}
