<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Model\Release\SchemaChange;
use Weline\Database\Service\Release\SchemaUpgradePlanner;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * T44a 静态验收：SchemaUpgradePlanner 只规划、不执行 DDL。
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
        echo 'T44A PLAN FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T44A PLAN PASS' . PHP_EOL;
    exit(0);
};

// ---- 1. 结构与签名 ----
$reflection = new ReflectionClass(SchemaUpgradePlanner::class);
$check($reflection->isFinal(), 'SchemaUpgradePlanner 必须是 final');

$constructor = $reflection->getConstructor();
$check($constructor !== null, 'SchemaUpgradePlanner 缺少构造函数');
if ($constructor !== null) {
    $check(
        $constructor->getNumberOfParameters() === 3
            && $constructor->getNumberOfRequiredParameters() === 3,
        'SchemaUpgradePlanner 构造参数应为 3 个必填依赖'
    );
    $constructorTypes = \array_map(
        static fn(ReflectionParameter $parameter): string => (string)$parameter->getType(),
        $constructor->getParameters(),
    );
    $check(
        $constructorTypes === [
            SchemaDiffEngine::class,
            SchemaChange::class,
            ConnectionFactory::class,
        ],
        'SchemaUpgradePlanner 构造依赖应为 SchemaDiffEngine/SchemaChange/ConnectionFactory'
    );
}

$planMethod = $reflection->getMethod('plan');
$check($planMethod->isPublic(), 'SchemaUpgradePlanner::plan 必须 public');
$planParameters = \array_map(
    static fn(ReflectionParameter $parameter): string => $parameter->getName(),
    $planMethod->getParameters(),
);
$check(
    $planParameters === ['releaseId', 'manifest', 'options'],
    'SchemaUpgradePlanner::plan(string $releaseId, array $manifest, array $options = []) 签名错误'
);
$check(
    $planMethod->getParameters()[2]->isDefaultValueAvailable()
        && $planMethod->getParameters()[2]->getDefaultValue() === [],
    'plan() 的 options 默认值应为 []'
);

$planTablesMethod = $reflection->getMethod('planTables');
$check($planTablesMethod->isPublic(), 'SchemaUpgradePlanner::planTables 必须 public');
$planTablesParameters = \array_map(
    static fn(ReflectionParameter $parameter): string => $parameter->getName(),
    $planTablesMethod->getParameters(),
);
$check(
    $planTablesParameters === ['declaredSchemas', 'actualSchemas', 'releaseId', 'options'],
    'planTables() 签名错误'
);

// ---- 2. 纯规划：expand / contract 分类 ----
$table = 't44a_planner_probe';
$id = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID');
$legacy = new ColumnDefinition('legacy', 'varchar', 64, true, false, false, null, 'Legacy');
$name = new ColumnDefinition('name', 'varchar', 128, true, false, false, null, 'Name');

$actual = new TableSchema(
    tableName: $table,
    comment: 'T44a probe',
    columns: [$id, $legacy],
    indexes: [new IndexDefinition('idx_legacy', ['legacy'])],
    foreignKeys: [new ForeignKeyDefinition('fk_legacy', ['legacy'], 'other_table', ['id'])],
);
$declared = new TableSchema(
    tableName: $table,
    comment: 'T44a probe',
    columns: [$id, $name],
    indexes: [new IndexDefinition('idx_name', ['name'])],
    foreignKeys: [new ForeignKeyDefinition('fk_name', ['name'], 'other_table', ['id'])],
);

$planner = new SchemaUpgradePlanner(
    new SchemaDiffEngine(),
    new SchemaChange(),
    ConnectionFactory::getInstance(),
);
$options = ['module' => 'Weline_Test', 'db_type' => 'mysql'];
$plan = $planner->planTables([$table => $declared], [$table => $actual], 'rel-t44a', $options);

$check(
    \array_keys($plan) === ['release_id', 'expand', 'transform', 'contract', 'blockers', 'table_count'],
    'plan 顶层字段应为 release_id/expand/transform/contract/blockers/table_count'
);
$check($plan['release_id'] === 'rel-t44a', 'plan release_id 错误');
$check($plan['table_count'] === 1, 'table_count 应为 1');
$check($plan['transform'] === [], 'transform 必须为空数组（由外部 DataTransform 提供）');
$check($plan['blockers'] === [], '正常 diff 不应产生 blockers');

