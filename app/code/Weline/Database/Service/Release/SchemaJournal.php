<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Release\Api\SchemaJournalInterface;

/**
 * Append-only schema journal backed by weline_schema_operation_event.
 *
 * Events are INSERT-only; node state is projected from the event stream.
 * No update or delete API is exposed.
 */
final class SchemaJournal implements SchemaJournalInterface
{
    /** @var list<string> */
    public const REQUIRED_FIELDS = ['release_id', 'operation_id', 'scope_key', 'node_id', 'state'];

    /** @var list<string> */
    public const STATES = [
        SchemaOperationEvent::STATE_PLANNED,
        SchemaOperationEvent::STATE_EXPANDED,
        SchemaOperationEvent::STATE_TRANSFORMED,
        SchemaOperationEvent::STATE_CONTRACTED,
        SchemaOperationEvent::STATE_VERIFIED,
        SchemaOperationEvent::STATE_COMMITTED,
        SchemaOperationEvent::STATE_FAILED,
        SchemaOperationEvent::STATE_COMPENSATED,
    ];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaOperationEvent $eventModel,
    ) {
        $this->eventModel->setConnection($this->connectionFactory);
    }

    public function append(array $event): int
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($event[$field]) || (string) $event[$field] === '') {
                throw new InvalidArgumentException('Schema journal event requires a non-empty field: ' . $field);
            }
        }

        $state = (string) $event['state'];
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Unsupported schema journal state: ' . $state);
        }

        $checksum = isset($event['checksum'])
            ? (string) $event['checksum']
            : hash(
                'sha256',
                (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

        $model = $this->newEventModel();
        $model->setData([
            SchemaOperationEvent::schema_fields_RELEASE_ID => (string) $event['release_id'],
            SchemaOperationEvent::schema_fields_OPERATION_ID => (string) $event['operation_id'],
            SchemaOperationEvent::schema_fields_SCOPE_KEY => (string) $event['scope_key'],
            SchemaOperationEvent::schema_fields_NODE_ID => (string) $event['node_id'],
            SchemaOperationEvent::schema_fields_STATE => $state,
            SchemaOperationEvent::schema_fields_MODULE_NAME => (string) ($event['module_name'] ?? ''),
            SchemaOperationEvent::schema_fields_TABLE_NAME => (string) ($event['table_name'] ?? ''),
            SchemaOperationEvent::schema_fields_PAYLOAD_HASH => (string) ($event['payload_hash'] ?? ''),
            SchemaOperationEvent::schema_fields_CHECKSUM => $checksum,
            SchemaOperationEvent::schema_fields_ACTOR => (string) ($event['actor'] ?? 'cli'),
            SchemaOperationEvent::schema_fields_SOURCE => (string) ($event['source'] ?? 'cli'),
            SchemaOperationEvent::schema_fields_DETAIL_JSON => isset($event['detail_json'])
                ? (string) $event['detail_json']
                : null,
            SchemaOperationEvent::schema_fields_OCCURRED_AT => (string) ($event['occurred_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $result = $model->save();
        $eventId = is_int($result) ? $result : (int) $model->getId();
        if ($eventId <= 0) {
            throw new RuntimeException(
                'Schema journal append failed for release ' . (string) $event['release_id']
            );
        }

        return $eventId;
    }

    public function events(string $releaseId, ?string $operationId = null, ?string $scopeKey = null): array
    {
        $model = $this->newEventModel();
        $model->where(SchemaOperationEvent::schema_fields_RELEASE_ID, $releaseId);
        if ($operationId !== null && $operationId !== '') {
            $model->where(SchemaOperationEvent::schema_fields_OPERATION_ID, $operationId);
        }
        if ($scopeKey !== null && $scopeKey !== '') {
            $model->where(SchemaOperationEvent::schema_fields_SCOPE_KEY, $scopeKey);
        }

        $rows = $model
            ->order(SchemaOperationEvent::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return array_values($rows);
    }

    public function latestState(string $scopeKey, string $nodeId): ?string
    {
        $rows = $this->newEventModel()
            ->where(SchemaOperationEvent::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(SchemaOperationEvent::schema_fields_NODE_ID, $nodeId)
            ->order(SchemaOperationEvent::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if ($rows === []) {
            return null;
        }

        $state = $rows[0][SchemaOperationEvent::schema_fields_STATE] ?? null;

        return $state === null ? null : (string) $state;
    }

    private function newEventModel(): SchemaOperationEvent
    {
        $model = clone $this->eventModel;
        $model->clearDataObject();
        $model->setConnection($this->connectionFactory);
        $model->reset();

        return $model;
    }
}
