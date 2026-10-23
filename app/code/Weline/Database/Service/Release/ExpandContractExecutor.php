<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Framework\Database\Release\Api\DataTransformInterface;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;

/**
 * Expand → Transform → Contract 编排，以及反向补偿。
 *
 * 只调用新发布域服务：DDL、payload hydration、列备份、数据变换、备份编目与 journal。
 * 任一步失败只记录 failed 并抛出，已应用节点不回滚（补偿由上层 coordinator 决定）。
 */
final class ExpandContractExecutor
{
    public const OBJECT_TYPE_STRUCTURE = 'structure';

    public function __construct(
        private readonly SchemaDdlExecutor $ddlExecutor,
        private readonly SchemaChangeHydrator $hydrator,
        private readonly ColumnBackupService $columnBackupService,
        private readonly DataTransformExecutor $dataTransformExecutor,
        private readonly BackupCatalog $backupCatalog,
        private readonly SchemaJournal $journal,
        private readonly SchemaChange $schemaChange,
    ) {
    }

    /**
     * @param array{
     *     scope_key?: string,
     *     operation_id?: string,
     *     expand?: list<array<string, mixed>>,
     *     transform?: list<mixed>,
     *     contract?: list<array<string, mixed>>
     * } $plan
     * @param array<string, mixed> $options
     * @return array{
     *     release_id: string,
     *     expanded: int,
     *     transformed: int,
     *     contracted: int,
     *     backups: list<array<string, mixed>>
     * }
     */
    public function apply(string $releaseId, array $plan, array $options = []): array
    {
        [$releaseId, $scopeKey, $operationId] = $this->resolveIdentity($releaseId, $plan, $options);

        $expanded = 0;
        $transformed = 0;
        $contracted = 0;
        $backups = [];
        $current = $this->changeContext(null);

        try {
            foreach ((array)($plan['expand'] ?? []) as $change) {
                $current = $this->changeContext($change);
                $op = $this->hydrateEntry($change);
                $current['table'] = $op->tableName !== '' ? $op->tableName : $current['table'];

                $this->ddlExecutor->apply($op, false);
                $this->markChangeStatus($releaseId, $current['node_id'], SchemaChange::STATUS_APPLIED);
                $this->journalState(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $current,
                    'expanded',
                    ['change_type' => $op->kind],
                );
                ++$expanded;
            }

            foreach ((array)($plan['transform'] ?? []) as $entry) {
                $transform = $this->resolveTransform($entry, $options);
                $current = $this->changeContext([
                    'node_id' => 'transform:' . $transform->id(),
                    'module' => (string)($options['module_name'] ?? ''),
                    'table' => $transform->table(),
                    'checksum' => '',
                ]);

                $this->dataTransformExecutor->run(
                    $transform,
                    $releaseId,
                    $options + ['operation_id' => $operationId],
                );
                $this->journalState(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $current,
                    'transformed',
                    ['transform_id' => $transform->id()],
                );
                ++$transformed;
            }

            foreach ((array)($plan['contract'] ?? []) as $change) {
                $current = $this->changeContext($change);
                $op = $this->hydrateEntry($change);
                $current['table'] = $op->tableName !== '' ? $op->tableName : $current['table'];

                $backup = $this->backupBeforeContract(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $op,
                    $change,
                    $options,
                );
                if ($backup !== null) {
                    $backups[] = $backup;
                }

                $this->ddlExecutor->apply($op, false);
                $this->markChangeStatus($releaseId, $current['node_id'], SchemaChange::STATUS_APPLIED);
                $this->journalState(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $current,
                    'contracted',
                    ['change_type' => $op->kind],
                );
                ++$contracted;
            }
        } catch (Throwable $exception) {
            $this->journalFailure($releaseId, $scopeKey, $operationId, $current, $exception);
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('expand/contract failed: ' . $exception->getMessage(), 0, $exception);
        }

        return [
            'release_id' => $releaseId,
            'expanded' => $expanded,
            'transformed' => $transformed,
            'contracted' => $contracted,
            'backups' => $backups,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     * @return array{
     *     release_id: string,
     *     compensated: int,
     *     rolled_back: int,
     *     backups: list<array<string, mixed>>
     * }
     */
    public function rollback(string $releaseId, array $plan, array $options = []): array
    {
        [$releaseId, $scopeKey, $operationId] = $this->resolveIdentity($releaseId, $plan, $options);
        $targetReleaseId = \trim((string)($options['target_release_id'] ?? $releaseId));

        $compensated = 0;
        $rolledBack = 0;
        $backups = [];
        $current = $this->changeContext(null);

        try {
            foreach (\array_reverse((array)($plan['contract'] ?? [])) as $change) {
                $current = $this->changeContext($change);
                $op = $this->hydrateEntry($change);
                $current['table'] = $op->tableName !== '' ? $op->tableName : $current['table'];

                switch ($op->kind) {
                    case SchemaDiffOp::KIND_DROP_COLUMN:
                        $column = $this->requireColumn($op);
                        $this->ddlExecutor->apply(
                            new SchemaDiffOp(
                                SchemaDiffOp::KIND_ADD_COLUMN,
                                $op->tableName,
                                $op->payload,
                                $op->modelClass,
                            ),
                            false,
                        );
                        $backups[] = ['type' => 'column_restore', 'table' => $op->tableName, 'column' => $column->name]
                            + $this->columnBackupService->restoreColumn(
                                $releaseId,
                                $scopeKey,
                                $op->tableName,
                                $column->name,
                                $operationId,
                                $options,
                            );
                        break;

                    case SchemaDiffOp::KIND_MODIFY_COLUMN:
                        $column = $this->requireColumn($op);
                        $this->ddlExecutor->apply($op, true);
                        $backups[] = ['type' => 'column_restore', 'table' => $op->tableName, 'column' => $column->name]
                            + $this->columnBackupService->restoreColumn(
                                $releaseId,
                                $scopeKey,
                                $op->tableName,
                                $column->name,
                                $operationId,
                                $options,
                            );
                        break;

                    default:
                        $this->ddlExecutor->apply($op, true);
                        break;
                }

                $this->markChangeStatus($releaseId, $current['node_id'], SchemaChange::STATUS_ROLLED_BACK);
                $this->journalCompensated(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $current,
                    $targetReleaseId,
                    $op->kind,
                );
                ++$compensated;
            }

            foreach (\array_reverse((array)($plan['expand'] ?? [])) as $change) {
                $current = $this->changeContext($change);
                $op = $this->hydrateEntry($change);
                $current['table'] = $op->tableName !== '' ? $op->tableName : $current['table'];

                if ($op->kind === SchemaDiffOp::KIND_CREATE_TABLE) {
                    $backups[] = $this->requireTableStructureBackup(
                        $releaseId,
                        $scopeKey,
                        $operationId,
                        $op->tableName,
                        $change,
                    );
                }

                $this->ddlExecutor->apply($op, true);
                $this->markChangeStatus($releaseId, $current['node_id'], SchemaChange::STATUS_ROLLED_BACK);
                $this->journalCompensated(
                    $releaseId,
                    $scopeKey,
                    $operationId,
                    $current,
                    $targetReleaseId,
                    $op->kind,
                );
                ++$rolledBack;
            }
        } catch (Throwable $exception) {
            $this->journalFailure($releaseId, $scopeKey, $operationId, $current, $exception);
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('rollback failed: ' . $exception->getMessage(), 0, $exception);
        }

        return [
            'release_id' => $releaseId,
            'compensated' => $compensated,
            'rolled_back' => $rolledBack,
            'backups' => $backups,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveIdentity(string $releaseId, array $plan, array $options): array
    {
        $releaseId = \trim($releaseId);
        if ($releaseId === '') {
            throw new InvalidArgumentException('release id 必填');
        }
        $scopeKey = \trim((string)($options['scope_key'] ?? $plan['scope_key'] ?? ''));
        if ($scopeKey === '') {
            throw new InvalidArgumentException('scope_key 必填');
        }
        $operationId = \trim((string)($options['operation_id'] ?? $plan['operation_id'] ?? $releaseId));
        if ($operationId === '') {
            $operationId = $releaseId;
        }

        return [$releaseId, $scopeKey, $operationId];
    }

    /**
     * @param array<string, mixed> $change
     */
    private function hydrateEntry(array $change): SchemaDiffOp
    {
        if (!\array_key_exists('table_name', $change) && isset($change['table'])) {
            $change['table_name'] = (string)$change['table'];
        }

        return $this->hydrator->hydrate($change);
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function backupBeforeContract(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        SchemaDiffOp $op,
        array $change,
        array $options,
    ): ?array {
        switch ($op->kind) {
            case SchemaDiffOp::KIND_DROP_COLUMN:
            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                $column = $this->requireColumn($op);
                $result = $this->columnBackupService->backupColumn(
                    $releaseId,
                    $scopeKey,
                    $op->tableName,
                    $column->name,
                    $operationId,
                    $options,
                );

                return ['type' => 'column', 'table' => $op->tableName, 'column' => $column->name] + $result;

            case SchemaDiffOp::KIND_DROP_INDEX:
            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                return $this->writeStructureBackup($releaseId, $scopeKey, $operationId, $op->tableName, $change);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function writeStructureBackup(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        string $tableName,
        array $change,
    ): array {
        $setId = $this->backupCatalog->createSet([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scopeKey,
            'kind' => BackupSet::KIND_OPERATION,
            'status' => BackupSet::STATUS_READY,
        ]);

        $dataJson = $this->encodeJson([
            'change_type' => (string)($change['change_type'] ?? ''),
            'table' => $tableName,
            'payload' => $change['payload'] ?? null,
            'rollback_payload' => $change['rollback_payload'] ?? null,
            'checksum' => (string)($change['checksum'] ?? ''),
        ], 'structure_backup_encode_failed');

        $checksum = \trim((string)($change['checksum'] ?? ''));
        if (\preg_match('/^[0-9a-f]{64}$/i', $checksum) !== 1) {
            $checksum = \hash('sha256', $dataJson);
        }

        $objectId = $this->backupCatalog->addObject($setId, [
            'object_type' => self::OBJECT_TYPE_STRUCTURE,
            'table_name' => $tableName,
            'data_json' => $dataJson,
            'data_checksum' => $checksum,
            'row_count' => 0,
        ]);

        return [
            'type' => 'structure',
            'table' => $tableName,
            'set_id' => $setId,
            'object_id' => $objectId,
            'checksum' => $checksum,
        ];
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function requireTableStructureBackup(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        string $tableName,
        array $change,
    ): array {
        $backup = $this->writeStructureBackup($releaseId, $scopeKey, $operationId, $tableName, $change);
        $objects = $this->backupCatalog->objects((int)$backup['set_id'], [
            'object_type' => self::OBJECT_TYPE_STRUCTURE,
            'table_name' => $tableName,
        ]);
        if ($objects === []) {
            throw new RuntimeException('table 无结构备份，拒绝 DROP TABLE: ' . $tableName);
        }

        return $backup;
    }

    private function requireColumn(SchemaDiffOp $op): ColumnDefinition
    {
        if (!$op->payload instanceof ColumnDefinition) {
            throw new RuntimeException(
                'column payload 必须是 ColumnDefinition; kind=' . $op->kind . '; table=' . $op->tableName
            );
        }

        return $op->payload;
    }

    /**
     * @param mixed $entry
     * @param array<string, mixed> $options
     */
    private function resolveTransform(mixed $entry, array $options): DataTransformInterface
    {
        if ($entry instanceof DataTransformInterface) {
            return $entry;
        }
        if (\is_string($entry) && $entry !== '') {
            $registry = $options['transforms'] ?? null;
            if (\is_array($registry) && ($registry[$entry] ?? null) instanceof DataTransformInterface) {
                return $registry[$entry];
            }
        }

        throw new RuntimeException('data transform 未注册或类型非法');
    }

    private function markChangeStatus(string $releaseId, string $nodeId, string $status): void
    {
        if ($nodeId === '') {
            return;
        }

        $model = clone $this->schemaChange;
        $model->clearDataObject();
        $model->clear()->reset();
        $existing = $model
            ->where(SchemaChange::schema_fields_RELEASE_ID, $releaseId)
            ->where(SchemaChange::schema_fields_NODE_ID, $nodeId)
            ->find()
            ->fetch();
        $existingId = (int)$existing->getId();
        if ($existingId <= 0) {
            return;
        }

        $model->clear()->reset()
            ->where(SchemaChange::schema_fields_ID, $existingId)
            ->update([SchemaChange::schema_fields_STATUS => $status])
            ->fetch();
    }

    /**
     * @param array{node_id: string, module: string, table: string, checksum: string} $current
     * @param array<string, mixed> $detail
     */
    private function journalState(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        array $current,
        string $state,
        array $detail,
    ): void {
        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scopeKey,
            'node_id' => $current['node_id'] !== ''
                ? $current['node_id']
                : 'release:' . $releaseId,
            'state' => $state,
            'module_name' => $current['module'],
            'table_name' => $current['table'],
            'payload_hash' => $current['checksum'],
            'detail_json' => $this->encodeJson($detail, 'journal_detail_encode_failed'),
        ]);
    }

    /**
     * @param array{node_id: string, module: string, table: string, checksum: string} $current
     */
    private function journalCompensated(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        array $current,
        string $targetReleaseId,
        string $changeType,
    ): void {
        $this->journalState(
            $releaseId,
            $scopeKey,
            $operationId,
            $current,
            'compensated',
            [
                'reason' => 'rollback',
                'target_release_id' => $targetReleaseId,
                'change_type' => $changeType,
            ],
        );
    }

    /**
     * @param array{node_id: string, module: string, table: string, checksum: string} $current
     */
    private function journalFailure(
        string $releaseId,
        string $scopeKey,
        string $operationId,
        array $current,
        Throwable $exception,
    ): void {
        try {
            $this->journalState(
                $releaseId,
                $scopeKey,
                $operationId,
                $current,
                'failed',
                ['error' => $exception->getMessage()],
            );
        } catch (Throwable) {
            // 保留原始异常；journal 写入失败不覆盖主错误。
        }
    }

    /**
     * @param array<string, mixed>|null $change
     * @return array{node_id: string, module: string, table: string, checksum: string}
     */
    private function changeContext(mixed $change): array
    {
        if (!\is_array($change)) {
            return ['node_id' => '', 'module' => '', 'table' => '', 'checksum' => ''];
        }

        return [
            'node_id' => \trim((string)($change['node_id'] ?? '')),
            'module' => (string)($change['module'] ?? $change['module_name'] ?? ''),
            'table' => (string)($change['table_name'] ?? $change['table'] ?? ''),
            'checksum' => (string)($change['checksum'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value, string $error): string
    {
        try {
            return (string)\json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException($error . ': ' . $exception->getMessage(), 0, $exception);
        }
    }
}
