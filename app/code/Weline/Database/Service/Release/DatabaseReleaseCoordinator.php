<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Model\Release\TableOwner;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Release\Api\DatabaseReleaseCoordinatorInterface;

/**
 * RSSE release coordinator: plan / apply / rollback / inspect.
 *
 * apply() always snapshots before DDL and never fabricates an applied release.
 * rollback() only implements L2 (whole-database restore) and journals "compensated".
 */
final class DatabaseReleaseCoordinator implements DatabaseReleaseCoordinatorInterface
{
    private const STATUS_PLANNED = 'planned';
    private const ROLLBACK_LEVEL_L2 = 'L2';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaManifestBuilder $manifestBuilder,
        private readonly BaselineReleaseBuilder $baselineBuilder,
        private readonly SchemaUpgradePlanner $planner,
        private readonly ExpandContractExecutor $executor,
        private readonly DatabaseSnapshotService $snapshotService,
        private readonly SchemaJournal $journal,
        private readonly SchemaRelease $releaseModel,
        private readonly SchemaCheckpoint $checkpointModel,
        private readonly SchemaScopeRecord $scopeModel,
        private readonly ?TableOwner $tableOwnerModel = null,
        private readonly ?SchemaSnapshot $snapshotModel = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $releaseId): array
    {
        $releaseId = \trim($releaseId);
        $release = $this->findRelease($releaseId);
        $blockers = [];
        if ($release === null) {
            $blockers[] = ['release_id' => $releaseId, 'reason' => 'release_not_found'];
        }

        $checkpoints = [];
        foreach ($this->newModel($this->checkpointModel)
            ->where(SchemaCheckpoint::schema_fields_RELEASE_ID, $releaseId)
            ->order(SchemaCheckpoint::schema_fields_MODULE_NAME, 'ASC')
            ->select()
            ->fetchArray() as $checkpoint) {
            $checkpoints[] = [
                'module' => (string)($checkpoint[SchemaCheckpoint::schema_fields_MODULE_NAME] ?? ''),
                'version' => (string)($checkpoint[SchemaCheckpoint::schema_fields_MODULE_VERSION] ?? ''),
                'format' => (int)($checkpoint[SchemaCheckpoint::schema_fields_FORMAT] ?? 0),
                'checksum' => (string)($checkpoint[SchemaCheckpoint::schema_fields_CHECKSUM] ?? ''),
                'tables' => \count($this->decode((string)($checkpoint[SchemaCheckpoint::schema_fields_TABLES_JSON] ?? ''))['tables'] ?? []),
            ];
        }

        return [
            'release' => $release,
            'checkpoints' => $checkpoints,
            'journal' => $this->journal->events($releaseId),
            'scopes' => \count($this->newModel($this->scopeModel)->select()->fetchArray()),
            'table_owners' => $this->tableOwnerModel instanceof TableOwner
                ? \count($this->newModel($this->tableOwnerModel)->select()->fetchArray())
                : 0,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function plan(string $releaseId, array $options = []): array
    {
        $manifest = $this->resolveManifest($options);
        $operationId = (string)($options['operation_id'] ?? 'plan-' . $releaseId);

        return $this->planner->plan($releaseId, $manifest, [
            'persist' => true,
            'operation_id' => $operationId,
        ] + $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(string $releaseId, array $options = []): array
    {
        $releaseId = \trim($releaseId);
        if ($releaseId === '') {
            throw new InvalidArgumentException('release id 不能为空');
        }
        $manifest = $this->resolveManifest($options);
        $operationId = (string)($options['operation_id'] ?? 'apply-' . $releaseId);
        $scope = $this->resolveScope($options);

        // Fast path: if the target schema already matches and an applied
        // release exists, no DB-affecting change means no snapshot is needed.
        // This keeps setup:upgrade from taking a full DB dump on every run.
        if (empty($options['force_snapshot'])) {
            $dryPlan = $this->planner->plan($releaseId, $manifest, [
                'persist' => false,
                'operation_id' => $operationId,
                'scope_key' => $scope->key(),
            ] + $options);
            if (($dryPlan['blockers'] ?? []) !== []) {
                throw new RuntimeException(
                    'schema plan 存在 blockers，拒绝执行 DDL：' . $this->encode($dryPlan['blockers'])
                );
            }
            $schemaUnchanged = ($dryPlan['expand'] ?? []) === []
                && ($dryPlan['transform'] ?? []) === []
                && ($dryPlan['contract'] ?? []) === [];
            if ($schemaUnchanged && $this->hasAppliedRelease()) {
                return [
                    'release_id' => $releaseId,
                    'status' => SchemaRelease::STATUS_APPLIED,
                    'idempotent' => true,
                    'fast_path' => 'schema_unchanged',
                    'snapshot_key' => null,
                    'snapshot_id' => null,
                ];
            }
        }

        $release = $this->findRelease($releaseId);
        if ($release !== null) {
            $status = (string)($release[SchemaRelease::schema_fields_STATUS] ?? '');
            if ($status === SchemaRelease::STATUS_APPLIED) {
                return [
                    'release_id' => $releaseId,
                    'status' => SchemaRelease::STATUS_APPLIED,
                    'idempotent' => true,
                    'snapshot_key' => null,
                ];
            }
            if ($status !== self::STATUS_PLANNED) {
                throw new RuntimeException('release 状态不可 apply：' . $status);
            }
        }

        if ($release === null) {
            $this->ensurePlannedRelease($releaseId, $manifest, $scope, $options, $operationId);
        } else {
            $this->ensurePlannedSignature($releaseId, $options);
        }

        // 快照必须发生在任何 DDL 之前。
        $snapshot = $this->snapshotService->create($releaseId, $scope, ['operation_id' => $operationId]);
        $snapshotKey = \trim((string)($snapshot['snapshot_key'] ?? ''));
        if ($snapshotKey === '') {
            throw new RuntimeException('快照创建未返回 snapshot_key，拒绝执行 DDL');
        }
        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scope->key(),
            'node_id' => 'snapshot:' . $snapshotKey,
            'state' => SchemaOperationEvent::STATE_VERIFIED,
            'checksum' => (string)($snapshot['checksum'] ?? ''),
            'detail_json' => $this->encode([
                'snapshot_id' => $snapshot['snapshot_id'] ?? null,
                'snapshot_key' => $snapshotKey,
                'path' => $snapshot['path'] ?? null,
            ]),
            'actor' => 'cli',
            'source' => 'cli',
        ]);

        $planOptions = [
            'persist' => true,
            'operation_id' => $operationId,
            'scope_key' => $scope->key(),
        ] + $options;
        $plan = $this->planner->plan($releaseId, $manifest, $planOptions);
        if (($plan['blockers'] ?? []) !== []) {
            throw new RuntimeException(
                'schema plan 存在 blockers，拒绝执行 DDL：' . $this->encode($plan['blockers'])
            );
        }

        $result = $this->executor->apply($releaseId, $plan, [
            'scope_key' => $scope->key(),
            'operation_id' => $operationId,
        ] + $options);

        $residual = $this->planner->plan($releaseId, $manifest, [
            'persist' => false,
            'operation_id' => $operationId,
            'scope_key' => $scope->key(),
        ] + $options);
        if (($residual['expand'] ?? []) !== [] || ($residual['contract'] ?? []) !== []) {
            // 逐条落日志（首 20 条）：不做汇总丢弃，运维需要知道是哪张表/哪个列反复残差。
            $residualRows = \array_merge(
                \is_array($residual['expand'] ?? null) ? $residual['expand'] : [],
                \is_array($residual['contract'] ?? null) ? $residual['contract'] : [],
            );
            foreach (\array_slice($residualRows, 0, 20) as $row) {
                \w_log_warning(
                    'RSSE residual change: ' . $this->encode($row),
                    [],
                    'schema_release.log'
                );
            }
            if (\count($residualRows) > 20) {
                \w_log_warning(
                    'RSSE residual change total: ' . \count($residualRows) . ' rows (first 20 logged)',
                    [],
                    'schema_release.log'
                );
            }
            throw new RuntimeException('apply 后仍有残余 expand/contract 变更，拒绝 commit（共 '
                . \count($residualRows) . ' 条，明细见 schema_release.log）');
        }

        $this->baselineBuilder->commit($releaseId, [
            'snapshot_id' => $snapshotKey,
            'baseline' => false,
            'signature' => $options['signature'] ?? null,
        ]);

        return $result + [
            'release_id' => $releaseId,
            'status' => SchemaRelease::STATUS_APPLIED,
            'snapshot_key' => $snapshotKey,
            'snapshot_id' => $snapshot['snapshot_id'] ?? null,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function rollback(string $releaseId, string $targetReleaseId, array $options = []): array
    {
        $releaseId = \trim($releaseId);
        $targetReleaseId = \trim($targetReleaseId);
        if ($releaseId === '' || $targetReleaseId === '') {
            throw new InvalidArgumentException('rollback 需要 release id 与 target release id');
        }

        $snapshot = $this->latestReadySnapshot($targetReleaseId);
        if ($snapshot === null) {
            throw new RuntimeException('target release 缺少 ready 快照，L2 回滚被阻断：' . $targetReleaseId);
        }
        $snapshotKey = \trim((string)($snapshot[SchemaSnapshot::schema_fields_SNAPSHOT_KEY] ?? ''));
        if ($snapshotKey === '') {
            $snapshotKey = (string)($snapshot[SchemaSnapshot::schema_fields_ID] ?? '');
        }
        if ($snapshotKey === '') {
            throw new RuntimeException('target release 快照缺少 snapshot_key：' . $targetReleaseId);
        }

        $this->snapshotService->restore($snapshotKey, $options);

        // 快照恢复后当前 release 行可能不存在（快照早于该 release），存在才标记。
        $current = $this->newModel($this->releaseModel)->load(
            SchemaRelease::schema_fields_RELEASE_ID,
            $releaseId,
            true,
        );
        if ($current->getId()) {
            $current->setData(SchemaRelease::schema_fields_STATUS, SchemaRelease::STATUS_ROLLED_BACK);
            $current->save();
        }
        $target = $this->newModel($this->releaseModel)->load(
            SchemaRelease::schema_fields_RELEASE_ID,
            $targetReleaseId,
            true,
        );
        if ($target->getId()
            && (string)$target->getData(SchemaRelease::schema_fields_STATUS) !== SchemaRelease::STATUS_APPLIED
        ) {
            $target->setData(SchemaRelease::schema_fields_STATUS, SchemaRelease::STATUS_APPLIED);
            $target->save();
        }

        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => (string)($options['operation_id'] ?? 'rollback-' . $releaseId),
            'scope_key' => $this->releaseScopeKey($releaseId, $options),
            'node_id' => 'rollback:release',
            'state' => SchemaOperationEvent::STATE_COMPENSATED,
            'checksum' => (string)($snapshot[SchemaSnapshot::schema_fields_CHECKSUM] ?? ''),
            'detail_json' => $this->encode([
                'reason' => 'rollback',
                'target_release_id' => $targetReleaseId,
                'snapshot_id' => $snapshotKey,
                'rollback_level' => self::ROLLBACK_LEVEL_L2,
            ]),
            'actor' => 'cli',
            'source' => 'cli',
        ]);

        return [
            'release_id' => $releaseId,
            'target_release_id' => $targetReleaseId,
            'snapshot_id' => $snapshotKey,
            'rollback_level' => self::ROLLBACK_LEVEL_L2,
            'status' => SchemaOperationEvent::STATE_COMPENSATED,
        ];
    }

    /**
     * 只写 planned release 及其 checkpoint/scope/owner/journal，不跑 DDL、不验证目标 schema。
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $options
     */
    private function ensurePlannedRelease(
        string $releaseId,
        array $manifest,
        SchemaScope $scope,
        array $options,
        string $operationId,
    ): void {
        $modules = $this->normalizeModules($manifest);
        $scopeKey = $scope->key();
        $manifestChecksum = (string)($manifest['checksum'] ?? $this->hash($modules));
        $schemaEpoch = $this->nextSchemaEpoch();

        $release = $this->newModel($this->releaseModel);
        $release->setData([
            SchemaRelease::schema_fields_RELEASE_ID => $releaseId,
            SchemaRelease::schema_fields_SCHEMA_EPOCH => $schemaEpoch,
            SchemaRelease::schema_fields_GIT_COMMIT => (string)($options['git_commit'] ?? $manifest['git_commit'] ?? ''),
            SchemaRelease::schema_fields_GIT_TAG => $this->nullableString($options['git_tag'] ?? $manifest['git_tag'] ?? null),
            SchemaRelease::schema_fields_DEPLOY_VERSION => $this->nullableString($options['deploy_version'] ?? $manifest['deploy_version'] ?? null),
            SchemaRelease::schema_fields_MANIFEST_JSON => $this->encode($manifest),
            SchemaRelease::schema_fields_MANIFEST_CHECKSUM => $manifestChecksum,
            SchemaRelease::schema_fields_SIGNATURE => $this->signatureValue($options['signature'] ?? null),
            SchemaRelease::schema_fields_ROLLBACK_POLICY => (string)($options['rollback_policy'] ?? 'L2'),
            SchemaRelease::schema_fields_STATUS => self::STATUS_PLANNED,
            SchemaRelease::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ]);
        $release->save();

        $scopeRow = $this->newModel($this->scopeModel);
        $existingScope = $scopeRow->where(SchemaScopeRecord::schema_fields_SCOPE_KEY, $scopeKey)->select()->fetchArray();
        if ($existingScope === []) {
            $scopeRow->setData([
                SchemaScopeRecord::schema_fields_SCOPE_KEY => $scopeKey,
                SchemaScopeRecord::schema_fields_CONNECTION_NAME => $scope->connectionName() !== '' ? $scope->connectionName() : 'default',
                SchemaScopeRecord::schema_fields_DATABASE_NAME => $scope->databaseName(),
                SchemaScopeRecord::schema_fields_SCHEMA_NAME => $scope->schemaName(),
                SchemaScopeRecord::schema_fields_SHARD_KEY => $scope->shardKey(),
                SchemaScopeRecord::schema_fields_TENANT_KEY => $scope->tenantKey(),
                SchemaScopeRecord::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
            ]);
            $scopeRow->save();
        }

        $owners = [];
        foreach ($modules as $moduleName => $module) {
            $checkpoint = $this->newModel($this->checkpointModel);
            $checkpoint->setData([
                SchemaCheckpoint::schema_fields_RELEASE_ID => $releaseId,
                SchemaCheckpoint::schema_fields_MODULE_NAME => $moduleName,
                SchemaCheckpoint::schema_fields_MODULE_VERSION => $module['version'],
                SchemaCheckpoint::schema_fields_SCOPE_KEY => $scopeKey,
                SchemaCheckpoint::schema_fields_FORMAT => 2,
                SchemaCheckpoint::schema_fields_CHECKSUM => $module['checksum'],
                SchemaCheckpoint::schema_fields_TABLES_JSON => $this->checkpointTablesJson($module),
                SchemaCheckpoint::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
            ]);
            $checkpoint->save();

            foreach ($module['tables'] as $tableName => $fingerprint) {
                if (isset($owners[$tableName]) && $owners[$tableName] !== $moduleName) {
                    throw new RuntimeException(\sprintf(
                        '表 %s 同时被模块 %s 与 %s 声明',
                        $tableName,
                        $owners[$tableName],
                        $moduleName,
                    ));
                }
                if (isset($owners[$tableName])) {
                    continue;
                }
                $owners[$tableName] = $moduleName;
                $this->writeTableOwner($scopeKey, $tableName, $moduleName, $fingerprint);
            }
        }

        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scopeKey,
            'node_id' => 'release:planned',
            'state' => SchemaOperationEvent::STATE_PLANNED,
            'checksum' => $manifestChecksum,
            'detail_json' => $this->encode([
                'modules' => \count($modules),
                'tables' => \count($owners),
                'baseline' => false,
            ]),
            'actor' => 'cli',
            'source' => 'cli',
        ]);
    }

    /** @param array<string, mixed> $options */
    private function ensurePlannedSignature(string $releaseId, array $options): void
    {
        $signature = $this->signatureValue($options['signature'] ?? null);
        if ($signature === null) {
            return;
        }
        $release = $this->newModel($this->releaseModel)->load(
            SchemaRelease::schema_fields_RELEASE_ID,
            $releaseId,
            true,
        );
        if (!$release->getId()) {
            return;
        }
        $existing = $release->getData(SchemaRelease::schema_fields_SIGNATURE);
        if ($existing !== null && \trim((string)$existing) !== '') {
            return;
        }
        $release->setData(SchemaRelease::schema_fields_SIGNATURE, $signature);
        $release->save();
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, array{version: string, tables: array<string, string>, schemas?: array<string, mixed>, checksum: string}>
     */
    private function normalizeModules(array $manifest): array
    {
        $modules = $manifest['modules'] ?? null;
        if (!\is_array($modules) || $modules === []) {
            throw new InvalidArgumentException('manifest.modules 不能为空');
        }
        $normalized = [];
        foreach ($modules as $moduleName => $module) {
            $moduleName = (string)$moduleName;
            $tables = \is_array($module) ? ($module['tables'] ?? null) : null;
            if ($moduleName === '' || !\is_array($tables) || $tables === []) {
                throw new InvalidArgumentException('manifest module 缺少 tables：' . $moduleName);
            }
            $tableMap = [];
            foreach ($tables as $tableName => $fingerprint) {
                $tableName = (string)$tableName;
                $fingerprint = (string)$fingerprint;
                if ($tableName === '' || $fingerprint === '') {
                    throw new InvalidArgumentException('模块 ' . $moduleName . ' 的 tables 条目非法');
                }
                $tableMap[$tableName] = $fingerprint;
            }
            $normalized[$moduleName] = [
                'version' => (string)($module['version'] ?? '0.0.0'),
                'tables' => $tableMap,
                'checksum' => (string)($module['checksum'] ?? $this->hash($tableMap)),
            ];
            if (isset($module['schemas']) && \is_array($module['schemas'])) {
                $normalized[$moduleName]['schemas'] = $module['schemas'];
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $options */
    private function resolveManifest(array $options): array
    {
        $manifest = $options['manifest'] ?? null;
        if (\is_array($manifest) && $manifest !== []) {
            return $manifest;
        }
        $modules = $options['modules'] ?? [];

        return $this->manifestBuilder->build(\is_array($modules) ? $modules : []);
    }

    /** @param array<string, mixed> $options */
    private function resolveScope(array $options): SchemaScope
    {
        $config = $this->connectionFactory->getConfigProvider();
        $connectionName = (string)($options['connection_name'] ?? '');
        if ($connectionName === '') {
            $connectionName = $config->getConnectionName() !== '' ? $config->getConnectionName() : 'default';
        }

        return new SchemaScope(
            $connectionName,
            (string)($options['database_name'] ?? $config->getDatabase()),
            '',
            '',
            '',
        );
    }

    /** @return array<string, mixed>|null */
    private function findRelease(string $releaseId): ?array
    {
        if ($releaseId === '') {
            return null;
        }
        $rows = $this->newModel($this->releaseModel)
            ->where(SchemaRelease::schema_fields_RELEASE_ID, $releaseId)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows[0] ?? null;
    }

    private function hasAppliedRelease(): bool
    {
        $rows = $this->newModel($this->releaseModel)
            ->where(SchemaRelease::schema_fields_STATUS, SchemaRelease::STATUS_APPLIED)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows !== [];
    }

    /** @return array<string, mixed>|null */
    private function latestReadySnapshot(string $releaseId): ?array
    {
        $snapshotModel = $this->snapshotModel ?? \Weline\Framework\Manager\ObjectManager::getInstance(SchemaSnapshot::class);
        $rows = $this->newModel($snapshotModel)
            ->where(SchemaSnapshot::schema_fields_RELEASE_ID, $releaseId)
            ->where(SchemaSnapshot::schema_fields_STATUS, SchemaSnapshot::STATUS_READY)
            ->order(SchemaSnapshot::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $options */
    private function releaseScopeKey(string $releaseId, array $options): string
    {
        $rows = $this->newModel($this->checkpointModel)
            ->where(SchemaCheckpoint::schema_fields_RELEASE_ID, $releaseId)
            ->limit(1)
            ->select()
            ->fetchArray();
        $scopeKey = (string)($rows[0][SchemaCheckpoint::schema_fields_SCOPE_KEY] ?? '');
        if ($scopeKey !== '') {
            return $scopeKey;
        }
        if (isset($options['scope_key']) && (string)$options['scope_key'] !== '') {
            return (string)$options['scope_key'];
        }

        return $this->resolveScope($options)->key();
    }

    private function nextSchemaEpoch(): int
    {
        $rows = $this->newModel($this->releaseModel)
            ->order(SchemaRelease::schema_fields_SCHEMA_EPOCH, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        return (int)($rows[0][SchemaRelease::schema_fields_SCHEMA_EPOCH] ?? 0) + 1;
    }

    private function writeTableOwner(string $scopeKey, string $tableName, string $moduleName, string $fingerprint): void
    {
        if ($this->tableOwnerModel === null) {
            return;
        }
        $model = $this->newModel($this->tableOwnerModel);
        $existing = $model->where(TableOwner::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(TableOwner::schema_fields_TABLE_NAME, $tableName)
            ->select()
            ->fetchArray();
        if ($existing !== []) {
            $owner = (string)($existing[0][TableOwner::schema_fields_OWNER_MODULE] ?? '');
            if ($owner !== '' && $owner !== $moduleName) {
                throw new RuntimeException(\sprintf('表 %s 已归属模块 %s，不能再归属 %s', $tableName, $owner, $moduleName));
            }
            return;
        }
        $model->setData([
            TableOwner::schema_fields_SCOPE_KEY => $scopeKey,
            TableOwner::schema_fields_TABLE_NAME => $tableName,
            TableOwner::schema_fields_OWNER_MODULE => $moduleName,
            TableOwner::schema_fields_SOURCE => 'model',
            TableOwner::schema_fields_CHECKSUM => $fingerprint,
            TableOwner::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ]);
        $model->save();
    }

    /** @param array<string, mixed> $module */
    private function checkpointTablesJson(array $module): string
    {
        $payload = ['tables' => $module['tables']];
        if (isset($module['schemas'])) {
            $payload['schemas'] = $module['schemas'];
        }

        return $this->encode($payload);
    }

    private function signatureValue(mixed $signature): ?string
    {
        if ($signature === null) {
            return null;
        }
        if (\is_array($signature)) {
            return $signature === [] ? null : $this->encode($signature);
        }
        $signature = \trim((string)$signature);

        return $signature === '' ? null : $signature;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = \trim((string)$value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string)\json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        return \hash('sha256', $this->encode($data));
    }

    /**
     * @template T of object
     * @param T $prototype
     * @return T
     */
    private function newModel(object $prototype): object
    {
        $model = clone $prototype;
        $model->setConnection($this->connectionFactory);
        $model->__init();
        $model->clear();

        return $model;
    }
}
