<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Model\SchemaIndexState;
use Weline\Framework\App\SystemUpdateActivity;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Manager\ObjectManager;

/**
 * Executes one index job outside the upgrade request.
 *
 * CREATE/DROP/REBUILD run with adapter-level concurrent DDL where available;
 * rebuilds create a new index first and only then remove the old one. The
 * applier never runs inside a transaction because CONCURRENTLY is forbidden.
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
        if (SystemUpdateActivity::inProgress()) {
            ObjectManager::getInstance(SchemaIndexJobService::class)
                ->markPendingAgain($jobId, 'deferred_system_update');

            return $this->result(false, SchemaIndexJob::STATUS_PENDING, $jobId, 'deferred_system_update', $started);
        }

        $connector = $this->connectionFactory->getConnector();
        if ($connector->getWrappedConnection()->inTransaction()) {
            throw new \RuntimeException((string)__('CONCURRENTLY 索引 DDL 不能在事务中执行'));
        }

        $table = (string)$job->getData(SchemaIndexJob::schema_fields_TABLE_NAME);
        $index = (string)$job->getData(SchemaIndexJob::schema_fields_INDEX_NAME);
        $physicalName = (string)$job->getData(SchemaIndexJob::schema_fields_PHYSICAL_NAME);
        if ($physicalName === '') {
            $physicalName = IndexDefinitionContract::physicalIdentity($connector, $table, $index);
        }
        $concurrent = $connector instanceof ConcurrentIndexBuildInterface ? $connector : null;

        return match ((string)$job->getData(SchemaIndexJob::schema_fields_ACTION)) {
            SchemaIndexJob::ACTION_CREATE => $this->applyCreate($job, $connector, $concurrent, $table, $index, $physicalName, $started),
            SchemaIndexJob::ACTION_DROP => $this->applyDrop($job, $connector, $concurrent, $table, $physicalName, $started),
            SchemaIndexJob::ACTION_REBUILD => $this->applyRebuild($job, $connector, $concurrent, $table, $index, $started),
            default => $this->blocked($job, $jobId, 'unsupported_action:' . (string)$job->getData(SchemaIndexJob::schema_fields_ACTION), $started),
        };
    }

    private function applyCreate(
        SchemaIndexJob $job,
        ConnectorInterface $connector,
        ?ConcurrentIndexBuildInterface $concurrent,
        string $table,
        string $index,
        string $physicalName,
        float $started,
    ): array {
        $jobId = (int)$job->getId();
        if (!$this->acquireLock($concurrent, $table, $job)) {
            return $this->blocked($job, $jobId, 'table_build_lock_busy', $started);
        }
        try {
            if ($concurrent !== null && $concurrent->isIndexValid($table, $physicalName)) {
                $this->markDone($job, $physicalName);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'already_valid', $started);
            }
            if ($concurrent !== null && $concurrent->isIndexPresent($table, $physicalName)) {
                $concurrent->dropInvalidIndex($table, $physicalName);
            }
            $definition = $this->definition($job);
            $this->markRunning($job);
            $this->configure($concurrent, $jobId);
            $this->createIndex($connector, $concurrent, $table, $index, $definition);
            $this->markDone($job, $physicalName);

            return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'created', $started);
        } catch (\Throwable $throwable) {
            return $this->fail($job, $jobId, $throwable, $started);
        } finally {
            $this->release($concurrent, $table);
        }
    }

    private function applyDrop(
        SchemaIndexJob $job,
        ConnectorInterface $connector,
        ?ConcurrentIndexBuildInterface $concurrent,
        string $table,
        string $physicalName,
        float $started,
    ): array {
        $jobId = (int)$job->getId();
        if (!$this->acquireLock($concurrent, $table, $job)) {
            return $this->blocked($job, $jobId, 'table_build_lock_busy', $started);
        }
        try {
            $present = $concurrent !== null
                ? $concurrent->isIndexPresent($table, $physicalName)
                : $connector->hasIndex($table, $physicalName);
            if (!$present) {
                $this->markDone($job, $physicalName, SchemaIndexState::STATUS_REMOVED);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'already_absent', $started);
            }
            $this->markRunning($job);
            $this->configure($concurrent, $jobId);
            $this->dropIndex($connector, $concurrent, $table, $physicalName);
            $this->markDone($job, $physicalName, SchemaIndexState::STATUS_REMOVED);

            return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'dropped', $started);
        } catch (\Throwable $throwable) {
            return $this->fail($job, $jobId, $throwable, $started);
        } finally {
            $this->release($concurrent, $table);
        }
    }

    private function applyRebuild(
        SchemaIndexJob $job,
        ConnectorInterface $connector,
        ?ConcurrentIndexBuildInterface $concurrent,
        string $table,
        string $index,
        float $started,
    ): array {
        $jobId = (int)$job->getId();
        $this->acquireLock($concurrent, $table, $job);
        $tempPhysical = '';
        try {
            $newDefinition = $this->definition($job);
            $oldDefinition = $this->oldDefinition($job) ?? $newDefinition;
            $oldLogical = (string)($oldDefinition['name'] ?? $index);
            $newLogical = (string)($newDefinition['name'] ?? $index);
            $oldPhysical = IndexDefinitionContract::physicalIdentity($connector, $table, $oldLogical);
            $newPhysical = IndexDefinitionContract::physicalIdentity($connector, $table, $newLogical);

            $this->markRunning($job);
            $this->configure($concurrent, $jobId);

            if ($concurrent === null) {
                if ($oldLogical === $newLogical) {
                    throw new \RuntimeException((string)__('当前适配器不支持同名索引 rebuild'));
                }
                $this->createIndex($connector, null, $table, $newLogical, $newDefinition);
                $this->dropIndex($connector, null, $table, $oldPhysical);
                $this->markDone($job, $newPhysical);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'rebuilt', $started);
            }

            if (!$concurrent->isIndexPresent($table, $oldPhysical)) {
                $this->createIndex($connector, $concurrent, $table, $newLogical, $newDefinition);
                $this->markDone($job, $newPhysical);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'rebuilt_absent_old', $started);
            }

            if ($oldLogical !== $newLogical) {
                $this->createIndex($connector, $concurrent, $table, $newLogical, $newDefinition);
                $this->dropIndex($connector, $concurrent, $table, $oldPhysical);
                $this->markDone($job, $newPhysical);

                return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'rebuilt_renamed', $started);
            }

            $tempLogical = substr($newLogical . '__rb_' . $jobId, 0, 60);
            $tempPhysical = IndexDefinitionContract::physicalIdentity($connector, $table, $tempLogical);
            $this->createIndex($connector, $concurrent, $table, $tempLogical, $newDefinition);
            if (!$concurrent->isIndexValid($table, $tempPhysical)) {
                throw new \RuntimeException((string)__('rebuild 临时索引无效：%{1}', [$tempPhysical]));
            }
            $this->dropIndex($connector, $concurrent, $table, $oldPhysical);
            $concurrent->renameIndex($table, $tempPhysical, $newLogical);
            $tempPhysical = '';
            if (!$concurrent->isIndexValid($table, $newPhysical)) {
                throw new \RuntimeException((string)__('rebuild 重命名后索引无效：%{1}', [$newPhysical]));
            }
            $this->markDone($job, $newPhysical);

            return $this->result(true, SchemaIndexJob::STATUS_DONE, $jobId, 'rebuilt', $started);
        } catch (\Throwable $throwable) {
            if ($tempPhysical !== '' && $concurrent !== null && $concurrent->isIndexPresent($table, $tempPhysical)) {
                try {
                    $concurrent->dropInvalidIndex($table, $tempPhysical);
                } catch (\Throwable) {
                }
            }

            return $this->fail($job, $jobId, $throwable, $started);
        } finally {
            $this->release($concurrent, $table);
        }
    }

    /** @param array{columns:list<string>,type:string,method:string,comment:string} $definition */
    private function createIndex(
        ConnectorInterface $connector,
        ?ConcurrentIndexBuildInterface $concurrent,
        string $table,
        string $logicalName,
        array $definition,
    ): void {
        $physicalTable = $connector->formatTableName($table);
        $idx = [
            'name' => $logicalName,
            'columns' => $definition['columns'],
            'type' => $definition['type'],
            'method' => $definition['method'],
            'comment' => $definition['comment'],
        ];
        $sql = $concurrent !== null
            ? $concurrent->buildAddIndexConcurrentlySql($physicalTable, $idx)
            : $connector->buildAddIndexSql($physicalTable, $idx);
        $connector->query($sql)->fetch();

        $physical = IndexDefinitionContract::physicalIdentity($connector, $table, $logicalName);
        $valid = $concurrent !== null
            ? $concurrent->isIndexValid($table, $physical)
            : $connector->hasIndex($table, $physical);
        if (!$valid) {
            if ($concurrent !== null) {
                $concurrent->dropInvalidIndex($table, $physical);
            }
            throw new \RuntimeException((string)__('索引构建后物理回读无效：%{1}', [$physical]));
        }
    }

    private function dropIndex(
        ConnectorInterface $connector,
        ?ConcurrentIndexBuildInterface $concurrent,
        string $table,
        string $physicalName,
    ): void {
        $physicalTable = $connector->formatTableName($table);
        $sql = $concurrent !== null
            ? $concurrent->buildDropIndexConcurrentlySql($physicalTable, $physicalName)
            : $connector->buildDropIndexSql($physicalTable, $physicalName);
        $connector->query($sql)->fetch();
        $present = $concurrent !== null
            ? $concurrent->isIndexPresent($table, $physicalName)
            : $connector->hasIndex($table, $physicalName);
        if ($present) {
            throw new \RuntimeException((string)__('索引删除后仍存在：%{1}', [$physicalName]));
        }
    }

    private function acquireLock(?ConcurrentIndexBuildInterface $concurrent, string $table, SchemaIndexJob $job): bool
    {
        if ($concurrent === null) {
            return true;
        }
        if ($concurrent->tryAcquireIndexBuildLock($table)) {
            return true;
        }
        $this->markBlocked($job, 'table_build_lock_busy');

        return false;
    }

    private function configure(?ConcurrentIndexBuildInterface $concurrent, int $jobId): void
    {
        if ($concurrent === null) {
            return;
        }
        $concurrent->configureConcurrentIndexBuild([
            'application_name' => 'weline-index:' . $jobId,
            'lock_timeout' => '3s',
            'statement_timeout' => '0',
            'maintenance_work_mem' => $this->config->maintenanceWorkMem(),
            'max_parallel_maintenance_workers' => $this->config->maxParallelWorkers(),
        ]);
    }

    private function release(?ConcurrentIndexBuildInterface $concurrent, string $table): void
    {
        if ($concurrent === null) {
            return;
        }
        $concurrent->releaseIndexBuildLock($table);
        $concurrent->resetConcurrentIndexBuild();
    }

    private function blocked(SchemaIndexJob $job, int $jobId, string $message, float $started): array
    {
        $this->markBlocked($job, $message);

        return $this->result(false, SchemaIndexJob::STATUS_BLOCKED, $jobId, $message, $started);
    }

    private function fail(SchemaIndexJob $job, int $jobId, \Throwable $throwable, float $started): array
    {
        $failureStatus = $this->markFailure($job, $throwable->getMessage());

        return $this->result(false, $failureStatus, $jobId, $throwable->getMessage(), $started);
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

    /** @return array{name:string,columns:list<string>,type:string,method:string,comment:string} */
    private function definition(SchemaIndexJob $job): array
    {
        $decoded = json_decode((string)$job->getData(SchemaIndexJob::schema_fields_DEFINITION_JSON), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('索引任务定义 JSON 无效：#%{1}', [(int)$job->getId()]));
        }
        $decoded['name'] = (string)($decoded['name'] ?? $job->getData(SchemaIndexJob::schema_fields_INDEX_NAME));

        return $this->normalizeDefinition($decoded, (int)$job->getId());
    }

    /** @return array{name:string,columns:list<string>,type:string,method:string,comment:string}|null */
    private function oldDefinition(SchemaIndexJob $job): ?array
    {
        $decoded = json_decode((string)$job->getData(SchemaIndexJob::schema_fields_OLD_DEFINITION_JSON), true);
        if (!is_array($decoded)) {
            return null;
        }
        $decoded['name'] = (string)($decoded['name'] ?? $job->getData(SchemaIndexJob::schema_fields_INDEX_NAME));

        return $this->normalizeDefinition($decoded, (int)$job->getId());
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{name:string,columns:list<string>,type:string,method:string,comment:string}
     */
    private function normalizeDefinition(array $decoded, int $jobId): array
    {
        $name = trim((string)($decoded['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException((string)__('索引任务定义缺少名称：#%{1}', [$jobId]));
        }
        $columns = array_values(array_filter(
            array_map(static fn(mixed $column): string => trim((string)$column), (array)($decoded['columns'] ?? [])),
            static fn(string $column): bool => $column !== '',
        ));
        if ($columns === []) {
            throw new \RuntimeException((string)__('索引任务定义缺少字段：#%{1}', [$jobId]));
        }

        return [
            'name' => $name,
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

    private function markDone(
        SchemaIndexJob $job,
        string $physicalName,
        string $stateStatus = SchemaIndexState::STATUS_APPLIED,
    ): void {
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
        $this->updateState($job, $stateStatus, $physicalName);
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
        $stateId = (int)$existing->getId();
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
