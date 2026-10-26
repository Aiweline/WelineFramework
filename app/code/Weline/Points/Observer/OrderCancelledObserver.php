<?php

declare(strict_types=1);

namespace Weline\Points\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Release reserved discount points when an order is cancelled before payment.
 */
final class OrderCancelledObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }
        $order = $data['order'] ?? null;
        $orderUuid = trim((string) (
            $data['order_uuid']
            ?? (is_object($order) && method_exists($order, 'getData') ? $order->getData('order_uuid') : '')
            ?? ''
        ));
        if ($orderUuid === '' || !interface_exists(\Weline\Points\Api\PointsDiscountSettlementInterface::class)) {
            return;
        }

        try {
            $settlement = ObjectManager::getInstance(\Weline\Points\Api\PointsDiscountSettlementInterface::class);
            if ($settlement instanceof \Weline\Points\Api\PointsDiscountSettlementInterface) {
                $settlement->releaseForOrder($orderUuid, 'order_cancel:' . $orderUuid);
            }
        } catch (\Throwable) {
        }
    }
}
