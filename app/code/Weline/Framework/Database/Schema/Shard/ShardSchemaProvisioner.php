<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Shard;

use Throwable;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\PgsqlSchemaIndexNormalizer;
use Weline\Framework\Database\Schema\SchemaCheckpointIdentity;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutorInterface;
use Weline\Framework\Database\Schema\SchemaReaderInterface;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * 通用分片 DDL Provisioner：只做 diff→executor→fingerprint，不跑业务 DML。
 * 失败进入 maintenance/failed，不删表；单站失败不影响其他站（调用方隔离）。
 */
final class ShardSchemaProvisioner implements ShardSchemaProvisionerInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ShardSchemaFamilyProviderRegistry $registry,
        private readonly SchemaReaderInterface $dbSchemaReader,
        private readonly SchemaDiffEngine $diffEngine,
        private readonly SchemaMigrationExecutorInterface $executor,
        private readonly PgsqlSchemaIndexNormalizer $pgsqlIndexNormalizer = new PgsqlSchemaIndexNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $context 透传 SchemaMigrationExecutor（operation_id 等）
     */
    public function provision(string $familyCode, string $shardKey, array $context = []): ShardProvisionResult
    {
        $shardKey = trim($shardKey);
        if ($familyCode === '' || $shardKey === '') {
            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: __('family_code 与 shard_key 均不能为空'),
            );
        }

        $provider = $this->registry->get($familyCode);
        if ($provider === null) {
            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: __('未注册的 shard family：%{1}', [$familyCode]),
            );
        }

        try {
            $schemas = $provider->getTableSchemasForShard($shardKey);
        } catch (Throwable $e) {
            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: $e->getMessage(),
            );
        }

        if ($schemas === []) {
            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: __('family %{1} shard %{2} 未声明任何表', [$familyCode, $shardKey]),
            );
        }

        $seen = [];
        foreach ($schemas as $schema) {
            if (!$schema instanceof TableSchema) {
                return new ShardProvisionResult(
                    familyCode: $familyCode,
                    shardKey: $shardKey,
                    status: ShardProvisionResult::STATUS_FAILED,
                    fingerprint: '',
                    errorMessage: __('getTableSchemasForShard 必须返回 TableSchema 列表'),
                );
            }
            $key = SchemaCheckpointIdentity::qualifiedTableName($schema->tableName);
            if (isset($seen[$key])) {
                return new ShardProvisionResult(
                    familyCode: $familyCode,
                    shardKey: $shardKey,
                    status: ShardProvisionResult::STATUS_FAILED,
                    fingerprint: '',
                    errorMessage: __('分片 %{1} 重复声明表 %{2}', [$shardKey, $schema->tableName]),
                );
            }
            $seen[$key] = true;
        }

        $connector = $this->connectionFactory->getConnector();
        $databaseType = strtolower($connector->getConfigProvider()->getDbType());
        $runtimeQualifiers = SchemaCheckpointIdentity::runtimeQualifiers($connector);

        /** @var list<SchemaDiffOp> $ops */
        $ops = [];
        /** @var array<string, array{before: string, after: string}> $tableFingerprints */
        $tableFingerprints = [];
        /** @var array<string, string> $declaredFingerprints */
        $declaredFingerprints = [];
        $tableNames = [];

        try {
            foreach ($schemas as $declared) {
                IndexDefinitionContract::assertAdapterLimits($connector, $declared->indexes);
                $actual = $this->dbSchemaReader->readTable($connector, $declared->tableName);
                if ($databaseType === 'pgsql' && $actual instanceof TableSchema) {
                    $actual = $this->pgsqlIndexNormalizer->normalize($connector, $declared, $actual);
                }
                $before = $this->schemaFingerprint($actual, true, $runtimeQualifiers);
                $after = $this->schemaFingerprint($declared, true, $runtimeQualifiers);
                $tableFingerprints[$declared->tableName] = [
                    'before' => $before,
                    'after' => $after,
                ];
                $declaredFingerprints[$declared->tableName] = $after;
                $tableNames[] = $declared->tableName;
                $diffOps = $this->diffEngine->diff(
                    $declared,
                    $actual,
                    $databaseType,
                    static fn(string $indexName): string => IndexDefinitionContract::physicalIdentity(
                        $connector,
                        $declared->tableName,
                        $indexName,
                    ),
                );
                foreach ($diffOps as $op) {
                    $ops[] = $op;
                }
            }

            $shardFingerprint = $this->composeShardFingerprint($declaredFingerprints);
            $executeContext = array_merge($context, [
                'family' => $familyCode,
                'shard_key' => $shardKey,
                'table_fingerprints' => $tableFingerprints,
            ]);
            $this->executor->execute($connector, $ops, $executeContext);

            $this->assertPostProvisionConverged($connector, $schemas, $databaseType);

            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_READY,
                fingerprint: $shardFingerprint,
                tableNames: $tableNames,
                tableFingerprints: $declaredFingerprints,
                ops: $ops,
            );
        } catch (Throwable $e) {
            $partialFingerprint = $declaredFingerprints === []
                ? ''
                : $this->composeShardFingerprint($declaredFingerprints);

            return new ShardProvisionResult(
                familyCode: $familyCode,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_MAINTENANCE,
                fingerprint: $partialFingerprint,
                tableNames: $tableNames,
                tableFingerprints: $declaredFingerprints,
                ops: $ops,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param list<TableSchema> $schemas
     */
    private function assertPostProvisionConverged(
        ConnectorInterface $connector,
        array $schemas,
        string $databaseType,
    ): void {
        foreach ($schemas as $declared) {
            $actual = $this->dbSchemaReader->readTable($connector, $declared->tableName);
            if ($databaseType === 'pgsql' && $actual instanceof TableSchema) {
                $actual = $this->pgsqlIndexNormalizer->normalize($connector, $declared, $actual);
            }
            $remaining = $this->diffEngine->diff(
                $declared,
                $actual,
                $databaseType,
                static fn(string $indexName): string => IndexDefinitionContract::physicalIdentity(
                    $connector,
                    $declared->tableName,
                    $indexName,
                ),
            );
            if ($remaining !== []) {
                $kinds = array_map(static fn(SchemaDiffOp $op): string => $op->kind, $remaining);
                throw new \RuntimeException(__(
                    '分片表 %{1} provision 后仍有未收敛 diff：%{2}',
                    [$declared->tableName, implode(',', $kinds)],
                ));
            }
        }
    }

    /**
     * @param array<string, string> $tableFingerprints
     */
    private function composeShardFingerprint(array $tableFingerprints): string
    {
        ksort($tableFingerprints);
        return hash(
            'sha256',
            (string)json_encode(
                $tableFingerprints,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }

    /** @param list<string> $runtimeQualifiers */
    private function schemaFingerprint(?TableSchema $schema, bool $canonical, array $runtimeQualifiers): string
    {
        if ($schema === null) {
            return hash('sha256', 'absent');
        }
        if ($canonical) {
            $schema = SchemaCheckpointIdentity::schema($schema, $runtimeQualifiers);
        }

        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_object($value)) {
                $value = get_object_vars($value);
                unset($value['modelClass']);
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $normalize($item);
                }
            }
            return $value;
        };

        return hash('sha256', (string)json_encode(
            $normalize($schema),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }
}
