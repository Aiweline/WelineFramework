<?php

declare(strict_types=1);

namespace WeShop\B2B\Observer;

use WeShop\B2B\Service\B2BOrderService;
use WeShop\B2B\Service\CreditService;
use WeShop\Order\Model\Order;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class OrderCancelledReleaseCredit implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $wrapper = $event->getData();
        if (!$wrapper instanceof DataObject) {
            return;
        }

        $order = $wrapper->getData('order');
        if (!$order instanceof Order) {
            return;
        }

        $orderId = (int) ($wrapper->getData('order_id') ?? $order->getId());
        $customerId = (int) ($wrapper->getData('customer_id') ?? $order->getData(Order::schema_fields_customer_id) ?? 0);
        if ($orderId <= 0 || $customerId <= 0) {
            return;
        }

        $b2bOrderService = ObjectManager::getInstance(B2BOrderService::class);
        $extension = $b2bOrderService->getByOrderId($orderId);
        if ($extension === null) {
            return;
        }

        $creditUsed = (float) ($extension->getData(\WeShop\B2B\Model\B2BOrder::schema_fields_CREDIT_USED) ?? 0);
        if ($creditUsed <= 0) {
            return;
        }

        ObjectManager::getInstance(CreditService::class)->releaseCredit($customerId, $creditUsed);
    }
}
