<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipOrderLine;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class DropshipOutboxService
{
    public const MAX_ATTEMPTS = 5;

    /** @var list<string> 永久失败白名单短语（不用过宽子串如单独 country；不含 Order exist——那是幂等已建单） */
    private const PERMANENT_FAILURE_PHRASES = [
        'Logistic not found',
        'Platform not support',
        'skipped:unsupported',
    ];

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

        $status = (string)$row->getData(DropshipPushOutbox::schema_fields_STATUS);
        if ($status === DropshipPushOutbox::STATUS_DONE) {
            return ['ok' => true, 'message' => 'already_done'];
        }
        if ($status === DropshipPushOutbox::STATUS_DEAD) {
            if ($this->maybeRecoverExistingFulfillment($row, $bizKey)) {
                return ['ok' => true, 'message' => 'recovered_existing', 'recovered' => true];
            }
            $this->maybeBackfillTerminalCompensation($row, $bizKey);

            return ['ok' => true, 'message' => 'already_dead', 'terminal' => true];
        }

        $providerCode = (string)$row->getData(DropshipPushOutbox::schema_fields_PROVIDER_CODE);
        $action = (string)$row->getData(DropshipPushOutbox::schema_fields_ACTION);
        $orderUuid = (string)$row->getData(DropshipPushOutbox::schema_fields_ORDER_UUID);
        $payload = json_decode((string)$row->getData(DropshipPushOutbox::schema_fields_PAYLOAD_JSON), true) ?: [];
        $provider = $this->channels->getProvider($providerCode);

        $attempts = (int)$row->getData(DropshipPushOutbox::schema_fields_ATTEMPTS) + 1;
        $row->setData(DropshipPushOutbox::schema_fields_ATTEMPTS, $attempts)->save();

        if (!$provider instanceof DropshipFulfillmentProviderInterface) {
            $lastError = 'skipped:unsupported';
            if ($action === 'create') {
                return $this->markTerminalDead($row, $bizKey, $providerCode, $action, $orderUuid, $payload, $lastError);
            }
            $row->setData([
                DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_SKIPPED,
                DropshipPushOutbox::schema_fields_LAST_ERROR => $lastError,
                DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            return ['ok' => true, 'skipped' => true];
        }

        try {
            if ($action === 'create') {
                $websiteId = (int)($payload['website_id'] ?? 0);
                $storeId = (int)($payload['store_id'] ?? 0);
                $shipCountry = strtoupper(trim((string)(
                    $payload['shipping']['country_code']
                    ?? $payload['shipping']['country']
                    ?? $payload['from_country_code']
                    ?? ''
                )));
                $map = $this->resolveWarehouseMapOrEmpty($providerCode, $websiteId, $storeId, $shipCountry);
                $result = $provider->createFulfillment([
                    'order_uuid' => $orderUuid,
                    'lines' => $payload['lines'] ?? [],
                    'shipping' => $payload['shipping'] ?? [],
                    'storage_id' => DropshipWarehouseMapService::remoteStorageId($map),
                    'from_country_code' => DropshipWarehouseMapService::remoteCountryCode($map),
                ]);
                if (!($result['ok'] ?? false)) {
                    throw new \RuntimeException((string)($result['message'] ?? 'create_failed'));
                }
                $this->upsertFulfillment(
                    $providerCode,
                    $orderUuid,
                    (string)($result['external_order_id'] ?? ''),
                    (string)($result['status'] ?? 'created'),
                    (string)($result['tracking_number'] ?? ''),
                    (string)($result['carrier'] ?? ''),
                );
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
            $message = $e->getMessage();
            if ($this->shouldMarkTerminal($attempts, $message)) {
                return $this->markTerminalDead($row, $bizKey, $providerCode, $action, $orderUuid, $payload, $message);
            }

            $row->setData([
                DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_ERROR,
                DropshipPushOutbox::schema_fields_LAST_ERROR => $message,
                DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->save();

            return ['ok' => false, 'message' => $message];
        }
    }

    /**
     * 合并写入 payload_json.compensation（无新表）。
     *
     * @param array<string, mixed> $compensation
     */
    public function mergeCompensation(string $bizKey, array $compensation): void
    {
        /** @var DropshipPushOutbox $model */
        $model = ObjectManager::getInstance(DropshipPushOutbox::class);
        $row = $model->clear()->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)->find()->fetch();
        if (!$row || !$row->getId()) {
            return;
        }
        $payload = json_decode((string)$row->getData(DropshipPushOutbox::schema_fields_PAYLOAD_JSON), true) ?: [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $prev = is_array($payload['compensation'] ?? null) ? $payload['compensation'] : [];
        $payload['compensation'] = array_merge($prev, $compensation);
        $row->setData([
            DropshipPushOutbox::schema_fields_PAYLOAD_JSON => json_encode($payload, JSON_UNESCAPED_UNICODE),
            DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,terminal:bool,message:string}
     */
    private function markTerminalDead(
        DropshipPushOutbox $row,
        string $bizKey,
        string $providerCode,
        string $action,
        string $orderUuid,
        array $payload,
        string $lastError,
    ): array {
        $prevStatus = (string)$row->getData(DropshipPushOutbox::schema_fields_STATUS);
        $row->setData([
            DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_DEAD,
            DropshipPushOutbox::schema_fields_LAST_ERROR => $lastError,
            DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        // 仅跃迁到 dead 时派一次（已是 dead 的 early-return 不会进这里）。
        if ($prevStatus !== DropshipPushOutbox::STATUS_DEAD) {
            $eventData = [
                'biz_key' => $bizKey,
                'order_uuid' => $orderUuid,
                'provider_code' => $providerCode,
                'action' => $action,
                'website_id' => (int)($payload['website_id'] ?? 0),
                'store_id' => (int)($payload['store_id'] ?? 0),
                'last_error' => $lastError,
                'payload' => $payload,
            ];
            try {
                /** @var DropshipPushTerminalCompensationService $compensation */
                $compensation = ObjectManager::getInstance(DropshipPushTerminalCompensationService::class);
                $compensation->handle($eventData);
            } catch (\Throwable $e) {
                w_log_error('dropship push_terminal handle failed: ' . $e->getMessage());
            }
            $this->dispatchPushTerminal($eventData);
        }

        return ['ok' => true, 'terminal' => true, 'message' => $lastError];
    }

    /**
     * dead 但本地已有远端履约单号：按幂等成功回收为 done（勿再自动退款）。
     */
    public function maybeRecoverExistingFulfillment(DropshipPushOutbox $row, string $bizKey = ''): bool
    {
        if ((string)$row->getData(DropshipPushOutbox::schema_fields_STATUS) !== DropshipPushOutbox::STATUS_DEAD) {
            return false;
        }
        $action = (string)$row->getData(DropshipPushOutbox::schema_fields_ACTION);
        if ($action !== 'create') {
            return false;
        }
        if ($bizKey === '') {
            $bizKey = (string)$row->getData(DropshipPushOutbox::schema_fields_BIZ_KEY);
        }
        $orderUuid = (string)$row->getData(DropshipPushOutbox::schema_fields_ORDER_UUID);
        $providerCode = (string)$row->getData(DropshipPushOutbox::schema_fields_PROVIDER_CODE);
        if ($orderUuid === '' || $providerCode === '') {
            return false;
        }

        /** @var DropshipFulfillment $fulModel */
        $fulModel = ObjectManager::getInstance(DropshipFulfillment::class);
        $ful = $fulModel->clear()
            ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipFulfillment::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        $externalId = '';
        if ($ful && $ful->getId()) {
            $externalId = trim((string)$ful->getData(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID));
        }
        if ($externalId === '') {
            return false;
        }

        $row->setData([
            DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_DONE,
            DropshipPushOutbox::schema_fields_LAST_ERROR => '',
            DropshipPushOutbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        $this->mergeCompensation($bizKey, [
            'status' => 'recovered_existing',
            'external_order_id' => $externalId,
            'at' => date('c'),
            'note' => 'local_fulfillment_present',
            'error_code' => '',
            'error_message' => '',
        ]);

        return true;
    }

    /**
     * 历史 dead（无 compensation）补派一次终态补偿（退款幂等，可安全重入）。
     */
    public function maybeBackfillTerminalCompensation(DropshipPushOutbox $row, string $bizKey = ''): bool
    {
        if ((string)$row->getData(DropshipPushOutbox::schema_fields_STATUS) !== DropshipPushOutbox::STATUS_DEAD) {
            return false;
        }
        $action = (string)$row->getData(DropshipPushOutbox::schema_fields_ACTION);
        if ($action !== 'create') {
            return false;
        }
        if ($bizKey === '') {
            $bizKey = (string)$row->getData(DropshipPushOutbox::schema_fields_BIZ_KEY);
        }
        $payload = json_decode((string)$row->getData(DropshipPushOutbox::schema_fields_PAYLOAD_JSON), true) ?: [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $comp = is_array($payload['compensation'] ?? null) ? $payload['compensation'] : [];
        if (($comp['status'] ?? '') !== '') {
            return false;
        }

        $eventData = [
            'biz_key' => $bizKey,
            'order_uuid' => (string)$row->getData(DropshipPushOutbox::schema_fields_ORDER_UUID),
            'provider_code' => (string)$row->getData(DropshipPushOutbox::schema_fields_PROVIDER_CODE),
            'action' => $action,
            'website_id' => (int)($payload['website_id'] ?? 0),
            'store_id' => (int)($payload['store_id'] ?? 0),
            'last_error' => (string)$row->getData(DropshipPushOutbox::schema_fields_LAST_ERROR),
            'payload' => $payload,
            'backfill' => true,
        ];
        // 直接调用补偿服务（不依赖事件目录是否已热更新），再派事件保持解耦监听面。
        try {
            /** @var DropshipPushTerminalCompensationService $compensation */
            $compensation = ObjectManager::getInstance(DropshipPushTerminalCompensationService::class);
            $compensation->handle($eventData);
        } catch (\Throwable $e) {
            w_log_error('dropship terminal backfill handle failed: ' . $e->getMessage());
        }
        $this->dispatchPushTerminal($eventData);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchPushTerminal(array $data): void
    {
        try {
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
            $events->dispatch('Weline_Dropship::push_terminal', $data);
        } catch (\Throwable $e) {
            w_log_error('dropship push_terminal dispatch failed: ' . $e->getMessage());
        }
    }

    private function shouldMarkTerminal(int $attempts, string $lastError): bool
    {
        if ($this->isPermanentFailure($lastError)) {
            return true;
        }

        return $attempts >= self::MAX_ATTEMPTS;
    }

    private function isPermanentFailure(string $lastError): bool
    {
        foreach (self::PERMANENT_FAILURE_PHRASES as $phrase) {
            if ($phrase !== '' && str_contains($lastError, $phrase)) {
                return true;
            }
        }

        return false;
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
            $st = (string)$existing->getData(DropshipPushOutbox::schema_fields_STATUS);
            if ($st === DropshipPushOutbox::STATUS_DONE || $st === DropshipPushOutbox::STATUS_DEAD) {
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

    private function upsertFulfillment(
        string $providerCode,
        string $orderUuid,
        string $externalId,
        string $status,
        string $trackingNumber = '',
        string $carrier = '',
    ): void {
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
        if ($trackingNumber !== '') {
            $data[DropshipFulfillment::schema_fields_TRACKING_NUMBER] = $trackingNumber;
        }
        if ($carrier !== '') {
            $data[DropshipFulfillment::schema_fields_CARRIER] = $carrier;
        }
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();

            return;
        }
        $data[DropshipFulfillment::schema_fields_CREATED_AT] = $now;
        $model->clear()->setData($data)->save();
    }

    /**
     * 仓映射缺失时降级为空 map（storage/from_country 由 Provider 自决），其它异常仍上抛。
     *
     * @return array<string, mixed>
     */
    private function resolveWarehouseMapOrEmpty(string $providerCode, int $websiteId, int $storeId, string $shipCountry): array
    {
        try {
            return $this->warehouseMap->resolve($providerCode, $websiteId, $storeId, $shipCountry);
        } catch (\Throwable $e) {
            if ($e->getMessage() === DropshipWarehouseMapService::ERROR_MISSING) {
                return [];
            }
            throw $e;
        }
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
