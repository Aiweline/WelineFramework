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
use Weline\Database\Domain\Release\SchemaScope;
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
use Weline\Framework\Database\Release\Api\DatabaseReleaseCoordinatorInterface;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;

/**
 * T45d 验收：DatabaseReleaseCoordinator 的 plan/apply/rollback/inspect 全链路（SQLite）。
 */

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$tmp = \sys_get_temp_dir() . DS . 'weline_t45d_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.sqlite';$brokenDir = \sys_get_temp_dir() . DS . 'weline_t45d_missing_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
$brokenPath = $brokenDir . DS . 'broken.sqlite';
$factory = null;
$brokenFactory = null;

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

$buildManifest = static function (array $schemas, array $fingerprints): array {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    $module = [
        'version' => '1.0.0',
        'tables' => $fingerprints,
        'schemas' => $schemas,
        'checksum' => \hash('sha256', (string)\json_encode($fingerprints, $flags)),
    ];
    $modules = ['Weline_Unit' => $module];

    return [
        'modules' => $modules,
        'checksum' => \hash('sha256', (string)\json_encode($modules, $flags)),
    ];
};

try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(\count($installed['created']) === 11, 'installer 应创建 11 张表');

    $connector->createTableFromSchema('unit_release', [
        'comment' => '',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'int',
                'length' => 11,
                'nullable' => false,
                'primaryKey' => true,
                'autoIncrement' => true,
                'default' => null,
                'comment' => '',
                'unique' => true,
            ],
            [
                'name' => 'name',
                'type' => 'varchar',
                'length' => 255,
                'nullable' => false,
                'primaryKey' => false,
                'autoIncrement' => false,
                'default' => '',
                'comment' => '',
                'unique' => false,
            ],
        ],
        'indexes' => [],
        'foreignKeys' => [],
    ]);
    $connector->getLink()->query("INSERT INTO unit_release (name) VALUES ('baseline-row')")->fetch();

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
    $check($baselineSchema instanceof TableSchema, 'unit_release 应可读回 schema');
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
    $newManifest = $buildManifest(
        $newSchemas,
        ['unit_release' => \hash('sha256', (string)\json_encode($newSchemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))],
    );

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

    // ---- baseline release + snapshot ----
    $baselineBuilder->build('rel-base', $baselineManifest, [
        'verify_actual' => true,
        'baseline' => true,
    ]);
    $baselineSnapshot = $snapshotService->create('rel-base', $scope, ['operation_id' => 'op-base-snapshot']);
    $baselineSnapshotKey = (string)($baselineSnapshot['snapshot_key'] ?? '');
    $check($baselineSnapshotKey !== '', 'baseline 快照应生成 snapshot_key');
    $baselineBuilder->commit('rel-base', [
        'snapshot_id' => $baselineSnapshotKey,
        'baseline' => true,
    ]);
    $baselineReleaseRow = $reader->readTable($connector, 'unit_release');
    $check($baselineReleaseRow instanceof TableSchema, 'baseline 后 unit_release 仍可读');

    // ---- plan ----
    $plan = $coordinator->plan('rel-plan', ['manifest' => $newManifest]);
    $planExpandKinds = \array_map(
        static fn(array $entry): string => (string)($entry['change_type'] ?? ''),
        (array)($plan['expand'] ?? []),
    );
    $check(\in_array('add_column', $planExpandKinds, true), 'plan 应规划 add_column，实际：' . \implode(',', $planExpandKinds));
    $check(($plan['blockers'] ?? []) === [], 'plan 不应有 blockers');

    // ---- apply ----
    $applyResult = $coordinator->apply('rel-new', [
        'manifest' => $newManifest,
        'operation_id' => 'op-new',
        'signature' => ['alg' => 'hmac-sha256', 'key_id' => 'test', 'value' => 'dGVzdA=='],
    ]);
    $check(($applyResult['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'apply 应 applied');
    $check(str_starts_with((string)($applyResult['snapshot_key'] ?? ''), 'snap-'), 'apply 应先创建快照');
    $check(($applyResult['idempotent'] ?? true) === false, '首次 apply 不应标记 idempotent');

    $afterApplySchema = $reader->readTable($connector, 'unit_release');
    $afterApplyColumns = \array_map(static fn($column): string => $column->name, $afterApplySchema?->columns ?? []);
    $check(\in_array('note', $afterApplyColumns, true), 'apply 后 note 列应存在');

    $relNewRow = $coordinator->inspect('rel-new');
    $check(($relNewRow['release']['status'] ?? '') === SchemaRelease::STATUS_APPLIED, 'inspect 应返回 applied release');
    $check(\count($relNewRow['checkpoints'] ?? []) === 1, 'inspect 应返回 1 个 checkpoint');
    $check(($relNewRow['journal'] ?? []) !== [], 'inspect 应返回 journal 事件');
    $check(($relNewRow['blockers'] ?? []) === [], 'inspect 已有 release 不应有 blockers');
    $check((int)($relNewRow['table_owners'] ?? 0) >= 1, 'inspect 应统计 table owner');

    $missingInspect = $coordinator->inspect('rel-missing');
    $check(($missingInspect['blockers'] ?? []) !== [], 'inspect 不存在的 release 应返回 blockers');

    $events = $journal->events('rel-new');
    $states = \array_map(static fn(array $event): string => (string)$event['state'], $events);
    $check(\in_array(SchemaOperationEvent::STATE_PLANNED, $states, true), 'journal 应含 planned');
    $check(\in_array(SchemaOperationEvent::STATE_EXPANDED, $states, true), 'journal 应含 expanded');
    $check(\in_array(SchemaOperationEvent::STATE_COMMITTED, $states, true), 'journal 应含 committed');
    $snapshotEvidence = false;
    foreach ($events as $event) {
        if (\str_starts_with((string)($event['node_id'] ?? ''), 'snapshot:')) {
            $snapshotEvidence = true;
            break;
        }
    }
    $check($snapshotEvidence, 'journal 应含 snapshot 证据事件');

    $idempotent = $coordinator->apply('rel-new', [
        'manifest' => $newManifest,
        'signature' => ['alg' => 'hmac-sha256', 'value' => 'dGVzdA=='],
    ]);
    $check(($idempotent['idempotent'] ?? false) === true, '重复 apply 应幂等返回');
    $check(\count($journal->events('rel-new')) === \count($events), '幂等 apply 不应新增 journal 事件');


    // ---- fast path: 已有 applied release + schema 无变化 → 不再快照 ----
    $fastPath = $coordinator->apply('rel-fast', [
        'manifest' => $newManifest,
        'signature' => ['alg' => 'hmac-sha256', 'value' => 'dGVzdA=='],
    ]);
    $check(($fastPath['idempotent'] ?? false) === true, 'schema 无变化应幂等返回');
    $check(($fastPath['fast_path'] ?? '') === 'schema_unchanged', '应命中 schema_unchanged 快速路径');
    $check(($fastPath['snapshot_key'] ?? null) === null, '快速路径不应创建快照');
    $check(($coordinator->inspect('rel-fast')['blockers'] ?? []) !== [], '快速路径不应落 release 行');

    // 数据：apply 之后写一行，回滚快照后应消失
    $connector->getLink()
        ->query("INSERT INTO unit_release (name, note) VALUES ('after-row', 'hello')")
        ->fetch();

    // ---- rollback ----
    $rollbackResult = $coordinator->rollback('rel-new', 'rel-base');
    $check(($rollbackResult['rollback_level'] ?? '') === 'L2', 'rollback 必须返回 rollback_level=L2');
    $check(($rollbackResult['snapshot_id'] ?? '') === $baselineSnapshotKey, 'rollback 应使用 target 的最新快照');
    $check(
        ($rollbackResult['status'] ?? '') === SchemaOperationEvent::STATE_COMPENSATED,
        'rollback 状态应为 compensated'
    );

    $restoredSchema = $reader->readTable($connector, 'unit_release');
    $restoredColumns = \array_map(static fn($column): string => $column->name, $restoredSchema?->columns ?? []);
    $check(!\in_array('note', $restoredColumns, true), 'rollback 后 note 列应消失');

    $rows = $connector->getLink()
        ->query('SELECT name FROM unit_release ORDER BY id')
        ->fetchAll(PDO::FETCH_COLUMN);
    $check($rows === ['baseline-row'], 'rollback 后数据应恢复到快照：' . \json_encode($rows));

    $targetReleaseRow = $coordinator->inspect('rel-base');
    $check(
        ($targetReleaseRow['release']['status'] ?? '') === SchemaRelease::STATUS_APPLIED,
        'rollback 后 target release 应为 applied'
    );
    $relNewAfterRestore = $coordinator->inspect('rel-new');
    $relNewStatus = $relNewAfterRestore['release']['status'] ?? null;
    $check(
        $relNewStatus === null || $relNewStatus === SchemaRelease::STATUS_ROLLED_BACK,
        'rollback 后当前 release 不得仍为 applied，实际：' . \var_export($relNewStatus, true)
    );

    $compensatedEvents = \array_values(\array_filter(
        $journal->events('rel-new'),
        static fn(array $event): bool => ($event['state'] ?? '') === SchemaOperationEvent::STATE_COMPENSATED,
    ));
    $check(\count($compensatedEvents) === 1, 'rollback 后 journal 应有 1 条 compensated 事件');
    $compensatedDetail = \json_decode((string)($compensatedEvents[0]['detail_json'] ?? ''), true);
    $check(($compensatedDetail['reason'] ?? '') === 'rollback', 'compensated detail 应记录 reason=rollback');
    $check(($compensatedDetail['target_release_id'] ?? '') === 'rel-base', 'compensated detail 应记录 target_release_id');
    $check(($compensatedDetail['snapshot_id'] ?? '') === $baselineSnapshotKey, 'compensated detail 应记录 snapshot_id');

    // ---- 无快照 apply：先抛异常，不执行 DDL ----
    $brokenConfig = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $brokenPath,
        'persistent' => false,
    ]);
    $brokenFactory = ConnectionFactory::getInstance($brokenConfig);
    $brokenSnapshotService = new DatabaseSnapshotService(
        $brokenFactory,
        new SchemaSnapshot(),
        new BackupCatalog($brokenFactory, new BackupSet(), new BackupObject()),
    );
    $noSnapshotCoordinator = new DatabaseReleaseCoordinator(
        $factory,
        $manifestBuilder,
        $baselineBuilder,
        $planner,
        $executor,
        $brokenSnapshotService,
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
    );
    $newSchemas2 = $baselineSchemas;
    $newSchemas2['unit_release']['columns'][] = [
        'name' => 'note2',
        'type' => 'varchar',
        'length' => 255,
        'nullable' => true,
        'primaryKey' => false,
        'autoIncrement' => false,
        'default' => null,
        'comment' => '',
        'unique' => false,
    ];
    $noSnapshotManifest = $buildManifest(
        $newSchemas2,
        ['unit_release' => \hash('sha256', (string)\json_encode($newSchemas2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))],
    );
    $snapshotFailure = false;
    try {
        $noSnapshotCoordinator->apply('rel-nosnap', [
            'manifest' => $noSnapshotManifest,
            'signature' => ['alg' => 'hmac-sha256', 'value' => 'dGVzdA=='],
        ]);
    } catch (Throwable) {
        $snapshotFailure = true;
    }
    $check($snapshotFailure, '快照创建失败时 apply 必须抛异常');
    $nosnapSchema = $reader->readTable($connector, 'unit_release');
    $nosnapColumns = \array_map(static fn($column): string => $column->name, $nosnapSchema?->columns ?? []);
    $check(!\in_array('note2', $nosnapColumns, true), '快照失败时不得执行 DDL');
    $nosnapRow = $coordinator->inspect('rel-nosnap');
    $check(
        ($nosnapRow['release']['status'] ?? '') === SchemaRelease::STATUS_PENDING
            || ($nosnapRow['release']['status'] ?? '') === 'planned',
        '快照失败后 release 应保持 planned/pending'
    );
    $check(
        ($nosnapRow['release']['applied_at'] ?? null) === null,
        '快照失败后 applied_at 必须为空'
    );
} finally {
    foreach ([$factory, $brokenFactory] as $closing) {
        if ($closing instanceof ConnectionFactory) {
            try {
                $closing->close();
            } catch (Throwable) {
                // ignore close failure during cleanup
            }
        }
    }
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
    foreach (['rel-base', 'rel-new', 'rel-nosnap', 'rel-plan'] as $releaseDir) {
        $dir = BP . 'var' . DS . 'backup' . DS . 'release' . DS . $releaseDir;
        if (\is_dir($dir)) {
            foreach (\glob($dir . DS . '*') ?: [] as $artifact) {
                if (\is_file($artifact)) {
                    @\unlink($artifact);
                }
            }
            @\rmdir($dir);
        }
    }
    $releaseRoot = BP . 'var' . DS . 'backup' . DS . 'release';
    @\rmdir($releaseRoot);
    @\rmdir(\dirname($releaseRoot));
    @\rmdir(\dirname(\dirname($releaseRoot)));
    if (\is_dir($brokenDir)) {
        @\rmdir($brokenDir);
    }
}

if ($failures !== []) {
    \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T45D COORDINATOR PASS' . PHP_EOL;
exit(0);
