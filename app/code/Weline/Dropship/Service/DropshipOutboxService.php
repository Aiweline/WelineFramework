<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipOrderLine;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Manager\ObjectManager;

class DropshipOutboxService
{
    public function __construct(
        private readonly DropshipChannelManager $channels,
        private readonly DropshipSettings $settings,
        private readonly DropshipWarehouseMapService $warehouseMap,
    ) {
    }

    public function admitCreateForOrder(string $orderUuid, array $lines, array $shipping, int $websiteId, int $storeId): void
    {
        if (!$this->settings->autoPushEnabled()) {
            return;
        }

        $byProvider = [];
        foreach ($lines as $line) {
            $code = (string)($line['provider_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $byProvider[$code][] = $line;
        }

        foreach ($byProvider as $providerCode => $providerLines) {
            $bizKey = 'dropship:' . $providerCode . ':create:' . $orderUuid;
            $this->upsertOutbox($bizKey, $providerCode, 'create', $orderUuid, [
                'lines' => $providerLines,
                'shipping' => $shipping,
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ]);
            $this->freezeLines($orderUuid, $providerLines);
            $this->enqueueQueue($bizKey);
        }
    }

    public function admitCancel(string $orderUuid, string $providerCode): void
    {
        $bizKey = 'dropship:' . $providerCode . ':cancel:' . $orderUuid;
        $this->upsertOutbox($bizKey, $providerCode, 'cancel', $orderUuid, []);
        $this->enqueueQueue($bizKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function processOne(string $bizKey): array
    {
        /** @var DropshipPushOutbox $model */
        $model = ObjectManager::getInstance(DropshipPushOutbox::class);
        $row = $model->clear()->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)->find()->fetch();
        if (!$row || !$row->getId()) {
            return ['ok' => false, 'message' => 'outbox_missing'];
        }
        if ((string)$row->getData(DropshipPushOutbox::schema_fields_STATUS) === DropshipPushOutbox::STATUS_DONE) {
            return ['ok' => true, 'message' => 'already_done'];
        }

        $providerCode = (string)$row->getData(DropshipPushOutbox::schema_fields_PROVIDER_CODE);
        $action = (string)$row->getData(DropshipPushOutbox::schema_fields_ACTION);
        $orderUuid = (string)$row->getData(DropshipPushOutbox::schema_fields_ORDER_UUID);
        $payload = json_decode((string)$row->getData(DropshipPushOutbox::schema_fields_PAYLOAD_JSON), true) ?: [];
        $provider = $this->channels->getProvider($providerCode);

        $attempts = (int)$row->getData(DropshipPushOutbox::schema_fields_ATTEMPTS) + 1;
        $row->setData(DropshipPushOutbox::schema_fields_ATTEMPTS, $attempts)->save();

        if (!$provider instanceof DropshipFulfillmentProviderInterface) {
            $row->setData([
                DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_SKIPPED,
                DropshipPushOutbox::schema_fields_LAST_ERROR => 'skipped:unsupported',
                DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            return ['ok' => true, 'skipped' => true];
        }

        try {
            if ($action === 'create') {
                $websiteId = (int)($payload['website_id'] ?? 0);
                $storeId = (int)($payload['store_id'] ?? 0);
                $map = $this->warehouseMap->resolve($providerCode, $websiteId, $storeId);
                $result = $provider->createFulfillment([
                    'order_uuid' => $orderUuid,
                    'lines' => $payload['lines'] ?? [],
                    'shipping' => $payload['shipping'] ?? [],
                    'storage_id' => (string)($map['cj_storage_id'] ?? ''),
                    'from_country_code' => (string)($map['cj_country_code'] ?? ''),
                ]);
                if (!($result['ok'] ?? false)) {
                    throw new \RuntimeException((string)($result['message'] ?? 'create_failed'));
                }
                $this->upsertFulfillment($providerCode, $orderUuid, (string)($result['external_order_id'] ?? ''), 'created');
            } elseif ($action === 'cancel') {
                $result = $provider->cancelFulfillment(['order_uuid' => $orderUuid]);
                if (($result['skipped'] ?? false) === true) {
                    $row->setData([
                        DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_SKIPPED,
                        DropshipPushOutbox::schema_fields_LAST_ERROR => (string)($result['message'] ?? 'skipped:unsupported'),
                        DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->save();

                    return ['ok' => true, 'skipped' => true];
                }
                if (!($result['ok'] ?? false)) {
                    throw new \RuntimeException((string)($result['message'] ?? 'cancel_failed'));
                }
            }

            $row->setData([
                DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_DONE,
                DropshipPushOutbox::schema_fields_LAST_ERROR => null,
                DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            return ['ok' => true];
        } catch (\Throwable $e) {
            $row->setData([
                DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_ERROR,
                DropshipPushOutbox::schema_fields_LAST_ERROR => $e->getMessage(),
                DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertOutbox(string $bizKey, string $providerCode, string $action, string $orderUuid, array $payload): void
    {
        /** @var DropshipPushOutbox $model */
        $model = ObjectManager::getInstance(DropshipPushOutbox::class);
        $existing = $model->clear()->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)->find()->fetch();
        $now = date('Y-m-d H:i:s');
        $data = [
            DropshipPushOutbox::schema_fields_BIZ_KEY => $bizKey,
            DropshipPushOutbox::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipPushOutbox::schema_fields_ACTION => $action,
            DropshipPushOutbox::schema_fields_ORDER_UUID => $orderUuid,
            DropshipPushOutbox::schema_fields_PAYLOAD_JSON => json_encode($payload, JSON_UNESCAPED_UNICODE),
            DropshipPushOutbox::schema_fields_UPDATED_AT => $now,
        ];
        if ($existing && $existing->getId()) {
            if ((string)$existing->getData(DropshipPushOutbox::schema_fields_STATUS) === DropshipPushOutbox::STATUS_DONE) {
                return;
            }
            $existing->setData($data)->save();

            return;
        }
        $data[DropshipPushOutbox::schema_fields_STATUS] = DropshipPushOutbox::STATUS_PENDING;
        $data[DropshipPushOutbox::schema_fields_ATTEMPTS] = 0;
        $data[DropshipPushOutbox::schema_fields_CREATED_AT] = $now;
        $model->clear()->setData($data)->save();
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function freezeLines(string $orderUuid, array $lines): void
    {
        /** @var DropshipOrderLine $model */
        $model = ObjectManager::getInstance(DropshipOrderLine::class);
        foreach ($lines as $i => $line) {
            $lineKey = (string)($line['line_key'] ?? ('line-' . $i));
            $biz = $model->clear()
                ->where(DropshipOrderLine::schema_fields_ORDER_UUID, $orderUuid)
                ->where(DropshipOrderLine::schema_fields_LINE_KEY, $lineKey)
                ->find()
                ->fetch();
            $data = [
                DropshipOrderLine::schema_fields_ORDER_UUID => $orderUuid,
                DropshipOrderLine::schema_fields_LINE_KEY => $lineKey,
                DropshipOrderLine::schema_fields_PROVIDER_CODE => (string)($line['provider_code'] ?? ''),
                DropshipOrderLine::schema_fields_EXTERNAL_SKU => (string)($line['external_sku'] ?? ''),
                DropshipOrderLine::schema_fields_LOCAL_OFFER_ID => isset($line['local_offer_id']) ? (int)$line['local_offer_id'] : null,
                DropshipOrderLine::schema_fields_QTY => (int)($line['qty'] ?? 1),
            ];
            if ($biz && $biz->getId()) {
                continue;
            }
            $data[DropshipOrderLine::schema_fields_CREATED_AT] = date('Y-m-d H:i:s');
            $model->clear()->setData($data)->save();
        }
    }

    private function upsertFulfillment(string $providerCode, string $orderUuid, string $externalId, string $status): void
    {
        /** @var DropshipFulfillment $model */
        $model = ObjectManager::getInstance(DropshipFulfillment::class);
        $existing = $model->clear()
            ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipFulfillment::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        $now = date('Y-m-d H:i:s');
        $data = [
            DropshipFulfillment::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipFulfillment::schema_fields_ORDER_UUID => $orderUuid,
            DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID => $externalId,
            DropshipFulfillment::schema_fields_STATUS => $status,
            DropshipFulfillment::schema_fields_UPDATED_AT => $now,
        ];
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();

            return;
        }
        $data[DropshipFulfillment::schema_fields_CREATED_AT] = $now;
        $model->clear()->setData($data)->save();
    }

    private function enqueueQueue(string $bizKey): void
    {
        try {
            if (!function_exists('w_query')) {
                return;
            }
            w_query('queue', 'createIfAbsent', [
                'class' => \Weline\Dropship\Queue\DropshipOrderPushConsumer::class,
                'name' => 'Dropship push ' . $bizKey,
                'module' => 'Weline_Dropship',
                'content' => ['biz_key' => $bizKey],
                'status' => 'pending',
                'auto' => true,
                'biz_key' => $bizKey,
                'idempotency_scope' => 'dropship_push',
                'idempotency_key' => $bizKey,
            ]);
        } catch (\Throwable $e) {
            w_log_error('dropship enqueue failed: ' . $e->getMessage());
        }
    }
}
