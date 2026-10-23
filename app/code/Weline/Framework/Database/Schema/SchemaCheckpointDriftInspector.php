<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Read-only drift detection: declared SchemaManifest checksums vs stored RSSE
 * SchemaCheckpoint rows.
 */
final class SchemaCheckpointDriftInspector
{
    /**
     * @param list<string>|null $moduleFilter
     * @return array{
     *   clean: bool,
     *   drifts: list<array{
     *     module: string,
     *     version: string,
     *     suggested_version: ?string,
     *     changed_tables: list<string>,
     *     added_tables: list<string>,
     *     removed_tables: list<string>,
     *     existing_checksum: ?string,
     *     expected_checksum: string
     *   }>,
     *   checked_modules: int
     * }
     */
    public function inspect(?array $moduleFilter = null): array
    {
        $filter = [];
        if (is_array($moduleFilter)) {
            foreach ($moduleFilter as $name) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $filter[$name] = true;
                }
            }
        }

        $builder = ObjectManager::getInstance(SchemaManifestBuilder::class);
        $manifest = $builder->build($filter === [] ? [] : array_keys($filter));
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);

        $checkpointModel = new SchemaCheckpoint();
        $checkpointModel->setConnection($connectionFactory);

        $allExpectedTables = [];
        foreach ((array)($manifest['modules'] ?? []) as $module) {
            if (is_array($module)) {
                foreach (array_keys((array)($module['tables'] ?? [])) as $tableName) {
                    $allExpectedTables[(string)$tableName] = true;
                }
            }
        }

        $drifts = [];
        $checked = 0;
        foreach ((array)($manifest['modules'] ?? []) as $moduleName => $module) {
            $moduleName = (string)$moduleName;
            if (!is_array($module)) {
                continue;
            }
            $moduleVersion = trim((string)($module['version'] ?? ''));
            if ($moduleVersion === '' || !preg_match('/^\d+\.\d+\.\d+/', $moduleVersion)) {
                continue;
            }
            $checked++;

            $rows = (clone $checkpointModel)->clearData()->reset()
                ->where(SchemaCheckpoint::schema_fields_MODULE_NAME, $moduleName)
                ->where(SchemaCheckpoint::schema_fields_MODULE_VERSION, $moduleVersion)
                ->order(SchemaCheckpoint::schema_fields_ID, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            $existing = $rows[0] ?? null;
            if (!is_array($existing)) {
                // No checkpoint yet is not drift for CI; only mismatch is.
                continue;
            }

            $expectedChecksum = (string)($module['checksum'] ?? '');
            if ($expectedChecksum === '') {
                $expectedChecksum = hash(
                    'sha256',
                    (string)json_encode($module['tables'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }
            if (hash_equals((string)($existing[SchemaCheckpoint::schema_fields_CHECKSUM] ?? ''), $expectedChecksum)) {
                continue;
            }

            $stored = json_decode((string)($existing[SchemaCheckpoint::schema_fields_TABLES_JSON] ?? ''), true);
            $storedTableMap = is_array($stored['tables'] ?? null) ? $stored['tables'] : [];
            $expectedTableMap = (array)($module['tables'] ?? []);
            $storedTables = array_keys($storedTableMap);
            $expectedTables = array_keys($expectedTableMap);
            $added = array_values(array_diff($expectedTables, $storedTables));
            $removed = array_values(array_diff($storedTables, $expectedTables));
            $changed = [];
            foreach (array_intersect($storedTables, $expectedTables) as $tableName) {
                if ((string)($storedTableMap[$tableName] ?? '') !== (string)($expectedTableMap[$tableName] ?? '')) {
                    $changed[] = (string)$tableName;
                }
            }
            $moved = array_values(array_filter(
                $removed,
                static fn(string $tableName): bool => isset($allExpectedTables[$tableName]),
            ));
            if ($added === [] && $changed === [] && count($moved) === count($removed)) {
                // Table ownership moved to another equivalent module; not schema drift.
                continue;
            }

            $drifts[] = [
                'module' => $moduleName,
                'version' => $moduleVersion,
                'suggested_version' => $this->suggestNextVersion($moduleVersion),
                'changed_tables' => $changed,
                'added_tables' => $added,
                'removed_tables' => $removed,
                'existing_checksum' => (string)($existing[SchemaCheckpoint::schema_fields_CHECKSUM] ?? ''),
                'expected_checksum' => $expectedChecksum,
            ];
        }

        return [
            'clean' => $drifts === [],
            'drifts' => $drifts,
            'checked_modules' => $checked,
        ];
    }

    private function suggestNextVersion(string $version): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1] . '.' . $matches[2] . '.' . ((int)$matches[3] + 1);
    }
}
