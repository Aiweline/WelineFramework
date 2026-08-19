<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 顾客账户 CheckoutGroup 汇总 Presenter（MOD-P2F-006 / TEST-BROWSER-02）。
 * 默认 Group 汇总；partial（退款/发票分叉）时展开子 Order。
 * 仅产出展示 DTO，不读 Customer Model/Session。
 */
final class AccountCheckoutGroupPresenter
{
    public const VIEW_SUMMARY = 'group_summary';
    public const VIEW_PARTIAL_EXPANDED = 'partial_expanded';

    /**
     * @param array{
     *   group_uuid: string,
     *   display_number?: string,
     *   status: string,
     *   grand_total_minor: int,
     *   currency?: string,
     *   orders: list<array{
     *     order_uuid: string,
     *     display_number?: string,
     *     status: string,
     *     amount_minor: int,
     *     refund_status?: string|null,
     *     invoice_status?: string|null,
     *     fulfillment_status?: string|null
     *   }>
     * } $group
     * @return array<string, mixed>
     */
    public function present(array $group): array
    {
        $orders = $group['orders'] ?? [];
        $partial = $this->isPartial($orders);
        $view = $partial ? self::VIEW_PARTIAL_EXPANDED : self::VIEW_SUMMARY;
        $currency = strtoupper(trim((string)($group['currency'] ?? 'CNY'))) ?: 'CNY';
        $status = $this->effectiveStatus($group);
        $orderCount = count($orders);

        $refundLabels = [];
        $invoiceLabels = [];
        $fulfillmentLabels = [];
        foreach ($orders as $order) {
            $rs = (string) ($order['refund_status'] ?? '');
            if ($rs !== '' && $rs !== 'none') {
                $refundLabels[] = $this->customerRefundLabel($rs);
            }
            $is = (string) ($order['invoice_status'] ?? '');
            if ($is !== '' && $is !== 'none') {
                $invoiceLabels[] = $this->customerInvoiceLabel($is);
            }
            $fs = (string)($order['fulfillment_status'] ?? '');
            if ($fs !== '' && $fs !== 'none') {
                $fulfillmentLabels[] = $this->customerFulfillmentLabel($fs);
            }
        }

        return [
            'view' => $view,
            'partial' => $partial,
            'group_uuid' => (string) ($group['group_uuid'] ?? ''),
            'display_number' => (string) ($group['display_number'] ?? $group['group_uuid'] ?? ''),
            'status' => $status,
            'status_label' => $this->customerOrderStatusLabel($status),
            'grand_total_minor' => (int) ($group['grand_total_minor'] ?? 0),
            'currency' => $currency,
            'total_label' => $this->moneyLabel((int)($group['grand_total_minor'] ?? 0), $currency),
            'order_count' => $orderCount,
            'order_count_label' => $orderCount . ' ' . \__('笔订单'),
            'summary_line' => $this->summaryLine($group, $partial),
            'refund_semantics' => array_values(array_unique($refundLabels)),
            'invoice_semantics' => array_values(array_unique($invoiceLabels)),
            'fulfillment_semantics' => array_values(array_unique($fulfillmentLabels)),
            'orders' => $partial
                ? array_map(fn (array $order): array => $this->mapOrder($order, $currency), $orders)
                : [],
            'hook' => 'account.sidebar',
            'content_hook' => 'account.sidebar.content',
            'section' => 'orders',
        ];
    }

    /**
     * @param list<array<string, mixed>> $orders
     */
    private function isPartial(array $orders): bool
    {
        if (count($orders) <= 1) {
            // 单 Order 的退款或部分履约仍需展开明细。
            foreach ($orders as $order) {
                $rs = (string) ($order['refund_status'] ?? '');
                if ($rs !== '' && $rs !== 'none') {
                    return true;
                }
                if ((string)($order['fulfillment_status'] ?? '') === 'partial') {
                    return true;
                }
            }

            return false;
        }

        $statuses = [];
        $refunds = [];
        $invoices = [];
        $fulfillments = [];
        foreach ($orders as $order) {
            $statuses[(string) ($order['status'] ?? '')] = true;
            $refunds[(string) ($order['refund_status'] ?? 'none')] = true;
            $invoices[(string)($order['invoice_status'] ?? 'none')] = true;
            $fulfillments[(string)($order['fulfillment_status'] ?? 'none')] = true;
            if ((string)($order['refund_status'] ?? 'none') !== 'none'
                || (string)($order['fulfillment_status'] ?? 'none') === 'partial'
            ) {
                return true;
            }
        }

        return count($statuses) > 1
            || count($refunds) > 1
            || count($invoices) > 1
            || count($fulfillments) > 1;
    }

