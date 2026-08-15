<?php

declare(strict_types=1);

namespace Weline\Database\Service;

/**
 * Supplies execution metadata for immutable migration scripts installed before
 * exact-table locking became mandatory. The audited allowlist lives in this
 * service: adjacent module files are never loaded as executable metadata.
 */
final class HistoricalMigrationMetadataRegistry
{
    private const CATALOG = [
        'app/code/Weline/Ai/Setup/Db/Migration/create_table__ai_models_20250101-v1.0.0.php' => [
            'script_sha256' => 'd01b68ae7e28e9cfa562ce9b672542f07cf9e5e11744f55e1171bc28b6cb2c59',
            'affected_tables' => ['ai_models'],
        ],
        'app/code/Weline/Ai/Setup/Db/Migration/add_token_price_fields_20250111-v1.1.0.php' => [
            'script_sha256' => 'ddf3cdff1eef3fbb4fc8587f514d8c3118c39b225e27ceb1bdd991e1aa8cc098',
            'affected_tables' => ['ai_model'],
        ],
        'app/code/Weline/ModuleManager/Setup/Db/Migration/'
            . 'add_module_table_policy_and_audit_20250318-v1.0.2.php' => [
            'script_sha256' => '6cd9c760d10a80438bffedb150d3abf2507629ce2b2156f4c6a4e7f2233788cb',
            'affected_tables' => ['weline_module_table', 'module_uninstall_audit'],
        ],
    ];

    /** @return list<string> */
    public function affectedTables(string $migrationFile): array
    {
        if (!is_file($migrationFile)) {
            throw new \RuntimeException('historical migration script is missing');
        }

        $projectRoot = realpath(defined('BP') ? (string)constant('BP') : dirname(__DIR__, 5));
        $migrationRealPath = realpath($migrationFile);
        if (!is_string($projectRoot) || !is_string($migrationRealPath)) {
            throw new \RuntimeException('historical migration path cannot be resolved');
        }

        $entry = null;
        foreach (self::CATALOG as $relativePath => $candidate) {
            $approvedPath = realpath(
                $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
            );
            if (is_string($approvedPath) && hash_equals($approvedPath, $migrationRealPath)) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            return [];
        }

        if (!is_array($entry)
            || array_diff(array_keys($entry), ['script_sha256', 'affected_tables']) !== []
            || !array_key_exists('script_sha256', $entry)
            || !array_key_exists('affected_tables', $entry)) {
            throw new \RuntimeException('historical migration metadata entry is invalid');
        }

        $expectedChecksum = strtolower(trim((string)$entry['script_sha256']));
        $actualChecksum = hash_file('sha256', $migrationFile);
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedChecksum)
            || !is_string($actualChecksum)
            || !hash_equals($expectedChecksum, $actualChecksum)) {
            throw new \RuntimeException('historical migration metadata checksum mismatch');
        }

        $rawTables = $entry['affected_tables'];
        if (!is_array($rawTables) || !array_is_list($rawTables) || $rawTables === []) {
            throw new \RuntimeException('historical migration affected tables are invalid');
        }
        $tables = [];
        foreach ($rawTables as $table) {
            if (!is_string($table)
                || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $table)
                || isset($tables[$table])) {
                throw new \RuntimeException('historical migration affected table identity is invalid');
            }
            $tables[$table] = true;
        }

        return array_keys($tables);
    }
}
