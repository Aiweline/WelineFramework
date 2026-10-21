<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P5: progress snapshot for a pending job, then the
 * database_index BinQuery enqueue operation moves it to queued.
 */

namespace {
    use Weline\Database\Extends\Module\Weline_Framework\Query\DatabaseIndexQueryProvider;
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\SchemaIndexProgressService;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Manager\ObjectManager;

    $jobId = 0;

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $table = 'w_sse_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_sse_' . bin2hex(random_bytes(4));
        $definitionJson = (string)json_encode([
            'name' => $index,
            'columns' => ['id'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $jobModel->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_CHANGE_SET_ID => 'sse-probe',
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $table,
            SchemaIndexJob::schema_fields_INDEX_NAME => $index,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_CREATE,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $definitionJson),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => 'probe_' . $index,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 100,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 3,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexJob::schema_fields_ID)->fetch();
        $job = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->find()
            ->fetch();
        $jobId = (int)$job->getId();
        if ($jobId < 1) {
            throw new \RuntimeException('sse probe job insert failed');
        }

        $progressService = ObjectManager::getInstance(SchemaIndexProgressService::class);
        $before = $progressService->snapshot($jobId);
        $provider = ObjectManager::getInstance(DatabaseIndexQueryProvider::class);
        $enqueued = $provider->execute('enqueue', ['job_id' => $jobId]);
        $after = $progressService->snapshot($jobId);
        $progressApi = $provider->execute('progress', ['job_id' => $jobId]);

        $ok = ($before['status'] ?? '') === SchemaIndexJob::STATUS_PENDING
            && ($before['percent'] ?? -1) === 0
            && !empty($enqueued['success'])
            && ($enqueued['status'] ?? '') === SchemaIndexJob::STATUS_QUEUED
            && ($after['status'] ?? '') === SchemaIndexJob::STATUS_QUEUED
            && !empty($progressApi['success'])
            && ($progressApi['job_id'] ?? 0) === $jobId;

        echo json_encode([
            'ok' => $ok,
            'job_id' => $jobId,
            'before' => $before,
            'enqueue' => $enqueued,
            'after' => $after,
            'progress_api' => $progressApi,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    } finally {
        if ($jobId > 0) {
            try {
                $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
                $bizKey = 'schema_index:job:' . $jobId;
                $connector->query(
                    "DELETE FROM w_weline_queue WHERE biz_key = '" . str_replace("'", "''", $bizKey) . "'"
                )->fetch();
                ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
                    ->where(SchemaIndexJob::schema_fields_ID, $jobId)
                    ->delete()->fetch();
                ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
                    ->where(SchemaIndexState::schema_fields_LAST_JOB_ID, $jobId)
                    ->delete()->fetch();
            } catch (\Throwable) {
            }
        }
    }
}
