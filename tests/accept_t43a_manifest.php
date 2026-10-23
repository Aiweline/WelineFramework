<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Service\Release\SchemaFingerprint;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;

/**
 * T43a 静态验收：SchemaFingerprint + SchemaManifestBuilder。
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
        echo 'T43A MANIFEST FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T43A MANIFEST PASS' . PHP_EOL;
    exit(0);
};

// ---- 1. 真实 Model 解析 + 指纹 ----
$parser = new SchemaParser();
$schema = $parser->parse('Weline\\Eav\\Model\\EavEntity');
$check($schema instanceof TableSchema, 'SchemaParser 应能解析 Weline\Eav\Model\EavEntity');
if (!$schema instanceof TableSchema) {
    $finish();
}
$check($schema->columns !== [], '解析结果应包含列声明');

$fingerprintReflection = new ReflectionClass(SchemaFingerprint::class);
$check($fingerprintReflection->isFinal(), 'SchemaFingerprint 必须是 final');
$fingerprintMethod = $fingerprintReflection->getMethod('fingerprint');
$check(
    $fingerprintMethod->getNumberOfParameters() === 1
        && (string)$fingerprintMethod->getParameters()[0]->getType() === TableSchema::class
        && (string)$fingerprintMethod->getReturnType() === 'string',
    'SchemaFingerprint::fingerprint(TableSchema): string 签名错误'
);

$fingerprint = new SchemaFingerprint(ConnectionFactory::getInstance());
$hash = $fingerprint->fingerprint($schema);
$check(
    \strlen($hash) === 64 && \ctype_xdigit($hash),
    'SchemaFingerprint 应返回 64 位 hex'
);
$check($fingerprint->fingerprint($schema) === $hash, '同一 schema 两次指纹应一致');

$withoutModelClass = new TableSchema(
    tableName: $schema->tableName,
    comment: $schema->comment,
    columns: $schema->columns,
    indexes: $schema->indexes,
    foreignKeys: $schema->foreignKeys,
    modelClass: null,
);
$check($fingerprint->fingerprint($withoutModelClass) === $hash, 'modelClass 不应参与指纹');

$commentChanged = new TableSchema(
    tableName: $schema->tableName,
    comment: $schema->comment . ' changed',
    columns: $schema->columns,
    indexes: $schema->indexes,
    foreignKeys: $schema->foreignKeys,
    modelClass: $schema->modelClass,
);
$check($fingerprint->fingerprint($commentChanged) !== $hash, '表 comment 变化后指纹应不同');

$columns = $schema->columns;
$firstColumn = $columns[0];
$columns[0] = new ColumnDefinition(
    name: $firstColumn->name . '_changed',
    type: $firstColumn->type,
    length: $firstColumn->length,
    nullable: $firstColumn->nullable,
    primaryKey: $firstColumn->primaryKey,
    autoIncrement: $firstColumn->autoIncrement,
    default: $firstColumn->default,
    comment: $firstColumn->comment,
    unique: $firstColumn->unique,
);
$columnChanged = new TableSchema(
    tableName: $schema->tableName,
    comment: $schema->comment,
    columns: $columns,
    indexes: $schema->indexes,
    foreignKeys: $schema->foreignKeys,
    modelClass: $schema->modelClass,
);
$check($fingerprint->fingerprint($columnChanged) !== $hash, '列变化后指纹应不同');

// ---- 2. SchemaManifestBuilder 结构与单模块构建 ----
$builderReflection = new ReflectionClass(SchemaManifestBuilder::class);
$check($builderReflection->isFinal(), 'SchemaManifestBuilder 必须是 final');
$check($builderReflection->hasMethod('build'), 'SchemaManifestBuilder::build 不存在');
$buildMethod = $builderReflection->getMethod('build');
$buildParameters = $buildMethod->getParameters();
$check(
    \count($buildParameters) === 1
        && $buildParameters[0]->getName() === 'moduleFilter'
        && (string)$buildParameters[0]->getType() === 'array'
        && $buildParameters[0]->isDefaultValueAvailable(),
    'SchemaManifestBuilder::build(array $moduleFilter = []) 签名错误'
);
$constructor = $builderReflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->getNumberOfRequiredParameters() === 4
        && $constructor->getNumberOfParameters() >= 5,
    'SchemaManifestBuilder 构造参数应为 4 必填 + 可选（buildActual 追加）'
);

$builder = new SchemaManifestBuilder(
    ObjectManager::getInstance(Handle::class),
    ObjectManager::getInstance(ModuleFileReader::class),
    new SchemaParser(),
    $fingerprint,
);
$manifest = $builder->build(['Weline_Eav']);

$check(
    \array_keys($manifest) === ['modules', 'checksum', 'generated_at'],
    'manifest 顶层字段应为 modules/checksum/generated_at'
);
$check(\array_keys($manifest['modules']) === ['Weline_Eav'], 'moduleFilter 应只输出 Weline_Eav');
$module = $manifest['modules']['Weline_Eav'] ?? null;
$check(\is_array($module), 'Weline_Eav 模块清单缺失');
if (!\is_array($module)) {
    $finish();
}

$check(
    \array_keys($module) === ['version', 'tables', 'schemas', 'checksum'],
    '模块字段应为 version/tables/schemas/checksum'
);
$check($module['tables'] !== [], 'Weline_Eav tables 不应为空');
$check(
    \array_keys($module['tables']) === \array_keys($module['schemas']),
    'schemas 的键必须与 tables 一致'
);

foreach ($module['tables'] as $tableName => $tableHash) {
    $check(\str_contains($tableName, 'eav_'), '表名应为声明式逻辑表名：' . $tableName);
    $check(\strlen($tableHash) === 64 && \ctype_xdigit($tableHash), '表指纹应为 64 位 hex：' . $tableName);
    $check(!\str_contains($tableName, '"'), '表名不应保留标识符引号：' . $tableName);
    foreach (['weline_database_', 'weline_framework_field_', 'weline_module_', 'weline_schema_'] as $excluded) {
        $check(!\str_contains($tableName, $excluded), '排除表不应进入 manifest：' . $tableName);
    }
}

$schemaEntryKeys = ['comment', 'columns', 'indexes', 'foreignKeys'];
$columnKeys = ['name', 'type', 'length', 'nullable', 'primaryKey', 'autoIncrement', 'default', 'comment', 'unique'];
$indexKeys = ['name', 'columns', 'type', 'method', 'comment'];
$foreignKeyKeys = ['name', 'columns', 'referencesTable', 'referencesColumns', 'onDeleteCascade', 'onUpdateCascade'];

foreach ($module['schemas'] as $tableName => $definition) {
    $check(\is_array($definition), 'schemas 条目应为数组：' . $tableName);
    $check(
        \array_keys($definition) === $schemaEntryKeys,
        'schemas 条目字段应为 comment/columns/indexes/foreignKeys：' . $tableName
    );
    $check($definition['columns'] !== [], 'schemas 列声明不应为空：' . $tableName);
    foreach ($definition['columns'] as $column) {
        $check(\array_keys($column) === $columnKeys, '列字段不匹配：' . $tableName);
    }
    foreach ($definition['indexes'] as $index) {
        $check(\array_keys($index) === $indexKeys, '索引字段不匹配：' . $tableName);
    }
    foreach ($definition['foreignKeys'] as $foreignKey) {
        $check(\array_keys($foreignKey) === $foreignKeyKeys, '外键字段不匹配：' . $tableName);
    }
}

$tables = $module['tables'];
\ksort($tables);
$expectedModuleChecksum = \hash('sha256', (string)\json_encode(
    $tables,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
));
$check($module['checksum'] === $expectedModuleChecksum, '模块 checksum 应按 tables 归一后计算');
$check(
    \strlen($manifest['checksum']) === 64 && \ctype_xdigit($manifest['checksum']),
    '总 checksum 应为 64 位 hex'
);
$check(
    \strtotime($manifest['generated_at']) !== false,
    'generated_at 应为可解析时间'
);

echo 'OK SchemaFingerprint (' . \count($schema->columns) . ' columns)' . PHP_EOL;
echo 'OK SchemaManifestBuilder (Weline_Eav: ' . \count($module['tables']) . ' tables)' . PHP_EOL;

$finish();
