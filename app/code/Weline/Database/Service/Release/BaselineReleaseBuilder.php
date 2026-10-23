<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Model\Release\TableOwner;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Manager\ObjectManager;

/**
 * Builds the first RSSE baseline release from a schema manifest.
 *
 * build() stops at planned; only commit() may mark the release applied.
 * Actual-schema drift always blocks: there is no silent accept-drift path.
 */
final class BaselineReleaseBuilder
{
    private const STATUS_PLANNED = 'planned';
    private const STATUS_APPLIED = 'applied';
    private const DEFAULT_ROLLBACK_POLICY = 'L2';

    public function __construct(
        private readonly ReleaseTableInstaller $installer,
        private readonly ConnectionFactory $connectionFactory,
        private readonly DbSchemaReader $schemaReader,
        private readonly SchemaFingerprint $schemaFingerprint,
        private readonly SchemaJournal $journal,
        private readonly SchemaRelease $releaseModel,
        private readonly SchemaCheckpoint $checkpointModel,
        private readonly SchemaScopeRecord $scopeModel,
        private readonly TableOwner $tableOwnerModel,
        private readonly ?SchemaSnapshot $snapshotModel = null,
        private readonly ?DatabaseSnapshotService $snapshotService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $options
     * @return array{release_id: string, schema_epoch: int, tables: int, modules: int, scope_key: string, blockers: list<array<string, string>>}
     */
    public function build(string $releaseId, array $manifest, array $options = []): array
    {
        $releaseId = \trim($releaseId);
        if ($releaseId === '') {
            throw new InvalidArgumentException('release_id 不能为空');
        }
        $modules = $this->normalizeModules($manifest);

        $this->installer->install();
        $connector = $this->connectionFactory->getConnector();

        $blockers = [];
        if ((bool)($options['verify_actual'] ?? true)) {
            foreach ($modules as $moduleName => $module) {
                foreach ($module['tables'] as $tableName => $expectedFingerprint) {
                    $actual = $this->schemaReader->readTable($connector, $tableName);
                    if ($actual === null) {
                        $blockers[] = [
                            'module' => $moduleName,
                            'table' => $tableName,
                            'reason' => 'missing_table',
                        ];
                        continue;
                    }
                    $actualFingerprint = $this->schemaFingerprint->fingerprint($actual);
                    if (!\hash_equals($expectedFingerprint, $actualFingerprint)) {
                        $blockers[] = [
                            'module' => $moduleName,
                            'table' => $tableName,
                            'reason' => 'fingerprint_mismatch',
                            'expected' => $expectedFingerprint,
                            'actual' => $actualFingerprint,
                        ];
                    }
                }
            }
        }
        if ($blockers !== []) {
            throw new RuntimeException(
                'Schema baseline 与实际库不一致，拒绝写入 planned：' . \json_encode(
                    $blockers,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                )
            );
        }

        $scope = $this->resolveScope($options);
        $scopeKey = $scope->key();
        $schemaEpoch = $this->nextSchemaEpoch();
        $manifestChecksum = (string)($manifest['checksum'] ?? $this->hash($modules));
        $storedManifest = $this->storedManifest($manifest, $options);

        $release = $this->newModel($this->releaseModel);
        $release->setData([
            SchemaRelease::schema_fields_RELEASE_ID => $releaseId,
            SchemaRelease::schema_fields_SCHEMA_EPOCH => $schemaEpoch,
            SchemaRelease::schema_fields_GIT_COMMIT => (string)($options['git_commit'] ?? $manifest['git_commit'] ?? ''),
            SchemaRelease::schema_fields_GIT_TAG => $this->nullableString($options['git_tag'] ?? $manifest['git_tag'] ?? null),
            SchemaRelease::schema_fields_DEPLOY_VERSION => $this->nullableString($options['deploy_version'] ?? $manifest['deploy_version'] ?? null),
            SchemaRelease::schema_fields_MANIFEST_JSON => $this->encode($storedManifest),
            SchemaRelease::schema_fields_MANIFEST_CHECKSUM => $manifestChecksum,
            SchemaRelease::schema_fields_SIGNATURE => $this->signatureValue($options['signature'] ?? null),
            SchemaRelease::schema_fields_ROLLBACK_POLICY => (string)($options['rollback_policy'] ?? self::DEFAULT_ROLLBACK_POLICY),
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

        $tableOwners = [];
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
                $ownerModule = $tableOwners[$tableName] ?? null;
                if ($ownerModule !== null && $ownerModule !== $moduleName) {
                    throw new RuntimeException(\sprintf(
                        '表 %s 同时被模块 %s 与 %s 声明',
                        $tableName,
                        $ownerModule,
                        $moduleName,
                    ));
                }
                if ($ownerModule === $moduleName) {
                    continue;
                }
                $tableOwners[$tableName] = $moduleName;
                $this->writeTableOwner($scopeKey, $tableName, $moduleName, $fingerprint);
            }
        }

        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => 'baseline-' . $releaseId,
            'scope_key' => $scopeKey,
            'node_id' => 'baseline:release',
            'state' => 'planned',
            'checksum' => $manifestChecksum,
            'detail_json' => $this->encode([
                'modules' => \count($modules),
                'tables' => \count($tableOwners),
                'verify_actual' => (bool)($options['verify_actual'] ?? true),
                'baseline' => (bool)($options['baseline'] ?? false),
            ]),
            'actor' => 'cli',
            'source' => 'cli',
        ]);

