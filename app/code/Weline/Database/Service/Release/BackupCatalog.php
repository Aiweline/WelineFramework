<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Release\Api\BackupCatalogInterface;

/**
 * Append-only catalog for release backup sets and their objects.
 *
 * Sets are unique per release/operation/scope; repeated registration returns
 * the existing row. Objects are never updated or deleted through this service.
 */
final class BackupCatalog implements BackupCatalogInterface
{
    /** @var list<string> */
    public const SET_REQUIRED_FIELDS = ['release_id', 'operation_id', 'scope_key', 'kind'];

    /** @var list<string> */
    public const OBJECT_REQUIRED_FIELDS = ['object_type', 'table_name'];

    /** @var list<string> */
    public const KINDS = [BackupSet::KIND_OPERATION, BackupSet::KIND_RELEASE];

    /** @var list<string> */
    public const OBJECT_FILTERS = ['object_type', 'table_name', 'column_name'];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly BackupSet $backupSet,
        private readonly BackupObject $backupObject,
    ) {
        $this->backupSet->setConnection($this->connectionFactory);
        $this->backupObject->setConnection($this->connectionFactory);
    }

    public function createSet(array $set): int
    {
        foreach (self::SET_REQUIRED_FIELDS as $field) {
            if (!isset($set[$field]) || (string) $set[$field] === '') {
                throw new InvalidArgumentException('Backup set requires a non-empty field: ' . $field);
            }
        }

        $kind = (string) $set['kind'];
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported backup set kind: ' . $kind);
        }

        $releaseId = (string) $set['release_id'];
        $operationId = (string) $set['operation_id'];
        $scopeKey = (string) $set['scope_key'];

        $existingId = $this->findSetId($releaseId, $operationId, $scopeKey);
        if ($existingId > 0) {
            return $existingId;
        }

        $model = $this->newSetModel();
        $model->setData([
            BackupSet::schema_fields_RELEASE_ID => $releaseId,
            BackupSet::schema_fields_OPERATION_ID => $operationId,
            BackupSet::schema_fields_SCOPE_KEY => $scopeKey,
            BackupSet::schema_fields_KIND => $kind,
            BackupSet::schema_fields_STATUS => (string) ($set['status'] ?? BackupSet::STATUS_PENDING),
            BackupSet::schema_fields_MANIFEST_JSON => isset($set['manifest_json'])
                ? (string) $set['manifest_json']
                : null,
            BackupSet::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);

        $result = $model->save();
        $setId = is_int($result) ? $result : (int) $model->getId();
        if ($setId <= 0) {
            throw new RuntimeException(
                'Backup set registration failed for release ' . $releaseId . ' / operation ' . $operationId
            );
        }

        return $setId;
    }

    public function addObject(int $setId, array $object): int
    {
        if ($setId <= 0 || !$this->setExists($setId)) {
            throw new InvalidArgumentException('Backup set not found: ' . $setId);
        }

        foreach (self::OBJECT_REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $object)) {
                throw new InvalidArgumentException('Backup object requires field: ' . $field);
            }
        }

        $objectType = (string) $object['object_type'];
        if ($objectType === '') {
            throw new InvalidArgumentException('Backup object requires a non-empty field: object_type');
        }

        $dataJson = array_key_exists('data_json', $object) && $object['data_json'] !== null
            ? (string) $object['data_json']
            : null;
        $checksum = array_key_exists('data_checksum', $object) && $object['data_checksum'] !== null
            ? (string) $object['data_checksum']
            : null;

        if ($checksum !== null && $checksum !== '' && preg_match('/^[0-9a-f]{64}$/i', $checksum) !== 1) {
            throw new InvalidArgumentException('Backup object data_checksum must be a sha256 hex digest');
        }
        if ($dataJson !== null && ($checksum === null || $checksum === '')) {
            throw new InvalidArgumentException('Backup object with data_json requires data_checksum');
        }

        $model = $this->newObjectModel();
        $model->setData([
            BackupObject::schema_fields_BACKUP_SET_ID => $setId,
            BackupObject::schema_fields_OBJECT_TYPE => $objectType,
            BackupObject::schema_fields_TABLE_NAME => (string) $object['table_name'],
            BackupObject::schema_fields_COLUMN_NAME => isset($object['column_name'])
                ? (string) $object['column_name']
                : null,
            BackupObject::schema_fields_SOURCE_VERSION => isset($object['source_version'])
                ? (string) $object['source_version']
                : null,
            BackupObject::schema_fields_TARGET_VERSION => isset($object['target_version'])
                ? (string) $object['target_version']
                : null,
            BackupObject::schema_fields_DATA_JSON => $dataJson,
            BackupObject::schema_fields_DATA_CHECKSUM => $checksum,
            BackupObject::schema_fields_ROW_COUNT => (int) ($object['row_count'] ?? 0),
            BackupObject::schema_fields_STATUS => BackupObject::STATUS_PENDING,
            BackupObject::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);

        $result = $model->save();
        $objectId = is_int($result) ? $result : (int) $model->getId();
        if ($objectId <= 0) {
            throw new RuntimeException('Backup object registration failed for backup set ' . $setId);
        }

        return $objectId;
    }

    public function findSet(string $releaseId, ?string $scopeKey = null): ?array
    {
        if ($releaseId === '') {
            return null;
        }

        $model = $this->newSetModel()->where(BackupSet::schema_fields_RELEASE_ID, $releaseId);
        if ($scopeKey !== null && $scopeKey !== '') {
            $model->where(BackupSet::schema_fields_SCOPE_KEY, $scopeKey);
        }

        $rows = $model
            ->order(BackupSet::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows === [] ? null : $this->normalizeSet($rows[0]);
    }

    public function objects(int $setId, array $filter = []): array
    {
        $model = $this->newObjectModel()->where(BackupObject::schema_fields_BACKUP_SET_ID, $setId);
        foreach (self::OBJECT_FILTERS as $field) {
            if (isset($filter[$field]) && (string) $filter[$field] !== '') {
                $model->where($field, (string) $filter[$field]);
            }
        }

        return array_values(
            $model
                ->order(BackupObject::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray()
        );
    }

    private function findSetId(string $releaseId, string $operationId, string $scopeKey): int
    {
        $rows = $this->newSetModel()
            ->where(BackupSet::schema_fields_RELEASE_ID, $releaseId)
            ->where(BackupSet::schema_fields_OPERATION_ID, $operationId)
            ->where(BackupSet::schema_fields_SCOPE_KEY, $scopeKey)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows === [] ? 0 : (int) ($rows[0][BackupSet::schema_fields_ID] ?? 0);
    }

    private function setExists(int $setId): bool
    {
        $rows = $this->newSetModel()
            ->where(BackupSet::schema_fields_ID, $setId)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSet(array $row): array
    {
        $manifestJson = $row[BackupSet::schema_fields_MANIFEST_JSON] ?? null;
        $manifest = null;
        if (is_string($manifestJson) && $manifestJson !== '') {
            $decoded = json_decode($manifestJson, true);
            $manifest = is_array($decoded) ? $decoded : $manifestJson;
        }

        return [
            'id' => (int) ($row[BackupSet::schema_fields_ID] ?? 0),
            'release_id' => (string) ($row[BackupSet::schema_fields_RELEASE_ID] ?? ''),
            'operation_id' => (string) ($row[BackupSet::schema_fields_OPERATION_ID] ?? ''),
            'scope_key' => (string) ($row[BackupSet::schema_fields_SCOPE_KEY] ?? ''),
            'kind' => (string) ($row[BackupSet::schema_fields_KIND] ?? ''),
            'status' => (string) ($row[BackupSet::schema_fields_STATUS] ?? ''),
            'manifest_json' => $manifestJson === null ? null : (string) $manifestJson,
            'manifest' => $manifest,
            'created_at' => $row[BackupSet::schema_fields_CREATED_AT] ?? null,
        ];
    }

    private function newSetModel(): BackupSet
    {
        $model = clone $this->backupSet;
        $model->clearDataObject();
        $model->setConnection($this->connectionFactory);
        $model->reset();

        return $model;
    }

    private function newObjectModel(): BackupObject
    {
        $model = clone $this->backupObject;
        $model->clearDataObject();
        $model->setConnection($this->connectionFactory);
        $model->reset();

        return $model;
    }
}
