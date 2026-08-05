<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Stage\SchemaDiffStage;

/**
 * Read-only drift detection: declared Model fingerprints vs stored checkpoints.
 * Reuses SchemaDiffStage::prepare fingerprint path (no second hash algorithm).
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

        /** @var SchemaDiffStage $stage */
        $stage = ObjectManager::make(SchemaDiffStage::class, [
            'moduleHandle' => ObjectManager::getInstance(Handle::class),
            'moduleReader' => ObjectManager::getInstance(ModuleFileReader::class),
            'connectionFactory' => ObjectManager::getInstance(ConnectionFactory::class),
            'schemaParser' => ObjectManager::getInstance(SchemaParser::class),
            'dbSchemaReader' => ObjectManager::getInstance(DbSchemaReader::class),
            'diffEngine' => ObjectManager::getInstance(SchemaDiffEngine::class),
            'executor' => ObjectManager::getInstance(SchemaMigrationExecutor::class),
            'printing' => ObjectManager::getInstance(Printing::class),
            'schemaProviderRegistry' => ObjectManager::getInstance(ShardSchemaFamilyProviderRegistry::class),
        ]);
        $stage->prepare([
            'operation_id' => 'schema-check-' . bin2hex(random_bytes(4)),
        ]);

        /** @var Migration $migration */
        $migration = ObjectManager::getInstance(Migration::class);
        $fingerprints = $stage->getModuleSchemaFingerprints();
        $fingerprintCandidates = $stage->getModuleSchemaFingerprintCandidates();
        $versions = $stage->getModuleVersions();
        $drifts = [];
        $checked = 0;

        foreach ($versions as $moduleName => $moduleVersion) {
            $moduleName = (string)$moduleName;
            $moduleVersion = trim((string)$moduleVersion);
            if ($filter !== [] && !isset($filter[$moduleName])) {
                continue;
            }
            if ($moduleVersion === '' || !preg_match('/^\d+\.\d+\.\d+/', $moduleVersion)) {
                continue;
            }
            $checked++;
            $tables = is_array($fingerprints[$moduleName] ?? null) ? $fingerprints[$moduleName] : [];
            $existing = $migration->getSchemaCheckpoint($moduleName, $moduleVersion);
            $format = (int)($existing['format'] ?? Migration::SCHEMA_CHECKPOINT_FORMAT_CURRENT);
            $expectedChecksum = $migration->schemaCheckpointChecksum($tables, $format);
            if ($existing === null) {
                // No checkpoint yet is not "drift" for CI — only mismatch on existing version.
                continue;
            }
            if (hash_equals((string)$existing['checksum'], $expectedChecksum)) {
                continue;
            }
            $candidates = is_array($fingerprintCandidates[$moduleName] ?? null)
                ? $fingerprintCandidates[$moduleName]
                : [];
            $compatible = false;
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateChecksum = $migration->schemaCheckpointChecksum($candidate, $format);
                if (hash_equals((string)$existing['checksum'], $candidateChecksum)) {
                    $compatible = true;
                    break;
                }
            }
            if ($compatible) {
                continue;
            }
            $diff = Migration::diffSchemaCheckpointTables(
                is_array($existing['tables'] ?? null) ? $existing['tables'] : [],
                $tables,
            );
            // Rebuild expected tables via checksum path may leave raw fingerprints unordered;
            // diff against payload tables when possible.
            $expectedPayloadTables = $tables;
            $drifts[] = [
                'module' => $moduleName,
                'version' => $moduleVersion,
                'suggested_version' => Migration::suggestNextModuleVersion($moduleVersion),
                'changed_tables' => $diff['changed'],
                'added_tables' => $diff['added'],
                'removed_tables' => $diff['removed'],
                'existing_checksum' => (string)$existing['checksum'],
                'expected_checksum' => $expectedChecksum,
                'expected_table_count' => count($expectedPayloadTables),
            ];
        }

        return [
            'clean' => $drifts === [],
            'drifts' => $drifts,
            'checked_modules' => $checked,
        ];
    }
}
