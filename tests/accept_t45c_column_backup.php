<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\ColumnBackupService;
use Weline\Database\Service\Release\DataTransformExecutor;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$expectRuntimeException = static function (callable $callback, string $message, string $needle = '') use ($check): void {
    try {
        $callback();
        $check(false, $message . ' (no exception)');
    } catch (RuntimeException $exception) {
        $check(
            $needle === '' || str_contains($exception->getMessage(), $needle),
            $message . ' (got: ' . $exception->getMessage() . ')'
        );
    }
};

// ---- 1. class shape ----
$reflection = new ReflectionClass(ColumnBackupService::class);
$check($reflection->isFinal(), 'ColumnBackupService must be final');

$constructor = $reflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->getNumberOfRequiredParameters() === 3
        && (string) $constructor->getParameters()[0]->getType() === ConnectionFactory::class
        && (string) $constructor->getParameters()[1]->getType() === BackupCatalog::class
        && (string) $constructor->getParameters()[2]->getType() === DbSchemaReader::class,
    'ColumnBackupService constructor must inject ConnectionFactory, BackupCatalog and DbSchemaReader'
);

foreach ([BackupCatalog::class, SchemaDdlExecutor::class, DataTransformExecutor::class, SchemaJournal::class] as $dependency) {
    $check(class_exists($dependency), 'required release dependency must exist: ' . $dependency);
}