$expandTypes = \array_map(static fn(array $change): string => $change['change_type'], $plan['expand']);
$contractTypes = \array_map(static fn(array $change): string => $change['change_type'], $plan['contract']);
\sort($expandTypes);
\sort($contractTypes);
$check(
    $expandTypes === ['add_column', 'add_foreign_key', 'add_index'],
    'expand 应包含 add_column/add_index/add_foreign_key，实际：' . \implode(',', $expandTypes)
);
$check(
    $contractTypes === ['drop_column', 'drop_foreign_key', 'drop_index'],
    'contract 应包含 drop_column/drop_index/drop_foreign_key，实际：' . \implode(',', $contractTypes)
);

$changeKeys = ['module', 'table', 'node_id', 'change_type', 'phase', 'payload', 'rollback_payload', 'checksum'];
foreach (['expand', 'contract'] as $phase) {
    foreach ($plan[$phase] as $change) {
        $check(\array_keys($change) === $changeKeys, $phase . ' change 字段不匹配');
        $check($change['phase'] === $phase, 'change phase 与所属分类不一致：' . $change['node_id']);
        $check($change['module'] === 'Weline_Test', 'change module 错误：' . $change['node_id']);
        $check($change['table'] === $table, 'change table 错误：' . $change['node_id']);
        $check(
            \preg_match('/^Weline_Test:t44a_planner_probe:(add|drop)_(column|index|foreign_key):[0-9a-f]{12}$/', $change['node_id']) === 1,
            'node_id 格式错误：' . $change['node_id']
        );
        $check(
            \strlen($change['checksum']) === 64 && \ctype_xdigit($change['checksum']),
            'checksum 应为 64 位 hex：' . $change['node_id']
        );
    }
}

$expandByName = [];
foreach ($plan['expand'] as $change) {
    $expandByName[$change['payload']['name']] = $change;
}
$contractByName = [];
foreach ($plan['contract'] as $change) {
    $contractByName[$change['payload']['name']] = $change;
}
$check(isset($expandByName['name']), 'expand 应包含新列 name');
$check(($expandByName['name']['payload']['length'] ?? null) === 128, 'add_column payload 应保留列定义');
$check(isset($contractByName['legacy']), 'contract 应包含旧列 legacy');
$check(($contractByName['legacy']['payload']['length'] ?? null) === 64, 'drop_column payload 应保留旧列定义');
$check(isset($expandByName['idx_name']), 'expand 应包含新索引 idx_name');
$check(isset($contractByName['idx_legacy']), 'contract 应包含旧索引 idx_legacy');
$check(isset($expandByName['fk_name']), 'expand 应包含新外键 fk_name');
$check(isset($contractByName['fk_legacy']), 'contract 应包含旧外键 fk_legacy');

$firstNodeIds = [];
$firstChecksums = [];
foreach (['expand', 'contract'] as $phase) {
    foreach ($plan[$phase] as $change) {
        $firstNodeIds[$change['change_type'] . ':' . $change['payload']['name']] = $change['node_id'];
        $firstChecksums[$change['change_type'] . ':' . $change['payload']['name']] = $change['checksum'];
    }
}

$repeatPlan = $planner->planTables([$table => $declared], [$table => $actual], 'rel-t44a', $options);
$check($repeatPlan === $plan, '同一输入两次规划结果必须完全一致（node_id/checksum 稳定）');
foreach (['expand', 'contract'] as $phase) {
    foreach ($repeatPlan[$phase] as $change) {
        $key = $change['change_type'] . ':' . $change['payload']['name'];
        $check(($firstNodeIds[$key] ?? null) === $change['node_id'], 'node_id 不稳定：' . $key);
        $check(($firstChecksums[$key] ?? null) === $change['checksum'], 'checksum 不稳定：' . $key);
    }
}

