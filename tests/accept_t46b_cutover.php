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
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\BaselineReleaseBuilder;
use Weline\Database\Service\Release\DatabaseSnapshotService;
use Weline\Database\Service\Release\LegacyTableArchiver;
use Weline\Database\Service\Release\ReleaseCutoverService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaFingerprint;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Database\Service\Release\BaselineReleaseBuilder as Builder;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\TableOwner;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Service\Release\SchemaJournal as Journal;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$tmp = sys_get_temp_dir() . DS . 'weline_t46b_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
try {
    $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $tmp, 'persistent' => false]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();
    $link = $connector->getLink();

    $link->exec('CREATE TABLE cutover_unit (id INTEGER PRIMARY KEY, name TEXT)');
    $link->exec('CREATE TABLE weline_database_migrations (migration_id INTEGER PRIMARY KEY)');
    $link->exec('CREATE TABLE weline_framework_field_backup (backup_id INTEGER PRIMARY KEY)');

    $reader = new DbSchemaReader();
    $actual = $reader->readTable($connector, 'cutover_unit');
    $fingerprint = (new SchemaFingerprint($factory))->fingerprint($actual);
    $manifest = [
        'modules' => [
            'Weline_Test' => [
                'version' => '1.0.0',
                'tables' => ['cutover_unit' => $fingerprint],
                'checksum' => hash('sha256', 'cutover-unit'),
            ],
        ],
        'checksum' => hash('sha256', 'cutover-manifest'),
    ];

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $journal = new Journal($factory, new SchemaOperationEvent());
    $baseline = new Builder(
        $installer,
        $factory,
        $reader,
        new SchemaFingerprint($factory),
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
    );
    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $snapshot = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $catalog);
    $archiver = new LegacyTableArchiver($factory, $snapshot);
    $service = new ReleaseCutoverService(
        $installer,
        null,
        $baseline,
        $snapshot,
        $archiver,
        $factory,
    );

    $result = $service->run('baseline-test', [
        'manifest' => $manifest,
        'verify_actual' => true,
        'legacy_tables' => ['weline_database_migrations', 'weline_framework_field_backup'],
        'archive_suffix' => '20261023',
    ]);
    $check(($result['release_id'] ?? '') === 'baseline-test', 'cutover release id');
    $check(($result['snapshot_key'] ?? '') !== '', 'cutover snapshot key');
    $check(($result['archived']['weline_database_migrations'] ?? '') === 'weline_database_migrations_legacy_20261023', 'legacy migration table archived');
    $check(!$connector->tableExist('weline_database_migrations'), 'old migration table renamed');
    $check($connector->tableExist('weline_database_migrations_legacy_20261023'), 'archived migration table exists');

    $releaseRows = (new SchemaRelease())->setConnection($factory)->reset()
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()->fetchArray();
    $check(count($releaseRows) === 1, 'baseline release row must exist');
    $check((string)($releaseRows[0][SchemaRelease::schema_fields_STATUS] ?? '') === 'applied', 'baseline release must be applied');

    $link = null;
} catch (Throwable $exception) {
    $failures[] = 'exception: ' . $exception->getMessage();
} finally {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T46B CUTOVER PASS' . PHP_EOL;