        return [
            'release_id' => $releaseId,
            'schema_epoch' => $schemaEpoch,
            'tables' => \count($tableOwners),
            'modules' => \count($modules),
            'scope_key' => $scopeKey,
            'blockers' => [],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function commit(string $releaseId, array $options = []): void
    {
        $release = $this->newModel($this->releaseModel)->load(
            SchemaRelease::schema_fields_RELEASE_ID,
            \trim($releaseId),
            true,
        );
        if (!$release->getId()) {
            throw new RuntimeException('Release 不存在：' . $releaseId);
        }

        $status = (string)$release->getData(SchemaRelease::schema_fields_STATUS);
        if ($status === self::STATUS_APPLIED) {
            return;
        }
        if ($status !== self::STATUS_PLANNED) {
            throw new RuntimeException('Release 状态不是 planned，不能 commit：' . $status);
        }

        $manifest = $this->decode((string)$release->getData(SchemaRelease::schema_fields_MANIFEST_JSON));
        $snapshotId = \trim((string)(
            $options['snapshot_id']
            ?? $manifest['snapshot_id']
            ?? $manifest['db_snapshot']['snapshot_id']
            ?? ''
        ));
        if ($snapshotId === '') {
            throw new RuntimeException('缺少 snapshot_id，禁止把 release 标记为 applied');
        }
        $this->assertSnapshotUsable($snapshotId);

        $baseline = (bool)($options['baseline'] ?? ($manifest['baseline'] ?? false));
        $signature = $release->getData(SchemaRelease::schema_fields_SIGNATURE);
        $unsigned = $signature === null || \trim((string)$signature) === '';
        if ($unsigned && !$baseline) {
            throw new RuntimeException('release 没有签名且不是 baseline，禁止 applied');
        }
        if ($unsigned) {
            $manifest['baseline'] = true;
            $manifest['unsigned_baseline'] = true;
            $release->setData(SchemaRelease::schema_fields_MANIFEST_JSON, $this->encode($manifest));
        }

        $release->setData([
            SchemaRelease::schema_fields_STATUS => self::STATUS_APPLIED,
            SchemaRelease::schema_fields_APPLIED_AT => \date('Y-m-d H:i:s'),
        ]);
        $release->save();

        $detail = ['snapshot_id' => $snapshotId];
        if ($unsigned) {
            $detail['baseline'] = true;
            $detail['unsigned_baseline'] = true;
        }
        $this->journal->append([
            'release_id' => (string)$release->getData(SchemaRelease::schema_fields_RELEASE_ID),
            'operation_id' => 'baseline-' . $releaseId,
            'scope_key' => $this->releaseScopeKey($releaseId, $options),
            'node_id' => 'baseline:release',
            'state' => 'committed',
            'checksum' => (string)$release->getData(SchemaRelease::schema_fields_MANIFEST_CHECKSUM),
            'detail_json' => $this->encode($detail),
            'actor' => 'cli',
            'source' => 'cli',
        ]);
    }

    /** snapshot 必须真实存在且可恢复：查不到/状态不对/校验失败一律阻断。 */
    private function assertSnapshotUsable(string $snapshotId): void
    {
        $snapshotModel = $this->snapshotModel ?? ObjectManager::getInstance(SchemaSnapshot::class);
        $row = $this->findSnapshotRow($snapshotId, $snapshotModel);
        if ($row === null) {
            throw new RuntimeException('SchemaSnapshot 不存在：' . $snapshotId);
        }
        $status = (string)($row[SchemaSnapshot::schema_fields_STATUS] ?? '');
        if (!\in_array($status, [SchemaSnapshot::STATUS_READY, SchemaSnapshot::STATUS_RESTORED], true)) {
            throw new RuntimeException('SchemaSnapshot 状态不可用于 applied：' . $status);
        }
        if (\trim((string)($row[SchemaSnapshot::schema_fields_CHECKSUM] ?? '')) === '') {
            throw new RuntimeException('SchemaSnapshot checksum 为空：' . $snapshotId);
        }
        if ($this->snapshotService === null) {
            return;
        }
        $snapshotService = $this->snapshotService;
        $verifyKey = (string)($row[SchemaSnapshot::schema_fields_SNAPSHOT_KEY] ?? '');
        if ($verifyKey === '') {
            $verifyKey = $snapshotId;
        }
        try {
            $verified = $snapshotService->verify($verifyKey);
        } catch (\Throwable $exception) {
            throw new RuntimeException('SchemaSnapshot 校验异常：' . $exception->getMessage(), 0, $exception);
        }
        if (!$verified) {
            throw new RuntimeException('SchemaSnapshot 校验失败：' . $snapshotId);
        }
    }

    /** @return array<string, mixed>|null */
    private function findSnapshotRow(string $snapshotId, ?SchemaSnapshot $snapshotModel = null): ?array
    {
        $snapshotModel ??= $this->snapshotModel ?? ObjectManager::getInstance(SchemaSnapshot::class);
        if ($snapshotId === '') {
            return null;
        }
        $rows = $this->newModel($snapshotModel)
            ->where(SchemaSnapshot::schema_fields_SNAPSHOT_KEY, $snapshotId)
            ->limit(1)
            ->select()
            ->fetchArray();
        if ($rows === [] && \ctype_digit($snapshotId)) {
            $rows = $this->newModel($snapshotModel)
                ->where(SchemaSnapshot::schema_fields_ID, (int)$snapshotId)
                ->limit(1)
                ->select()
                ->fetchArray();
        }

        return $rows[0] ?? null;
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
            if ($moduleName === '' || !\is_array($module)) {
                throw new InvalidArgumentException('manifest.modules 条目非法');
            }
            $tables = $module['tables'] ?? null;
            if (!\is_array($tables) || $tables === []) {
                throw new InvalidArgumentException('模块 ' . $moduleName . ' 缺少 tables');
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
    private function resolveScope(array $options): SchemaScope
    {
        $config = $this->connectionFactory->getConfigProvider();
        $connectionName = (string)($options['connection_name'] ?? '');
        if ($connectionName === '') {
            $connectionName = $config->getConnectionName() !== '' ? $config->getConnectionName() : 'default';
        }
        $databaseName = (string)($options['database_name'] ?? $config->getDatabase());

        return new SchemaScope($connectionName, $databaseName, '', '', '');
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
        $model = $this->newModel($this->tableOwnerModel);
        $existing = $model->where(TableOwner::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(TableOwner::schema_fields_TABLE_NAME, $tableName)
            ->select()
            ->fetchArray();
        if ($existing !== []) {
            $existingOwner = (string)($existing[0][TableOwner::schema_fields_OWNER_MODULE] ?? '');
            if ($existingOwner !== '' && $existingOwner !== $moduleName) {
                throw new RuntimeException(\sprintf(
                    '表 %s 已归属模块 %s，不能再归属 %s',
                    $tableName,
                    $existingOwner,
                    $moduleName,
                ));
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

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function storedManifest(array $manifest, array $options): array
    {
        if (!\array_key_exists('snapshot_id', $manifest)) {
            $snapshotId = $this->nullableString($options['snapshot_id'] ?? null);
            if ($snapshotId !== null) {
                $manifest['snapshot_id'] = $snapshotId;
            }
        }
        if (!\array_key_exists('baseline', $manifest) && (bool)($options['baseline'] ?? false)) {
            $manifest['baseline'] = true;
        }

        return $manifest;
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