// ---- 3. 缺表 -> create_table（expand） ----
$createPlan = $planner->planTables([$table => $declared], [], 'rel-t44a', $options);
$check(\count($createPlan['expand']) === 1, '缺表时应只产出一条 create_table');
$check(\count($createPlan['contract']) === 0, '缺表时不应产出 contract');
$createChange = $createPlan['expand'][0] ?? null;
$check(\is_array($createChange) && $createChange['change_type'] === 'create_table', 'create_table 分类错误');
$check(($createChange['payload']['tableName'] ?? null) === $table, 'create_table payload 应包含表名');
$check($createChange['rollback_payload'] === null, 'create_table 无回滚 payload');

// ---- 4. MODIFY_COLUMN / MODIFY_TABLE_COMMENT -> contract ----
$modifiedDeclared = new TableSchema(
    tableName: 't44a_planner_modify',
    comment: 'Declared comment',
    columns: [
        new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID'),
        new ColumnDefinition('name', 'varchar', 255, true, false, false, null, 'Name'),
    ],
);
$modifiedActual = new TableSchema(
    tableName: 't44a_planner_modify',
    comment: 'Actual comment',
    columns: [
        new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID'),
        new ColumnDefinition('name', 'varchar', 128, true, false, false, null, 'Name'),
    ],
);
$modifyPlan = $planner->planTables(
    ['t44a_planner_modify' => $modifiedDeclared],
    ['t44a_planner_modify' => $modifiedActual],
    'rel-t44a',
    $options,
);
$modifyTypes = \array_map(static fn(array $change): string => $change['change_type'], $modifyPlan['contract']);
\sort($modifyTypes);
$check(
    $modifyTypes === ['modify_column', 'modify_table_comment'],
    '列/注释漂移应归入 contract，实际：' . \implode(',', $modifyTypes)
);
foreach ($modifyPlan['contract'] as $change) {
    $check($change['phase'] === 'contract', 'modify 类变更 phase 应为 contract');
}
$commentChange = null;
foreach ($modifyPlan['contract'] as $change) {
    if ($change['change_type'] === 'modify_table_comment') {
        $commentChange = $change;
    }
}
$check($commentChange !== null, '缺少 modify_table_comment');
$check(($commentChange['payload'] ?? null) === 'Declared comment', 'modify_table_comment 正向 payload 错误');
$check(($commentChange['rollback_payload'] ?? null) === 'Actual comment', 'modify_table_comment 回滚 payload 错误');

// ---- 5. 幂等：声明与现状一致时无变更 ----
$samePlan = $planner->planTables([$table => $declared], [$table => $declared], 'rel-t44a', $options);
$check($samePlan['expand'] === [] && $samePlan['contract'] === [], '声明与现状一致时不应产出变更');
$check($samePlan['table_count'] === 1, '无变更时 table_count 仍应统计表');

$invalidPlan = $planner->planTables(['bad' => 'not-a-schema'], [], 'rel-t44a', $options);
$check(\count($invalidPlan['blockers']) === 1, '非法 declared schema 应进入 blockers');
$check($invalidPlan['expand'] === [], '非法 declared schema 不应产出变更');

$emptyReleaseRejected = false;
try {
    $planner->plan('', ['modules' => []]);
} catch (InvalidArgumentException) {
    $emptyReleaseRejected = true;
}
$check($emptyReleaseRejected, 'plan() 空 release id 应抛 InvalidArgumentException');

// ---- 6. 不执行 DDL / 不接旧域 ----
$plannerSource = (string)\file_get_contents(BP . 'app/code/Weline/Database/Service/Release/SchemaUpgradePlanner.php');
$check($plannerSource !== '', '无法读取 SchemaUpgradePlanner 源码');
foreach (['ALTER TABLE', 'CREATE TABLE', 'DROP TABLE', '->query('] as $ddl) {
    $check(!\str_contains($plannerSource, $ddl), 'planner 不应执行 DDL：' . $ddl);
}
foreach (['SchemaRelease', 'SchemaCheckpoint'] as $forbidden) {
    $check(!\str_contains($plannerSource, $forbidden), 'planner 不应写 release/checkpoint：' . $forbidden);
}

