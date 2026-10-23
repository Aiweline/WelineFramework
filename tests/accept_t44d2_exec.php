<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\DataTransformProgress;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\ColumnBackupService;
use Weline\Database\Service\Release\DataTransformExecutor;
use Weline\Database\Service\Release\ExpandContractExecutor;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaChangeHydrator;
use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaParser;

/**
 * T44d2 静态验收：ExpandContractExecutor 的 expand/contract 编排与 rollback 补偿。
 */

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$finish = static function () use (&$failures): never {
    if ($failures !== []) {
        \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
        echo 'T44D2 EXEC FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T44D2 EXEC PASS' . PHP_EOL;
    exit(0);
};

$databasePath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 't44d2_exec_' . \bin2hex(\random_bytes(6)) . '.sqlite';
\register_shutdown_function(static function () use ($databasePath): void {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
});

$config = new ConfigProvider([
    'type' => 'sqlite',
    'database' => $databasePath,
    'prefix' => '',
]);
$connectionFactory = ConnectionFactory::getInstance($config);
$connector = $connectionFactory->getConnector();
$check(
    \strtolower($connector->getConfigProvider()->getDbType()) === 'sqlite',
    '验收连接必须是 sqlite'
);

(new ReleaseTableInstaller(new SchemaParser(), $connectionFactory))->install();

$journal = new SchemaJournal($connectionFactory, new SchemaOperationEvent());
$backupCatalog = new BackupCatalog($connectionFactory, new BackupSet(), new BackupObject());
$columnBackupService = new ColumnBackupService($connectionFactory, $backupCatalog, new DbSchemaReader());
$dataTransformExecutor = new DataTransformExecutor(
    $connectionFactory,
    new DataTransformProgress(),
    $journal,
);
$changeModel = new SchemaChange();
$changeModel->setConnection($connectionFactory);
$executor = new ExpandContractExecutor(
    new SchemaDdlExecutor($connectionFactory),
    new SchemaChangeHydrator(),
    $columnBackupService,
    $dataTransformExecutor,
    $backupCatalog,
    $journal,
    $changeModel,
);

$tableSql = $connector->formatTableName('exec_unit');
$columnNames = static function () use ($connector): array {
    return \array_map(
        static fn(array $column): string => (string)($column['name'] ?? ''),
        $connector->getTableColumns('exec_unit'),
    );
};
$legacyValues = static function () use ($connector, $tableSql): array {
    $rows = $connector->query('SELECT legacy FROM ' . $tableSql . ' ORDER BY id')->fetchArray();

    return \array_map(static fn(array $row): string => (string)($row['legacy'] ?? ''), $rows);
};
$changeStatus = static function (string $releaseId, string $nodeId) use ($connectionFactory): string {
    $model = new SchemaChange();
    $model->setConnection($connectionFactory);
    $rows = $model
        ->where(SchemaChange::schema_fields_RELEASE_ID, $releaseId)
        ->where(SchemaChange::schema_fields_NODE_ID, $nodeId)
        ->limit(1)
        ->select()
        ->fetchArray();

    return (string)($rows[0][SchemaChange::schema_fields_STATUS] ?? '');
};
$journalStates = static function (string $releaseId) use ($journal): array {
    return \array_map(
        static fn(array $event): string => (string)($event[SchemaOperationEvent::schema_fields_STATE] ?? ''),
        $journal->events($releaseId),
    );
};

// ---- 1. 初始表：exec_unit(id PK, name, legacy) + 3 行 ----
$connector->createTableFromSchema('exec_unit', [
    'comment' => 'T44d2 exec unit',
    'columns' => [
        ['name' => 'id', 'type' => 'int', 'length' => 11, 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'default' => null, 'comment' => 'ID', 'unique' => false],
        ['name' => 'name', 'type' => 'varchar', 'length' => 64, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false, 'default' => null, 'comment' => 'Name', 'unique' => false],
        ['name' => 'legacy', 'type' => 'varchar', 'length' => 64, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false, 'default' => null, 'comment' => 'Legacy', 'unique' => false],
    ],
    'indexes' => [],
    'foreignKeys' => [],
]);
foreach ([['Alpha', 'L1'], ['Beta', 'L2'], ['Gamma', 'L3']] as $row) {
    $connector->query(
        'INSERT INTO ' . $tableSql . " (name, legacy) VALUES ('" . $row[0] . "', '" . $row[1] . "')"
    )->fetch();
}
$check($columnNames() === ['id', 'name', 'legacy'], '初始列应为 id/name/legacy');
$check($legacyValues() === ['L1', 'L2', 'L3'], '初始 legacy 数据错误');

// ---- 2. 构造 plan：expand add note / contract drop legacy ----
$releaseId = 'rel-t44d2';
$scopeKey = 'sqlite:t44d2';
$operationId = 'op-t44d2';
$addNoteNode = 'Weline_Test:exec_unit:add_column:aaaaaaaaaaaa';
$dropLegacyNode = 'Weline_Test:exec_unit:drop_column:cccccccccccc';

$notePayload = [
    'autoIncrement' => false,
    'comment' => 'Note',
    'default' => null,
    'length' => 255,
    'name' => 'note',
    'nullable' => true,
    'primaryKey' => false,
    'type' => 'varchar',
    'unique' => false,
];
$legacyPayload = [
    'autoIncrement' => false,
    'comment' => 'Legacy',
    'default' => null,
    'length' => 64,
    'name' => 'legacy',
    'nullable' => true,
    'primaryKey' => false,
    'type' => 'varchar',
    'unique' => false,
];
$expandChange = [
    'module' => 'Weline_Test',
    'table' => 'exec_unit',
    'node_id' => $addNoteNode,
    'change_type' => 'add_column',
    'phase' => 'expand',
    'payload' => $notePayload,
    'rollback_payload' => null,
    'checksum' => \hash('sha256', 'add_note'),
];
$dropLegacyChange = [
    'module' => 'Weline_Test',
    'table' => 'exec_unit',
    'node_id' => $dropLegacyNode,
    'change_type' => 'drop_column',
    'phase' => 'contract',
    'payload' => $legacyPayload,
    'rollback_payload' => null,
    'checksum' => \hash('sha256', 'drop_legacy'),
];
$plan = [
    'release_id' => $releaseId,
    'scope_key' => $scopeKey,
    'expand' => [$expandChange],
    'transform' => [],
    'contract' => [$dropLegacyChange],
    'blockers' => [],
    'table_count' => 1,
];
$options = ['scope_key' => $scopeKey, 'operation_id' => $operationId];

$seedChange = static function (array $change) use ($connectionFactory, $releaseId, $scopeKey, $operationId): void {
    $model = new SchemaChange();
    $model->setConnection($connectionFactory);
    $model->setData([
        SchemaChange::schema_fields_RELEASE_ID => $releaseId,
        SchemaChange::schema_fields_OPERATION_ID => $operationId,
        SchemaChange::schema_fields_SCOPE_KEY => $scopeKey,
        SchemaChange::schema_fields_NODE_ID => (string)$change['node_id'],
        SchemaChange::schema_fields_MODULE_NAME => (string)$change['module'],
        SchemaChange::schema_fields_TABLE_NAME => (string)$change['table'],
        SchemaChange::schema_fields_CHANGE_TYPE => (string)$change['change_type'],
        SchemaChange::schema_fields_PHASE => (string)$change['phase'],
        SchemaChange::schema_fields_PAYLOAD_JSON => (string)\json_encode($change['payload'], JSON_UNESCAPED_UNICODE),
        SchemaChange::schema_fields_ROLLBACK_PAYLOAD_JSON => $change['rollback_payload'] === null
            ? null
            : (string)\json_encode($change['rollback_payload'], JSON_UNESCAPED_UNICODE),
        SchemaChange::schema_fields_CHECKSUM => (string)$change['checksum'],
        SchemaChange::schema_fields_STATUS => SchemaChange::STATUS_PLANNED,
        SchemaChange::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
    ]);
    $model->save();
};
foreach ([$expandChange, $dropLegacyChange] as $change) {
    $seedChange($change);
}

// ---- 3. apply：expand → contract ----
$applyResult = $executor->apply($releaseId, $plan, $options);
$check($applyResult['release_id'] === $releaseId, 'apply release_id 错误');
$check($applyResult['expanded'] === 1, 'apply expanded 应为 1');
$check($applyResult['transformed'] === 0, 'apply transformed 应为 0');
$check($applyResult['contracted'] === 1, 'apply contracted 应为 1');
$check(\count($applyResult['backups']) === 1, 'apply backups 应包含 legacy 列备份');
$check(\in_array('note', $columnNames(), true), 'apply 后 note 列应存在');
$check(!\in_array('legacy', $columnNames(), true), 'apply 后 legacy 列应消失');
$check(
    $changeStatus($releaseId, $addNoteNode) === SchemaChange::STATUS_APPLIED,
    'expand 节点应标记 applied'
);
$check(
    $changeStatus($releaseId, $dropLegacyNode) === SchemaChange::STATUS_APPLIED,
    'contract 节点应标记 applied'
);
$applyStates = $journalStates($releaseId);
$check(\in_array('expanded', $applyStates, true), 'journal 缺少 expanded');
$check(\count(\array_keys($applyStates, 'contracted', true)) === 1, 'journal 应有 1 条 contracted');
$check(!\in_array('failed', $applyStates, true), 'apply 不应产生 failed');

$backupSet = $backupCatalog->findSet($releaseId, $scopeKey);
$check(\is_array($backupSet), 'BackupCatalog 应存在 release/scope 备份集');
$setId = (int)($backupSet['id'] ?? 0);
$columnObjects = $setId > 0
    ? $backupCatalog->objects($setId, [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'exec_unit',
        'column_name' => 'legacy',
    ])
    : [];
$check($columnObjects !== [], 'ColumnBackup 应有 legacy 列备份对象');
$columnDataJson = (string)($columnObjects[0][BackupObject::schema_fields_DATA_JSON] ?? '');
$check(
    \str_contains($columnDataJson, 'L1')
        && \str_contains($columnDataJson, 'L2')
        && \str_contains($columnDataJson, 'L3'),
    'ColumnBackup 数据应包含 L1/L2/L3'
);
$check(
    (int)($columnObjects[0][BackupObject::schema_fields_ROW_COUNT] ?? 0) === 3,
    'ColumnBackup 行数应为 3'
);
// ---- 4. rollback：反向 contract → 反向 expand ----
$rollbackResult = $executor->rollback($releaseId, $plan, $options);
$check($rollbackResult['release_id'] === $releaseId, 'rollback release_id 错误');
$check($rollbackResult['compensated'] === 1, 'rollback compensated 应为 1');
$check($rollbackResult['rolled_back'] === 1, 'rollback rolled_back 应为 1');
$check(\in_array('legacy', $columnNames(), true), 'rollback 后 legacy 列应恢复');
$check(!\in_array('note', $columnNames(), true), 'rollback 后 note 列应删除');
$check($legacyValues() === ['L1', 'L2', 'L3'], 'rollback 后 legacy 数据应恢复');
$check(
    $changeStatus($releaseId, $addNoteNode) === SchemaChange::STATUS_ROLLED_BACK,
    'rollback 后 expand 节点应标记 rolled_back'
);
$rollbackStates = $journalStates($releaseId);
$check(\count(\array_keys($rollbackStates, 'compensated', true)) === 2, 'journal 应有 2 条 compensated');
$compensatedEvents = \array_values(\array_filter(
    $journal->events($releaseId),
    static fn(array $event): bool => (string)($event[SchemaOperationEvent::schema_fields_STATE] ?? '') === 'compensated',
));
$check($compensatedEvents !== [], 'compensated journal 事件缺失');
$detailJson = (string)($compensatedEvents[0][SchemaOperationEvent::schema_fields_DETAIL_JSON] ?? '');
$check(
    \str_contains($detailJson, '"reason":"rollback"')
        && \str_contains($detailJson, '"target_release_id":"' . $releaseId . '"'),
    'compensated detail 应包含 reason=rollback / target_release_id'
);
$restoreBackups = \array_values(\array_filter(
    $rollbackResult['backups'],
    static fn(array $backup): bool => ($backup['type'] ?? '') === 'column_restore',
));
$check($restoreBackups !== [], 'rollback 应返回 column_restore 备份记录');
$check(
    (int)($restoreBackups[0]['restored'] ?? 0) === 3,
    'rollback 应恢复 3 行 legacy 数据'
);
$postRollbackColumnObjects = $setId > 0
    ? $backupCatalog->objects($setId, [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'exec_unit',
        'column_name' => 'legacy',
    ])
    : [];
$check($postRollbackColumnObjects !== [], 'rollback 后 column backup 对象应仍存在');

// ---- 5. CREATE_TABLE 回滚：先写结构备份再 drop ----
$auxReleaseId = 'rel-t44d2-aux';
$auxScopeKey = 'sqlite:t44d2:aux';
$auxOperationId = 'op-t44d2-aux';
$auxChange = [
    'module' => 'Weline_Test',
    'table' => 'exec_aux',
    'table_name' => 'exec_aux',
    'node_id' => 'Weline_Test:exec_aux:create_table:dddddddddddd',
    'change_type' => 'create_table',
    'phase' => 'expand',
    'payload' => [
        'tableName' => 'exec_aux',
        'comment' => 'Aux table',
        'columns' => [[
            'autoIncrement' => true,
            'comment' => 'ID',
            'default' => null,
            'length' => 11,
            'name' => 'id',
            'nullable' => false,
            'primaryKey' => true,
            'type' => 'int',
            'unique' => false,
        ]],
        'indexes' => [],
        'foreignKeys' => [],
    ],
    'rollback_payload' => null,
    'checksum' => \hash('sha256', 'create_aux'),
];
$auxPlan = [
    'release_id' => $auxReleaseId,
    'scope_key' => $auxScopeKey,
    'expand' => [$auxChange],
    'transform' => [],
    'contract' => [],
];
$auxOptions = ['scope_key' => $auxScopeKey, 'operation_id' => $auxOperationId];
$executor->apply($auxReleaseId, $auxPlan, $auxOptions);
$check($connector->tableExist('exec_aux'), 'CREATE_TABLE apply 后 exec_aux 应存在');
$executor->rollback($auxReleaseId, $auxPlan, $auxOptions);
$check(!$connector->tableExist('exec_aux'), 'CREATE_TABLE rollback 后 exec_aux 应删除');
$auxSet = $backupCatalog->findSet($auxReleaseId, $auxScopeKey);
$auxSetId = (int)($auxSet['id'] ?? 0);
$auxStructureObjects = $auxSetId > 0
    ? $backupCatalog->objects($auxSetId, ['object_type' => 'structure', 'table_name' => 'exec_aux'])
    : [];
$check($auxStructureObjects !== [], 'CREATE_TABLE rollback 前应写入表结构备份');

// ---- 6. 失败注入：错误 payload → journal failed、抛异常、不删已有列 ----
$failReleaseId = 'rel-t44d2-fail';
$failPlan = [
    'release_id' => $failReleaseId,
    'scope_key' => $scopeKey,
    'expand' => [],
    'transform' => [],
    'contract' => [[
        'module' => 'Weline_Test',
        'table' => 'exec_unit',
        'node_id' => 'Weline_Test:exec_unit:drop_column:eeeeeeeeeeee',
        'change_type' => 'drop_column',
        'phase' => 'contract',
        'payload' => 'not-a-column',
        'rollback_payload' => null,
        'checksum' => \hash('sha256', 'fail_drop_column'),
    ]],
];
$thrown = false;
try {
    $executor->apply($failReleaseId, $failPlan, ['scope_key' => $scopeKey, 'operation_id' => 'op-fail']);
} catch (RuntimeException) {
    $thrown = true;
}
$check($thrown, '错误 payload 应抛 RuntimeException');
$check(\in_array('legacy', $columnNames(), true), '失败后 legacy 列不得被删除');
$check(\in_array('name', $columnNames(), true), '失败后 name 列不得被删除');
$check($legacyValues() === ['L1', 'L2', 'L3'], '失败后 legacy 数据不得变化');
$failStates = $journalStates($failReleaseId);
$check(\in_array('failed', $failStates, true), '失败后 journal 应记录 failed');

// ---- 7. 不接旧域 ----
$executorSource = (string)\file_get_contents(
    BP . 'app/code/Weline/Database/Service/Release/ExpandContractExecutor.php'
);
$check($executorSource !== '', '无法读取 ExpandContractExecutor 源码');
$legacySymbols = [
    'SchemaMigration' . 'Executor',
    'SchemaDiff' . 'Stage',
    'Migration' . 'Service',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
foreach ([$executorSource, (string)\file_get_contents(__FILE__)] as $source) {
    foreach ($legacySymbols as $symbol) {
        $check(!\str_contains($source, $symbol), '发现旧域符号：' . $symbol);
    }
}

echo 'OK ExpandContractExecutor (expand=' . $applyResult['expanded']
    . ' contract=' . $applyResult['contracted']
    . ' rollback=' . ($rollbackResult['compensated'] + $rollbackResult['rolled_back']) . ')' . PHP_EOL;
echo 'OK ColumnBackup / BackupCatalog structure + column objects' . PHP_EOL;
echo 'OK failed journal + no destructive DDL on invalid payload' . PHP_EOL;

$finish();
