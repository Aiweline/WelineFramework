<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipWebhookInbox;
use Weline\Framework\Manager\ObjectManager;

/**
 * Advances webhook inbox rows into fulfillment projection (shell-owned).
 */
class DropshipWebhookInboxService
{
    /**
     * @return array<string, mixed>
     */
    public function processOne(int $inboxId): array
    {
        /** @var DropshipWebhookInbox $model */
        $model = ObjectManager::getInstance(DropshipWebhookInbox::class);
        $row = $model->clear()->where(DropshipWebhookInbox::schema_fields_ID, $inboxId)->find()->fetch();
        if (!$row || !$row->getId()) {
            return ['ok' => false, 'message' => 'inbox_missing'];
        }
        $status = (string)$row->getData(DropshipWebhookInbox::schema_fields_STATUS);
        if ($status === 'done') {
            return ['ok' => true, 'message' => 'already_done'];
        }

        $providerCode = (string)$row->getData(DropshipWebhookInbox::schema_fields_PROVIDER_CODE);
        $body = (string)$row->getData(DropshipWebhookInbox::schema_fields_BODY);
        $payload = json_decode($body, true) ?: [];
        $externalOrderId = (string)($payload['orderId'] ?? $payload['external_order_id'] ?? $payload['id'] ?? '');
        $tracking = (string)($payload['trackNumber'] ?? $payload['tracking'] ?? '');
        $carrier = (string)($payload['logisticName'] ?? $payload['carrier'] ?? '');
        $fulfillStatus = (string)($payload['orderStatus'] ?? $payload['status'] ?? 'updated');

        if ($externalOrderId !== '') {
            /** @var DropshipFulfillment $ff */
            $ff = ObjectManager::getInstance(DropshipFulfillment::class);
            $existing = $ff->clear()
                ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, $providerCode)
                ->where(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID, $externalOrderId)
                ->find()
                ->fetch();
            $now = date('Y-m-d H:i:s');
            $data = [
                DropshipFulfillment::schema_fields_PROVIDER_CODE => $providerCode,
                DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID => $externalOrderId,
                DropshipFulfillment::schema_fields_STATUS => $fulfillStatus,
                DropshipFulfillment::schema_fields_TRACKING_NUMBER => $tracking,
                DropshipFulfillment::schema_fields_CARRIER => $carrier,
                DropshipFulfillment::schema_fields_UPDATED_AT => $now,
            ];
            if ($existing && $existing->getId()) {
                $existing->setData($data)->save();
            } else {
                $data[DropshipFulfillment::schema_fields_ORDER_UUID] = (string)($payload['orderNumber'] ?? $payload['order_uuid'] ?? '');
                $data[DropshipFulfillment::schema_fields_CREATED_AT] = $now;
                $ff->clear()->setData($data)->save();
            }
        }

        $row->setData([
            DropshipWebhookInbox::schema_fields_STATUS => 'done',
            DropshipWebhookInbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return ['ok' => true, 'inbox_id' => $inboxId];
    }

    public function enqueue(int $inboxId): void
    {
        try {
            if (!function_exists('w_query')) {
                return;
            }
            w_query('queue', 'createIfAbsent', [
                'class' => \Weline\Dropship\Queue\DropshipWebhookInboxConsumer::class,
                'name' => 'Dropship webhook inbox ' . $inboxId,
                'module' => 'Weline_Dropship',
                'content' => ['inbox_id' => $inboxId],
                'status' => 'pending',
                'auto' => true,
                'biz_key' => 'dropship:inbox:' . $inboxId,
                'idempotency_scope' => 'dropship_inbox',
                'idempotency_key' => 'dropship:inbox:' . $inboxId,
            ]);
        } catch (\Throwable $e) {
            w_log_error('dropship inbox enqueue failed: ' . $e->getMessage());
        }
    }
}
