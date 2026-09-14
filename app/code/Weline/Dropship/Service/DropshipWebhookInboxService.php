<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Model\DropshipWebhookInbox;
use Weline\Dropship\Queue\DropshipListingSyncConsumer;
use Weline\Framework\Manager\ObjectManager;

/**
 * Advances webhook inbox rows into shell projections (fulfillment / catalog follow).
 * Reads only shell-standard keys from Provider::parseWebhook.
 */
class DropshipWebhookInboxService
{
    /**
     * @return array<string, mixed>
     */
    public function processOne(int $inboxId, bool $force = false): array
    {
        /** @var DropshipWebhookInbox $model */
        $model = ObjectManager::getInstance(DropshipWebhookInbox::class);
        $row = $model->clear()->where(DropshipWebhookInbox::schema_fields_ID, $inboxId)->find()->fetch();
        if (!$row || !$row->getId()) {
            return ['ok' => false, 'message' => 'inbox_missing'];
        }
        $providerCode = (string)$row->getData(DropshipWebhookInbox::schema_fields_PROVIDER_CODE);
        $body = (string)$row->getData(DropshipWebhookInbox::schema_fields_BODY);
        $envelope = json_decode($body, true);
        if (!\is_array($envelope)) {
            $envelope = [];
        }
        $fulfillment = \is_array($envelope['fulfillment'] ?? null) ? $envelope['fulfillment'] : [];
        $catalog = \is_array($envelope['catalog'] ?? null) ? $envelope['catalog'] : [];
        $makeup = \is_array($envelope['makeup'] ?? null) ? $envelope['makeup'] : [];
        $topic = strtolower(trim((string)($envelope['topic'] ?? '')));
        $status = (string)$row->getData(DropshipWebhookInbox::schema_fields_STATUS);

        $needsReparse = $this->fulfillmentNeedsReparse($fulfillment)
            && $catalog === []
            && $makeup === []
            && ($topic === '' || $topic === 'unknown' || $topic === 'order' || $topic === 'logistics' || $topic === 'private_order');
        if ($status === 'done' && !$force && !$needsReparse && ($fulfillment !== [] || $catalog !== [] || $makeup !== [])) {
            return ['ok' => true, 'message' => 'already_done'];
        }

        if (($needsReparse || $topic === '') && $providerCode !== '') {
            $parsed = $this->reparseEnvelope($providerCode, $envelope, $body);
            if ($parsed !== []) {
                if (\is_array($parsed['fulfillment'] ?? null)) {
                    $fulfillment = $parsed['fulfillment'];
                    $envelope['fulfillment'] = $fulfillment;
                }
                if (\is_array($parsed['catalog'] ?? null)) {
                    $catalog = $parsed['catalog'];
                    $envelope['catalog'] = $catalog;
                }
                if (\is_array($parsed['makeup'] ?? null)) {
                    $makeup = $parsed['makeup'];
                    $envelope['makeup'] = $makeup;
                }
                if (trim((string)($parsed['topic'] ?? '')) !== '') {
                    $topic = strtolower(trim((string)$parsed['topic']));
                    $envelope['topic'] = $topic;
                }
                if (trim((string)($parsed['event'] ?? '')) !== '') {
                    $envelope['event'] = (string)$parsed['event'];
                }
                $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE);
                if (\is_string($encoded) && $encoded !== '') {
                    $row->setData(DropshipWebhookInbox::schema_fields_BODY, $encoded);
                }
            }
        }

        $provider = null;
        if ($providerCode !== '') {
            try {
                /** @var DropshipChannelManager $channels */
                $channels = ObjectManager::getInstance(DropshipChannelManager::class);
                $provider = $channels->getProvider($providerCode);
            } catch (\Throwable) {
                $provider = null;
            }
        }

        $actions = [];
        if ($this->topicAllowed($provider, $topic ?: $this->inferTopic($fulfillment, $catalog, $makeup))) {
            if ($this->hasFulfillmentWork($fulfillment) || $makeup !== []) {
                $actions['fulfillment'] = $this->projectFulfillment($providerCode, $fulfillment, $makeup);
            }
            if ($this->hasCatalogWork($catalog)) {
                $actions['catalog'] = $this->enqueueCatalogFollow($providerCode, $catalog);
            }
        } else {
            $actions['skipped'] = 'capability_disabled:' . ($topic !== '' ? $topic : 'unknown');
        }

