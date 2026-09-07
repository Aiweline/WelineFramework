<?php

declare(strict_types=1);

namespace Weline\B2B\Observer;

use Weline\B2B\Service\B2BHangOrderService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Appends order_type + type_payload.hang_status for tob hang orders (non-destructive).
 */
final class SyncHangStatusTypePayloadObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }
        $orderType = strtolower(trim((string)($data['order_type'] ?? '')));
        $orderRef = trim((string)(
            $data['order_ref']
            ?? $data['order_uuid']
            ?? ''
        ));
        if ($orderRef === '' && isset($data['order']) && is_object($data['order'])) {
            $order = $data['order'];
            if (method_exists($order, 'getData')) {
                $orderRef = trim((string)$order->getData('order_uuid'));
                if ($orderRef === '') {
                    $orderRef = trim((string)$order->getData('order_number'));
                }
                if ($orderType === '') {
                    $orderType = strtolower(trim((string)$order->getData('order_type')));
                }
            }
        }
        if ($orderType !== '' && $orderType !== 'tob') {
            return;
        }
        if ($orderRef === '') {
            return;
        }

        try {
            $hang = ObjectManager::getInstance(B2BHangOrderService::class);
            if (!$hang instanceof B2BHangOrderService) {
                return;
            }
            $payload = $hang->typePayloadForOrder($orderRef);
            if ($payload === []) {
                return;
            }
            $existing = is_array($data['type_payload'] ?? null) ? $data['type_payload'] : [];
            $event->setData('order_type', 'tob');
            $event->setData('type_payload', array_merge($existing, $payload));
        } catch (\Throwable) {
            // event enrichment must never break order domain
        }
    }
}
