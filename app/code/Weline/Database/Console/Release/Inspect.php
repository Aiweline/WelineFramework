<?php

declare(strict_types=1);

namespace Weline\Database\Console\Release;

use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\TableOwner;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;

/** 只读查看 RSSE release / checkpoint / scope / table owner 状态。 */
final class Inspect implements CommandInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaRelease $releaseModel,
        private readonly SchemaCheckpoint $checkpointModel,
        private readonly SchemaScopeRecord $scopeModel,
        private readonly TableOwner $tableOwnerModel,
    ) {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $releaseId = \trim((string)($args['release'] ?? ''));
        $format = \strtolower(\trim((string)($args['format'] ?? 'text')));

        $releaseQuery = $this->newModel($this->releaseModel);
        if ($releaseId !== '') {
            $releaseQuery->where(SchemaRelease::schema_fields_RELEASE_ID, $releaseId);
        }
        $releaseRows = $releaseQuery
            ->order(SchemaRelease::schema_fields_SCHEMA_EPOCH, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $release = $releaseRows[0] ?? null;

        $checkpoints = [];
        if ($release !== null) {
            $checkpoints = $this->newModel($this->checkpointModel)
                ->where(SchemaCheckpoint::schema_fields_RELEASE_ID, (string)$release[SchemaRelease::schema_fields_RELEASE_ID])
                ->order(SchemaCheckpoint::schema_fields_MODULE_NAME, 'ASC')
                ->select()
                ->fetchArray();
        }

        $scopes = $this->newModel($this->scopeModel)
            ->order(SchemaScopeRecord::schema_fields_SCOPE_KEY, 'ASC')
            ->select()
            ->fetchArray();
        $owners = $this->newModel($this->tableOwnerModel)
            ->select()
            ->fetchArray();

        $payload = $this->buildPayload($release, $checkpoints, $scopes, $owners);
        $output = $format === 'json'
            ? (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : $this->renderText($payload);

        echo $output . PHP_EOL;

        return $output;
    }

    public function tip(): string
    {
        return __('只读查看 RSSE release、checkpoint、scope 与 table owner 状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('release:inspect', $this->tip(), [
            '--release' => __('指定 release_id，缺省显示最新 release'),
            '--format' => __('输出格式：text（默认）或 json'),
        ], [], [
            'php bin/w release:inspect',
            'php bin/w release:inspect --release=rel-20261023-abcdef --format=json',
        ]);
    }

    /**
     * @param array<string, mixed>|null $release
     * @param list<array<string, mixed>> $checkpoints
     * @param list<array<string, mixed>> $scopes
     * @param list<array<string, mixed>> $owners
     * @return array<string, mixed>
     */
    private function buildPayload(?array $release, array $checkpoints, array $scopes, array $owners): array
    {
        $checkpointPayload = [];
        foreach ($checkpoints as $checkpoint) {
            $checkpointPayload[] = [
                'module' => (string)($checkpoint[SchemaCheckpoint::schema_fields_MODULE_NAME] ?? ''),
                'version' => (string)($checkpoint[SchemaCheckpoint::schema_fields_MODULE_VERSION] ?? ''),
                'checksum' => (string)($checkpoint[SchemaCheckpoint::schema_fields_CHECKSUM] ?? ''),
                'format' => (int)($checkpoint[SchemaCheckpoint::schema_fields_FORMAT] ?? 0),
                'tables' => $this->countTables((string)($checkpoint[SchemaCheckpoint::schema_fields_TABLES_JSON] ?? '')),
            ];
        }

        $scopePayload = [];
        foreach ($scopes as $scope) {
            $scopePayload[] = [
                'scope_key' => (string)($scope[SchemaScopeRecord::schema_fields_SCOPE_KEY] ?? ''),
                'connection_name' => (string)($scope[SchemaScopeRecord::schema_fields_CONNECTION_NAME] ?? ''),
                'database_name' => (string)($scope[SchemaScopeRecord::schema_fields_DATABASE_NAME] ?? ''),
                'schema_name' => (string)($scope[SchemaScopeRecord::schema_fields_SCHEMA_NAME] ?? ''),
                'shard_key' => (string)($scope[SchemaScopeRecord::schema_fields_SHARD_KEY] ?? ''),
                'tenant_key' => (string)($scope[SchemaScopeRecord::schema_fields_TENANT_KEY] ?? ''),
            ];
        }

        $ownersByModule = [];
        foreach ($owners as $owner) {
            $module = (string)($owner[TableOwner::schema_fields_OWNER_MODULE] ?? '');
            $ownersByModule[$module] = ($ownersByModule[$module] ?? 0) + 1;
        }
        \ksort($ownersByModule);

        return [
            'release' => $release === null ? null : [
                'release_id' => (string)($release[SchemaRelease::schema_fields_RELEASE_ID] ?? ''),
                'schema_epoch' => (int)($release[SchemaRelease::schema_fields_SCHEMA_EPOCH] ?? 0),
                'status' => (string)($release[SchemaRelease::schema_fields_STATUS] ?? ''),
                'git_commit' => (string)($release[SchemaRelease::schema_fields_GIT_COMMIT] ?? ''),
                'git_tag' => $release[SchemaRelease::schema_fields_GIT_TAG] ?? null,
                'deploy_version' => $release[SchemaRelease::schema_fields_DEPLOY_VERSION] ?? null,
                'manifest_checksum' => (string)($release[SchemaRelease::schema_fields_MANIFEST_CHECKSUM] ?? ''),
                'rollback_policy' => (string)($release[SchemaRelease::schema_fields_ROLLBACK_POLICY] ?? ''),
                'created_at' => $release[SchemaRelease::schema_fields_CREATED_AT] ?? null,
                'applied_at' => $release[SchemaRelease::schema_fields_APPLIED_AT] ?? null,
            ],
            'checkpoints' => $checkpointPayload,
            'scopes' => $scopePayload,
            'table_owners' => [
                'total' => \count($owners),
                'by_module' => $ownersByModule,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function renderText(array $payload): string
    {
        $lines = [];
        $release = $payload['release'];
        if ($release === null) {
            $lines[] = 'Release: (none)';
        } else {
            $lines[] = \sprintf(
                'Release: %s epoch=%d status=%s rollback=%s',
                $release['release_id'],
                $release['schema_epoch'],
                $release['status'],
                $release['rollback_policy'],
            );
            $lines[] = \sprintf(
                '  git=%s tag=%s deploy=%s',
                $release['git_commit'] !== '' ? $release['git_commit'] : '-',
                $release['git_tag'] ?? '-',
                $release['deploy_version'] ?? '-',
            );
            $lines[] = \sprintf(
                '  created_at=%s applied_at=%s',
                $release['created_at'] ?? '-',
                $release['applied_at'] ?? '-',
            );
        }

        $lines[] = 'Checkpoints:';
        if ($payload['checkpoints'] === []) {
            $lines[] = '  (none)';
        }
        foreach ($payload['checkpoints'] as $checkpoint) {
            $lines[] = \sprintf(
                '  %s version=%s format=%d tables=%d checksum=%s',
                $checkpoint['module'],
                $checkpoint['version'],
                $checkpoint['format'],
                $checkpoint['tables'],
                $checkpoint['checksum'],
            );
        }

        $lines[] = 'Scopes:';
        if ($payload['scopes'] === []) {
            $lines[] = '  (none)';
        }
        foreach ($payload['scopes'] as $scope) {
            $lines[] = \sprintf(
                '  %s connection=%s database=%s schema=%s shard=%s tenant=%s',
                $scope['scope_key'],
                $scope['connection_name'],
                $scope['database_name'],
                $scope['schema_name'],
                $scope['shard_key'],
                $scope['tenant_key'],
            );
        }

        $lines[] = \sprintf('Table owners: total=%d', $payload['table_owners']['total']);
        foreach ($payload['table_owners']['by_module'] as $module => $count) {
            $lines[] = \sprintf('  %s: %d', $module, $count);
        }

        return \implode(PHP_EOL, $lines);
    }

    private function countTables(string $tablesJson): int
    {
        if ($tablesJson === '') {
            return 0;
        }
        $decoded = \json_decode($tablesJson, true);
        if (!\is_array($decoded)) {
            return 0;
        }
        if (isset($decoded['tables']) && \is_array($decoded['tables'])) {
            return \count($decoded['tables']);
        }

        return \count($decoded);
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
