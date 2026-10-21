<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P6 scheduler guards: recent state + done job enforce
 * per-table cooldown and per-table window budget before the next pending job.
 */

namespace {
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\SchemaIndexJobService;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Manager\ObjectManager;

    $table = '';

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $table = 'w_guard_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_guard_' . bin2hex(random_bytes(4));
        $json = (string)json_encode(['name' => $index, 'columns' => ['id'], 'type' => 'DEFAULT', 'method' => 'BTREE', 'comment' => '']);
        $now = date('Y-m-d H:i:s');
        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $base = [
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $table,
            SchemaIndexJob::schema_fields_INDEX_NAME => $index,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_CREATE,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $json,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $json),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => 'probe_' . $index,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 3,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ];
        $jobModel->clear()->reset()->insert(array_merge($base, [
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 100,
        ]), SchemaIndexJob::schema_fields_ID)->fetch();
        $jobModel->clear()->reset()->insert(array_merge($base, [
            SchemaIndexJob::schema_fields_INDEX_NAME => $index . '_done',
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $json . 'done'),
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_DONE,
            SchemaIndexJob::schema_fields_PRIORITY => 100,
            SchemaIndexJob::schema_fields_FINISHED_AT => $now,
        ]), SchemaIndexJob::schema_fields_ID)->fetch();

        $state = ObjectManager::getInstance(SchemaIndexState::class);
        $state->clear()->reset()->insert([
            SchemaIndexState::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexState::schema_fields_TABLE_NAME => $table,
            SchemaIndexState::schema_fields_INDEX_NAME => $index,
            SchemaIndexState::schema_fields_DEFINITION_JSON => $json,
            SchemaIndexState::schema_fields_DEFINITION_HASH => hash('sha256', $json),
            SchemaIndexState::schema_fields_PHYSICAL_NAME => 'probe_' . $index,
            SchemaIndexState::schema_fields_STATUS => SchemaIndexState::STATUS_APPLIED,
            SchemaIndexState::schema_fields_APPLIED_AT => $now,
            SchemaIndexState::schema_fields_LAST_CHECKED_AT => $now,
            SchemaIndexState::schema_fields_CREATE_TIME => $now,
            SchemaIndexState::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexState::schema_fields_ID)->fetch();

        $jobCount = (int)ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)->total();
        $doneCount = (int)ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_DONE)->total();
        $stateCount = (int)ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $table)->total();
        $service = ObjectManager::getInstance(SchemaIndexJobService::class);
        $cooled = $service->nextPendingJob(300, 0);
        $budgeted = $service->nextPendingJob(0, 1);
        $available = $service->nextPendingJob(0, 0);

        $ok = $cooled === null
            && $budgeted === null
            && is_array($available)
            && (string)($available[SchemaIndexJob::schema_fields_TABLE_NAME] ?? '') === $table;

        echo json_encode([
            'ok' => $ok,
            'cooldown_blocked' => $cooled === null,
            'table_budget_blocked' => $budgeted === null,
            'available_job_id' => (int)($available[SchemaIndexJob::schema_fields_ID] ?? 0),
            'table' => $table,
            'job_count' => $jobCount,
            'done_count' => $doneCount,
            'state_count' => $stateCount,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    } finally {
        if ($table !== '') {
            try {
                $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
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
}
