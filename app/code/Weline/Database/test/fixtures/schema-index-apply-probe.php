<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P3: a queued create job is applied by IndexApplier with
 * CREATE INDEX CONCURRENTLY, then job/state are marked done/applied.
 */

namespace {
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\IndexApplier;
    use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\Schema\IndexDefinitionContract;
    use Weline\Framework\Manager\ObjectManager;

    $table = '';
    $index = '';

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }
        if (!$connector instanceof ConcurrentIndexBuildInterface) {
            echo json_encode(['skipped' => true, 'reason' => 'concurrent index capability unavailable'], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $table = 'w_p3_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_p3_' . bin2hex(random_bytes(4));
        $connector->query('DROP TABLE IF EXISTS ' . $table)->fetch();
        $connector->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, a VARCHAR(32))')->fetch();

        $definition = [
            'name' => $index,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ];
        $definitionJson = (string)json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $definitionHash = hash('sha256', $definitionJson);
        $physicalName = IndexDefinitionContract::physicalIdentity($connector, $table, $index);
        $now = date('Y-m-d H:i:s');

        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $jobModel->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_CHANGE_SET_ID => 'p3-probe',
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $table,
            SchemaIndexJob::schema_fields_INDEX_NAME => $index,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_CREATE,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => $definitionHash,
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => $physicalName,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_QUEUED,
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
            throw new \RuntimeException('job insert failed');
        }

        $applier = ObjectManager::getInstance(IndexApplier::class);
        $result = $applier->apply($jobId);
        if (empty($result['success'])) {
            // A transient lock_timeout leaves the job pending; retry once to
            // exercise the same retry path the cron/queue will use.
            $result = $applier->apply($jobId);
        }
        $jobAfter = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();
        $state = ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexState::schema_fields_INDEX_NAME, $index)
            ->find()
            ->fetch();

        $valid = $connector->isIndexValid($table, $physicalName);
        $ok = !empty($result['success'])
            && $valid
            && (string)$jobAfter->getData(SchemaIndexJob::schema_fields_STATUS) === SchemaIndexJob::STATUS_DONE
            && (string)$state->getData(SchemaIndexState::schema_fields_STATUS) === SchemaIndexState::STATUS_APPLIED;

        echo json_encode([
            'ok' => $ok,
            'table' => $table,
            'index' => $physicalName,
            'apply' => $result,
            'job_status' => (string)$jobAfter->getData(SchemaIndexJob::schema_fields_STATUS),
            'state_status' => (string)$state->getData(SchemaIndexState::schema_fields_STATUS),
            'index_valid' => $valid,
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
}
