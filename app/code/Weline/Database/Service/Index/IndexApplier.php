<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Model\SchemaIndexState;
use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Manager\ObjectManager;

/**
 * Executes one create-index job outside the upgrade request.
 *
 * P3 intentionally handles ACTION_CREATE only; drop/rebuild stay blocked for
 * the later drop/rebuild phase. The applier never runs inside a transaction
 * because CREATE INDEX CONCURRENTLY is forbidden there.
 */
final class IndexApplier
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaIndexConfig $config,
    ) {
    }

    /**
     * @return array{success:bool,status:string,job_id:int,message:string,duration_ms:int}
     */
    public function apply(int $jobId): array
    {
        $started = microtime(true);
        $job = $this->loadJob($jobId);
        if ($job === null) {
            throw new \RuntimeException((string)__('索引任务不存在：%{1}', [$jobId]));
        }

        $status = (string)$job->getData(SchemaIndexJob::schema_fields_STATUS);
        if ($status === SchemaIndexJob::STATUS_DONE) {
            return $this->result(true, $status, $jobId, 'already_done', $started);
        }

        $action = (string)$job->getData(SchemaIndexJob::schema_fields_ACTION);
        if ($action !== SchemaIndexJob::ACTION_CREATE) {
            $this->markBlocked($job, 'action_pending_later_phase:' . $action);

            return $this->result(false, SchemaIndexJob::STATUS_BLOCKED, $jobId, 'action_pending_later_phase', $started);
        }

        $connector = $this->connectionFactory->getConnector();
        if ($connector->getWrappedConnection()->inTransaction()) {
            throw new \RuntimeException((string)__('CREATE INDEX CONCURRENTLY 不能在事务中执行'));
        }

        $table = (string)$job->getData(SchemaIndexJob::schema_fields_TABLE_NAME);
        $index = (string)$job->getData(SchemaIndexJob::schema_fields_INDEX_NAME);
        $physicalName = (string)$job->getData(SchemaIndexJob::schema_fields_PHYSICAL_NAME);
        if ($physicalName === '') {
            $physicalName = IndexDefinitionContract::physicalIdentity($connector, $table, $index);
        }

        $concurrent = $connector instanceof ConcurrentIndexBuildInterface ? $connector : null;
        $lockAcquired = false;
        if ($concurrent !== null) {
            if ($concurrent->isIndexValid($table, $physicalName)) {
                $this->markDone($job, $physicalName);
                $this->releaseLock($concurrent, $table, $lockAcquired);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'already_valid', $started);
            }
            $lockAcquired = $concurrent->tryAcquireIndexBuildLock($table);
            if (!$lockAcquired) {
                $this->markBlocked($job, 'table_build_lock_busy');

                return $this->result(false, SchemaIndexJob::STATUS_BLOCKED, $jobId, 'table_build_lock_busy', $started);
            }
        }

        try {
            $definition = $this->definition($job);
            $this->markRunning($job);
            if ($concurrent !== null) {
                $concurrent->configureConcurrentIndexBuild([
                    'application_name' => 'weline-index:' . $jobId,
                    'lock_timeout' => '3s',
                    'statement_timeout' => '0',
                    'maintenance_work_mem' => $this->config->maintenanceWorkMem(),
                    'max_parallel_maintenance_workers' => $this->config->maxParallelWorkers(),
                ]);
            }

            $physicalTable = $connector->formatTableName($table);
            $idx = [
                'name' => $index,
                'columns' => $definition['columns'],
                'type' => $definition['type'],
                'method' => $definition['method'],
                'comment' => $definition['comment'],
            ];
            $sql = $concurrent !== null
                ? $concurrent->buildAddIndexConcurrentlySql($physicalTable, $idx)
                : $connector->buildAddIndexSql($physicalTable, $idx);
            $connector->query($sql)->fetch();

            $valid = $concurrent !== null
                ? $concurrent->isIndexValid($table, $physicalName)
                : $connector->hasIndex($table, $physicalName);
            if (!$valid) {
                throw new \RuntimeException((string)__('索引构建后物理回读无效：%{1}', [$physicalName]));
            }

            $this->markDone($job, $physicalName);

            return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'created', $started);
        } catch (\Throwable $throwable) {
            $failureStatus = $this->markFailure($job, $throwable->getMessage());

            return $this->result(false, $failureStatus, $jobId, $throwable->getMessage(), $started);
        } finally {
            $this->releaseLock($concurrent, $table, $lockAcquired);
            if ($concurrent !== null) {
                $concurrent->resetConcurrentIndexBuild();
            }
        }
    }

    private function loadJob(int $jobId): ?SchemaIndexJob
    {
        $model = ObjectManager::getInstance(SchemaIndexJob::class);
        $row = $model->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();

        return $row->getId() ? $row : null;
    }

    /** @return array{columns:list<string>,type:string,method:string,comment:string} */
    private function definition(SchemaIndexJob $job): array
    {
        $raw = (string)$job->getData(SchemaIndexJob::schema_fields_DEFINITION_JSON);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('索引任务定义 JSON 无效：#%{1}', [(int)$job->getId()]));
        }
        $columns = array_values(array_filter(
            array_map(static fn(mixed $column): string => trim((string)$column), (array)($decoded['columns'] ?? [])),
            static fn(string $column): bool => $column !== '',
        ));
        if ($columns === []) {
            throw new \RuntimeException((string)__('索引任务定义缺少字段：#%{1}', [(int)$job->getId()]));
        }

        return [
            'columns' => $columns,
            'type' => (string)($decoded['type'] ?? 'DEFAULT'),
            'method' => (string)($decoded['method'] ?? 'BTREE'),
            'comment' => (string)($decoded['comment'] ?? ''),
        ];
    }

    private function markRunning(SchemaIndexJob $job): void
    {
        $now = date('Y-m-d H:i:s');
        $jobId = (int)$job->getId();
        $attempts = (int)$job->getData(SchemaIndexJob::schema_fields_ATTEMPTS) + 1;
        ObjectManager::create(SchemaIndexJob::class, [], false)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->update([
                SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_RUNNING,
                SchemaIndexJob::schema_fields_ATTEMPTS => $attempts,
                SchemaIndexJob::schema_fields_STARTED_AT => $now,
                SchemaIndexJob::schema_fields_HEARTBEAT_AT => $now,
                SchemaIndexJob::schema_fields_LAST_ERROR => null,
                SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
            ])->fetch();
        $job->setData(SchemaIndexJob::schema_fields_ATTEMPTS, $attempts);
        $job->setData(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_RUNNING);
        $this->updateState($job, SchemaIndexState::STATUS_RUNNING, null);
    }

    private function markDone(SchemaIndexJob $job, string $physicalName): void
    {
        $now = date('Y-m-d H:i:s');
        $jobId = (int)$job->getId();
        ObjectManager::create(SchemaIndexJob::class, [], false)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->update([
                SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_DONE,
                SchemaIndexJob::schema_fields_PHYSICAL_NAME => $physicalName,
                SchemaIndexJob::schema_fields_FINISHED_AT => $now,
                SchemaIndexJob::schema_fields_HEARTBEAT_AT => $now,
                SchemaIndexJob::schema_fields_LAST_ERROR => null,
                SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
            ])->fetch();
        $this->updateState($job, SchemaIndexState::STATUS_APPLIED, $physicalName);
    }

    private function markFailure(SchemaIndexJob $job, string $message): string
    {
        $attempts = (int)$job->getData(SchemaIndexJob::schema_fields_ATTEMPTS);
        $maxAttempts = max(1, (int)$job->getData(SchemaIndexJob::schema_fields_MAX_ATTEMPTS));
        $terminal = $attempts >= $maxAttempts;
        $now = date('Y-m-d H:i:s');
        $jobId = (int)$job->getId();
        $delay = min(600, 30 * max(1, $attempts));
        ObjectManager::create(SchemaIndexJob::class, [], false)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->update([
                SchemaIndexJob::schema_fields_STATUS => $terminal ? SchemaIndexJob::STATUS_FAILED : SchemaIndexJob::STATUS_PENDING,
                SchemaIndexJob::schema_fields_SCHEDULED_AT => $terminal ? null : date('Y-m-d H:i:s', time() + $delay),
                SchemaIndexJob::schema_fields_LAST_ERROR => mb_substr($message, 0, 2000),
                SchemaIndexJob::schema_fields_HEARTBEAT_AT => $now,
                SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
            ])->fetch();
        $this->updateState($job, SchemaIndexState::STATUS_FAILED, null, $message);

        return $terminal ? SchemaIndexJob::STATUS_FAILED : SchemaIndexJob::STATUS_PENDING;
    }

    private function markBlocked(SchemaIndexJob $job, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $jobId = (int)$job->getId();
        ObjectManager::create(SchemaIndexJob::class, [], false)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->update([
                SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_BLOCKED,
                SchemaIndexJob::schema_fields_LAST_ERROR => mb_substr($message, 0, 2000),
                SchemaIndexJob::schema_fields_HEARTBEAT_AT => $now,
                SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
            ])->fetch();
        $this->updateState($job, SchemaIndexState::STATUS_PENDING, null, $message);
    }

    private function updateState(
        SchemaIndexJob $job,
        string $stateStatus,
        ?string $physicalName,
        ?string $error = null,
    ): void {
        $table = (string)$job->getData(SchemaIndexJob::schema_fields_TABLE_NAME);
        $index = (string)$job->getData(SchemaIndexJob::schema_fields_INDEX_NAME);
        $model = ObjectManager::getInstance(SchemaIndexState::class);
        $existing = $model->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexState::schema_fields_INDEX_NAME, $index)
            ->find()
            ->fetch();
        $now = date('Y-m-d H:i:s');
        $data = [
            SchemaIndexState::schema_fields_STATUS => $stateStatus,
            SchemaIndexState::schema_fields_LAST_JOB_ID => (int)$job->getId(),
            SchemaIndexState::schema_fields_LAST_ERROR => $error,
            SchemaIndexState::schema_fields_LAST_CHECKED_AT => $now,
            SchemaIndexState::schema_fields_UPDATE_TIME => $now,
        ];
        if ($physicalName !== null && $physicalName !== '') {
            $data[SchemaIndexState::schema_fields_PHYSICAL_NAME] = $physicalName;
        }
        if ($stateStatus === SchemaIndexState::STATUS_APPLIED) {
            $data[SchemaIndexState::schema_fields_APPLIED_AT] = $now;
        }
        $stateId = (int)$existing->getId();
        if ($stateId > 0) {
            ObjectManager::create(SchemaIndexState::class, [], false)->clear()->reset()
                ->where(SchemaIndexState::schema_fields_ID, $stateId)
                ->update($data)
                ->fetch();

            return;
        }
        $data[SchemaIndexState::schema_fields_MODULE] = (string)$job->getData(SchemaIndexJob::schema_fields_MODULE);
        $data[SchemaIndexState::schema_fields_TABLE_NAME] = $table;
        $data[SchemaIndexState::schema_fields_INDEX_NAME] = $index;
        $data[SchemaIndexState::schema_fields_DEFINITION_JSON] = (string)$job->getData(SchemaIndexJob::schema_fields_DEFINITION_JSON);
        $data[SchemaIndexState::schema_fields_DEFINITION_HASH] = (string)$job->getData(SchemaIndexJob::schema_fields_DEFINITION_HASH);
        $data[SchemaIndexState::schema_fields_CREATE_TIME] = $now;
        ObjectManager::create(SchemaIndexState::class, [], false)->clear()->reset()
            ->insert($data, SchemaIndexState::schema_fields_ID)
            ->fetch();
    }

    private function releaseLock(?ConcurrentIndexBuildInterface $connector, string $table, bool $acquired): void
    {
        if ($connector !== null && $acquired) {
            $connector->releaseIndexBuildLock($table);
        }
    }

    /**
     * @return array{success:bool,status:string,job_id:int,message:string,duration_ms:int}
     */
    private function result(bool $success, string $status, int $jobId, string $message, float $started): array
    {
        return [
            'success' => $success,
            'status' => $status,
            'job_id' => $jobId,
            'message' => $message,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}
