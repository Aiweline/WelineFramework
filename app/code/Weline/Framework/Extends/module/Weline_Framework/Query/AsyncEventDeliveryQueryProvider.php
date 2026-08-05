<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Event\Async\Admin\DeliveryAccessPolicy;
use Weline\Framework\Event\Async\Admin\DeliveryReplayService;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class AsyncEventDeliveryQueryProvider implements QueryProviderInterface
{
    private const STATUSES = [
        'pending',
        'provisioning',
        'queued',
        'running',
        'retry_wait',
        'succeeded',
        'dead',
        'superseded',
        'skipped',
    ];

    public function __construct(
        private readonly Delivery $deliveryModel,
        private readonly DeliveryAccessPolicy $accessPolicy,
        private readonly DeliveryReplayService $replayService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'async_event_delivery';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'asyncEventDeliveryList' => $this->listing($params),
            'asyncEventDeliveryDetail' => $this->detail($params),
            'asyncEventDeliveryReplay' => $this->replay($params),
            default => throw new \InvalidArgumentException(
                (string)__('异步事件 Delivery 查询器不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('异步事件 Delivery 运维'),
            'description' => (string)__('按后台网站权限查看脱敏 Delivery，并安全创建死信重放。'),
            'module' => 'Weline_Framework',
            'operations' => [
                [
                    'name' => 'asyncEventDeliveryList',
                    'description' => (string)__('分页读取当前网站或已授权全站的 Delivery。'),
                    'frontend' => true,
                    'backend' => true,
                    'external' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => DeliveryAccessPolicy::ACL_VIEW,
                    ],
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1, 'description' => (string)__('页码，默认 1')],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 100, 'description' => (string)__('每页 1–100 条，默认 20')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'max_length' => 24, 'description' => (string)__('Delivery 状态，默认 dead')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'min' => 0, 'description' => (string)__('网站 ID；0 是合法默认站点')],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'max_length' => 16, 'description' => (string)__('current 或 all；all 需要全站权限')],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'asyncEventDeliveryDetail',
                    'description' => (string)__('读取单个 Delivery 的白名单字段和脱敏载荷。'),
                    'frontend' => true,
                    'backend' => true,
                    'external' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => DeliveryAccessPolicy::ACL_VIEW,
                    ],
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'delivery_id', 'type' => 'int', 'required' => true, 'min' => 1, 'description' => (string)__('Delivery 主键')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'min' => 0, 'description' => (string)__('网站 ID；缺失时使用当前后台网站')],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'asyncEventDeliveryReplay',
                    'description' => (string)__('为 dead Delivery 创建新 event_id、Outbox 与单 Observer 目标。'),
                    'frontend' => true,
                    'backend' => true,
                    'external' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => DeliveryAccessPolicy::ACL_REPLAY,
                    ],
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'delivery_id', 'type' => 'int', 'required' => true, 'min' => 1, 'description' => (string)__('待重放的 dead Delivery 主键')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'min' => 0, 'description' => (string)__('网站 ID；缺失时使用当前后台网站')],
                        ['name' => 'reason', 'type' => 'string', 'required' => true, 'max_length' => 500, 'description' => (string)__('重放原因，去空白后 1–500 字节')],
                    ],
                    'returns' => ['type' => 'map'],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function listing(array $params): array
    {
        $page = $this->positiveInteger($params['page'] ?? 1, 'page');
        $pageSize = $this->positiveInteger($params['page_size'] ?? 20, 'page_size');
        if ($pageSize > 100) {
            throw new \InvalidArgumentException((string)__('page_size 只允许 1–100'));
        }
        $status = strtolower(trim((string)($params['status'] ?? 'dead')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException((string)__('Delivery status 无效'));
        }
        $websiteId = $this->accessPolicy->resolveListWebsite($params);

        $total = $this->scopedQuery($status, $websiteId)->total();
        $rows = $this->scopedQuery($status, $websiteId)
            ->order(Delivery::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize, [], 100)
            ->select(implode(',', [
                Delivery::schema_fields_ID,
                Delivery::schema_fields_EVENT_ID,
                Delivery::schema_fields_OBSERVER_KEY,
                Delivery::schema_fields_OBSERVER_MODULE,
                Delivery::schema_fields_OBSERVER_NAME,
                Delivery::schema_fields_PAYLOAD_JSON,
                Delivery::schema_fields_STATUS,
                Delivery::schema_fields_ATTEMPT_NO,
                Delivery::schema_fields_MAX_ATTEMPTS,
                Delivery::schema_fields_TRANSPORT_NAME,
                Delivery::schema_fields_QUEUE_ID,
                Delivery::schema_fields_LAST_ERROR_CODE,
                Delivery::schema_fields_TERMINAL_REASON,
                Delivery::schema_fields_REPLAY_OF_DELIVERY_ID,
                Delivery::schema_fields_REPLAY_REQUESTED_BY,
                Delivery::schema_fields_REPLAY_REQUESTED_AT,
                Delivery::schema_fields_FINISHED_AT,
                Delivery::schema_fields_CREATED_AT,
                Delivery::schema_fields_UPDATED_AT,
            ]))
            ->fetchArray();

        $items = [];
        foreach ((array)$rows as $row) {
            $payload = $this->decodePayload((string)($row[Delivery::schema_fields_PAYLOAD_JSON] ?? ''));
            if ($payload === null) {
                continue;
            }
            $rowWebsiteId = $this->accessPolicy->websiteFromPayload($payload);
            if ($websiteId !== null && $rowWebsiteId !== $websiteId) {
                continue;
            }
            $items[] = $this->listItem($row, $payload);
        }
        $permissions = $this->accessPolicy->permissions();

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int)$total,
            'total_pages' => (int)ceil((int)$total / $pageSize),
            'status' => $status,
            'scope' => $websiteId === null ? 'all' : 'current',
            'website_id' => $websiteId,
            'permissions' => $permissions,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function detail(array $params): array
    {
        $this->accessPolicy->requirePermission(DeliveryAccessPolicy::ACL_VIEW);
        $deliveryId = $this->positiveInteger($params['delivery_id'] ?? 0, 'delivery_id');
        $websiteId = $this->accessPolicy->resolveWebsite($params);
        $delivery = $this->findDelivery($deliveryId);
        if ($delivery === null) {
            throw new \RuntimeException((string)__('Delivery 不存在或不属于当前网站范围'));
        }
        $payload = $this->decodePayload((string)$delivery->getData(Delivery::schema_fields_PAYLOAD_JSON));
        if ($payload === null) {
            throw new \RuntimeException((string)__('Delivery payload JSON 无效'));
        }
        $this->accessPolicy->assertPayloadWebsite($payload, $websiteId);

        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $website = is_array($payload['website'] ?? null) ? $payload['website'] : [];
        $permissions = $this->accessPolicy->permissions();
        return [
            'delivery_id' => (int)$delivery->getData(Delivery::schema_fields_ID),
            'outbox_id' => (int)$delivery->getData(Delivery::schema_fields_OUTBOX_ID),
            'event_id' => (string)$delivery->getData(Delivery::schema_fields_EVENT_ID),
            'event_name' => (string)($payload['event_name'] ?? ''),
            'observer_key' => (string)$delivery->getData(Delivery::schema_fields_OBSERVER_KEY),
            'observer_module' => (string)$delivery->getData(Delivery::schema_fields_OBSERVER_MODULE),
            'observer_name' => (string)$delivery->getData(Delivery::schema_fields_OBSERVER_NAME),
            'payload_schema_version' => (int)$delivery->getData(Delivery::schema_fields_PAYLOAD_SCHEMA_VERSION),
            'website' => [
                'id' => (int)($website['id'] ?? -1),
                'code' => (string)($website['code'] ?? ''),
            ],
            'resource' => [
                'type' => (string)($resource['type'] ?? ''),
                'id' => (string)($resource['id'] ?? ''),
                'action' => (string)($resource['action'] ?? ''),
                'revision' => (int)($resource['revision'] ?? 0),
            ],
            'status' => (string)$delivery->getData(Delivery::schema_fields_STATUS),
            'attempt_no' => (int)$delivery->getData(Delivery::schema_fields_ATTEMPT_NO),
            'max_attempts' => (int)$delivery->getData(Delivery::schema_fields_MAX_ATTEMPTS),
            'transport_name' => (string)$delivery->getData(Delivery::schema_fields_TRANSPORT_NAME),
            'queue_id' => $delivery->getData(Delivery::schema_fields_QUEUE_ID) === null
                ? null
                : (int)$delivery->getData(Delivery::schema_fields_QUEUE_ID),
            'last_error_code' => (string)$delivery->getData(Delivery::schema_fields_LAST_ERROR_CODE),
            'last_error' => $this->accessPolicy->escapedError(
                $delivery->getData(Delivery::schema_fields_LAST_ERROR),
            ),
            'terminal_reason' => (string)$delivery->getData(Delivery::schema_fields_TERMINAL_REASON),
            'replay_of_delivery_id' => $delivery->getData(Delivery::schema_fields_REPLAY_OF_DELIVERY_ID) === null
                ? null
                : (int)$delivery->getData(Delivery::schema_fields_REPLAY_OF_DELIVERY_ID),
            'replay_requested_by' => (string)$delivery->getData(Delivery::schema_fields_REPLAY_REQUESTED_BY),
            'replay_requested_at' => $delivery->getData(Delivery::schema_fields_REPLAY_REQUESTED_AT),
            'started_at' => $delivery->getData(Delivery::schema_fields_STARTED_AT),
            'finished_at' => $delivery->getData(Delivery::schema_fields_FINISHED_AT),
            'created_at' => $delivery->getData(Delivery::schema_fields_CREATED_AT),
            'updated_at' => $delivery->getData(Delivery::schema_fields_UPDATED_AT),
            'payload' => $this->accessPolicy->redactedPayload($payload),
            'can_replay' => $permissions['replay'] === true
                && $this->replayService->canReplay($delivery),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function replay(array $params): array
    {
        $deliveryId = $this->positiveInteger($params['delivery_id'] ?? 0, 'delivery_id');
        $websiteId = $this->accessPolicy->resolveWebsite($params);
        $reason = trim((string)($params['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 500) {
            throw new \InvalidArgumentException((string)__('重放原因必须是 1–500 字节'));
        }
        return $this->replayService->replay($deliveryId, $websiteId, $reason);
    }

    private function scopedQuery(string $status, ?int $websiteId): Delivery
    {
        $query = $this->newDelivery()->where(Delivery::schema_fields_STATUS, $status);
        if ($websiteId !== null) {
            $query->where(
                Delivery::schema_fields_CONTEXT_JSON,
                '%"website_id":' . $websiteId . '}%',
                'LIKE',
            );
        }
        return $query;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload @return array<string,mixed> */
    private function listItem(array $row, array $payload): array
    {
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $website = is_array($payload['website'] ?? null) ? $payload['website'] : [];
        return [
            'delivery_id' => (int)($row[Delivery::schema_fields_ID] ?? 0),
            'event_id' => (string)($row[Delivery::schema_fields_EVENT_ID] ?? ''),
            'event_name' => (string)($payload['event_name'] ?? ''),
            'observer_key' => (string)($row[Delivery::schema_fields_OBSERVER_KEY] ?? ''),
            'observer_module' => (string)($row[Delivery::schema_fields_OBSERVER_MODULE] ?? ''),
            'observer_name' => (string)($row[Delivery::schema_fields_OBSERVER_NAME] ?? ''),
            'website_id' => (int)($website['id'] ?? -1),
            'website_code' => (string)($website['code'] ?? ''),
            'resource_type' => (string)($resource['type'] ?? ''),
            'resource_id' => (string)($resource['id'] ?? ''),
            'resource_action' => (string)($resource['action'] ?? ''),
            'revision' => (int)($resource['revision'] ?? 0),
            'status' => (string)($row[Delivery::schema_fields_STATUS] ?? ''),
            'attempt_no' => (int)($row[Delivery::schema_fields_ATTEMPT_NO] ?? 0),
            'max_attempts' => (int)($row[Delivery::schema_fields_MAX_ATTEMPTS] ?? 0),
            'transport_name' => (string)($row[Delivery::schema_fields_TRANSPORT_NAME] ?? ''),
            'queue_id' => isset($row[Delivery::schema_fields_QUEUE_ID])
                ? (int)$row[Delivery::schema_fields_QUEUE_ID]
                : null,
            'last_error_code' => (string)($row[Delivery::schema_fields_LAST_ERROR_CODE] ?? ''),
            'terminal_reason' => (string)($row[Delivery::schema_fields_TERMINAL_REASON] ?? ''),
            'replay_of_delivery_id' => isset($row[Delivery::schema_fields_REPLAY_OF_DELIVERY_ID])
                ? (int)$row[Delivery::schema_fields_REPLAY_OF_DELIVERY_ID]
                : null,
            'replay_requested_by' => (string)($row[Delivery::schema_fields_REPLAY_REQUESTED_BY] ?? ''),
            'replay_requested_at' => $row[Delivery::schema_fields_REPLAY_REQUESTED_AT] ?? null,
            'finished_at' => $row[Delivery::schema_fields_FINISHED_AT] ?? null,
            'created_at' => $row[Delivery::schema_fields_CREATED_AT] ?? null,
            'updated_at' => $row[Delivery::schema_fields_UPDATED_AT] ?? null,
        ];
    }

    private function findDelivery(int $deliveryId): ?Delivery
    {
        $delivery = $this->newDelivery();
        $delivery->where(Delivery::schema_fields_ID, $deliveryId)->find()->fetch();
        return $delivery->getId() ? $delivery : null;
    }

    private function newDelivery(): Delivery
    {
        $model = clone $this->deliveryModel;
        return $model->clearData()->clearQuery();
    }

    /** @return array<string,mixed>|null */
    private function decodePayload(string $json): ?array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($payload) && !array_is_list($payload) ? $payload : null;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $integer = (int)$value;
        } else {
            throw new \InvalidArgumentException((string)__('%{1} 必须是正整数', [$label]));
        }
        if ($integer < 1) {
            throw new \InvalidArgumentException((string)__('%{1} 必须是正整数', [$label]));
        }
        return $integer;
    }
}