$tmp = sys_get_temp_dir() . DS . 'weline_t45c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
$factory = null;
try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(count($installed['created']) === 11, 'installer must create 11 tables, got ' . count($installed['created']));

    $connector = $factory->getConnector();
    $link = $connector->getLink();
    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $service = new ColumnBackupService($factory, $catalog, new DbSchemaReader());

    $quoteTable = static fn(string $table): string => $connector->quoteTable($table);
    $quoteColumn = static fn(string $column): string => $connector->quoteIdentifier($column);
    $columnNames = static fn(string $table): array => array_map(
        static fn(array $column): string => (string) ($column['name'] ?? ''),
        $connector->getTableColumns($table)
    );

    // ---- 2. fixture ----
    $link->exec(
        'CREATE TABLE ' . $quoteTable('backup_unit') . ' ('
        . $quoteColumn('id') . ' INTEGER PRIMARY KEY AUTOINCREMENT, '
        . $quoteColumn('name') . ' VARCHAR(255) NULL, '
        . $quoteColumn('note') . ' VARCHAR(255) NULL)'
    );
    $insert = $link->prepare(
        'INSERT INTO ' . $quoteTable('backup_unit') . ' ('
        . $quoteColumn('id') . ', ' . $quoteColumn('name') . ', ' . $quoteColumn('note') . ') VALUES (?, ?, ?)'
    );
    $insert->execute([1, 'alpha', 'one']);
    $insert->execute([2, null, 'two']);
    $insert->execute([3, '', 'three']);
    $insert->closeCursor();

    // ---- 3. backup ----
    $backup = $service->backupColumn('rel-t45c', 'scope-t45c', 'backup_unit', 'name', 'op-t45c', [
        'chunk_rows' => 2,
        'source_version' => '1.0.0',
        'target_version' => '1.1.0',
    ]);
    $check(($backup['set_id'] ?? 0) > 0, 'backupColumn must return a positive set_id');
    $check(($backup['objects'] ?? 0) === 2, 'chunk_rows=2 must produce 2 objects, got ' . (int) ($backup['objects'] ?? 0));
    $check(($backup['rows'] ?? 0) === 3, 'backupColumn must report 3 rows');
    $check(
        preg_match('/^[0-9a-f]{64}$/', (string) ($backup['checksum'] ?? '')) === 1,
        'backupColumn must return a sha256 aggregate checksum'
    );

    $set = $catalog->findSet('rel-t45c', 'scope-t45c');
    $check(is_array($set), 'backup set must be persisted');
    $check(($set['id'] ?? null) === $backup['set_id'], 'backup set id must match');
    $check(($set['kind'] ?? null) === BackupSet::KIND_OPERATION, 'backup set kind must be operation');
    $check(($set['operation_id'] ?? null) === 'op-t45c', 'backup set must store the operation id');
    $check(($set['status'] ?? null) === BackupSet::STATUS_READY, 'backup set status must be ready');

    $columnObjects = $catalog->objects((int) $backup['set_id'], [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'backup_unit',
        'column_name' => 'name',
    ]);
    $check(count($columnObjects) === 2, 'column backup must keep 2 chunk objects');
    $check(
        array_map(static fn(array $object): int => (int) $object['row_count'], $columnObjects) === [2, 1],
        'chunk objects must store 2 and 1 rows'
    );

    foreach ($columnObjects as $object) {
        $check(($object['status'] ?? '') === BackupObject::STATUS_READY, 'backup object status must be ready');
        $check(
            hash('sha256', (string) $object['data_json']) === (string) $object['data_checksum'],
            'backup object checksum must match its data_json'
        );
        $check(($object['source_version'] ?? null) === '1.0.0', 'backup object must store source_version');
        $check(($object['target_version'] ?? null) === '1.1.0', 'backup object must store target_version');
    }

    $firstChunk = json_decode((string) $columnObjects[0]['data_json'], true);
    $secondChunk = json_decode((string) $columnObjects[1]['data_json'], true);
    $check(is_array($firstChunk) && is_array($secondChunk), 'backup data_json must decode');
    $check(($firstChunk['table'] ?? null) === 'backup_unit', 'data_json must store the table');
    $check(($firstChunk['column'] ?? null) === 'name', 'data_json must store the column');
    $check(($firstChunk['rows'][0] ?? null) === ['pk' => ['id' => 1], 'value' => 'alpha'], 'first chunk must store alpha');
    $check(
        is_array($firstChunk['rows'][1] ?? null)
            && array_key_exists('value', $firstChunk['rows'][1])
            && $firstChunk['rows'][1]['value'] === null,
        'NULL must stay NULL in data_json'
    );
    $check(
        ($secondChunk['rows'][0]['value'] ?? null) === '',
        'empty string must stay empty string in data_json'
    );

    // ---- 4. destructive schema change: DROP + ADD column ----
    $ddl = new SchemaDdlExecutor($factory);
    $nameColumn = new ColumnDefinition(name: 'name', type: 'varchar', length: 255, nullable: true);
    $ddl->apply(new SchemaDiffOp(SchemaDiffOp::KIND_DROP_COLUMN, 'backup_unit', $nameColumn));
    $check(!in_array('name', $columnNames('backup_unit'), true), 'DROP COLUMN must remove name');
    $ddl->apply(new SchemaDiffOp(SchemaDiffOp::KIND_ADD_COLUMN, 'backup_unit', $nameColumn));
    $check(in_array('name', $columnNames('backup_unit'), true), 'ADD COLUMN must restore the empty name column');

    $setValue = $link->prepare(
        'UPDATE ' . $quoteTable('backup_unit') . ' SET ' . $quoteColumn('name') . ' = ? WHERE '
        . $quoteColumn('id') . ' = ?'
    );
    $setValue->execute(['', 2]);
    $setValue->execute(['manual', 3]);
    $setValue->closeCursor();

    // ---- 5. conflict-safe restore ----
    $restore = $service->restoreColumn('rel-t45c', 'scope-t45c', 'backup_unit', 'name', 'op-t45c');
    $check(
        $restore === ['restored' => 2, 'unchanged' => 0, 'conflicts' => 1],
        'first restore must be 2 restored / 1 conflict, got ' . (string) json_encode($restore)
    );

    $rows = $link->query(
        'SELECT ' . $quoteColumn('id') . ', ' . $quoteColumn('name') . ' FROM '
        . $quoteTable('backup_unit') . ' ORDER BY ' . $quoteColumn('id')
    )->fetchAll(PDO::FETCH_ASSOC);
    $check(count($rows) === 3, 'restore must keep all 3 rows');
    $check(($rows[0]['name'] ?? null) === 'alpha', 'row 1 must restore alpha');
    $check(array_key_exists('name', $rows[1]) && $rows[1]['name'] === null, 'row 2 must restore NULL');
    $check(($rows[2]['name'] ?? null) === 'manual', 'row 3 conflict must not be overwritten');

    $conflictObjects = $catalog->objects((int) $backup['set_id'], [
        'object_type' => ColumnBackupService::OBJECT_TYPE_CONFLICT,
        'table_name' => 'backup_unit',
        'column_name' => 'name',
    ]);
    $check(count($conflictObjects) === 1, 'restore must append exactly one conflict object');
    $conflict = json_decode((string) $conflictObjects[0]['data_json'], true);
    $check(is_array($conflict), 'conflict data_json must decode');
    $check(($conflict['reason'] ?? null) === 'value_mismatch', 'conflict reason must be value_mismatch');
    $check(($conflict['pk'] ?? null) === ['id' => 3], 'conflict must keep the primary key');
    $check(array_key_exists('backup_value', $conflict) && $conflict['backup_value'] === '', 'conflict must keep the backup value');
    $check(($conflict['current_value'] ?? null) === 'manual', 'conflict must keep the current value');
    $check(($conflict['source_backup_id'] ?? 0) > 0, 'conflict must reference its source backup object');
    $check(($conflictObjects[0]['status'] ?? '') === BackupObject::STATUS_READY, 'conflict object status must be ready');

    // ---- 6. repeated restore is idempotent for backup/conflict rows ----
    $secondRestore = $service->restoreColumn('rel-t45c', 'scope-t45c', 'backup_unit', 'name', 'op-t45c');
    $check(
        $secondRestore === ['restored' => 1, 'unchanged' => 1, 'conflicts' => 1],
        'second restore must be 1 restored / 1 unchanged / 1 conflict, got ' . (string) json_encode($secondRestore)
    );
    $check(
        count($catalog->objects((int) $backup['set_id'], [
            'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
            'table_name' => 'backup_unit',
            'column_name' => 'name',
        ])) === 2,
        'repeated restore must not delete or duplicate column backup objects'
    );
    $check(
        count($catalog->objects((int) $backup['set_id'], [
            'object_type' => ColumnBackupService::OBJECT_TYPE_CONFLICT,
            'table_name' => 'backup_unit',
            'column_name' => 'name',
        ])) === 1,
        'repeated restore must not duplicate conflict objects'
    );

    // ---- 7. missing row becomes a row_missing conflict ----
    $link->exec(
        'CREATE TABLE ' . $quoteTable('backup_missing') . ' ('
        . $quoteColumn('id') . ' INTEGER PRIMARY KEY AUTOINCREMENT, '
        . $quoteColumn('note') . ' VARCHAR(255) NULL)'
    );
    $insertMissing = $link->prepare(
        'INSERT INTO ' . $quoteTable('backup_missing') . ' (' . $quoteColumn('id') . ', '
        . $quoteColumn('note') . ') VALUES (?, ?)'
    );
    $insertMissing->execute([1, 'keep']);
    $insertMissing->execute([2, 'gone']);
    $insertMissing->closeCursor();

    $missingBackup = $service->backupColumn(
        'rel-t45c',
        'scope-t45c',
        'backup_missing',
        'note',
        'op-missing'
    );
    $link->exec(
        'UPDATE ' . $quoteTable('backup_missing') . ' SET ' . $quoteColumn('note') . ' = NULL WHERE '
        . $quoteColumn('id') . ' = 1'
    );
    $link->exec(
        'DELETE FROM ' . $quoteTable('backup_missing') . ' WHERE ' . $quoteColumn('id') . ' = 2'
    );

    $missingRestore = $service->restoreColumn(
        'rel-t45c',
        'scope-t45c',
        'backup_missing',
        'note',
        'op-missing'
    );
    $check(
        $missingRestore === ['restored' => 1, 'unchanged' => 0, 'conflicts' => 1],
        'missing row restore must be 1 restored / 1 conflict, got ' . (string) json_encode($missingRestore)
    );
    $missingConflicts = $catalog->objects((int) $missingBackup['set_id'], [
        'object_type' => ColumnBackupService::OBJECT_TYPE_CONFLICT,
        'table_name' => 'backup_missing',
        'column_name' => 'note',
    ]);
    $check(count($missingConflicts) === 1, 'row_missing must append one conflict object');
    $missingConflict = json_decode((string) $missingConflicts[0]['data_json'], true);
    $check(($missingConflict['reason'] ?? null) === 'row_missing', 'missing row conflict reason must be row_missing');
    $check(($missingConflict['pk'] ?? null) === ['id' => 2], 'missing row conflict must keep the primary key');

    // ---- 8. guards ----
    $link->exec(
        'CREATE TABLE ' . $quoteTable('no_identity') . ' (' . $quoteColumn('label') . ' VARCHAR(32) NULL)'
    );
    $expectRuntimeException(
        static fn(): array => $service->backupColumn(
            'rel-t45c',
            'scope-t45c',
            'no_identity',
            'label',
            'op-no-identity'
        ),
        'backupColumn must reject a table without a primary key',
        'no_stable_row_identity'
    );
    $expectRuntimeException(
        static fn(): array => $service->backupColumn(
            'rel-t45c',
            'scope-t45c',
            'missing_table',
            'label',
            'op-missing-table'
        ),
        'backupColumn must reject an unknown table',
        'table_not_found'
    );
    $expectRuntimeException(
        static fn(): array => $service->restoreColumn(
            'rel-t45c',
            'scope-t45c',
            'backup_unit',
            'name',
            'op-no-backup'
        ),
        'restoreColumn must reject an operation without a backup set',
        'backup_set_not_found'
    );
} finally {
    if ($factory instanceof ConnectionFactory) {
        try {
            $factory->close();
        } catch (Throwable) {
            // ignore close failure during cleanup
        }
    }
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T45C COLUMN BACKUP FAIL', PHP_EOL;
    exit(1);
}

echo 'T45C COLUMN BACKUP PASS', PHP_EOL;
