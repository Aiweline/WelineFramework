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
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Release\Api\BackupCatalogInterface;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$reflection = new ReflectionClass(BackupCatalog::class);
$check($reflection->isFinal(), 'BackupCatalog must be final');
$check($reflection->implementsInterface(BackupCatalogInterface::class), 'BackupCatalog must implement BackupCatalogInterface');

$constructor = $reflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->getNumberOfRequiredParameters() === 3
        && (string) $constructor->getParameters()[0]->getType() === ConnectionFactory::class
        && (string) $constructor->getParameters()[1]->getType() === BackupSet::class
        && (string) $constructor->getParameters()[2]->getType() === BackupObject::class,
    'BackupCatalog constructor must inject ConnectionFactory, BackupSet and BackupObject'
);

$tmp = sys_get_temp_dir() . DS . 'weline_t45a_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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

    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $link = $factory->getConnector()->getLink();

    // ---- createSet: insert + idempotent unique key ----
    $setA = $catalog->createSet([
        'release_id' => 'rel-t45a',
        'operation_id' => 'op-1',
        'scope_key' => 'scope-a',
        'kind' => BackupSet::KIND_OPERATION,
        'status' => BackupSet::STATUS_READY,
        'manifest_json' => '{"tables":["demo_table"]}',
    ]);
    $check($setA > 0, 'createSet must return a positive id');

    $sameSet = $catalog->createSet([
        'release_id' => 'rel-t45a',
        'operation_id' => 'op-1',
        'scope_key' => 'scope-a',
        'kind' => BackupSet::KIND_OPERATION,
        'status' => BackupSet::STATUS_FAILED,
        'manifest_json' => '{"changed":true}',
    ]);
    $check($sameSet === $setA, 'createSet must be idempotent for the same release/operation/scope');

    $setCount = (int) $link->query('SELECT COUNT(*) FROM weline_schema_backup_set')->fetchColumn();
    $check($setCount === 1, 'idempotent createSet must not insert a second row, got ' . $setCount);

    $setB = $catalog->createSet([
        'release_id' => 'rel-t45a',
        'operation_id' => 'op-2',
        'scope_key' => 'scope-b',
        'kind' => BackupSet::KIND_RELEASE,
    ]);
    $check($setB > $setA, 'a different unique key must create a new set');

    // ---- findSet: scope filter, latest row, manifest projection ----
    $foundA = $catalog->findSet('rel-t45a', 'scope-a');
    $check(is_array($foundA), 'findSet must return the matching set');
    $check(($foundA['id'] ?? null) === $setA, 'findSet must return the matching set id');
    $check(($foundA['kind'] ?? null) === BackupSet::KIND_OPERATION, 'findSet must return the set kind');
    $check(($foundA['status'] ?? null) === BackupSet::STATUS_READY, 'duplicate createSet must not update status');
    $check(
        ($foundA['manifest'] ?? null) === ['tables' => ['demo_table']],
        'findSet must decode the stored manifest'
    );
    $check(
        ($foundA['manifest_json'] ?? null) === '{"tables":["demo_table"]}',
        'duplicate createSet must not update manifest_json'
    );

    $latest = $catalog->findSet('rel-t45a');
    $check(($latest['id'] ?? null) === $setB, 'findSet without scope must return the newest set');
    $check(($latest['status'] ?? null) === BackupSet::STATUS_PENDING, 'createSet status must default to pending');
    $check(($latest['manifest'] ?? null) === null, 'findSet must project a null manifest when absent');
    $check($catalog->findSet('rel-t45a', 'missing-scope') === null, 'findSet must return null for an unknown scope');
    $check($catalog->findSet('missing-release') === null, 'findSet must return null for an unknown release');

    // ---- addObject: table + column + snapshot-compatible empty table name ----
    $tableObjectId = $catalog->addObject($setA, [
        'object_type' => BackupObject::OBJECT_TYPE_TABLE,
        'table_name' => 'demo_table',
        'source_version' => '1.0.0',
        'target_version' => '1.1.0',
        'row_count' => 3,
    ]);
    $check($tableObjectId > 0, 'addObject must return a positive id');

    $payload = '{"rows":[{"id":1,"name":"alpha"}]}';
    $checksum = hash('sha256', $payload);
    $columnObjectId = $catalog->addObject($setA, [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'demo_table',
        'column_name' => 'name',
        'data_json' => $payload,
        'data_checksum' => $checksum,
        'row_count' => 1,
    ]);
    $check($columnObjectId > $tableObjectId, 'object ids must grow monotonically');

    $snapshotObjectId = $catalog->addObject($setB, [
        'object_type' => 'snapshot',
        'table_name' => '',
        'data_json' => '{"path":"snapshot.sqlite"}',
        'data_checksum' => hash('sha256', '{"path":"snapshot.sqlite"}'),
        'row_count' => 0,
    ]);
    $check($snapshotObjectId > 0, 'snapshot objects must be accepted with an empty table_name');

    // ---- objects: ordering + filters ----
    $all = $catalog->objects($setA);
    $check(count($all) === 2, 'objects must return 2 rows, got ' . count($all));
    $check(
        array_map(static fn(array $row): int => (int) $row['backup_object_id'], $all)
            === [$tableObjectId, $columnObjectId],
        'objects must be ordered by backup_object_id ASC'
    );
    $check((int) ($all[0]['row_count'] ?? -1) === 3, 'addObject must persist row_count');
    $check((string) ($all[1]['data_checksum'] ?? '') === $checksum, 'addObject must persist data_checksum');

    $columnRows = $catalog->objects($setA, ['object_type' => BackupObject::OBJECT_TYPE_COLUMN]);
    $check(count($columnRows) === 1, 'objects must filter by object_type');
    $check((int) ($columnRows[0]['backup_object_id'] ?? 0) === $columnObjectId, 'object_type filter returned the wrong row');

    $tableRows = $catalog->objects($setA, [
        'object_type' => BackupObject::OBJECT_TYPE_TABLE,
        'table_name' => 'demo_table',
    ]);
    $check(count($tableRows) === 1, 'objects must combine object_type and table_name filters');

    $namedRows = $catalog->objects($setA, ['column_name' => 'name']);
    $check(count($namedRows) === 1, 'objects must filter by column_name');
    $check($catalog->objects($setA, ['table_name' => 'missing_table']) === [], 'objects must return [] for an unknown table');
    $check($catalog->objects($setA, ['object_type' => 'missing_type']) === [], 'objects must return [] for an unknown type');
    $check($catalog->objects(999999) === [], 'objects must return [] for an unknown backup set');
    $check(count($catalog->objects($setB)) === 1, 'objects must be scoped to their own backup set');

    // ---- validation: required fields, kind, checksum, set existence ----
    $rejected = false;
    try {
        $catalog->createSet([
            'release_id' => 'rel-t45a',
            'operation_id' => 'op-3',
            'scope_key' => 'scope-c',
            'kind' => BackupSet::KIND_SNAPSHOT,
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'createSet must reject a kind other than release|operation');

    $rejected = false;
    try {
        $catalog->createSet([
            'release_id' => 'rel-t45a',
            'scope_key' => 'scope-c',
            'kind' => BackupSet::KIND_OPERATION,
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'createSet must reject a missing required field');

    $rejected = false;
    try {
        $catalog->addObject($setA, [
            'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
            'table_name' => 'demo_table',
            'data_json' => '{}',
            'data_checksum' => 'deadbeef',
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'addObject must reject a data_checksum that is not 64 hex characters');

    $rejected = false;
    try {
        $catalog->addObject($setA, [
            'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
            'table_name' => 'demo_table',
            'data_json' => '{}',
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'addObject must reject data_json without data_checksum');

    $rejected = false;
    try {
        $catalog->addObject(999999, [
            'object_type' => BackupObject::OBJECT_TYPE_TABLE,
            'table_name' => 'demo_table',
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'addObject must reject an unknown backup set');

    $rejected = false;
    try {
        $catalog->addObject($setA, ['table_name' => 'demo_table']);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'addObject must reject a missing object_type');

    $rejected = false;
    try {
        $catalog->addObject($setA, ['object_type' => BackupObject::OBJECT_TYPE_TABLE]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'addObject must reject a missing table_name');

    $check(count($catalog->objects($setA)) === 2, 'rejected object registrations must not write rows');
    $check(
        (int) $link->query('SELECT COUNT(*) FROM weline_schema_backup_object')->fetchColumn() === 3,
        'exactly three backup objects must be stored'
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
    echo 'T45A BACKUP FAIL', PHP_EOL;
    exit(1);
}

echo 'T45A BACKUP PASS', PHP_EOL;
