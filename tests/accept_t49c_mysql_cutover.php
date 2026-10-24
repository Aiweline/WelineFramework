<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
define('DS', \DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/bootstrap.php';

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
use Weline\Database\Service\Release\LegacyTableArchiver;
use Weline\Database\Service\Release\ReleaseCutoverService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaChangeHydrator;
use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaFingerprint;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Database\Service\Release\SchemaManifestBuilder;
use Weline\Database\Service\Release\SchemaUpgradePlanner;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;

/**
 * T49c 严格验收：MySQL 临时库完整 RSSE cutover 演练。
 * 只在 weline_cutover_my_<pid>_<rand> 临时库操作；不连接/修改默认库。
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
        echo 'T49C MYSQL-CUTOVER FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T49C MYSQL-CUTOVER PASS' . PHP_EOL;
    exit(0);
};

$schemaToArray = static function (TableSchema $schema): array {
    $columns = [];
    foreach ($schema->columns as $column) {
        $columns[] = [
            'name' => $column->name,
            'type' => $column->type,
            'length' => $column->length,
            'nullable' => $column->nullable,
            'primaryKey' => $column->primaryKey,
            'autoIncrement' => $column->autoIncrement,
            'default' => $column->default,
            'comment' => $column->comment,
            'unique' => $column->unique,
        ];
    }
    $indexes = [];
    foreach ($schema->indexes as $index) {
        $indexes[] = [
            'name' => $index->name,
            'columns' => $index->columns,
            'type' => $index->type,
            'comment' => $index->comment,
            'method' => $index->method,
        ];
    }
    $foreignKeys = [];
    foreach ($schema->foreignKeys as $foreignKey) {
        $foreignKeys[] = [
            'name' => $foreignKey->name,
            'columns' => $foreignKey->columns,
            'referencesTable' => $foreignKey->referencesTable,
            'referencesColumns' => $foreignKey->referencesColumns,
            'onDeleteCascade' => $foreignKey->onDeleteCascade,
            'onUpdateCascade' => $foreignKey->onUpdateCascade,
        ];
    }

    return [
        'comment' => $schema->comment,
        'columns' => $columns,
        'indexes' => $indexes,
        'foreignKeys' => $foreignKeys,
    ];
};

$buildManifest = static function (string $version, array $schemas, array $fingerprints): array {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    $module = [
        'version' => $version,
        'tables' => $fingerprints,
        'schemas' => $schemas,
        'checksum' => \hash('sha256', (string)\json_encode($fingerprints, $flags)),
    ];
    $modules = ['Weline_Drill' => $module];

    return [
        'modules' => $modules,
        'checksum' => \hash('sha256', (string)\json_encode($modules, $flags)),
    ];
};

$dbName = 'weline_cutover_my_' . \getmypid() . '_' . \strtolower(\bin2hex(\random_bytes(4)));
$adminPdo = null;
$factory = null;
$connector = null;
$created = false;
$snapshotPaths = [];

try {
    if (\preg_match('/\Aweline_cutover_my_\d+_[a-f0-9]{8}\z/', $dbName) !== 1) {
        throw new RuntimeException('invalid temp database name: ' . $dbName);
    }

    $adminPdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $adminPdo->exec(
        'CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
    );
    $created = true;

    $config = new ConfigProvider([
        'type' => 'mysql',
        'hostname' => '127.0.0.1',
        'hostport' => 3306,
        'database' => $dbName,
        'username' => 'root',
        'password' => '',
        'prefix' => '',
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_general_ci',
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();
    $check(\strtolower((string)$connector->getConfigProvider()->getDbType()) === 'mysql', '验收连接必须是 mysql');
    $currentDb = (string)(($connector->query('SELECT DATABASE() AS db')->fetchArray()[0]['db'] ?? ''));
    $check($currentDb === $dbName, '必须连接到临时库 ' . $dbName . '，实际：' . $currentDb);

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(\count($installed['created']) === 11, 'ReleaseTableInstaller 应创建 11 张 RSSE 表');

    // ---- 1. 旧 schema + 旧账本 ----
    $connector->query(
        'CREATE TABLE legacy_orders (id int PRIMARY KEY, status varchar(32), customer varchar(64))'
    )->fetch();
    $connector->query('CREATE TABLE weline_database_migrations (id int PRIMARY KEY)')->fetch();
    $connector->query('CREATE TABLE weline_database_backups (id int PRIMARY KEY)')->fetch();
    $connector->query(
        "INSERT INTO legacy_orders (id, status, customer) VALUES"
        . " (1, 'paid', 'alice'), (2, 'new', 'bob'), (3, 'paid', 'carol')"
    )->fetch();

    $reader = new DbSchemaReader();
    $fingerprint = new SchemaFingerprint($factory);
    $actual = $reader->readTable($connector, 'legacy_orders');
    $check($actual instanceof TableSchema, 'legacy_orders 应可读回 schema');
    if (!$actual instanceof TableSchema) {
        throw new RuntimeException('legacy_orders schema unavailable');
    }

    $baselineSchemas = ['legacy_orders' => $schemaToArray($actual)];
    $baselineFingerprint = $fingerprint->fingerprint($actual);
    $baselineManifest = $buildManifest('1.0.0', $baselineSchemas, ['legacy_orders' => $baselineFingerprint]);

    // ---- 2. cutover：baseline + snapshot + archive ----
    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $snapshotService = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $catalog);
    $baselineBuilder = new BaselineReleaseBuilder(
        $installer,
        $factory,
        $reader,
        $fingerprint,
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
        $snapshotService,
    );
    $archiver = new LegacyTableArchiver($factory, $snapshotService);
    $cutover = new ReleaseCutoverService(
        $installer,
        null,
        $baselineBuilder,
        $snapshotService,
        $archiver,
        $factory,
    );

    $cutoverResult = $cutover->run('my-drill-baseline', [
        'manifest' => $baselineManifest,
        'legacy_tables' => ['weline_database_migrations', 'weline_database_backups'],
        'archive_suffix' => 'my',
        'operation_id' => 'cutover-my-drill-baseline',
    ]);
    $check(($cutoverResult['release_id'] ?? '') === 'my-drill-baseline', 'cutover release id 错误');
    $baselineSnapshotKey = (string)($cutoverResult['snapshot_key'] ?? '');
    $check($baselineSnapshotKey !== '', 'cutover 应返回 snapshot_key');
    $archived = (array)($cutoverResult['archived'] ?? []);
    $check(
        ($archived['weline_database_migrations'] ?? '') === 'weline_database_migrations_legacy_my',
        '旧 migrations 表应归档为 _legacy_my'
    );
    $check(
        ($archived['weline_database_backups'] ?? '') === 'weline_database_backups_legacy_my',
        '旧 backups 表应归档为 _legacy_my'
    );
    $check(!$connector->tableExist('weline_database_migrations'), '旧 migrations 表原表名应消失');
    $check(!$connector->tableExist('weline_database_backups'), '旧 backups 表原表名应消失');
    $check($connector->tableExist('weline_database_migrations_legacy_my'), '归档 migrations 表应存在');
    $check($connector->tableExist('weline_database_backups_legacy_my'), '归档 backups 表应存在');
    $check($connector->tableExist('legacy_orders'), 'legacy_orders 必须保留');

    $releaseRows = (new SchemaRelease())->setConnection($factory)->reset()
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'my-drill-baseline')
        ->limit(1)->select()->fetchArray();
    $check(
        ($releaseRows[0][SchemaRelease::schema_fields_STATUS] ?? '') === SchemaRelease::STATUS_APPLIED,
        'baseline release 必须 applied'
    );

    $baselineSnapshotRow = (new SchemaSnapshot())->setConnection($factory)->reset()
        ->where(SchemaSnapshot::schema_fields_SNAPSHOT_KEY, $baselineSnapshotKey)
        ->limit(1)->select()->fetchArray()[0] ?? null;
    $baselineSnapshotPath = (string)($baselineSnapshotRow[SchemaSnapshot::schema_fields_PATH] ?? '');
    if ($baselineSnapshotPath !== '') {
        $snapshotPaths[] = $baselineSnapshotPath;
    }
    $check($baselineSnapshotPath !== '' && \is_file($baselineSnapshotPath), 'baseline 快照文件必须真实存在');

    // ---- 3. 新 release：legacy_orders + note ----
    $newSchemas = $baselineSchemas;
    $newSchemas['legacy_orders']['columns'][] = [
        'name' => 'note',
        'type' => 'varchar',
        'length' => 255,
        'nullable' => true,
        'primaryKey' => false,
        'autoIncrement' => false,
        'default' => null,
        'comment' => '',
        'unique' => false,
    ];
    $noteColumn = new ColumnDefinition('note', 'varchar', 255, true, false, false, null, '', false);
    $newSchemaObject = new TableSchema(
        tableName: $actual->tableName,
        comment: $actual->comment,
        columns: \array_merge($actual->columns, [$noteColumn]),
        indexes: $actual->indexes,
        foreignKeys: $actual->foreignKeys,
    );
    $newManifest = $buildManifest(
        '1.1.0',
        $newSchemas,
        ['legacy_orders' => $fingerprint->fingerprint($newSchemaObject)],
    );

    $manifestBuilder = new SchemaManifestBuilder(
        ObjectManager::getInstance(Handle::class),
        ObjectManager::getInstance(ModuleFileReader::class),
        new SchemaParser(),
        $fingerprint,
    );
    $planner = new SchemaUpgradePlanner(new SchemaDiffEngine(), new SchemaChange(), $factory);
    $changeModel = new SchemaChange();
    $changeModel->setConnection($factory);
    $executor = new ExpandContractExecutor(
        new SchemaDdlExecutor($factory),
        new SchemaChangeHydrator(),
        new ColumnBackupService($factory, $catalog, $reader),
        new DataTransformExecutor($factory, new DataTransformProgress(), $journal),
        $catalog,
        $journal,
        $changeModel,
    );
    $coordinator = new DatabaseReleaseCoordinator(
        $factory,
        $manifestBuilder,
        $baselineBuilder,
        $planner,
        $executor,
        $snapshotService,
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
    );

    $applyResult = $coordinator->apply('my-drill-release', [
        'manifest' => $newManifest,
        'operation_id' => 'apply-my-drill-release',
        'signature' => ['alg' => 'hmac-sha256', 'value' => 'my-drill-signature'],
    ]);
    $check(($applyResult['status'] ?? '') === SchemaRelease::STATUS_APPLIED, '新 release 必须 applied');
    $applySnapshotKey = (string)($applyResult['snapshot_key'] ?? '');
    $check($applySnapshotKey !== '', 'apply 应返回 snapshot_key');

    $afterApply = $reader->readTable($connector, 'legacy_orders');
    $afterApplyColumns = \array_map(static fn($column): string => $column->name, $afterApply?->columns ?? []);
    $check(\in_array('note', $afterApplyColumns, true), 'apply 后 note 列必须存在');
    $rowsAfterApply = $connector->query(
        'SELECT id, status, customer FROM legacy_orders ORDER BY id'
    )->fetchArray();
    $check(
        \array_map(static fn(array $row): string => $row['id'] . ':' . $row['status'] . ':' . $row['customer'], $rowsAfterApply)
            === ['1:paid:alice', '2:new:bob', '3:paid:carol'],
        'apply 后 3 行数据必须保留'
    );

    $applySnapshotRow = (new SchemaSnapshot())->setConnection($factory)->reset()
        ->where(SchemaSnapshot::schema_fields_SNAPSHOT_KEY, $applySnapshotKey)
        ->limit(1)->select()->fetchArray()[0] ?? null;
    $applySnapshotPath = (string)($applySnapshotRow[SchemaSnapshot::schema_fields_PATH] ?? '');
    if ($applySnapshotPath !== '') {
        $snapshotPaths[] = $applySnapshotPath;
    }
    $check($applySnapshotPath !== '' && \is_file($applySnapshotPath), 'apply 前快照文件必须真实存在');

    $events = $journal->events('my-drill-release');
    $states = \array_map(static fn(array $event): string => (string)($event[SchemaOperationEvent::schema_fields_STATE] ?? ''), $events);
    $nodeIds = \array_map(static fn(array $event): string => (string)($event[SchemaOperationEvent::schema_fields_NODE_ID] ?? ''), $events);
    $check(\in_array(SchemaOperationEvent::STATE_PLANNED, $states, true), 'journal 应含 planned');
    $check(\in_array(SchemaOperationEvent::STATE_EXPANDED, $states, true), 'journal 应含 expanded');
    $check(\in_array(SchemaOperationEvent::STATE_COMMITTED, $states, true), 'journal 应含 committed');
    $snapshotEvidence = false;
    foreach ($nodeIds as $nodeId) {
        if (\str_starts_with($nodeId, 'snapshot:')) {
            $snapshotEvidence = true;
            break;
        }
    }
    $check($snapshotEvidence, 'journal 应含 snapshot 证据事件');
    $check(!\in_array(SchemaOperationEvent::STATE_FAILED, $states, true), 'apply 不应出现 failed');

    // ---- 4. L2 rollback 到 baseline ----
    $rollbackResult = $coordinator->rollback('my-drill-release', 'my-drill-baseline', [
        'operation_id' => 'rollback-my-drill-release',
    ]);
    $check(($rollbackResult['rollback_level'] ?? '') === 'L2', 'rollback_level 必须为 L2');
    $check(($rollbackResult['status'] ?? '') === SchemaOperationEvent::STATE_COMPENSATED, 'rollback status 必须为 compensated');
    $check(($rollbackResult['snapshot_id'] ?? '') === $baselineSnapshotKey, 'rollback 应恢复 baseline 快照');

    $afterRollback = $reader->readTable($connector, 'legacy_orders');
    $afterRollbackColumns = \array_map(static fn($column): string => $column->name, $afterRollback?->columns ?? []);
    $check(!\in_array('note', $afterRollbackColumns, true), 'rollback 后 note 列必须消失');
    $rowsAfterRollback = $connector->query(
        'SELECT id, status, customer FROM legacy_orders ORDER BY id'
    )->fetchArray();
    $check(
        \array_map(static fn(array $row): string => $row['id'] . ':' . $row['status'] . ':' . $row['customer'], $rowsAfterRollback)
            === ['1:paid:alice', '2:new:bob', '3:paid:carol'],
        'rollback 后 3 行数据必须恢复'
    );
    $check($connector->tableExist('legacy_orders'), 'rollback 后 legacy_orders 必须存在');

    $baselineReleaseAfterRollback = (new SchemaRelease())->setConnection($factory)->reset()
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'my-drill-baseline')
        ->limit(1)->select()->fetchArray();
    $check(
        ($baselineReleaseAfterRollback[0][SchemaRelease::schema_fields_STATUS] ?? '') === SchemaRelease::STATUS_APPLIED,
        'rollback 后 baseline release 应为 applied'
    );
    $rollbackEvents = $journal->events('my-drill-release');
    $compensated = false;
    foreach ($rollbackEvents as $event) {
        if ((string)($event[SchemaOperationEvent::schema_fields_STATE] ?? '') !== SchemaOperationEvent::STATE_COMPENSATED) {
            continue;
        }
        $detail = (string)($event[SchemaOperationEvent::schema_fields_DETAIL_JSON] ?? '');
        if (\str_contains($detail, '"rollback_level":"L2"')
            && \str_contains($detail, '"target_release_id":"my-drill-baseline"')
        ) {
            $compensated = true;
            break;
        }
    }
    $check($compensated, 'journal 必须记录 L2 compensated 回滚事件');
} catch (Throwable $exception) {
    $failures[] = 'exception: ' . $exception->getMessage();
} finally {
    if ($factory !== null) {
        try {
            $factory->close();
        } catch (Throwable) {
            // best-effort
        }
    }
    if ($connector !== null) {
        try {
            $connector->close();
        } catch (Throwable) {
            // best-effort
        }
    }
    $factory = null;
    $connector = null;

    if ($created && $adminPdo instanceof PDO) {
        try {
            $adminPdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        } catch (Throwable $exception) {
            $failures[] = 'cleanup: DROP DATABASE failed: ' . $exception->getMessage();
        }
    }
    $adminPdo = null;

    foreach ($snapshotPaths as $snapshotPath) {
        if (\is_string($snapshotPath)
            && \str_starts_with($snapshotPath, BP . 'var' . DS . 'backup' . DS . 'release' . DS)
            && \is_file($snapshotPath)
        ) {
            @\unlink($snapshotPath);
        }
    }
    foreach (['my-drill-baseline', 'my-drill-release'] as $releaseId) {
        $dir = BP . 'var' . DS . 'backup' . DS . 'release' . DS . $releaseId;
        if (!\is_dir($dir)) {
            continue;
        }
        foreach (\glob($dir . DS . '*') ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($dir);
    }
}

if ($failures === []) {
    echo 'OK MySQL temp database: ' . $dbName . PHP_EOL;
    echo 'OK baseline snapshot + legacy archive' . PHP_EOL;
    echo 'OK release apply keeps 3 rows / rollback L2 restores' . PHP_EOL;
}

$finish();
