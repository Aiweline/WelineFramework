<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PayPalShipmentTrackingSyncService;

final class PayPalOrderShippedTrackingObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        try {
            ObjectManager::getInstance(PayPalShipmentTrackingSyncService::class)
                ->syncFromOrderShippedEvent($data);
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('[PayPal] tracking sync failed: ' . $throwable->getMessage());
            }
        }
    }
}
