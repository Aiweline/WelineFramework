<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$models = [
    'Weline\\Database\\Model\\Release\\DataTransformProgress' => [
        'table' => 'weline_schema_data_transform_progress',
        'primary_key' => 'progress_id',
        'fields' => [
            'schema_fields_ID' => 'progress_id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_TRANSFORM_ID' => 'transform_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_CURSOR_JSON' => 'cursor_json',
            'schema_fields_ROWS_DONE' => 'rows_done',
            'schema_fields_STATUS' => 'status',
        ],
    ],
    'Weline\\Database\\Model\\Release\\BackupSet' => [
        'table' => 'weline_schema_backup_set',
        'primary_key' => 'backup_set_id',
        'fields' => [
            'schema_fields_ID' => 'backup_set_id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_OPERATION_ID' => 'operation_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_KIND' => 'kind',
            'schema_fields_MANIFEST_JSON' => 'manifest_json',
        ],
    ],
    'Weline\\Database\\Model\\Release\\BackupObject' => [
        'table' => 'weline_schema_backup_object',
        'primary_key' => 'backup_object_id',
        'fields' => [
            'schema_fields_ID' => 'backup_object_id',
            'schema_fields_BACKUP_SET_ID' => 'backup_set_id',
            'schema_fields_OBJECT_TYPE' => 'object_type',
            'schema_fields_TABLE_NAME' => 'table_name',
            'schema_fields_COLUMN_NAME' => 'column_name',
            'schema_fields_DATA_CHECKSUM' => 'data_checksum',
            'schema_fields_ROW_COUNT' => 'row_count',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaSnapshot' => [
        'table' => 'weline_schema_snapshot',
        'primary_key' => 'snapshot_id',
        'fields' => [
            'schema_fields_ID' => 'snapshot_id',
            'schema_fields_BACKUP_SET_ID' => 'backup_set_id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_ENGINE' => 'engine',
            'schema_fields_PATH' => 'path',
            'schema_fields_POSITION' => 'position',
            'schema_fields_SIZE_BYTES' => 'size_bytes',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaPhysicalState' => [
        'table' => 'weline_schema_physical_state',
        'primary_key' => 'state_id',
        'fields' => [
            'schema_fields_ID' => 'state_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_TABLE_NAME' => 'table_name',
            'schema_fields_DECLARED_FINGERPRINT' => 'declared_fingerprint',
            'schema_fields_PHYSICAL_FINGERPRINT' => 'physical_fingerprint',
            'schema_fields_INDEX_READY' => 'index_ready',
        ],
    ],
];

$tables = [];
foreach ($models as $class => $expected) {
    $check(class_exists($class), 'model not loadable: ' . $class);
    if (!class_exists($class)) {
        continue;
    }
    $ref = new ReflectionClass($class);
    $check($ref->isSubclassOf(\Weline\Framework\Database\Model::class), 'model must extend Database Model: ' . $class);
    $check($ref->isInstantiable(), 'model must be instantiable: ' . $class);

    $table = $ref->getConstant('schema_table');
    $check(is_string($table) && str_starts_with($table, 'weline_schema_'), 'schema_table must use weline_schema_ prefix: ' . $class);
    $check($table === $expected['table'], 'schema_table mismatch: ' . $class);
    if (is_string($table)) {
        $tables[] = $table;
    }

    $check($ref->getConstant('schema_primary_key') === $expected['primary_key'], 'schema_primary_key mismatch: ' . $class);

    foreach ($expected['fields'] as $constant => $value) {
        $check($ref->hasConstant($constant), 'missing field constant ' . $constant . ' on ' . $class);
        if ($ref->hasConstant($constant)) {
            $check($ref->getConstant($constant) === $value, 'field constant value mismatch: ' . $class . '::' . $constant);
        }
    }
}
$check(count($tables) === count(array_unique($tables)), 'schema_table values must be unique');

// Split literals so this acceptance file stays free of legacy symbols for the repo-wide rg gate.
$legacySymbols = [
    'SchemaMigration' . 'Executor',
    'Setup\\Model\\' . 'Migration',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
$scanFiles = [
    BP . 'app/code/Weline/Database/Model/Release/DataTransformProgress.php',
    BP . 'app/code/Weline/Database/Model/Release/BackupSet.php',
    BP . 'app/code/Weline/Database/Model/Release/BackupObject.php',
    BP . 'app/code/Weline/Database/Model/Release/SchemaSnapshot.php',
    BP . 'app/code/Weline/Database/Model/Release/SchemaPhysicalState.php',
];
foreach ($scanFiles as $file) {
    $check(is_file($file), 'missing model file: ' . $file);
    if (!is_file($file)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    foreach ($legacySymbols as $symbol) {
        $check(!str_contains($source, $symbol), 'legacy symbol ' . $symbol . ' found in ' . $file);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T42C2 MODELS PASS', PHP_EOL;
