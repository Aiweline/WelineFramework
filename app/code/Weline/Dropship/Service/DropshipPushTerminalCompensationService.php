<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\OrderRefundCoordinator;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * push_terminal 补偿：provider 行级退款 + 安慰邮件（禁止走订单退款事件链）。
 */
class DropshipPushTerminalCompensationService
{
    public const MAIL_CHANNEL = 'Weline_Dropship::fulfillment_consolation';

    public function __construct(
        private readonly DropshipSettings $settings,
        private readonly DropshipOutboxService $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data): void
    {
        try {
            $this->handleUnsafe($data);
        } catch (\Throwable $e) {
            w_log_error('dropship push_terminal compensation failed: ' . $e->getMessage());
            $bizKey = trim((string)($data['biz_key'] ?? ''));
            if ($bizKey !== '') {
                $this->outbox->mergeCompensation($bizKey, [
                    'status' => 'refund_failed',
                    'error_code' => 'compensation_exception',
                    'error_message' => $e->getMessage(),
                    'at' => date('c'),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleUnsafe(array $data): void
    {
        $action = (string)($data['action'] ?? '');
        if ($action !== 'create') {
            return;
        }

        $bizKey = trim((string)($data['biz_key'] ?? ''));
        $orderUuid = trim((string)($data['order_uuid'] ?? ''));
        if ($bizKey === '' || $orderUuid === '') {
            return;
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $existingComp = is_array($payload['compensation'] ?? null) ? $payload['compensation'] : [];
        if (($existingComp['status'] ?? '') === 'refunded' && !empty($existingComp['mail_sent_at'])) {
            return;
        }

        $websiteId = (int)($data['website_id'] ?? ($payload['website_id'] ?? 0));
        $storeId = (int)($data['store_id'] ?? ($payload['store_id'] ?? 0));
        $storageScope = $this->resolveStorageScope($websiteId, $storeId);

        if (!$this->settings->isAutoRefundOnPushFail($storageScope)) {
            $this->outbox->mergeCompensation($bizKey, [
                'status' => 'switch_off',
                'at' => date('c'),
            ]);

            return;
        }

        if (($existingComp['status'] ?? '') === 'refunded') {
            $this->sendConsolationMail($bizKey, $orderUuid, $existingComp);

            return;
        }

        $requestItems = $this->mapRequestItems($orderUuid, $payload);
        if ($requestItems === []) {
            $this->outbox->mergeCompensation($bizKey, [
                'status' => 'refund_failed',
                'error_code' => 'items_unmapped',
                'at' => date('c'),
            ]);

            return;
        }

        $shippingRefundMinor = $this->resolveShippingRefundMinor($orderUuid, $requestItems);

        /** @var OrderRefundCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(OrderRefundCoordinator::class);
        $result = $coordinator->requestRefund(
            $orderUuid,
            'dropship:auto-refund:' . $bizKey,
            0,
            $requestItems,
            $shippingRefundMinor,
            'dropship_push_terminal_unfulfillable',
        );

        if (!($result['ok'] ?? false)) {
            $this->outbox->mergeCompensation($bizKey, [
                'status' => 'refund_failed',
                'error_code' => (string)($result['error_code'] ?? 'refund_failed'),
                'at' => date('c'),
            ]);

            return;
        }

        $caseUuid = (string)(($result['case']['refund_case_uuid'] ?? $result['case']['uuid'] ?? '') ?: '');
        $comp = [
            'status' => 'refunded',
            'refund_case_uuid' => $caseUuid,
            'replayed' => !empty($result['replayed']),
            'at' => date('c'),
        ];
        $this->outbox->mergeCompensation($bizKey, $comp);
        $this->sendConsolationMail($bizKey, $orderUuid, $comp);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{item_uuid:string,qty_minor:int}>
     */
    private function mapRequestItems(string $orderUuid, array $payload): array
    {
        $offerIds = [];
        foreach ((array)($payload['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $offerId = (int)($line['local_offer_id'] ?? 0);
            if ($offerId > 0) {
                $offerIds[$offerId] = true;
            }
        }
        if ($offerIds === []) {
            return [];
        }

        /** @var OrderItem $model */
        $model = ObjectManager::getInstance(OrderItem::class);
        $rows = $model->clear()
            ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetchArray();

        $items = [];
        foreach ((array)$rows as $row) {
            $offerId = (int)($row[OrderItem::schema_fields_OFFER_ID] ?? 0);
            if (!isset($offerIds[$offerId])) {
                continue;
            }
            $itemUuid = trim((string)($row[OrderItem::schema_fields_ITEM_UUID] ?? ''));
            $qtyMinor = (int)($row[OrderItem::schema_fields_QTY_MINOR] ?? 0);
            if ($qtyMinor <= 0) {
                $qtyMinor = (int)($row[OrderItem::schema_fields_QTY_ORDERED] ?? 0);
            }
            if ($itemUuid === '' || $qtyMinor <= 0) {
                continue;
            }
            $items[] = [
                'item_uuid' => $itemUuid,
                'qty_minor' => $qtyMinor,
            ];
        }

        return $items;
    }

    /**
     * 运费保守：仅当本笔覆盖订单全部仍开放行时才退剩余运费，否则 0。
     *
     * @param list<array{item_uuid:string,qty_minor:int}> $requestItems
     */
    private function resolveShippingRefundMinor(string $orderUuid, array $requestItems): int
    {
        $cover = [];
        foreach ($requestItems as $item) {
            $cover[$item['item_uuid']] = true;
        }

        /** @var OrderItem $model */
        $model = ObjectManager::getInstance(OrderItem::class);
        $rows = $model->clear()
            ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetchArray();

        foreach ((array)$rows as $row) {
            $itemUuid = trim((string)($row[OrderItem::schema_fields_ITEM_UUID] ?? ''));
            if ($itemUuid === '' || isset($cover[$itemUuid])) {
                continue;
            }
            $qtyMinor = (int)($row[OrderItem::schema_fields_QTY_MINOR] ?? 0);
            if ($qtyMinor <= 0) {
                $qtyMinor = (int)($row[OrderItem::schema_fields_QTY_ORDERED] ?? 0);
            }
            $refunded = (int)($row[OrderItem::schema_fields_QTY_REFUNDED] ?? 0);
            $cancelled = (int)($row[OrderItem::schema_fields_QTY_CANCELLED] ?? 0);
            $remaining = max(0, $qtyMinor - $refunded - $cancelled);
            if ($remaining > 0) {
                return 0;
            }
        }

        /** @var Order $orderModel */
        $orderModel = ObjectManager::getInstance(Order::class);
        $order = $orderModel->clear()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$order || !$order->getId()) {
            return 0;
        }
        $money = json_decode((string)$order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON), true) ?: [];
        if (isset($money['shipping_amount_minor'])) {
            return max(0, (int)$money['shipping_amount_minor']);
        }

        return max(0, (int)round((float)$order->getData(Order::schema_fields_SHIPPING_AMOUNT) * 100));
    }

    /**
     * @param array<string, mixed> $comp
     */
    private function sendConsolationMail(string $bizKey, string $orderUuid, array $comp): void
    {
        if (!empty($comp['mail_sent_at'])) {
            return;
        }

        /** @var Order $orderModel */
        $orderModel = ObjectManager::getInstance(Order::class);
        $order = $orderModel->clear()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        $email = '';
        if ($order && $order->getId()) {
            $email = trim((string)$order->getData(Order::schema_fields_CUSTOMER_EMAIL));
        }
        if ($email === '' || !function_exists('w_query')) {
            $this->outbox->mergeCompensation($bizKey, [
                'mail_skipped' => $email === '' ? 'no_customer_email' : 'smtp_unavailable',
                'at' => date('c'),
            ]);

            return;
        }

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Dropship',
                'channel' => self::MAIL_CHANNEL,
                'to' => $email,
                'vars' => [
                    'order_uuid' => $orderUuid,
                    'message' => (string)__('很抱歉，您的订单中部分货源商品目前无法履约。我们已启动退款，款项将按原支付方式退回（到账时间视渠道而定）。如有疑问请联系客服。'),
                ],
            ]);
            $ok = is_array($result) ? !empty($result['success']) : (bool)$result;
            if ($ok) {
                $this->outbox->mergeCompensation($bizKey, [
                    'mail_sent_at' => date('c'),
                ]);
            } else {
                $this->outbox->mergeCompensation($bizKey, [
                    'status' => 'mail_failed',
                    'mail_error' => is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed',
                    'at' => date('c'),
                ]);
                // 退款已成功：保持 refunded，仅附加 mail_failed 标记（merge 会覆盖 status——需保留 refunded）
                $this->outbox->mergeCompensation($bizKey, [
                    'status' => 'refunded',
                    'mail_failed' => true,
                ]);
            }
        } catch (\Throwable $e) {
            w_log_error('dropship consolation mail failed: ' . $e->getMessage());
            $this->outbox->mergeCompensation($bizKey, [
                'status' => 'refunded',
                'mail_failed' => true,
                'mail_error' => $e->getMessage(),
                'at' => date('c'),
            ]);
        }
    }

    private function resolveStorageScope(int $websiteId, int $storeId): string
    {
        if ($websiteId <= 0) {
            return 'default.default.default';
        }
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website->load($websiteId);
            $websiteCode = trim((string)$website->getCode());
            if ($websiteCode === '') {
                return 'default.default.default';
            }
            $storeCode = '';
            if ($storeId > 0) {
                /** @var Store $store */
                $store = ObjectManager::getInstance(Store::class);
                $store->load($storeId);
                $storeCode = trim((string)$store->getCode());
            }
            /** @var SystemConfigTargetScopeService $scopeService */
            $scopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
            $input = [
                'website_code' => $websiteCode,
            ];
            if ($storeCode !== '') {
                $input['store_code'] = $storeCode;
                $input['scope_kind'] = SystemConfigTargetScopeService::KIND_STORE;
            } else {
                $input['scope_kind'] = SystemConfigTargetScopeService::KIND_WEBSITE;
            }
            $resolved = $scopeService->resolveFromInput($input, false);

            return (string)($resolved['storage_scope'] ?? 'default.default.default');
        } catch (\Throwable) {
            return 'default.default.default';
        }
    }
}
