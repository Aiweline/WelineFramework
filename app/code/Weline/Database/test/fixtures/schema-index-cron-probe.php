<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P3 cron scheduling: a pending job is admitted to the
 * queue and marked queued, without executing DDL in the cron process.
 */

namespace {
    use Weline\Database\Cron\SchemaIndexAutoApply;
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\SchemaIndexConfig;
    use Weline\Database\Service\Index\SchemaIndexDeltaDetector;
    use Weline\Database\Service\Index\SchemaIndexJobService;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Manager\ObjectManager;

    $jobId = 0;
    $stateFile = '';

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $table = 'w_p3_cron_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_p3_cron_' . bin2hex(random_bytes(4));
        $definitionJson = (string)json_encode([
            'name' => $index,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');

        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $jobModel->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_CHANGE_SET_ID => 'p3-cron-probe',
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $table,
            SchemaIndexJob::schema_fields_INDEX_NAME => $index,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_CREATE,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $definitionJson),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => 'probe_physical_' . $index,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 100,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 3,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexJob::schema_fields_ID)->fetch();
        $job = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_INDEX_NAME, $index)
            ->find()
            ->fetch();
        $jobId = (int)$job->getId();
        if ($jobId < 1) {
            throw new \RuntimeException('cron probe job insert failed');
        }

        $config = new SchemaIndexConfig(null, null, '*', true, 999.0);
        // auto_apply 只消费看门狗 flagged：用临时状态文件造出「看门狗已标记」的现场。
        $stateFile = rtrim((string)\constant('VAR_DIR'), '/\\') . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . 'schema_index_delta_probe_' . bin2hex(random_bytes(4)) . '.json';
        $detector = new SchemaIndexDeltaDetector(null, $stateFile);
        $detector->remember();
        $cron = new SchemaIndexAutoApply($config, ObjectManager::getInstance(SchemaIndexJobService::class), $detector);
        $message = $cron->execute();

        $jobAfter = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();
        $bizKey = 'schema_index:job:' . $jobId;
        $queueRows = $connector->query(
            "SELECT queue_id, status FROM w_weline_queue WHERE biz_key = '" . str_replace("'", "''", $bizKey) . "'"
        )->fetch();
        $queueCount = is_array($queueRows) ? count($queueRows) : 0;

        $ok = $queueCount === 1
            && (string)$jobAfter->getData(SchemaIndexJob::schema_fields_STATUS) === SchemaIndexJob::STATUS_QUEUED;

        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'job_status' => (string)$jobAfter->getData(SchemaIndexJob::schema_fields_STATUS),
            'queue_count' => $queueCount,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode([
            'ok' => false,
            'error' => $throwable->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
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
        if ($stateFile !== '' && is_file($stateFile)) {
            @unlink($stateFile);
        }
    }
}