    /**
     * @param array<string, mixed> $group
     */
    private function summaryLine(array $group, bool $partial): string
    {
        $n = count($group['orders'] ?? []);
        $base = (string) ($group['display_number'] ?? $group['group_uuid'] ?? '');
        if ($partial) {
            return $base . ' · ' . \__('部分状态不同，已展开');
        }

        return $base . ' · ' . $n . ' ' . \__('笔订单');
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function mapOrder(array $order, string $groupCurrency): array
    {
        $status = (string)($order['status'] ?? '');
        $currency = strtoupper(trim((string)($order['currency'] ?? $groupCurrency))) ?: 'CNY';

        return [
            'order_uuid' => (string) ($order['order_uuid'] ?? ''),
            'display_number' => (string) ($order['display_number'] ?? $order['order_uuid'] ?? ''),
            'status' => $status,
            'status_label' => $this->customerOrderStatusLabel($status),
            'amount_minor' => (int) ($order['amount_minor'] ?? 0),
            'currency' => $currency,
            'total_label' => $this->moneyLabel((int)($order['amount_minor'] ?? 0), $currency),
            'refund_label' => $this->customerRefundLabel((string) ($order['refund_status'] ?? 'none')),
            'invoice_label' => $this->customerInvoiceLabel((string) ($order['invoice_status'] ?? 'none')),
            'fulfillment_label' => $this->customerFulfillmentLabel((string) ($order['fulfillment_status'] ?? 'none')),
        ];
    }

    /** @param array<string, mixed> $group */
    private function effectiveStatus(array $group): string
    {
        $statuses = [];
        foreach (($group['orders'] ?? []) as $order) {
            $status = trim((string)($order['status'] ?? ''));
            if ($status !== '') {
                $statuses[$status] = true;
            }
        }

        if (count($statuses) === 1) {
            return (string)array_key_first($statuses);
        }
        if (count($statuses) > 1) {
            return 'mixed';
        }

        return trim((string)($group['status'] ?? ''));
    }

    private function customerOrderStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => \__('待支付'),
            'processing' => \__('处理中'),
            'paid' => \__('已支付'),
            'fulfilled', 'shipped' => \__('已发货'),
            'completed', 'delivered' => \__('已完成'),
            'cancelled', 'canceled' => \__('已取消'),
            'refunded' => \__('已退款'),
            'mixed' => \__('状态不一致'),
            '' => \__('状态待确认'),
            default => $status,
        };
    }

    private function moneyLabel(int $amountMinor, string $currency): string
    {
        return $currency . ' ' . number_format($amountMinor / 100, 2, '.', ',');
    }

    private function customerRefundLabel(string $status): string
    {
        return match ($status) {
            'processing', 'pending', 'unknown', 'submitted' => \__('退款处理中'),
            'succeeded', 'refunded' => \__('已退款'),
            'failed' => \__('退款失败'),
            'refund_late_success_review' => \__('退款对账中'),
            'none', '' => \__('无退款'),
            default => \__('退款状态待确认'),
        };
    }

    private function customerInvoiceLabel(string $status): string
    {
        return match ($status) {
            'issued' => \__('已开票'),
            'pending' => \__('发票处理中'),
            'none', '' => \__('无发票'),
            default => \__('发票状态待确认'),
        };
    }

    private function customerFulfillmentLabel(string $status): string
    {
        return match ($status) {
            'pending' => \__('待发货'),
            'partial' => \__('部分履约'),
            'shipped' => \__('已发货'),
            'delivered' => \__('已送达'),
            'none', '' => \__('履约未开始'),
            default => \__('履约状态待确认'),
        };
    }
}