$legacySymbols = [
    'SchemaDiff' . 'Stage',
    'Migration' . 'Service',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
foreach ([$plannerSource, (string)\file_get_contents(__FILE__)] as $source) {
    foreach ($legacySymbols as $symbol) {
        $check(!\str_contains($source, $symbol), '发现旧域符号：' . $symbol);
    }
}

echo 'OK SchemaUpgradePlanner (expand=' . \count($plan['expand'])
    . ' contract=' . \count($plan['contract']) . ')' . PHP_EOL;
echo 'OK create_table / modify_column / modify_table_comment classification' . PHP_EOL;
echo 'OK no DDL, no legacy symbols' . PHP_EOL;


// ---- 3b. 新列上的 UNIQUE 索引必须推迟到 contract（先回填再建唯一索引） ----
$uniqueTable = 't44a_planner_unique';
$uniqueId = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID');
$uniqueCode = new ColumnDefinition('code', 'varchar', 64, false, false, false, '', 'Code');
$uniqueActual = new TableSchema(tableName: $uniqueTable, comment: '', columns: [$uniqueId], indexes: [], foreignKeys: []);
$uniqueDeclared = new TableSchema(
    tableName: $uniqueTable,
    comment: '',
    columns: [$uniqueId, $uniqueCode],
    indexes: [new IndexDefinition('uk_code', ['code'], 'UNIQUE')],
    foreignKeys: [],
);
$uniquePlan = $planner->planTables([$uniqueTable => $uniqueDeclared], [$uniqueTable => $uniqueActual], 'rel-t44a', $options);
$uniqueExpand = array_map(static fn(array $c): string => $c['change_type'], $uniquePlan['expand']);
$uniqueContract = array_map(static fn(array $c): string => $c['change_type'], $uniquePlan['contract']);
$check(in_array('add_column', $uniqueExpand, true), '新列 code 应在 expand');
$check(in_array('add_index', $uniqueContract, true), '新列上的 UNIQUE 索引必须在 contract（避免回填前唯一冲突）');
$check(!in_array('add_index', $uniqueExpand, true), '新列上的 UNIQUE 索引不得在 expand 提前创建');


// ---- 3c. PostgreSQL: boolean default false vs 0 不应产生 modify_column ----
$boolTable = 't44a_planner_bool';
$boolId = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID');
$boolDeclaredCol = new ColumnDefinition('flag', 'boolean', null, false, false, false, 0, 'Flag');
$boolActualCol = new ColumnDefinition('flag', 'boolean', null, false, false, false, 'false', 'Flag');
$boolDeclared = new TableSchema(tableName: $boolTable, comment: '', columns: [$boolId, $boolDeclaredCol], indexes: [], foreignKeys: []);
$boolActual = new TableSchema(tableName: $boolTable, comment: '', columns: [$boolId, $boolActualCol], indexes: [], foreignKeys: []);
$boolPlan = $planner->planTables([$boolTable => $boolDeclared], [$boolTable => $boolActual], 'rel-t44a', ['module' => 'Weline_Test', 'db_type' => 'pgsql']);
$boolMods = array_filter($boolPlan['contract'], static fn(array $c): bool => $c['change_type'] === 'modify_column');
$check($boolMods === [], 'boolean default false/0 不应规划 modify_column');

// ---- 3d. PostgreSQL: 物理索引名应归一化，不产生 add/drop ----
$pgTable = 'w_t44a_planner_pgidx';
$pgId = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID');
$pgCode = new ColumnDefinition('code', 'varchar', 64, false, false, false, '', 'Code');
$pgDeclared = new TableSchema(
    tableName: $pgTable,
    comment: '',
    columns: [$pgId, $pgCode],
    indexes: [new IndexDefinition('idx_code', ['code'])],
    foreignKeys: [],
);
$pgActual = new TableSchema(
    tableName: $pgTable,
    comment: '',
    columns: [$pgId, $pgCode],
    indexes: [new IndexDefinition('public__' . $pgTable . '__idx_code', ['code'])],
    foreignKeys: [],
);
$pgPlan = $planner->planTables([$pgTable => $pgDeclared], [$pgTable => $pgActual], 'rel-t44a', ['module' => 'Weline_Test', 'db_type' => 'pgsql']);
$pgIndexOps = array_filter(
    array_merge($pgPlan['expand'], $pgPlan['contract']),
    static fn(array $c): bool => in_array($c['change_type'], ['add_index', 'drop_index'], true),
);
$check($pgIndexOps === [], 'PG 物理索引名不应被误判为 add/drop index');

$finish();
