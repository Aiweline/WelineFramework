<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderHistory;
use Weline\Order\Service\OrderMailNotifier;

/**
 * 订单状态变更：记历史；notify_customer 时发信；发货/退款映射专用渠道。
 */
class OrderStatusChangedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $oldStatus = (string)($data['old_status'] ?? '');
        $newStatus = (string)($data['new_status'] ?? '');
        $comment = $data['comment'] ?? null;
        $notifyCustomer = (bool)($data['notify_customer'] ?? false);

        if (!$orderId || $newStatus === '') {
            return;
        }

        $this->addHistory((int)$orderId, $newStatus, is_string($comment) ? $comment : null, $notifyCustomer);

        if ($notifyCustomer && $order instanceof Order) {
            try {
                /** @var OrderMailNotifier $notifier */
                $notifier = ObjectManager::getInstance(OrderMailNotifier::class);
                $notifier->notifyStatusChanged(
                    $order,
                    $oldStatus,
                    $newStatus,
                    true,
                    ['comment' => (string)($comment ?? '')],
                );
            } catch (\Throwable $e) {
                w_log_error('OrderStatusChangedObserver mail: ' . $e->getMessage(), [], 'order_mail');
            }
        }
    }

    private function addHistory(int $orderId, string $status, ?string $comment, bool $notifyCustomer): void
    {
        /** @var OrderHistory $history */
        $history = ObjectManager::getInstance(OrderHistory::class);
        $history->setData(OrderHistory::schema_fields_ORDER_ID, $orderId)
            ->setData(OrderHistory::schema_fields_STATUS, $status)
            ->setData(OrderHistory::schema_fields_COMMENT, $comment)
            ->setData(OrderHistory::schema_fields_IS_CUSTOMER_NOTIFIED, $notifyCustomer ? 1 : 0)
            ->save();
    }
}
