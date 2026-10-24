<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P2 upgrade recording:
 * - drop+add of the same index becomes one rebuild job and skips DDL;
 * - running the same batch again does not duplicate the job;
 * - UNIQUE indexes stay synchronous and never enter the job queue.
 *
 * Run through app/bootstrap.php (not PHPUnit sandbox) so the models and the
 * executor share the real pgsql connection.
 */

namespace {
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Framework\Database\Connection\Api\ConnectorInterface;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\Schema\IndexDefinition;
    use Weline\Framework\Database\Schema\SchemaDiffOp;
    use Weline\Database\Service\Release\SchemaDiffExecutor;
    use Weline\Framework\Manager\ObjectManager;

    $table = '';
    $index = '';
    $uniqueIndex = '';

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $table = 'w_p2_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_p2_' . bin2hex(random_bytes(4));
        $uniqueIndex = 'uk_p2_' . bin2hex(random_bytes(4));
        $connector->query('DROP TABLE IF EXISTS ' . $table)->fetch();
        $connector->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, a VARCHAR(32), b VARCHAR(32))')->fetch();
        $connector->query('CREATE INDEX ' . $index . ' ON ' . $table . ' (a)')->fetch();

        $executor = ObjectManager::getInstance(SchemaDiffExecutor::class);
        $drop = new SchemaDiffOp(
            SchemaDiffOp::KIND_DROP_INDEX,
            $table,
            new IndexDefinition($index, ['a']),
        );
        $add = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            $table,
            new IndexDefinition($index, ['b']),
        );
        $context = ['operation_id' => 'p2-probe-' . bin2hex(random_bytes(4))];

        $executor->execute($connector, [$drop, $add], $context);
        $firstJobs = jobRows($table);
        $firstIndexDef = indexDefinition($connector, $table, $index);

        $executor->execute($connector, [$drop, $add], $context);
        $secondJobs = jobRows($table);

        $unique = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            $table,
            new IndexDefinition($uniqueIndex, ['b'], 'UNIQUE'),
        );
        $executor->execute($connector, [$unique], $context);
        $uniquePresent = indexDefinition($connector, $table, $uniqueIndex) !== '';
        $afterUniqueJobs = jobRows($table);

        $rebuildJob = $firstJobs[0] ?? [];
        $ok = count($firstJobs) === 1
            && (string)($rebuildJob[SchemaIndexJob::schema_fields_ACTION] ?? '') === SchemaIndexJob::ACTION_REBUILD
            && count($secondJobs) === 1
            && count($afterUniqueJobs) === 1
            && $firstIndexDef !== ''
            && str_contains($firstIndexDef, '(a)')
            && $uniquePresent;

        echo json_encode([
            'ok' => $ok,
            'table' => $table,
            'first_job_count' => count($firstJobs),
            'first_job_action' => (string)($rebuildJob[SchemaIndexJob::schema_fields_ACTION] ?? ''),
            'first_index_def' => $firstIndexDef,
            'second_job_count' => count($secondJobs),
            'unique_index_present' => $uniquePresent,
            'jobs_after_unique' => count($afterUniqueJobs),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode([
            'ok' => false,
            'error' => $throwable->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    } finally {
        if ($table !== '') {
            try {
                ObjectManager::getInstance(ConnectionFactory::class)->getConnector()
                    ->query('DROP TABLE IF EXISTS ' . $table)->fetch();
            } catch (\Throwable) {
            }
            try {
                ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
                    ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
                    ->delete()->fetch();
                ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
                    ->where(SchemaIndexState::schema_fields_TABLE_NAME, $table)
                    ->delete()->fetch();
            } catch (\Throwable) {
            }
        }
    }

    /** @return list<array<string,mixed>> */
    function jobRows(string $table): array
    {
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    function indexDefinition(ConnectorInterface $connector, string $table, string $index): string
    {
        $safeTable = str_replace("'", "''", $table);
        $safeIndex = str_replace("'", "''", $index);
        $sql = "SELECT indexdef FROM pg_indexes WHERE tablename = '" . $safeTable
            . "' AND (indexname = '" . $safeIndex . "' OR indexdef LIKE '%" . $safeIndex . "%')";
        $rows = $connector->query($sql)->fetch();
        if (!is_array($rows)) {
            return '';
        }
        $row = $rows[0] ?? $rows;
        if (!is_array($row)) {
            return '';
        }

        return (string)($row['indexdef'] ?? '');
    }
}
