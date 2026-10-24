<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
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
            'method' => $index->method,
            'comment' => $index->comment,
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

    return ['comment' => $schema->comment, 'columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys];
};

$buildManifest = static function (array $schemas, array $fingerprints): array {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    $module = [
        'version' => '1.0.0',
        'tables' => $fingerprints,
        'schemas' => $schemas,
        'checksum' => hash('sha256', (string)json_encode($fingerprints, $flags)),
    ];
    $modules = ['Weline_Unit' => $module];

    return ['modules' => $modules, 'checksum' => hash('sha256', (string)json_encode($modules, $flags))];
};

$dbName = 'weline_mariadb_' . getmypid() . '_' . bin2hex(random_bytes(4));
$pdo = null;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

    $config = new ConfigProvider([
        'type' => 'mysql',
        'hostname' => '127.0.0.1',
        'hostport' => '3307',
        'database' => $dbName,
        'username' => 'root',
        'password' => '',
        'prefix' => '',
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_general_ci',
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(count($installed['created']) === 11, 'installer must create 11 tables on MySQL');

    $connector->createTableFromSchema('unit_release', [
        'comment' => '',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'length' => 11, 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'default' => null, 'comment' => '', 'unique' => true],
            ['name' => 'name', 'type' => 'varchar', 'length' => 255, 'nullable' => false, 'primaryKey' => false, 'autoIncrement' => false, 'default' => '', 'comment' => '', 'unique' => false],
        ],
        'indexes' => [],
        'foreignKeys' => [],
    ]);
    $connector->query("INSERT INTO unit_release (name) VALUES ('baseline-row')")->fetch();

    $scope = new SchemaScope(
        $config->getConnectionName() !== '' ? $config->getConnectionName() : 'default',
        $config->getDatabase(),
        '',
        '',
        '',
    );

    $fingerprint = new SchemaFingerprint($factory);
    $reader = new DbSchemaReader();
    $baselineSchema = $reader->readTable($connector, 'unit_release');
    $check($baselineSchema instanceof TableSchema, 'unit_release readable on MySQL');
    $baselineSchemas = ['unit_release' => $schemaToArray($baselineSchema)];
    $baselineFingerprint = $fingerprint->fingerprint($baselineSchema);
    $baselineManifest = $buildManifest($baselineSchemas, ['unit_release' => $baselineFingerprint]);

    $newSchemas = $baselineSchemas;
    $newSchemas['unit_release']['columns'][] = [
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
    $newManifest = $buildManifest($newSchemas, [
        'unit_release' => hash('sha256', (string)json_encode($newSchemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
    ]);

    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $backupCatalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $snapshotService = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $backupCatalog);
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
    );
    $manifestBuilder = new SchemaManifestBuilder(
        ObjectManager::getInstance(Handle::class),
        ObjectManager::getInstance(ModuleFileReader::class),
        new SchemaParser(),
        $fingerprint,
    );
    $planner = new SchemaUpgradePlanner(new SchemaDiffEngine(), new SchemaChange(), $factory);
    $executor = new ExpandContractExecutor(
        new SchemaDdlExecutor($factory),
        new SchemaChangeHydrator(),
        new ColumnBackupService($factory, $backupCatalog, $reader),
        new DataTransformExecutor($factory, new DataTransformProgress(), $journal),
        $backupCatalog,
        $journal,
        new SchemaChange(),
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

    $baselineBuilder->build('rel-base', $baselineManifest, ['verify_actual' => true, 'baseline' => true]);
    $baselineSnapshot = $snapshotService->create('rel-base', $scope, ['operation_id' => 'op-base']);
    $baselineSnapshotKey = (string)($baselineSnapshot['snapshot_key'] ?? '');
    $check($baselineSnapshotKey !== '', 'MySQL baseline snapshot created');
    $baselineBuilder->commit('rel-base', ['snapshot_id' => $baselineSnapshotKey, 'baseline' => true]);

    $apply = $coordinator->apply('rel-new', [
        'manifest' => $newManifest,
        'operation_id' => 'op-new',
        'signature' => ['alg' => 'hmac-sha256', 'key_id' => 'test', 'value' => 'dGVzdA=='],
    ]);
    $check(($apply['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'MySQL apply applied');
    $afterSchema = $reader->readTable($connector, 'unit_release');
    $afterColumns = array_map(static fn($column): string => $column->name, $afterSchema?->columns ?? []);
    $check(in_array('note', $afterColumns, true), 'MySQL apply added note column');

    $rows = $connector->query('SELECT name FROM unit_release ORDER BY id')->fetchArray();
    $check(($rows[0]['name'] ?? '') === 'baseline-row', 'MySQL data preserved after apply');

    $release = $coordinator->inspect('rel-new');
    $check(($release['release']['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'MySQL release applied inspect');
    $check(($release['blockers'] ?? []) === [], 'MySQL inspect blockers empty');


    $connector->query("INSERT INTO unit_release (name) VALUES ('after-row')")->fetch();
    $rollback = $coordinator->rollback('rel-new', 'rel-base');
    $check(($rollback['rollback_level'] ?? '') === 'L2', 'MySQL rollback level L2');
    $check(($rollback['status'] ?? '') === SchemaOperationEvent::STATE_COMPENSATED, 'MySQL rollback compensated');
    $rollbackSchema = $reader->readTable($connector, 'unit_release');
    $rollbackColumns = array_map(static fn($column): string => $column->name, $rollbackSchema?->columns ?? []);
    $check(!in_array('note', $rollbackColumns, true), 'MySQL rollback removed note column');
    $rollbackRows = $connector->query('SELECT name FROM unit_release ORDER BY id')->fetchArray();
    $check(($rollbackRows[0]['name'] ?? '') === 'baseline-row', 'MySQL rollback restored baseline row');
    $check(count($rollbackRows) === 1, 'MySQL rollback removed after-row');

    $connector->getLink()->exec('DROP TABLE unit_release');
} catch (Throwable $exception) {
    $failures[] = 'exception: ' . $exception->getMessage();
} finally {
    try {
        if ($pdo instanceof PDO) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        }
    } catch (Throwable) {
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T49E MARIADB PASS' . PHP_EOL;
