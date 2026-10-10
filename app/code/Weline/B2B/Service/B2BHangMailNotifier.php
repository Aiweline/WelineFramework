<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * Tob hang customer mail (deposit wait-CS / balance paid awaiting shipment).
 */
final class B2BHangMailNotifier
{
    public const CHANNEL_DEPOSIT = 'Weline_B2B::hang_deposit_received';
    public const CHANNEL_BALANCE = 'Weline_B2B::hang_balance_paid';

    /**
     * @param array<string, mixed> $hangPayload event type_payload / hang array
     * @return array{success:bool,message:string,skipped?:bool,channel?:string}
     */
    public function notifyForTransition(string $previousStatus, string $hangStatus, array $hangPayload): array
    {
        $previousStatus = strtolower(trim($previousStatus));
        $hangStatus = strtolower(trim($hangStatus));
        $channel = null;
        if ($previousStatus !== B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL
            && $hangStatus === B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL
        ) {
            $channel = self::CHANNEL_DEPOSIT;
        } elseif ($previousStatus !== B2BOrderHang::STATUS_COMPLETED
            && $hangStatus === B2BOrderHang::STATUS_COMPLETED
        ) {
            $channel = self::CHANNEL_BALANCE;
        }
        if ($channel === null) {
            return ['success' => false, 'message' => 'no_mail_for_transition', 'skipped' => true];
        }

        $orderRef = trim((string)($hangPayload['order_ref'] ?? $hangPayload['order_uuid'] ?? ''));
        if ($orderRef === '') {
            return ['success' => false, 'message' => 'missing_order_ref', 'skipped' => true];
        }
        if (!function_exists('w_query')) {
            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $order = $this->loadOrder($orderRef);
        if ($order === null) {
            return ['success' => false, 'message' => 'order_not_found', 'skipped' => true];
        }
        $email = trim((string)$order->getData(Order::schema_fields_CUSTOMER_EMAIL));
        if ($email === '') {
            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }

        $currency = strtoupper(trim((string)$order->getData(Order::schema_fields_CURRENCY))) ?: 'CNY';
        $depositMinor = (int)($hangPayload['deposit_amount_minor'] ?? 0);
        $balanceMinor = (int)($hangPayload['balance_amount_minor'] ?? 0);
        $vars = [
            'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
            'order_number' => (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
            'customer_name' => (string)$order->getData(Order::schema_fields_CUSTOMER_NAME),
            'customer_email' => $email,
            'hang_status' => $hangStatus,
            'hang_status_label' => HangStatusLabels::label($hangStatus, backendPaymentComplete: true),
            'deposit_amount' => $this->money($currency, $depositMinor),
            'balance_amount' => $this->money($currency, $balanceMinor),
            'grand_total' => $currency . ' ' . number_format((float)$order->getData(Order::schema_fields_GRAND_TOTAL), 2),
            'currency' => $currency,
            'message' => '',
        ];

        $scope = $this->resolveScopeLocale($order, $hangPayload);
        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_B2B',
                'channel' => $channel,
                'to' => $email,
                'vars' => $vars,
                'website_code' => $scope['website_code'],
                'scope' => $scope['storage_scope'],
                'locale' => $scope['locale'],
            ]);
            if (is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => '', 'channel' => $channel];
            }

            return [
                'success' => false,
                'message' => is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed',
                'channel' => $channel,
            ];
        } catch (\Throwable $e) {
            if (function_exists('w_log_error')) {
                w_log_error('B2BHangMailNotifier: ' . $e->getMessage(), [], 'b2b_hang_mail');
            }

            return ['success' => false, 'message' => $e->getMessage(), 'channel' => $channel];
        }
    }


    /**
     * @param array<string, mixed> $hangPayload
     * @return array{website_code:string,storage_scope:string,locale:string}
     */
    private function resolveScopeLocale(Order $order, array $hangPayload): array
    {
        try {
            if (class_exists(\Weline\Order\Service\OrderMailNotifier::class)) {
                $notifier = ObjectManager::getInstance(\Weline\Order\Service\OrderMailNotifier::class);
                if ($notifier instanceof \Weline\Order\Service\OrderMailNotifier) {
                    return $notifier->resolveScopeLocale($order);
                }
            }
        } catch (\Throwable) {
        }

        $websiteCode = 'default';
        $websiteId = (int)($hangPayload['website_id'] ?? $order->getData(Order::schema_fields_WEBSITE_ID) ?? 0);
        if ($websiteId > 0) {
            $websiteCode = 'website' . $websiteId;
        }

        return [
            'website_code' => $websiteCode,
            'storage_scope' => $websiteCode . '.default.default',
            'locale' => 'zh_Hans_CN',
        ];
    }

    private function loadOrder(string $orderRef): ?Order
    {
        try {
            /** @var Order $model */
            $model = ObjectManager::getInstance(Order::class);
            $hit = $model->reset()
                ->where(Order::schema_fields_ORDER_UUID, $orderRef)
                ->find()
                ->fetch();
            if ($hit instanceof Order && $hit->getId()) {
                return $hit;
            }
            $hit = $model->reset()
                ->where(Order::schema_fields_ORDER_NUMBER, $orderRef)
                ->find()
                ->fetch();

            return ($hit instanceof Order && $hit->getId()) ? $hit : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(string $currency, int $minor): string
    {
        return $currency . ' ' . number_format($minor / 100, 2);
    }
}
