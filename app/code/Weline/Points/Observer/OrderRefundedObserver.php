<?php

declare(strict_types=1);

namespace Weline\Points\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Points\Service\PointsService;

/**
 * Refund clawback is separate from the payment shell returning spent points.
 */
final class OrderRefundedObserver implements ObserverInterface
{
    public function __construct(
        private readonly PointsService $points,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        try {
            $this->returnDiscountPoints($data);
            $this->points->clawbackForRefund($data);
        } catch (\Throwable $exception) {
            if (\function_exists('w_log_error')) {
                w_log_error('Points order_refunded side-effect failed: ' . $exception->getMessage(), [
                    'order_id' => (int) ($data['order_id'] ?? 0),
                    'exception' => $exception::class,
                ], 'points');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function returnDiscountPoints(array $data): void
    {
        $order = $data['order'] ?? null;
        $orderUuid = trim((string) (
            $data['order_uuid']
            ?? (is_object($order) && method_exists($order, 'getData') ? $order->getData('order_uuid') : '')
            ?? ''
        ));
        if ($orderUuid === '' || !interface_exists(\Weline\Points\Api\PointsDiscountSettlementInterface::class)) {
            return;
        }
        $refund = $data['refund'] ?? null;
        $refundKey = (string) (
            $data['refund_id']
            ?? (is_object($refund) && method_exists($refund, 'getData') ? $refund->getData('payment_id') : '')
            ?? ''
        );
        if ($refundKey === '') {
            $refundKey = 'refund:' . (string) ($data['order_id'] ?? $orderUuid);
        }

        $currency = strtoupper((string) (
            $data['currency']
            ?? (is_object($order) && method_exists($order, 'getData') ? $order->getData('currency') : '')
            ?? 'CNY'
        )) ?: 'CNY';
        $precision = \Weline\Points\Service\OrderPointsContext::precisionForCurrency($currency);
        $totalMinor = 0;
        if (is_object($order) && method_exists($order, 'getData')) {
            $snapshot = json_decode((string) ($order->getData('money_snapshot_json') ?? ''), true);
            if (is_array($snapshot)) {
                $totalMinor = max(0, (int) ($snapshot['grand_total_minor'] ?? 0));
                $precision = max(0, min(6, (int) ($snapshot['precision'] ?? $precision)));
            }
            if ($totalMinor <= 0) {
                $totalMinor = (int) round(max(0.0, (float) ($order->getData('grand_total') ?? 0)) * (10 ** $precision));
            }
        }
        $refundMinor = (int) round(max(0.0, (float) ($data['refund_amount'] ?? 0)) * (10 ** $precision));

        $settlement = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Points\Api\PointsDiscountSettlementInterface::class,
        );
        if ($settlement instanceof \Weline\Points\Api\PointsDiscountSettlementInterface) {
            $settlement->returnForRefund($orderUuid, $refundMinor, $totalMinor, $refundKey);
        }
    }
}
