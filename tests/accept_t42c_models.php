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
    'Weline\\Database\\Model\\Release\\SchemaRelease' => [
        'table' => 'weline_schema_release',
        'primary_key' => 'id',
        'fields' => [
            'schema_fields_ID' => 'id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_SCHEMA_EPOCH' => 'schema_epoch',
            'schema_fields_MANIFEST_JSON' => 'manifest_json',
            'schema_fields_STATUS' => 'status',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaCheckpoint' => [
        'table' => 'weline_schema_checkpoint',
        'primary_key' => 'id',
        'fields' => [
            'schema_fields_ID' => 'id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_MODULE_NAME' => 'module_name',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_TABLES_JSON' => 'tables_json',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaOperationEvent' => [
        'table' => 'weline_schema_operation_event',
        'primary_key' => 'event_id',
        'fields' => [
            'schema_fields_ID' => 'event_id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_OPERATION_ID' => 'operation_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_NODE_ID' => 'node_id',
            'schema_fields_STATE' => 'state',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaChange' => [
        'table' => 'weline_schema_change',
        'primary_key' => 'change_id',
        'fields' => [
            'schema_fields_ID' => 'change_id',
            'schema_fields_RELEASE_ID' => 'release_id',
            'schema_fields_OPERATION_ID' => 'operation_id',
            'schema_fields_NODE_ID' => 'node_id',
            'schema_fields_CHANGE_TYPE' => 'change_type',
            'schema_fields_PHASE' => 'phase',
        ],
    ],
    'Weline\\Database\\Model\\Release\\SchemaScopeRecord' => [
        'table' => 'weline_schema_scope',
        'primary_key' => 'scope_id',
        'fields' => [
            'schema_fields_ID' => 'scope_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_CONNECTION_NAME' => 'connection_name',
            'schema_fields_DATABASE_NAME' => 'database_name',
        ],
    ],
    'Weline\\Database\\Model\\Release\\TableOwner' => [
        'table' => 'weline_schema_table_owner',
        'primary_key' => 'owner_id',
        'fields' => [
            'schema_fields_ID' => 'owner_id',
            'schema_fields_SCOPE_KEY' => 'scope_key',
            'schema_fields_TABLE_NAME' => 'table_name',
            'schema_fields_OWNER_MODULE' => 'owner_module',
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

$installer = 'Weline\\Database\\Service\\Release\\ReleaseTableInstaller';
$check(class_exists($installer), 'installer not loadable: ' . $installer);
if (class_exists($installer)) {
    $ref = new ReflectionClass($installer);
    $check($ref->isInstantiable(), 'installer must be instantiable');
    $check($ref->hasMethod('install'), 'installer must expose install()');
    $dependencies = [];
    foreach ($ref->getConstructor()?->getParameters() ?? [] as $parameter) {
        $dependencies[] = (string) $parameter->getType();
    }
    $check(
        $dependencies === [
            'Weline\\Framework\\Database\\Schema\\SchemaParser',
            'Weline\\Framework\\Database\\ConnectionFactory',
        ],
        'installer must inject SchemaParser and ConnectionFactory only, got: ' . implode(', ', $dependencies)
    );
}

// Split literals so this acceptance file stays free of legacy symbols for the repo-wide rg gate.
$legacySymbols = [
    'Setup\\Model\\' . 'Migration',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
$scanDirs = [
    BP . 'app/code/Weline/Database/Model/Release',
    BP . 'app/code/Weline/Database/Service/Release',
];
foreach ($scanDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $check(
            preg_match('/SchemaMigrationExecutor(?!Interface)/', $source) !== 1,
            'legacy symbol SchemaMigrationExecutor found in ' . $file->getPathname()
        );
        foreach ($legacySymbols as $symbol) {
            $check(
                !str_contains($source, $symbol),
                'legacy symbol ' . $symbol . ' found in ' . $file->getPathname()
            );
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T42C MODELS PASS', PHP_EOL;
