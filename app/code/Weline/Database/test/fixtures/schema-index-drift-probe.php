<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P7 drift verification/self-heal:
 *  - applied state but missing physical index => drifted;
 *  - pending state is skipped (async backlog is not drift);
 *  - --fix enqueues a repair CREATE job.
 */

namespace {
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\SchemaIndexDriftService;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\Schema\IndexDefinitionContract;
    use Weline\Framework\Manager\ObjectManager;

    $logicalTable = '';

    try {
        require dirname(__DIR__, 5) . '/bootstrap.php';

        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        if (!in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $logicalTable = 'drift_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_drift_' . bin2hex(random_bytes(4));
        $pendingIndex = 'idx_drift_pending_' . bin2hex(random_bytes(4));
        $physicalTable = $connector->formatTableName($logicalTable);
        $connector->query('CREATE TABLE ' . $physicalTable . ' (id integer PRIMARY KEY, a integer)')->fetch();
        $definition = (string)json_encode([
            'name' => $index,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $connector->query($connector->buildAddIndexSql($physicalTable, [
            'name' => $index,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ]))->fetch();
        $physical = IndexDefinitionContract::physicalIdentity($connector, $logicalTable, $index);
        $now = date('Y-m-d H:i:s');
        $stateModel = ObjectManager::getInstance(SchemaIndexState::class);
        $stateModel->clear()->reset()->insert([
            SchemaIndexState::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexState::schema_fields_TABLE_NAME => $logicalTable,
            SchemaIndexState::schema_fields_INDEX_NAME => $index,
            SchemaIndexState::schema_fields_DEFINITION_JSON => $definition,
            SchemaIndexState::schema_fields_DEFINITION_HASH => hash('sha256', $definition),
            SchemaIndexState::schema_fields_PHYSICAL_NAME => $physical,
            SchemaIndexState::schema_fields_STATUS => SchemaIndexState::STATUS_APPLIED,
            SchemaIndexState::schema_fields_APPLIED_AT => $now,
            SchemaIndexState::schema_fields_LAST_CHECKED_AT => $now,
            SchemaIndexState::schema_fields_CREATE_TIME => $now,
            SchemaIndexState::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexState::schema_fields_ID)->fetch();
        $stateModel->clear()->reset()->insert([
            SchemaIndexState::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexState::schema_fields_TABLE_NAME => $logicalTable,
            SchemaIndexState::schema_fields_INDEX_NAME => $pendingIndex,
            SchemaIndexState::schema_fields_DEFINITION_JSON => $definition,
            SchemaIndexState::schema_fields_DEFINITION_HASH => hash('sha256', $definition . $pendingIndex),
            SchemaIndexState::schema_fields_PHYSICAL_NAME => IndexDefinitionContract::physicalIdentity($connector, $logicalTable, $pendingIndex),
            SchemaIndexState::schema_fields_STATUS => SchemaIndexState::STATUS_PENDING,
            SchemaIndexState::schema_fields_CREATE_TIME => $now,
            SchemaIndexState::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexState::schema_fields_ID)->fetch();

        // Simulate physical drift: the declared applied index disappears.
        $connector->query($connector->buildDropIndexSql($physicalTable, $physical))->fetch();

        $service = ObjectManager::getInstance(SchemaIndexDriftService::class);
        $detect = $service->verify(false, $logicalTable);
        $repair = $service->verify(true, $logicalTable);

        $allStates = $stateModel->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $logicalTable)
            ->select()
            ->fetchArray();
        $appliedStateStatus = '';
        $pendingStateStatus = '';
        foreach (is_array($allStates) ? $allStates : [] as $stateRow) {
            if (!is_array($stateRow)) {
                continue;
            }
            if ((string)($stateRow[SchemaIndexState::schema_fields_INDEX_NAME] ?? '') === $index) {
                $appliedStateStatus = (string)($stateRow[SchemaIndexState::schema_fields_STATUS] ?? '');
            }
            if ((string)($stateRow[SchemaIndexState::schema_fields_INDEX_NAME] ?? '') === $pendingIndex) {
                $pendingStateStatus = (string)($stateRow[SchemaIndexState::schema_fields_STATUS] ?? '');
            }
        }
        $repairJobId = (int)($repair['rows'][0]['repair_job_id'] ?? 0);
        $repairJobModel = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $repairJobId)
            ->find()
            ->fetch();
        $repairJob = $repairJobModel->getId()
            ? [
                SchemaIndexJob::schema_fields_ID => (int)$repairJobModel->getId(),
                SchemaIndexJob::schema_fields_ACTION => (string)$repairJobModel->getData(SchemaIndexJob::schema_fields_ACTION),
                SchemaIndexJob::schema_fields_STATUS => (string)$repairJobModel->getData(SchemaIndexJob::schema_fields_STATUS),
            ]
            : null;
        $ok = ($detect['checked'] ?? -1) === 1
            && ($detect['drifted'] ?? 0) === 1
            && $appliedStateStatus === SchemaIndexState::STATUS_DRIFTED
            && $pendingStateStatus === SchemaIndexState::STATUS_PENDING
            && ($repair['repaired'] ?? 0) === 1
            && is_array($repairJob)
            && (string)($repairJob[SchemaIndexJob::schema_fields_ACTION] ?? '') === SchemaIndexJob::ACTION_CREATE
            && (string)($repairJob[SchemaIndexJob::schema_fields_STATUS] ?? '') === SchemaIndexJob::STATUS_PENDING;

        echo json_encode([
            'ok' => $ok,
            'detect' => $detect,
            'repair' => $repair,
            'applied_state' => $appliedStateStatus,
            'pending_state' => $pendingStateStatus,
            'repair_job' => $repairJob,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    } finally {
        if ($logicalTable !== '') {
            try {
                $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
                $connector->query('DROP TABLE IF EXISTS ' . $connector->formatTableName($logicalTable) . ' CASCADE')->fetch();
                ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
                    ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $logicalTable)->delete()->fetch();
                ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
                    ->where(SchemaIndexState::schema_fields_TABLE_NAME, $logicalTable)->delete()->fetch();
            } catch (\Throwable) {
            }
        }
    }
}