        $row->setData([
            DropshipWebhookInbox::schema_fields_STATUS => 'done',
            DropshipWebhookInbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        $externalOrderId = (string)($fulfillment['external_order_id'] ?? ($makeup['related_external_order_id'] ?? ''));

        return [
            'ok' => true,
            'inbox_id' => $inboxId,
            'topic' => $topic !== '' ? $topic : $this->inferTopic($fulfillment, $catalog, $makeup),
            'projected' => !empty($actions['fulfillment']['projected']),
            'external_order_id' => $externalOrderId,
            'actions' => $actions,
        ];
    }

    /**
     * @param array<string, mixed> $fulfillment
     */
    private function fulfillmentNeedsReparse(array $fulfillment): bool
    {
        if ($fulfillment === []) {
            return true;
        }

        return trim((string)($fulfillment['external_order_id'] ?? '')) === '';
    }

    /**
     * @param array<string, mixed> $fulfillment
     */
    private function hasFulfillmentWork(array $fulfillment): bool
    {
        return trim((string)($fulfillment['external_order_id'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function hasCatalogWork(array $catalog): bool
    {
        return trim((string)($catalog['external_spu'] ?? '')) !== ''
            || trim((string)($catalog['external_sku'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $fulfillment
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $makeup
     */
    private function inferTopic(array $fulfillment, array $catalog, array $makeup): string
    {
        if ($makeup !== []) {
            return 'makeup';
        }
        if ($this->hasCatalogWork($catalog)) {
            return trim((string)($catalog['qty'] ?? '')) !== '' || array_key_exists('qty', $catalog)
                ? 'stock'
                : 'product';
        }
        if ($this->hasFulfillmentWork($fulfillment)) {
            $track = trim((string)($fulfillment['tracking_number'] ?? ''));
            $status = strtolower(trim((string)($fulfillment['status'] ?? '')));
            if ($track !== '' && ($status === '' || str_contains($status, 'track') || $status === 'updated')) {
                return 'logistics';
            }

            return 'order';
        }

        return 'unknown';
    }

    private function topicAllowed(?object $provider, string $topic): bool
    {
        if (!$provider instanceof DropshipWebhookProviderInterface) {
            return true;
        }
        if (!method_exists($provider, 'getCapabilities')) {
            return true;
        }
        /** @var array<string, mixed> $caps */
        $caps = (array)$provider->getCapabilities();
        if (array_key_exists('webhook', $caps) && empty($caps['webhook'])) {
            return false;
        }
        $map = [
            'order' => 'webhook_order',
            'private_order' => 'webhook_private_order',
            'product' => 'webhook_product',
            'stock' => 'webhook_stock',
            'logistics' => 'webhook_logistics',
            'makeup' => 'webhook_makeup',
            'dispute' => 'webhook_dispute',
            'unknown' => '',
        ];
        $key = $map[$topic] ?? '';
        if ($key === '' || !array_key_exists($key, $caps)) {
            return true;
        }

        return !empty($caps[$key]);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function reparseEnvelope(string $providerCode, array $envelope, string $storedBody): array
    {
        try {
            /** @var DropshipChannelManager $channels */
            $channels = ObjectManager::getInstance(DropshipChannelManager::class);
            $provider = $channels->getProvider($providerCode);
            if (!$provider instanceof DropshipWebhookProviderInterface) {
                return [];
            }
            $raw = trim((string)($envelope['raw_body'] ?? ''));
            if ($raw === '' && \is_array($envelope['raw'] ?? null)) {
                $encoded = json_encode($envelope['raw'], JSON_UNESCAPED_UNICODE);
                $raw = \is_string($encoded) ? $encoded : '';
            }
            if ($raw === '') {
                $raw = $storedBody;
            }
            $parsed = $provider->parseWebhook([], $raw);

            return \is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $fulfillment
     * @param array<string, mixed> $makeup
     * @return array<string, mixed>
     */
    private function projectFulfillment(string $providerCode, array $fulfillment, array $makeup): array
    {
        $externalOrderId = trim((string)($fulfillment['external_order_id'] ?? ''));
        if ($externalOrderId === '' && $makeup !== []) {
            $externalOrderId = trim((string)($makeup['related_external_order_id'] ?? ''));
        }
        if ($externalOrderId === '') {
            return ['projected' => false];
        }

        $tracking = (string)($fulfillment['tracking_number'] ?? '');
        $carrier = (string)($fulfillment['carrier'] ?? '');
        $fulfillStatus = (string)($fulfillment['status'] ?? 'updated');
        $orderUuid = (string)($fulfillment['order_uuid'] ?? '');
        if ($makeup !== []) {
            $mStatus = strtoupper(trim((string)($makeup['status'] ?? 'UPDATED')));
            $fulfillStatus = 'makeup_' . strtolower($mStatus !== '' ? $mStatus : 'updated');
        }

        /** @var DropshipFulfillment $ff */
        $ff = ObjectManager::getInstance(DropshipFulfillment::class);
        $existing = $ff->clear()
            ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID, $externalOrderId)
            ->find()
            ->fetch();
        $now = date('Y-m-d H:i:s');
        $trackingJson = null;
        if ($makeup !== []) {
            $trackingJson = json_encode(['makeup' => $makeup], JSON_UNESCAPED_UNICODE) ?: null;
        }
        $data = [
            DropshipFulfillment::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID => $externalOrderId,
            DropshipFulfillment::schema_fields_STATUS => $fulfillStatus,
            DropshipFulfillment::schema_fields_UPDATED_AT => $now,
        ];
        if ($tracking !== '') {
            $data[DropshipFulfillment::schema_fields_TRACKING_NUMBER] = $tracking;
        }
        if ($carrier !== '') {
            $data[DropshipFulfillment::schema_fields_CARRIER] = $carrier;
        }
        if ($trackingJson !== null) {
            $data[DropshipFulfillment::schema_fields_TRACKING_JSON] = $trackingJson;
        }
        if ($existing && $existing->getId()) {
            if ($orderUuid !== '' && trim((string)$existing->getData(DropshipFulfillment::schema_fields_ORDER_UUID)) === '') {
                $data[DropshipFulfillment::schema_fields_ORDER_UUID] = $orderUuid;
            }
            // 空运单不覆盖已有运单
            if ($tracking === '' && trim((string)$existing->getData(DropshipFulfillment::schema_fields_TRACKING_NUMBER)) !== '') {
                unset($data[DropshipFulfillment::schema_fields_TRACKING_NUMBER]);
            }
            $existing->setData($data)->save();
        } else {
            $data[DropshipFulfillment::schema_fields_ORDER_UUID] = $orderUuid !== '' ? $orderUuid : ('ext:' . $externalOrderId);
            $data[DropshipFulfillment::schema_fields_TRACKING_NUMBER] = $tracking;
            $data[DropshipFulfillment::schema_fields_CARRIER] = $carrier;
            $data[DropshipFulfillment::schema_fields_CREATED_AT] = $now;
            $ff->clear()->setData($data)->save();
        }

        return ['projected' => true, 'external_order_id' => $externalOrderId, 'status' => $fulfillStatus];
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function enqueueCatalogFollow(string $providerCode, array $catalog): array
    {
        $spu = trim((string)($catalog['external_spu'] ?? ''));
        $sku = trim((string)($catalog['external_sku'] ?? ''));
        if ($spu === '' && $sku === '') {
            return ['enqueued' => 0];
        }
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $q = $model->clear()->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerCode);
        if ($spu !== '') {
            $q->where(DropshipListing::schema_fields_EXTERNAL_SPU, $spu);
        } elseif ($sku !== '') {
            $q->where(DropshipListing::schema_fields_EXTERNAL_SKU, $sku);
        }
        $rows = $q->limit(50)->select()->fetchArray();
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int)($row[DropshipListing::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $enqueued = 0;
        foreach ($ids as $listingId) {
            try {
                if (!function_exists('w_query')) {
                    break;
                }
                w_query('queue', 'createIfAbsent', [
                    'class' => DropshipListingSyncConsumer::class,
                    'name' => 'Dropship listing sync #' . $listingId . ' (webhook)',
                    'module' => 'Weline_Dropship',
                    'content' => ['listing_id' => $listingId],
                    'status' => 'pending',
                    'auto' => true,
                    'biz_key' => 'dropship:listing:sync:' . $listingId,
                    'idempotency_scope' => 'dropship_listing_sync',
                    'idempotency_key' => 'dropship:listing:sync:webhook:' . $listingId . ':' . date('YmdHi'),
                ]);
                $enqueued++;
            } catch (\Throwable $e) {
                w_log_error('dropship webhook catalog enqueue failed: ' . $e->getMessage());
            }
        }

        return [
            'enqueued' => $enqueued,
            'listing_ids' => $ids,
            'external_spu' => $spu,
            'external_sku' => $sku,
        ];
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
