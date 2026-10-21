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
 * Compares declared index state rows with the physical database and optionally
 * enqueues repair jobs. Pending/running rows are intentionally skipped so an
 * async queue backlog is not reported as schema drift.
 */
final class SchemaIndexDriftService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaIndexJobService $jobService,
    ) {
    }

    /** @return array{checked:int,drifted:int,repaired:int,rows:list<array<string,mixed>>} */
    public function verify(bool $fix = false, ?string $table = null): array
    {
        $connector = $this->connectionFactory->getConnector();
        $concurrent = $connector instanceof ConcurrentIndexBuildInterface ? $connector : null;
        $rows = $this->states($table);
        $checked = 0;
        $drifted = 0;
        $repaired = 0;
        $resultRows = [];

        foreach ($rows as $row) {
            $stateId = (int)($row[SchemaIndexState::schema_fields_ID] ?? 0);
            $stateStatus = (string)($row[SchemaIndexState::schema_fields_STATUS] ?? '');
            if (in_array($stateStatus, [
                SchemaIndexState::STATUS_PENDING,
                SchemaIndexState::STATUS_RUNNING,
            ], true)) {
                continue;
            }
            $checked++;
            $tableName = (string)($row[SchemaIndexState::schema_fields_TABLE_NAME] ?? '');
            $indexName = (string)($row[SchemaIndexState::schema_fields_INDEX_NAME] ?? '');
            $physical = (string)($row[SchemaIndexState::schema_fields_PHYSICAL_NAME] ?? '');
            if ($physical === '') {
                $physical = IndexDefinitionContract::physicalIdentity($connector, $tableName, $indexName);
            }
            $present = $concurrent !== null
                ? $concurrent->isIndexPresent($tableName, $physical)
                : $connector->hasIndex($tableName, $physical);
            $valid = $present && ($concurrent !== null
                ? $concurrent->isIndexValid($tableName, $physical)
                : true);

            $expectPresent = $stateStatus !== SchemaIndexState::STATUS_REMOVED;
            $mismatch = $expectPresent ? (!$present || !$valid) : $present;
            $rowResult = [
                'state_id' => $stateId,
                'table' => $tableName,
                'index' => $indexName,
                'physical' => $physical,
                'status' => $stateStatus,
                'present' => $present,
                'valid' => $valid,
                'drifted' => $mismatch,
                'repaired' => false,
                'repair_job_id' => 0,
            ];
            if ($mismatch) {
                $drifted++;
                $this->updateState($stateId, SchemaIndexState::STATUS_DRIFTED, $present
                    ? (string)__('物理索引存在但状态不一致')
                    : (string)__('物理索引缺失'));
                if ($fix) {
                    $jobId = $this->enqueueRepair($row, $tableName, $indexName, $physical);
                    $rowResult['repaired'] = $jobId > 0;
                    $rowResult['repair_job_id'] = $jobId;
                    if ($jobId > 0) {
                        $repaired++;
                    }
                }
            } elseif ($stateStatus === SchemaIndexState::STATUS_DRIFTED) {
                $this->updateState($stateId, SchemaIndexState::STATUS_APPLIED, null);
            } else {
                $this->touchState($stateId);
            }
            $resultRows[] = $rowResult;
        }

        return [
            'checked' => $checked,
            'drifted' => $drifted,
            'repaired' => $repaired,
            'rows' => $resultRows,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function states(?string $table): array
    {
        $model = ObjectManager::getInstance(SchemaIndexState::class);
        $query = $model->clear()->reset();
        if ($table !== null && $table !== '') {
            $query->where(SchemaIndexState::schema_fields_TABLE_NAME, $table);
        }
        $rows = $query->order(SchemaIndexState::schema_fields_UPDATE_TIME, 'DESC')->select()->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,mixed> $state */
    private function enqueueRepair(array $state, string $table, string $index, string $physical): int
    {
        $action = (string)($state[SchemaIndexState::schema_fields_STATUS] ?? '') === SchemaIndexState::STATUS_REMOVED
            ? SchemaIndexJob::ACTION_DROP
            : SchemaIndexJob::ACTION_CREATE;
        $existing = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_INDEX_NAME, $index)
            ->where(SchemaIndexJob::schema_fields_STATUS, [
                SchemaIndexJob::STATUS_PENDING,
                SchemaIndexJob::STATUS_QUEUED,
                SchemaIndexJob::STATUS_RUNNING,
            ], 'IN')
            ->total();
        if ((int)$existing > 0) {
            return 0;
        }

        $definitionJson = (string)($state[SchemaIndexState::schema_fields_DEFINITION_JSON] ?? '');
        if ($definitionJson === '') {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_MODULE => (string)($state[SchemaIndexState::schema_fields_MODULE] ?? ''),
            SchemaIndexJob::schema_fields_TABLE_NAME => $table,
            SchemaIndexJob::schema_fields_INDEX_NAME => $index,
            SchemaIndexJob::schema_fields_ACTION => $action,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => (string)($state[SchemaIndexState::schema_fields_DEFINITION_HASH] ?? hash('sha256', $definitionJson)),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => $physical,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 0,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 3,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexJob::schema_fields_ID)->fetch();
        $inserted = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_INDEX_NAME, $index)
            ->where(SchemaIndexJob::schema_fields_ACTION, $action)
            ->order(SchemaIndexJob::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        return (int)($inserted[0][SchemaIndexJob::schema_fields_ID] ?? 0);
    }

    private function updateState(int $stateId, string $status, ?string $error): void
    {
        ObjectManager::create(SchemaIndexState::class, [], false)->clear()->reset()
            ->where(SchemaIndexState::schema_fields_ID, $stateId)
            ->update([
                SchemaIndexState::schema_fields_STATUS => $status,
                SchemaIndexState::schema_fields_LAST_CHECKED_AT => date('Y-m-d H:i:s'),
                SchemaIndexState::schema_fields_LAST_ERROR => $error,
                SchemaIndexState::schema_fields_UPDATE_TIME => date('Y-m-d H:i:s'),
            ])->fetch();
    }

    private function touchState(int $stateId): void
    {
        ObjectManager::create(SchemaIndexState::class, [], false)->clear()->reset()
            ->where(SchemaIndexState::schema_fields_ID, $stateId)
            ->update([
                SchemaIndexState::schema_fields_LAST_CHECKED_AT => date('Y-m-d H:i:s'),
                SchemaIndexState::schema_fields_UPDATE_TIME => date('Y-m-d H:i:s'),
            ])->fetch();
    }
}
