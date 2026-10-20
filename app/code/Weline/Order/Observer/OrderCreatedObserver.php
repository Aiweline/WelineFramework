<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Admin\Service\BackendMenuSignalInbox;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\UrlInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Extends\Module\Weline_Admin\BackendMenuSignalProvider\OrderListMenuSignalProvider;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderMailNotifier;

class OrderCreatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }
        try {
            /** @var OrderMailNotifier $notifier */
            $notifier = ObjectManager::getInstance(OrderMailNotifier::class);
            $notifier->notify($order, OrderMailNotifier::CHANNEL_CREATED);
        } catch (\Throwable $e) {
            w_log_error('OrderCreatedObserver mail: ' . $e->getMessage(), [], 'order_mail');
        }
        $this->publishUnreadMenuMessage($order);
    }

    private function publishUnreadMenuMessage(Order $order): void
    {
        $orderId = (int)$order->getId();
        if ($orderId <= 0 || !class_exists(BackendMenuSignalInbox::class)) {
            return;
        }
        $number = trim((string)$order->getData(Order::schema_fields_ORDER_NUMBER));
        $title = (string)__('订单') . ' ' . ($number !== '' ? $number : ('#' . $orderId));
        try {
            /** @var UrlInterface $url */
            $url = ObjectManager::getInstance(UrlInterface::class);
            $href = trim((string)$url->getBackendUrl('order/backend/order/view', [
                'id' => $orderId,
            ]));
            if ($href === '') {
                return;
            }
            /** @var BackendMenuSignalInbox $inbox */
            $inbox = ObjectManager::getInstance(BackendMenuSignalInbox::class);
            $inbox->publish(
                OrderListMenuSignalProvider::MENU_SOURCE_ID,
                (string)$orderId,
                $title,
                $href,
                true,
            );
        } catch (\Throwable $e) {
            w_log_error('OrderCreatedObserver menu signal: ' . $e->getMessage(), [], 'order_menu_signal');
        }
    }
}
