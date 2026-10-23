<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use Throwable;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Framework\Database\ConnectionFactory;

/**
 * One-way cutover: install RSSE tables, snapshot, write a verified baseline
 * release, and optionally archive legacy tables.
 *
 * No legacy code is read or executed. A verified snapshot is mandatory before
 * any legacy table is renamed.
 */
final class ReleaseCutoverService
{
    public function __construct(
        private readonly ReleaseTableInstaller $installer,
        private readonly ?SchemaManifestBuilder $manifestBuilder,
        private readonly BaselineReleaseBuilder $baselineBuilder,
        private readonly DatabaseSnapshotService $snapshotService,
        private readonly LegacyTableArchiver $archiver,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(string $releaseId, array $options = []): array
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            throw new InvalidArgumentException('release id 不能为空');
        }

        if (is_array($options['manifest'] ?? null)) {
            $manifest = $options['manifest'];
        } elseif ($this->manifestBuilder !== null) {
            $manifest = $this->manifestBuilder->build((array)($options['modules'] ?? []));
        } else {
            throw new InvalidArgumentException('cutover requires manifest or SchemaManifestBuilder');
        }

        $this->installer->install();
        $baseline = $this->baselineBuilder->build($releaseId, $manifest, [
            'verify_actual' => (bool)($options['verify_actual'] ?? true),
            'baseline' => true,
            'git_commit' => (string)($options['git_commit'] ?? ''),
            'git_tag' => $options['git_tag'] ?? null,
            'deploy_version' => $options['deploy_version'] ?? null,
            'rollback_policy' => (string)($options['rollback_policy'] ?? 'L2'),
        ]);

        $scope = $this->scope();
        $snapshot = $this->snapshotService->create($releaseId, $scope, [
            'operation_id' => (string)($options['operation_id'] ?? 'cutover-' . $releaseId),
        ]);
        $snapshotKey = trim((string)($snapshot['snapshot_key'] ?? ''));
        if ($snapshotKey === '') {
            throw new \RuntimeException('cutover snapshot did not return snapshot_key');
        }

        $this->baselineBuilder->commit($releaseId, [
            'snapshot_id' => $snapshotKey,
            'baseline' => true,
        ]);

        $archived = [];
        $legacyTables = array_values(array_filter(array_map('strval', (array)($options['legacy_tables'] ?? []))));
        if ($legacyTables !== [] && (bool)($options['archive_legacy'] ?? true)) {
            $archived = $this->archiver->archive(
                $legacyTables,
                $snapshotKey,
                (string)($options['archive_suffix'] ?? ''),
            );
        }

        return [
            'release_id' => $releaseId,
            'schema_epoch' => (int)($baseline['schema_epoch'] ?? 0),
            'tables' => (int)($baseline['tables'] ?? 0),
            'snapshot_key' => $snapshotKey,
            'archived' => $archived,
        ];
    }

    private function scope(): SchemaScope
    {
        $connector = $this->connectionFactory->getConnector();
        $config = $connector->getConfigProvider();

        return new SchemaScope(
            (string)$config->getConnectionName(),
            (string)$config->getDatabase(),
            '',
        );
    }
}
