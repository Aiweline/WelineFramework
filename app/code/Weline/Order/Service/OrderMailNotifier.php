<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Websites\Model\Website;

/**
 * 订单邮件薄服务：从订单归属站显式推导 scope/locale，经 smtp.send channel+vars 发出。
 */
class OrderMailNotifier
{
    public const CHANNEL_CREATED = 'Weline_Order::order_created';
    public const CHANNEL_PAID = 'Weline_Order::order_paid';
    public const CHANNEL_STATUS_CHANGED = 'Weline_Order::order_status_changed';
    public const CHANNEL_SHIPPED = 'Weline_Order::order_shipped';
    public const CHANNEL_REFUND = 'Weline_Order::order_refund';

    /**
     * @param array<string, mixed> $extraVars
     * @return array{success:bool,message:string,skipped?:bool}
     */
    public function notify(Order $order, string $channel, array $extraVars = []): array
    {
        $email = trim((string)$order->getData(Order::schema_fields_CUSTOMER_EMAIL));
        if ($email === '') {
            w_log_warning('OrderMailNotifier skip: empty customer_email', [
                'order_id' => (int)$order->getId(),
                'channel' => $channel,
            ], 'order_mail');

            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }
        if (!function_exists('w_query')) {
            w_log_warning('OrderMailNotifier skip: smtp unavailable', [
                'order_id' => (int)$order->getId(),
                'channel' => $channel,
            ], 'order_mail');

            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $scopeCtx = $this->resolveScopeLocale($order);
        $vars = array_merge([
            'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
            'order_id' => (string)$order->getId(),
            'order_number' => (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
            'customer_name' => (string)$order->getData(Order::schema_fields_CUSTOMER_NAME),
            'customer_email' => $email,
            'status' => (string)$order->getData(Order::schema_fields_STATUS),
            'payment_status' => (string)$order->getData(Order::schema_fields_PAYMENT_STATUS),
            'fulfillment_status' => (string)$order->getData(Order::schema_fields_FULFILLMENT_STATUS),
            'grand_total' => (string)$order->getData(Order::schema_fields_GRAND_TOTAL),
            'message' => (string)($extraVars['message'] ?? ''),
            'comment' => (string)($extraVars['comment'] ?? ''),
            'old_status' => (string)($extraVars['old_status'] ?? ''),
        ], $extraVars);

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Order',
                'channel' => $channel,
                'to' => $email,
                'vars' => $vars,
                'website_code' => $scopeCtx['website_code'],
                'scope' => $scopeCtx['storage_scope'],
                'locale' => $scopeCtx['locale'],
            ]);
            if (is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => ''];
            }

            return [
                'success' => false,
                'message' => is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed',
            ];
        } catch (\Throwable $e) {
            w_log_error('OrderMailNotifier failed: ' . $e->getMessage(), [], 'order_mail');

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * status_changed：尊重 notify_customer；发货/退款状态映射到专用渠道。
     *
     * @param array<string, mixed> $extraVars
     */
    public function notifyStatusChanged(
        Order $order,
        string $oldStatus,
        string $newStatus,
        bool $notifyCustomer,
        array $extraVars = [],
    ): array {
        if (!$notifyCustomer) {
            return ['success' => false, 'message' => 'notify_customer_false', 'skipped' => true];
        }

        $mapped = $this->mapStatusToChannel($newStatus);
        $vars = array_merge([
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ], $extraVars);

        return $this->notify($order, $mapped, $vars);
    }

    public function mapStatusToChannel(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            Order::STATUS_FULFILLED,
            Order::FULFILLMENT_STATUS_SHIPPED,
            'shipped' => self::CHANNEL_SHIPPED,
            Order::STATUS_REFUNDED,
            Order::PAYMENT_STATUS_REFUNDED,
            'refund',
            'refunded' => self::CHANNEL_REFUND,
            default => self::CHANNEL_STATUS_CHANGED,
        };
    }

    /**
     * @return array{website_code:string,storage_scope:string,locale:string}
     */
    public function resolveScopeLocale(Order $order): array
    {
        $websiteCode = 'default';
        $storageScope = 'default.default.default';
        $locale = 'zh_Hans_CN';

        try {
            /** @var OrderObjectScopeService $scopeService */
            $scopeService = ObjectManager::getInstance(OrderObjectScopeService::class);
            $identity = $scopeService->fromOrder($order);
            $websiteCode = strtolower(trim((string)$identity->websiteCode)) ?: 'default';
            if ($identity->storeCode) {
                $storageScope = $websiteCode . '.' . strtolower((string)$identity->storeCode) . '.default';
            } else {
                $storageScope = $websiteCode . '.default.default';
            }
        } catch (\Throwable) {
            $websiteId = (int)$order->getData(Order::schema_fields_WEBSITE_ID);
            if ($websiteId > 0) {
                try {
                    /** @var Website $website */
                    $website = ObjectManager::getInstance(Website::class);
                    $website->load($websiteId);
                    $code = strtolower(trim((string)$website->getCode()));
                    if ($code !== '') {
                        $websiteCode = $code;
                        $storageScope = $code . '.default.default';
                    }
                } catch (\Throwable) {
                }
            }
        }

        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $row = $website->clear()->where(Website::schema_fields_CODE, $websiteCode)->find()->fetch();
            if ($row && $row->getId()) {
                $lang = trim((string)($row->getDefaultLanguage() ?? ''));
                if ($lang !== '') {
                    $locale = $lang;
                }
            }
        } catch (\Throwable) {
        }

        $snapshot = json_decode((string)$order->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON), true);
        if (is_array($snapshot)) {
            $snapLocale = trim((string)($snapshot['locale'] ?? $snapshot['language'] ?? ''));
            if ($snapLocale !== '' && $snapLocale !== 'default') {
                $locale = $snapLocale;
            }
        }

        return [
            'website_code' => $websiteCode,
            'storage_scope' => $storageScope,
            'locale' => $locale,
        ];
    }
}
