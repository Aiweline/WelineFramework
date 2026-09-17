<?php

declare(strict_types=1);

namespace Weline\Customer\Observer;

use Weline\Customer\Service\AnonymousPaidCustomerBinder;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * First paid projection → ensure anonymous customer + notify contact backfill.
 */
final class OrderPaidAnonymousCustomerObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            $orderUuid = trim((string)($data['order_uuid'] ?? ''));
            if ($orderUuid === '') {
                $order = $data['order'] ?? null;
                if (is_object($order) && method_exists($order, 'getData')) {
                    $orderUuid = trim((string)$order->getData('order_uuid'));
                }
            }
            if ($orderUuid === '') {
                return;
            }
            $context = $data['context'] ?? null;
            $metadata = [];
            if (is_object($context) && isset($context->metadata) && is_array($context->metadata)) {
                $metadata = $context->metadata;
            } elseif (is_array($data['metadata'] ?? null)) {
                $metadata = $data['metadata'];
            }

            /** @var AnonymousPaidCustomerBinder $binder */
            $binder = ObjectManager::getInstance(AnonymousPaidCustomerBinder::class);
            $binder->bindPaidOrder($orderUuid, $metadata);
        } catch (\Throwable) {
            // Paid state already committed; contact bind must not break payment.
        }
    }
}
