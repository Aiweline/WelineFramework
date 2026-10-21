<?php

declare(strict_types=1);

/**
 * Real-pgsql probe for P7: same-name REBUILD must create the new definition,
 * swap names without leaving the old index, and DROP must remove it.
 */

namespace {
    use Weline\Database\Model\SchemaIndexJob;
    use Weline\Database\Model\SchemaIndexState;
    use Weline\Database\Service\Index\IndexApplier;
    use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
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
        if (!$connector instanceof ConcurrentIndexBuildInterface) {
            echo json_encode(['skipped' => true, 'reason' => 'concurrent index capability required'], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $logicalTable = 'rb_probe_' . bin2hex(random_bytes(4));
        $logicalIndex = 'idx_rb_' . bin2hex(random_bytes(4));
        $physicalTable = $connector->formatTableName($logicalTable);
        $connector->query('CREATE TABLE ' . $physicalTable . ' (id integer PRIMARY KEY, a integer, b integer)')->fetch();
        $oldPhysical = IndexDefinitionContract::physicalIdentity($connector, $logicalTable, $logicalIndex);
        $connector->query($connector->buildAddIndexSql($physicalTable, [
            'name' => $logicalIndex,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ]))->fetch();

        $oldJson = (string)json_encode([
            'name' => $logicalIndex,
            'columns' => ['a'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newJson = (string)json_encode([
            'name' => $logicalIndex,
            'columns' => ['b'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $jobModel->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $logicalTable,
            SchemaIndexJob::schema_fields_INDEX_NAME => $logicalIndex,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_REBUILD,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $newJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $newJson),
            SchemaIndexJob::schema_fields_OLD_DEFINITION_JSON => $oldJson,
            SchemaIndexJob::schema_fields_OLD_DEFINITION_HASH => hash('sha256', $oldJson),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => $oldPhysical,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 0,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 1,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexJob::schema_fields_ID)->fetch();
        $rebuildJob = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $logicalTable)
            ->where(SchemaIndexJob::schema_fields_ACTION, SchemaIndexJob::ACTION_REBUILD)
            ->find()
            ->fetch();
        $rebuildId = (int)$rebuildJob->getId();

        $applier = ObjectManager::getInstance(IndexApplier::class);
        $rebuildResult = $applier->apply($rebuildId);
        $rebuildJob = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $rebuildId)
            ->find()
            ->fetch();
        $newPhysical = (string)$rebuildJob->getData(SchemaIndexJob::schema_fields_PHYSICAL_NAME);
        $validAfterRebuild = $connector->isIndexValid($logicalTable, $newPhysical);
        $cleanPhysical = str_replace(['"', '`'], '', (string)$physicalTable);
        $cleanTableName = (string)substr($cleanPhysical, (int)strrpos($cleanPhysical, '.') + 1);
        $indexDefRows = $connector->query(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = '"
            . str_replace("'", "''", $cleanTableName)
            . "' AND indexname = '" . str_replace("'", "''", $newPhysical) . "'"
        )->fetchArray();
        $indexDefRow = is_array($indexDefRows) ? ($indexDefRows[0] ?? null) : null;
        $newDefinitionText = is_array($indexDefRow) ? (string)($indexDefRow['indexdef'] ?? '') : '';

        $dropJson = (string)json_encode([
            'name' => $logicalIndex,
            'columns' => ['b'],
            'type' => 'DEFAULT',
            'method' => 'BTREE',
            'comment' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jobModel->clear()->reset()->insert([
            SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
            SchemaIndexJob::schema_fields_TABLE_NAME => $logicalTable,
            SchemaIndexJob::schema_fields_INDEX_NAME => $logicalIndex,
            SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_DROP,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $dropJson,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $dropJson),
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => $newPhysical,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 0,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 1,
            SchemaIndexJob::schema_fields_CREATE_TIME => $now,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ], SchemaIndexJob::schema_fields_ID)->fetch();
        $dropJob = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $logicalTable)
            ->where(SchemaIndexJob::schema_fields_ACTION, SchemaIndexJob::ACTION_DROP)
            ->find()
            ->fetch();
        $dropId = (int)$dropJob->getId();
        $dropResult = $applier->apply($dropId);
        $absentAfterDrop = !$connector->isIndexPresent($logicalTable, $newPhysical);
        $dropJob = $jobModel->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $dropId)
            ->find()
            ->fetch();
        $dropState = ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $logicalTable)
            ->find()
            ->fetch();

        $ok = !empty($rebuildResult['success'])
            && ($rebuildResult['status'] ?? '') === SchemaIndexJob::STATUS_DONE
            && $validAfterRebuild
            && str_contains($newDefinitionText, '(b)')
            && !empty($dropResult['success'])
            && ($dropResult['status'] ?? '') === SchemaIndexJob::STATUS_DONE
            && $absentAfterDrop
            && (string)$dropJob->getData(SchemaIndexJob::schema_fields_STATUS) === SchemaIndexJob::STATUS_DONE
            && (string)$dropState->getData(SchemaIndexState::schema_fields_STATUS) === SchemaIndexState::STATUS_REMOVED;

        echo json_encode([
            'ok' => $ok,
            'rebuild_job_id' => $rebuildId,
            'rebuild_result' => $rebuildResult,
            'new_physical' => $newPhysical,
            'new_definition' => $newDefinitionText,
            'drop_job_id' => $dropId,
            'drop_result' => $dropResult,
            'drop_state' => (string)$dropState->getData(SchemaIndexState::schema_fields_STATUS),
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
