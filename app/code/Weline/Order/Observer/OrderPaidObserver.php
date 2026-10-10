<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderMailNotifier;

class OrderPaidObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $order = $data['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }
        if ($this->shouldSuppressCustomerPaidMail($data, $order)) {
            return;
        }
        try {
            /** @var OrderMailNotifier $notifier */
            $notifier = ObjectManager::getInstance(OrderMailNotifier::class);
            $notifier->notify($order, OrderMailNotifier::CHANNEL_PAID);
        } catch (\Throwable $e) {
            w_log_error('OrderPaidObserver mail: ' . $e->getMessage(), [], 'order_mail');
        }
    }

    /**
     * Tob hang 尾款付清走 B2B hang_balance_paid，禁止再发通用 order_paid（防客户感知重复支付）。
     *
     * @param array<string, mixed> $data
     */
    private function shouldSuppressCustomerPaidMail(array $data, Order $order): bool
    {
        $metadata = $data['metadata'] ?? null;
        if (!\is_array($metadata) && isset($data['context']) && \is_object($data['context']) && isset($data['context']->metadata)) {
            $metadata = $data['context']->metadata;
        }
        if (\is_array($metadata) && !empty($metadata['suppress_customer_paid_mail'])) {
            return true;
        }

        $orderType = strtolower(trim((string)$order->getData(Order::schema_fields_ORDER_TYPE)));
        if ($orderType !== 'tob') {
            return false;
        }
        $raw = $order->getData(Order::schema_fields_TYPE_PAYLOAD_JSON);
        $payload = [];
        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $payload = \is_array($decoded) ? $decoded : [];
        } elseif (\is_array($raw)) {
            $payload = $raw;
        }
        $hangStatus = strtolower(trim((string)($payload['hang_status'] ?? '')));

        // Hang lifecycle present ⇒ B2B owns customer payment mails for this order.
        return $hangStatus !== '';
    }
}
