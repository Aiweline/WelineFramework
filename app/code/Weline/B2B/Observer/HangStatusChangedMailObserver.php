<?php

declare(strict_types=1);

namespace Weline\B2B\Observer;

use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangMailNotifier;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * Hang status transition → customer mail; balance completed → pin fulfillment pending.
 */
final class HangStatusChangedMailObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }
        $hangStatus = strtolower(trim((string)($data['hang_status'] ?? '')));
        $previous = strtolower(trim((string)($data['previous_hang_status'] ?? '')));
        $payload = is_array($data['type_payload'] ?? null) ? $data['type_payload'] : [];
        $payload['order_ref'] = (string)($data['order_ref'] ?? $payload['order_ref'] ?? '');
        $payload['deposit_amount_minor'] = (int)($payload['deposit_amount_minor'] ?? $data['deposit_amount_minor'] ?? 0);
        $payload['balance_amount_minor'] = (int)($payload['balance_amount_minor'] ?? $data['balance_amount_minor'] ?? 0);

        try {
            $mail = ObjectManager::getInstance(B2BHangMailNotifier::class);
            if ($mail instanceof B2BHangMailNotifier) {
                $mail->notifyForTransition($previous, $hangStatus, $payload);
            }
        } catch (\Throwable $e) {
            if (function_exists('w_log_error')) {
                w_log_error('HangStatusChangedMailObserver mail: ' . $e->getMessage(), [], 'b2b_hang_mail');
            }
        }

        if ($previous !== B2BOrderHang::STATUS_COMPLETED && $hangStatus === B2BOrderHang::STATUS_COMPLETED) {
            $this->pinFulfillmentPending(trim((string)($payload['order_ref'] ?? '')));
        }
    }

    private function pinFulfillmentPending(string $orderRef): void
    {
        if ($orderRef === '') {
            return;
        }
        try {
            /** @var Order $model */
            $model = ObjectManager::getInstance(Order::class);
            $hit = $model->reset()
                ->where(Order::schema_fields_ORDER_UUID, $orderRef)
                ->find()
                ->fetch();
            if (!($hit instanceof Order) || !$hit->getId()) {
                $hit = $model->reset()
                    ->where(Order::schema_fields_ORDER_NUMBER, $orderRef)
                    ->find()
                    ->fetch();
            }
            if (!($hit instanceof Order) || !$hit->getId()) {
                return;
            }
            $current = strtolower(trim((string)$hit->getData(Order::schema_fields_FULFILLMENT_STATUS)));
            if (in_array($current, [
                Order::FULFILLMENT_STATUS_PARTIAL,
                Order::FULFILLMENT_STATUS_SHIPPED,
                Order::FULFILLMENT_STATUS_DELIVERED,
            ], true)) {
                return;
            }
            if ($current === Order::FULFILLMENT_STATUS_PENDING) {
                return;
            }
            $hit->setData(Order::schema_fields_FULFILLMENT_STATUS, Order::FULFILLMENT_STATUS_PENDING)->save();
        } catch (\Throwable $e) {
            if (function_exists('w_log_error')) {
                w_log_error('HangStatusChangedMailObserver fulfillment: ' . $e->getMessage(), [], 'b2b_hang_mail');
            }
        }
    }
}
