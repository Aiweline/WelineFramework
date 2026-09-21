<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentShipmentTrackingDispatcher;

/**
 * Shell listener: order_shipped → method_code ProviderShipmentTrackingInterface.
 */
final class OrderShippedShipmentTrackingObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        try {
            ObjectManager::getInstance(PaymentShipmentTrackingDispatcher::class)
                ->dispatchFromOrderShippedEvent($data);
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('[Payment] shipment tracking sync failed: ' . $throwable->getMessage());
            }
        }
    }
}
