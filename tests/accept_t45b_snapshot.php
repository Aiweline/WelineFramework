<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\DatabaseSnapshotService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Release\Api\DatabaseSnapshotInterface;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$reflection = new ReflectionClass(DatabaseSnapshotService::class);
$check($reflection->isFinal(), 'DatabaseSnapshotService must be final');
$check(
    $reflection->implementsInterface(DatabaseSnapshotInterface::class),
    'DatabaseSnapshotService must implement DatabaseSnapshotInterface'
);

$constructor = $reflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->getNumberOfRequiredParameters() === 3
        && (string) $constructor->getParameters()[0]->getType() === ConnectionFactory::class
        && (string) $constructor->getParameters()[1]->getType() === SchemaSnapshot::class
        && (string) $constructor->getParameters()[2]->getType() === BackupCatalog::class,
    'DatabaseSnapshotService constructor must inject ConnectionFactory, SchemaSnapshot and BackupCatalog'
);

$check(
    defined(SchemaSnapshot::class . '::schema_fields_SNAPSHOT_KEY')
        && SchemaSnapshot::schema_fields_SNAPSHOT_KEY === 'snapshot_key',
    'SchemaSnapshot must expose a snapshot_key field'
);

$tmp = sys_get_temp_dir() . DS . 'weline_t45b_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
$snapshotPath = null;
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

    $link = $factory->getConnector()->getLink();

    $snapshotColumns = $link
        ->query("PRAGMA table_info('weline_schema_snapshot')")
        ->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_map(static fn(array $column): string => (string) ($column['name'] ?? ''), $snapshotColumns);
    $check(in_array('snapshot_key', $columnNames, true), 'weline_schema_snapshot must have a snapshot_key column');

    $snapshotIndexes = $link
        ->query("PRAGMA index_list('weline_schema_snapshot')")
        ->fetchAll(PDO::FETCH_ASSOC);
    $keyIndexUnique = false;
    foreach ($snapshotIndexes as $index) {
        if (str_ends_with((string) ($index['name'] ?? ''), 'uk_schema_snapshot_key')) {
            $keyIndexUnique = (int) ($index['unique'] ?? 0) === 1;
        }
    }
    $check($keyIndexUnique, 'snapshot_key must have a UNIQUE index');

    $link->exec('CREATE TABLE snap_unit (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $link->exec("INSERT INTO snap_unit (id, name) VALUES (1, 'before')");

    $catalog = new BackupCatalog($factory, new \Weline\Database\Model\Release\BackupSet(), new \Weline\Database\Model\Release\BackupObject());
    $service = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $catalog);

    $releaseId = 'rel-t45b-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $scope = new SchemaScope('default', 't45b', 'main');
    $snapshot = $service->create($releaseId, $scope, [
        'operation_id' => 'op-snapshot',
        'position' => 'test-position',
    ]);

    $snapshotPath = (string) ($snapshot['path'] ?? '');
    $snapshotId = (int) ($snapshot['snapshot_id'] ?? 0);
    $snapshotKey = (string) ($snapshot['snapshot_key'] ?? '');

    $check($snapshotId > 0, 'create must return a positive int snapshot_id');
    $check(str_starts_with($snapshotKey, 'snap-'), 'create must return a snap- snapshot_key');
    $check(
        preg_match('/^snap-[A-Za-z0-9-]+$/', $snapshotKey) === 1,
        'snapshot_key must be filesystem safe'
    );
    $check(($snapshot['engine'] ?? null) === SchemaSnapshot::ENGINE_SQLITE, 'create must report the sqlite engine');
    $check(($snapshot['status'] ?? null) === SchemaSnapshot::STATUS_READY, 'create must mark the snapshot ready');
    $check(($snapshot['release_id'] ?? null) === $releaseId, 'create must return the release id');
    $check(($snapshot['scope_key'] ?? null) === $scope->key(), 'create must return the scope key');
    $check((int) ($snapshot['backup_set_id'] ?? 0) > 0, 'create must attach a backup set');
    $check(($snapshot['position'] ?? null) === 'test-position', 'create must persist the position option');
    $check(
        str_contains($snapshotPath, DS . 'var' . DS . 'backup' . DS . 'release' . DS)
            && str_ends_with($snapshotPath, $snapshotKey . '.sqlite'),
        'snapshot artifact must live under var/backup/release with the snapshot_key filename'
    );
    $check(is_file($snapshotPath), 'snapshot artifact must exist');
    $check(
        (string) ($snapshot['checksum'] ?? '') === hash_file('sha256', $snapshotPath),
        'create must return the artifact sha256 checksum'
    );
    $check(
        (int) ($snapshot['size_bytes'] ?? 0) === (int) filesize($snapshotPath),
        'create must return the artifact size'
    );

    $snapshotRow = $link
        ->query('SELECT * FROM weline_schema_snapshot WHERE snapshot_id = ' . $snapshotId)
        ->fetch(PDO::FETCH_ASSOC);
    $check(is_array($snapshotRow), 'SchemaSnapshot row must exist');
    if (is_array($snapshotRow)) {
        $check((string) ($snapshotRow['snapshot_key'] ?? '') === $snapshotKey, 'SchemaSnapshot must persist snapshot_key');
        $check((string) ($snapshotRow['status'] ?? '') === SchemaSnapshot::STATUS_READY, 'SchemaSnapshot status must be ready');
        $check((string) ($snapshotRow['checksum'] ?? '') === (string) $snapshot['checksum'], 'SchemaSnapshot checksum must match');
        $check((string) ($snapshotRow['position'] ?? '') === 'test-position', 'SchemaSnapshot position must be persisted');
    }

    $setRow = $link
        ->query('SELECT * FROM weline_schema_backup_set WHERE backup_set_id = ' . (int) $snapshot['backup_set_id'])
        ->fetch(PDO::FETCH_ASSOC);
    $check(is_array($setRow), 'BackupSet row must exist');
    if (is_array($setRow)) {
        $check((string) ($setRow['kind'] ?? '') === \Weline\Database\Model\Release\BackupSet::KIND_RELEASE, 'BackupSet kind must be release');
        $check((string) ($setRow['status'] ?? '') === 'creating', 'BackupSet status must be creating');
        $check((string) ($setRow['operation_id'] ?? '') === 'op-snapshot', 'BackupSet operation id must be persisted');
    }

    $objectRow = $link
        ->query(
            'SELECT * FROM weline_schema_backup_object WHERE backup_set_id = '
            . (int) $snapshot['backup_set_id']
            . " AND object_type = 'snapshot'"
        )
        ->fetch(PDO::FETCH_ASSOC);
    $check(is_array($objectRow), 'BackupObject snapshot row must exist');
    if (is_array($objectRow)) {
        $check((string) ($objectRow['table_name'] ?? 'x') === '', 'snapshot BackupObject must use an empty table_name');
        $check((string) ($objectRow['data_checksum'] ?? '') === (string) $snapshot['checksum'], 'BackupObject checksum must match');
    }

    $link->exec("UPDATE snap_unit SET name = 'after' WHERE id = 1");

    $check($service->verify($snapshotKey), 'verify must accept the snapshot_key');
    $check($service->verify((string) $snapshotId), 'verify must accept the int snapshot_id');

    unset($link);
    $service->restore((string) $snapshotId);

    $link = $factory->getConnector()->getLink();
    $restoredName = $link->query('SELECT name FROM snap_unit WHERE id = 1')->fetchColumn();
    $check($restoredName === 'before', 'restore by snapshot_id must bring back the snapshot data');

    $restoredRow = $link
        ->query('SELECT * FROM weline_schema_snapshot WHERE snapshot_id = ' . $snapshotId)
        ->fetch(PDO::FETCH_ASSOC);
    $check(is_array($restoredRow), 'SchemaSnapshot row must exist after restore');
    if (is_array($restoredRow)) {
        $check((string) ($restoredRow['status'] ?? '') === SchemaSnapshot::STATUS_RESTORED, 'restore must mark the snapshot restored');
        $check((string) ($restoredRow['snapshot_key'] ?? '') === $snapshotKey, 'restore must preserve snapshot_key');
        $check((string) ($restoredRow['restored_at'] ?? '') !== '', 'restore must set restored_at');
    }

    $restoredObject = $link
        ->query(
            'SELECT COUNT(*) FROM weline_schema_backup_object WHERE backup_set_id = '
            . (int) $snapshot['backup_set_id']
            . " AND object_type = 'snapshot'"
        )
        ->fetchColumn();
    $check((int) $restoredObject === 1, 'restore must reconcile exactly one snapshot BackupObject row');

    $check($service->verify($snapshotKey), 'verify must pass after restore');

    // Cover restore by snapshot_key as well.
    $link->exec("UPDATE snap_unit SET name = 'after-key' WHERE id = 1");
    unset($link);
    $service->restore($snapshotKey);

    $link = $factory->getConnector()->getLink();
    $restoredByKey = $link->query('SELECT name FROM snap_unit WHERE id = 1')->fetchColumn();
    $check($restoredByKey === 'before', 'restore by snapshot_key must bring back the snapshot data');
    $objectCount = $link
        ->query(
            'SELECT COUNT(*) FROM weline_schema_backup_object WHERE backup_set_id = '
            . (int) $snapshot['backup_set_id']
            . " AND object_type = 'snapshot'"
        )
        ->fetchColumn();
    $check((int) $objectCount === 1, 'repeated restore must not duplicate the snapshot BackupObject row');

    file_put_contents($snapshotPath, 'tampered', FILE_APPEND);
    $check(!$service->verify($snapshotKey), 'verify must reject a tampered artifact by snapshot_key');
    $check(!$service->verify((string) $snapshotId), 'verify must reject a tampered artifact by snapshot_id');

    $rejected = false;
    try {
        $service->restore($snapshotKey);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $check($rejected, 'restore must reject a tampered artifact');

    $check(!$service->verify('snap-missing'), 'verify must reject an unknown snapshot_key');
    $check(!$service->verify('999999'), 'verify must reject an unknown snapshot_id');

    $rejected = false;
    try {
        $service->restore('999999');
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'restore must reject an unknown snapshot id');
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
    if (is_string($snapshotPath) && $snapshotPath !== '') {
        if (is_file($snapshotPath)) {
            @unlink($snapshotPath);
        }
        $releaseDir = dirname($snapshotPath);
        @rmdir($releaseDir);
        @rmdir(dirname($releaseDir));
        @rmdir(dirname(dirname($releaseDir)));
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T45B SNAPSHOT FAIL', PHP_EOL;
    exit(1);
}

echo 'T45B SNAPSHOT PASS', PHP_EOL;
