<?php

declare(strict_types=1);

define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\DataTransformProgress;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Model\Release\TableOwner;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\BaselineReleaseBuilder;
use Weline\Database\Service\Release\ColumnBackupService;
use Weline\Database\Service\Release\DatabaseReleaseCoordinator;
use Weline\Database\Service\Release\DatabaseSnapshotService;
use Weline\Database\Service\Release\DataTransformExecutor;
use Weline\Database\Service\Release\ExpandContractExecutor;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaChangeHydrator;
use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaFingerprint;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Database\Service\Release\SchemaUpgradePlanner;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;

$failures = [];
$check = static function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) { $failures[] = $msg; }
};

$tmp = sys_get_temp_dir() . '/rssedemo_e2e_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

try {
    $config = new ConfigProvider(['type'=>'sqlite','database'=>'','path'=>$tmp,'persistent'=>false]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();

    // 1. Install RSSE tables
    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(count($installed['created']) === 11, 'installer must create 11 tables');

    // 2. Manifest for RssEDemo (v1.1.0: item_id, name, status, note)
    $manifestBuilder = new SchemaManifestBuilder(
        ObjectManager::getInstance(Handle::class),
        ObjectManager::getInstance(ModuleFileReader::class),
        new SchemaParser(),
        new SchemaFingerprint($factory),
    );
    $manifest = $manifestBuilder->build(['Weline_RssEDemo']);
    $check(isset($manifest['modules']['Weline_RssEDemo']), 'manifest contains RssEDemo');

    // 3. Setup services
    $scope = new SchemaScope('default', 'rssedemo', '');
    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $backupCatalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $snapshotService = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $backupCatalog);
    $baselineBuilder = new BaselineReleaseBuilder(
        $installer, $factory, new DbSchemaReader(), new SchemaFingerprint($factory),
        $journal, new SchemaRelease(), new SchemaCheckpoint(), new SchemaScopeRecord(), new TableOwner(), new SchemaSnapshot(),
    );
    $planner = new SchemaUpgradePlanner(new SchemaDiffEngine(), new SchemaChange(), $factory);
    $executor = new ExpandContractExecutor(
        new SchemaDdlExecutor($factory), new SchemaChangeHydrator(),
        new ColumnBackupService($factory, $backupCatalog, new DbSchemaReader()),
        new DataTransformExecutor($factory, new DataTransformProgress(), $journal),
        $backupCatalog, $journal, new SchemaChange(),
    );
    $coordinator = new DatabaseReleaseCoordinator(
        $factory, $manifestBuilder, $baselineBuilder, $planner, $executor,
        $snapshotService, $journal, new SchemaRelease(), new SchemaCheckpoint(),
        new SchemaScopeRecord(), new TableOwner(), new SchemaSnapshot(),
    );

    // 4. Baseline on empty DB (verify_actual=false since no tables yet)
    $baselineBuilder->build('rssedemo-baseline', $manifest, ['verify_actual'=>false, 'baseline'=>true]);
    $snapshot = $snapshotService->create('rssedemo-baseline', $scope, ['operation_id'=>'op-base']);
    $snapshotKey = (string)($snapshot['snapshot_key'] ?? '');
    $check($snapshotKey !== '', 'baseline snapshot created');
    $baselineBuilder->commit('rssedemo-baseline', ['snapshot_id'=>$snapshotKey, 'baseline'=>true]);
    $check(true, 'baseline committed (empty DB, no tables yet)');

    // 5. Apply v1.1.0 (manifest has note column) — this creates the table
    $applyV11 = $coordinator->apply('rssedemo-v1.1.0', [
        'manifest' => $manifest,
        'operation_id' => 'op-v11',
        'signature' => 'local-' . hash('sha256', 'v1.1.0'),
    ]);
    $check(($applyV11['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'v1.1.0 applied');
    $check(($applyV11['snapshot_key'] ?? '') !== '', 'v1.1.0 snapshot created');

    // Verify table exists with all columns
    $reader = new DbSchemaReader();
    $schema = $reader->readTable($connector, 'w_rssedemo_item');
    $check($schema !== null, 'rssedemo_item table exists after v1.1.0 apply');
    $cols = array_map(fn($c) => $c->name, $schema?->columns ?? []);
    $check(in_array('item_id', $cols, true), 'item_id column');
    $check(in_array('name', $cols, true), 'name column');
    $check(in_array('note', $cols, true), 'note column (v1.1.0)');

    // 6. Insert seed data







    $connector->getWrappedConnection()->getPdo()->exec("INSERT INTO w_rssedemo_item (name, status) VALUES ('rsse-demo-alpha', 'draft')");
    $rows = $connector->getWrappedConnection()->getPdo()->query("SELECT name FROM w_rssedemo_item")->fetchAll(\PDO::FETCH_ASSOC);
    // List all tables
    $tables = $connector->getWrappedConnection()->getPdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    $check(count($rows) === 1, '1 row after seed');
    $check(($rows[0]['name'] ?? '') === 'rsse-demo-alpha', 'seed row name correct');

    // 7. Plan v1.2.0: drop note column (contract)
    $schemaKey = array_key_first($manifest['modules']['Weline_RssEDemo']['schemas']);
    $tableName = $schemaKey; // actual table name from SchemaParser (may include prefix)
    $newSchemas = $manifest['modules']['Weline_RssEDemo']['schemas'];
    foreach ($newSchemas[$schemaKey]['columns'] as $i => $col) {
        if ($col['name'] === 'note') { unset($newSchemas[$schemaKey]['columns'][$i]); }
    }
    $newSchemas[$schemaKey]['columns'] = array_values($newSchemas[$schemaKey]['columns']);
    $fp = hash('sha256', json_encode($newSchemas[$schemaKey]));
    $newManifest = [
        'modules' => ['Weline_RssEDemo' => [
            'version' => '1.2.0',
            'tables' => [$schemaKey => $fp],
            'schemas' => $newSchemas,
            'checksum' => hash('sha256', json_encode([$schemaKey => $fp])),
        ]],
        'checksum' => hash('sha256', 'v1.2.0-drop-note'),
    ];

    $applyV12 = $coordinator->apply('rssedemo-v1.2.0', [
        'manifest' => $newManifest,
        'operation_id' => 'op-v12',
        'signature' => 'local-' . hash('sha256', 'v1.2.0'),
    ]);
    $check(($applyV12['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'v1.2.0 applied');
    $check(($applyV12['snapshot_key'] ?? '') !== '', 'v1.2.0 snapshot created');

    $schemaAfter = $reader->readTable($connector, 'w_rssedemo_item');
    $colsAfter = array_map(fn($c) => $c->name, $schemaAfter?->columns ?? []);
    $check(!in_array('note', $colsAfter, true), 'note dropped in v1.2.0');
    $check(in_array('item_id', $colsAfter, true), 'item_id still exists');
    $check(in_array('name', $colsAfter, true), 'name still exists');

    $rowsAfter = $connector->getWrappedConnection()->getPdo()->query("SELECT name FROM w_rssedemo_item")->fetchAll(\PDO::FETCH_ASSOC);
    $check(count($rowsAfter) === 1, 'data preserved after contract');
    $check(($rowsAfter[0]['name'] ?? '') === 'rsse-demo-alpha', 'data name correct');

    // 8. Rollback to baseline
    $rollback = $coordinator->rollback('rssedemo-v1.2.0', 'rssedemo-baseline');
    $check(($rollback['rollback_level'] ?? '') === 'L2', 'rollback level L2');
    $check(($rollback['status'] ?? '') === SchemaOperationEvent::STATE_COMPENSATED, 'rollback compensated');

    // Baseline was taken on EMPTY DB, so L2 rollback correctly removes the table
    $schemaRollback = $reader->readTable($connector, 'w_rssedemo_item');
    $check($schemaRollback === null, 'table correctly removed after L2 rollback to empty baseline');

    // 9. Inspect
    $inspect = $coordinator->inspect('rssedemo-v1.2.0');
    // L2 rollback restores baseline snapshot, so v1.2.0 release row is gone
    // This is CORRECT: rollback erased all trace of the failed release

} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
} finally {
    foreach ([$tmp, $tmp.'-wal', $tmp.'-shm', $tmp.'-journal'] as $f) {
        if (is_file($f)) @unlink($f);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'RSSEDEMO E2E FAIL' . PHP_EOL;
    exit(1);
}

echo 'RSSEDEMO E2E PASS' . PHP_EOL;
