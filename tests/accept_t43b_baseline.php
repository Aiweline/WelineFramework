<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
define('DS', \DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Console\Release\Inspect;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Model\Release\TableOwner;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\BaselineReleaseBuilder;
use Weline\Database\Service\Release\DatabaseSnapshotService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaFingerprint;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaParser;

/**
 * T43b 验收：BaselineReleaseBuilder（planned → commit）+ release:inspect（只读）。
 */

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$tmp = \sys_get_temp_dir() . DS . 'weline_t43b_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.sqlite';
$artifact = \sys_get_temp_dir() . DS . 'weline_t43b_snap_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.bin';
$factory = null;
try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();
    \file_put_contents($artifact, 't43b snapshot artifact ' . \bin2hex(\random_bytes(8)));
    $check(\is_file($artifact) && \filesize($artifact) > 0, '快照制品文件应创建成功');

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $install = $installer->install();
    $check(\count($install['created']) === 11, 'installer 应创建 11 张表，实际 ' . \count($install['created']));

    $connector->createTableFromSchema('unit_baseline', [
        'comment' => '',
        'columns' => [[
            'name' => 'id',
            'type' => 'int',
            'length' => 11,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'comment' => '',
            'unique' => true,
        ]],
        'indexes' => [],
        'foreignKeys' => [],
    ]);
    $check($connector->tableExist('unit_baseline'), '测试表 unit_baseline 应存在');

    $model = static function (object $prototype) use ($factory): object {
        $instance = clone $prototype;
        $instance->setConnection($factory);
        $instance->__init();
        $instance->clear();

        return $instance;
    };

    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $builder = new BaselineReleaseBuilder(
        $installer,
        $factory,
        new DbSchemaReader(),
        new SchemaFingerprint($factory),
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
    );
    $snapshotService = new DatabaseSnapshotService(
        $factory,
        new SchemaSnapshot(),
        new BackupCatalog($factory, new BackupSet(), new BackupObject()),
    );
    $builderWithVerify = new BaselineReleaseBuilder(
        $installer,
        $factory,
        new DbSchemaReader(),
        new SchemaFingerprint($factory),
        $journal,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
        new SchemaSnapshot(),
        $snapshotService,
    );
    $insertSnapshot = static function (
        string $snapshotKey,
        string $checksum,
        string $status,
        string $scopeKey,
        string $releaseId,
    ) use ($model, $artifact): int {
        $snapshot = $model(new SchemaSnapshot());
        $snapshot->setData([
            SchemaSnapshot::schema_fields_SNAPSHOT_KEY => $snapshotKey,
            SchemaSnapshot::schema_fields_RELEASE_ID => $releaseId,
            SchemaSnapshot::schema_fields_SCOPE_KEY => $scopeKey,
            SchemaSnapshot::schema_fields_ENGINE => SchemaSnapshot::ENGINE_SQLITE,
            SchemaSnapshot::schema_fields_PATH => $artifact,
            SchemaSnapshot::schema_fields_CHECKSUM => $checksum,
            SchemaSnapshot::schema_fields_SIZE_BYTES => (int)\filesize($artifact),
            SchemaSnapshot::schema_fields_STATUS => $status,
            SchemaSnapshot::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ]);
        $saved = $snapshot->save();

        return \is_int($saved) && $saved > 0 ? $saved : (int)$snapshot->getId();
    };

    $unitFingerprint = \str_repeat('a', 64);
    $moduleChecksum = \str_repeat('b', 64);
    $manifestChecksum = \str_repeat('c', 64);
    $manifest = [
        'modules' => [
            'Weline_Unit' => [
                'version' => '1.0.0',
                'tables' => ['unit_baseline' => $unitFingerprint],
                'schemas' => [
                    'unit_baseline' => [
                        'comment' => '',
                        'columns' => [[
                            'name' => 'id',
                            'type' => 'int',
                            'length' => 11,
                            'nullable' => false,
                            'primaryKey' => true,
                            'autoIncrement' => true,
                            'default' => null,
                            'comment' => '',
                            'unique' => true,
                        ]],
                        'indexes' => [],
                        'foreignKeys' => [],
                    ],
                ],
                'checksum' => $moduleChecksum,
            ],
        ],
        'checksum' => $manifestChecksum,
    ];

    $result = $builder->build('baseline-test', $manifest, [
        'verify_actual' => false,
        'baseline' => true,
    ]);
    $check($result['release_id'] === 'baseline-test', 'build 返回 release_id 错误');
    $check($result['schema_epoch'] === 1, '空表时 schema_epoch 应为 1，实际 ' . $result['schema_epoch']);
    $check($result['tables'] === 1, 'build 返回 tables 应为 1');
    $check($result['modules'] === 1, 'build 返回 modules 应为 1');
    $check($result['blockers'] === [], 'build 成功时 blockers 应为空');
    $check($result['scope_key'] !== '', 'build 应返回 scope_key');

    $releaseRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(($releaseRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'planned', 'build 后 status 应为 planned');
    $check(
        ($releaseRow[SchemaRelease::schema_fields_APPLIED_AT] ?? null) === null,
        'build 后 applied_at 必须为空'
    );
    $check((int)($releaseRow[SchemaRelease::schema_fields_SCHEMA_EPOCH] ?? 0) === 1, 'release schema_epoch 应为 1');
    $check(
        ($releaseRow[SchemaRelease::schema_fields_MANIFEST_CHECKSUM] ?? '') === $manifestChecksum,
        'release manifest_checksum 错误'
    );
    $check(
        ($releaseRow[SchemaRelease::schema_fields_ROLLBACK_POLICY] ?? '') === 'L2',
        'rollback_policy 默认应为 L2'
    );
    $storedManifest = \json_decode((string)($releaseRow[SchemaRelease::schema_fields_MANIFEST_JSON] ?? ''), true);
    $check(\is_array($storedManifest), 'release manifest_json 应为合法 JSON');
    $check(
        isset($storedManifest['modules']['Weline_Unit']['tables']['unit_baseline']),
        'manifest_json 应保存模块表指纹'
    );

    $plannedEvents = $journal->events('baseline-test');
    $check(\count($plannedEvents) === 1, 'build 后 journal 应有 1 条事件，实际 ' . \count($plannedEvents));
    $check(
        ($plannedEvents[0]['state'] ?? '') === SchemaOperationEvent::STATE_PLANNED,
        'build 后 journal 事件状态应为 planned'
    );
    $check(
        ($plannedEvents[0]['operation_id'] ?? '') === 'baseline-baseline-test',
        'planned 事件 operation_id 错误'
    );
    $check(($plannedEvents[0]['node_id'] ?? '') === 'baseline:release', 'planned 事件 node_id 错误');
    $check(
        ($plannedEvents[0]['checksum'] ?? '') === $manifestChecksum,
        'planned 事件 checksum 应等于 manifest checksum'
    );

    $blocked = false;
    try {
        $builder->commit('baseline-test');
    } catch (RuntimeException) {
        $blocked = true;
    }
    $check($blocked, 'commit 未带 snapshot_id 必须抛 RuntimeException');

    // 伪造 snapshot（weline_schema_snapshot 无对应行）必须拒绝，release 保持 planned
    $forgedBlocked = false;
    try {
        $builder->commit('baseline-test', ['snapshot_id' => 'snap-test', 'baseline' => true]);
    } catch (RuntimeException) {
        $forgedBlocked = true;
    }
    $check($forgedBlocked, '不存在的 snapshot_id 必须抛 RuntimeException');
    $forgedRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        ($forgedRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'planned',
        '伪造快照被拒后 release 必须保持 planned'
    );
    $check(\count($journal->events('baseline-test')) === 1, '伪造快照不得追加 committed 事件');

    // 插入真实快照行（checksum 与临时制品一致）
    $snapshotRowId = $insertSnapshot(
        'snap-test',
        (string)\hash_file('sha256', $artifact),
        SchemaSnapshot::STATUS_READY,
        $result['scope_key'],
        'baseline-test',
    );
    $check($snapshotRowId > 0, 'SchemaSnapshot 应写入成功');

    $builder->commit('baseline-test', ['snapshot_id' => 'snap-test', 'baseline' => true]);

    $releaseRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(($releaseRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'applied', 'commit 后 status 应为 applied');
    $check(
        (string)($releaseRow[SchemaRelease::schema_fields_APPLIED_AT] ?? '') !== '',
        'commit 后 applied_at 不应为空'
    );
    $committedManifest = \json_decode(
        (string)($releaseRow[SchemaRelease::schema_fields_MANIFEST_JSON] ?? ''),
        true
    );
    $check(($committedManifest['unsigned_baseline'] ?? false) === true, 'unsigned baseline 应记录 unsigned_baseline=true');
    $check(($committedManifest['baseline'] ?? false) === true, 'unsigned baseline 应记录 baseline=true');

    $events = $journal->events('baseline-test');
    $check(\count($events) === 2, 'commit 后 journal 应有 2 条事件，实际 ' . \count($events));
    $check(
        \array_map(static fn(array $event): string => (string)$event['state'], $events)
            === [SchemaOperationEvent::STATE_PLANNED, SchemaOperationEvent::STATE_COMMITTED],
        'journal 状态顺序应为 planned,committed'
    );
    $committedDetail = \json_decode((string)($events[1]['detail_json'] ?? ''), true);
    $check(($committedDetail['snapshot_id'] ?? '') === 'snap-test', 'committed 事件应记录 snapshot_id');
    $check(($committedDetail['unsigned_baseline'] ?? false) === true, 'committed 事件应标记 unsigned_baseline');

    $appliedAt = (string)($releaseRow[SchemaRelease::schema_fields_APPLIED_AT] ?? '');
    $builder->commit('baseline-test', ['snapshot_id' => 'snap-test', 'baseline' => true]);
    $releaseRowAgain = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        (string)($releaseRowAgain[SchemaRelease::schema_fields_APPLIED_AT] ?? '') === $appliedAt,
        '重复 commit 不应改变 applied_at'
    );
    $check(\count($journal->events('baseline-test')) === 2, '重复 commit 必须幂等（不追加事件）');

    // 快照强校验：checksum 不匹配 / status 非 ready|restored / checksum 为空 → 保持 planned
    $snapshotCases = $builder->build('snapshot-cases', $manifest, [
        'verify_actual' => false,
        'baseline' => true,
    ]);
    $insertSnapshot(
        'snap-bad-checksum',
        \str_repeat('f', 64),
        SchemaSnapshot::STATUS_READY,
        $snapshotCases['scope_key'],
        'snapshot-cases',
    );
    $badChecksumBlocked = false;
    try {
        $builderWithVerify->commit('snapshot-cases', ['snapshot_id' => 'snap-bad-checksum']);
    } catch (RuntimeException) {
        $badChecksumBlocked = true;
    }
    $check($badChecksumBlocked, 'snapshot checksum 不匹配必须抛 RuntimeException');

    $insertSnapshot(
        'snap-failed',
        (string)\hash_file('sha256', $artifact),
        SchemaSnapshot::STATUS_FAILED,
        $snapshotCases['scope_key'],
        'snapshot-cases',
    );
    $failedStatusBlocked = false;
    try {
        $builder->commit('snapshot-cases', ['snapshot_id' => 'snap-failed']);
    } catch (RuntimeException) {
        $failedStatusBlocked = true;
    }
    $check($failedStatusBlocked, 'snapshot status=failed 必须抛 RuntimeException');

    $insertSnapshot(
        'snap-empty-checksum',
        '',
        SchemaSnapshot::STATUS_READY,
        $snapshotCases['scope_key'],
        'snapshot-cases',
    );
    $emptyChecksumBlocked = false;
    try {
        $builder->commit('snapshot-cases', ['snapshot_id' => 'snap-empty-checksum']);
    } catch (RuntimeException) {
        $emptyChecksumBlocked = true;
    }
    $check($emptyChecksumBlocked, 'snapshot checksum 为空必须抛 RuntimeException');

    $snapshotCaseRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'snapshot-cases')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        ($snapshotCaseRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'planned',
        '快照被拒后 release 必须保持 planned'
    );
    $check(
        \count($journal->events('snapshot-cases')) === 1,
        '快照被拒不得追加 committed 事件'
    );

    // int snapshot_id 匹配 + 行级校验通过
    $intSnapshotId = $insertSnapshot(
        'snap-int-id',
        (string)\hash_file('sha256', $artifact),
        SchemaSnapshot::STATUS_READY,
        $snapshotCases['scope_key'],
        'snapshot-cases',
    );
    $builder->commit('snapshot-cases', ['snapshot_id' => (string)$intSnapshotId]);
    $snapshotCaseRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'snapshot-cases')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        ($snapshotCaseRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'applied',
        'int snapshot_id 应可用于 commit'
    );
    $check(\count($journal->events('snapshot-cases')) === 2, 'int snapshot_id commit 后 journal 应有 2 条事件');

    // 注入 DatabaseSnapshotService 时 verify() 通过才允许 applied
    $verifyRelease = $builderWithVerify->build('snapshot-verify', $manifest, [
        'verify_actual' => false,
        'baseline' => true,
    ]);
    $insertSnapshot(
        'snap-verified',
        (string)\hash_file('sha256', $artifact),
        SchemaSnapshot::STATUS_READY,
        $verifyRelease['scope_key'],
        'snapshot-verify',
    );
    $builderWithVerify->commit('snapshot-verify', ['snapshot_id' => 'snap-verified']);
    $verifyRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'snapshot-verify')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        ($verifyRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'applied',
        'DatabaseSnapshotService::verify 通过后应 applied'
    );

    // snapshot_id 也可来自 release manifest（build options 写入）
    $builder->build('manifest-snapshot', $manifest, [
        'verify_actual' => false,
        'baseline' => true,
        'snapshot_id' => 'snap-manifest',
    ]);
    $manifestSnapshotRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'manifest-snapshot')
        ->select()
        ->fetchArray()[0] ?? [];
    $manifestSnapshotData = \json_decode(
        (string)($manifestSnapshotRow[SchemaRelease::schema_fields_MANIFEST_JSON] ?? ''),
        true
    );
    $check(
        ($manifestSnapshotData['snapshot_id'] ?? '') === 'snap-manifest',
        'build 应把 snapshot_id 写入 manifest_json'
    );
    $insertSnapshot(
        'snap-manifest',
        (string)\hash_file('sha256', $artifact),
        SchemaSnapshot::STATUS_READY,
        $result['scope_key'],
        'manifest-snapshot',
    );
    $builder->commit('manifest-snapshot');
    $manifestSnapshotRow = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'manifest-snapshot')
        ->select()
        ->fetchArray()[0] ?? [];
    $check(
        ($manifestSnapshotRow[SchemaRelease::schema_fields_STATUS] ?? '') === 'applied',
        'commit 未显式传 snapshot_id 时应回退 release manifest'
    );

    $checkpoints = $model(new SchemaCheckpoint())
        ->where(SchemaCheckpoint::schema_fields_RELEASE_ID, 'baseline-test')
        ->select()
        ->fetchArray();
    $check(\count($checkpoints) === 1, 'baseline-test SchemaCheckpoint 应为 1 条，实际 ' . \count($checkpoints));
    $checkpoint = $checkpoints[0] ?? [];
    $check(
        ($checkpoint[SchemaCheckpoint::schema_fields_MODULE_NAME] ?? '') === 'Weline_Unit',
        'checkpoint module_name 错误'
    );
    $check(
        (int)($checkpoint[SchemaCheckpoint::schema_fields_FORMAT] ?? 0) === 2,
        'checkpoint format 应为 2'
    );
    $check(
        ($checkpoint[SchemaCheckpoint::schema_fields_CHECKSUM] ?? '') === $moduleChecksum,
        'checkpoint checksum 应为模块 checksum'
    );
    $checkpointTables = \json_decode((string)($checkpoint[SchemaCheckpoint::schema_fields_TABLES_JSON] ?? ''), true);
    $check(
        ($checkpointTables['tables']['unit_baseline'] ?? '') === $unitFingerprint,
        'checkpoint tables_json 应保存表指纹'
    );
    $check(
        isset($checkpointTables['schemas']['unit_baseline']),
        'checkpoint tables_json 存在 schemas 时应保存元数据'
    );

    $scopes = $model(new SchemaScopeRecord())->select()->fetchArray();
    $check(\count($scopes) === 1, 'SchemaScopeRecord 应为 1 条，实际 ' . \count($scopes));

    $owners = $model(new TableOwner())->select()->fetchArray();
    $check(\count($owners) === 1, 'TableOwner 应为 1 条，实际 ' . \count($owners));
    $owner = $owners[0] ?? [];
    $check(
        ($owner[TableOwner::schema_fields_OWNER_MODULE] ?? '') === 'Weline_Unit',
        'TableOwner owner_module 错误'
    );
    $check(($owner[TableOwner::schema_fields_SOURCE] ?? '') === 'model', 'TableOwner source 应为 model');
    $check(
        ($owner[TableOwner::schema_fields_CHECKSUM] ?? '') === $unitFingerprint,
        'TableOwner checksum 应为表指纹'
    );

    // 漂移必须阻断：没有任何静默降级路径
    $driftedManifest = $manifest;
    $driftedManifest['modules']['Weline_Unit']['tables']['unit_baseline'] = \str_repeat('d', 64);
    $driftBlocked = false;
    try {
        $builder->build('drift-test', $driftedManifest, ['verify_actual' => true]);
    } catch (RuntimeException) {
        $driftBlocked = true;
    }
    $check($driftBlocked, '实际库指纹不一致必须抛 RuntimeException');
    $driftRows = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'drift-test')
        ->select()
        ->fetchArray();
    $check($driftRows === [], '漂移被阻断时不得写入 release');
    $check($journal->events('drift-test') === [], '漂移被阻断时不得写 journal');

    // 非 baseline 无签名禁止 applied
    $builder->build('unsigned-test', $manifest, ['verify_actual' => false]);
    $unsignedBlocked = false;
    try {
        $builder->commit('unsigned-test', ['snapshot_id' => 'snap-test']);
    } catch (RuntimeException) {
        $unsignedBlocked = true;
    }
    $check($unsignedBlocked, '非 baseline 且无签名必须抛 RuntimeException');
    $unsignedRows = $model(new SchemaRelease())
        ->where(SchemaRelease::schema_fields_RELEASE_ID, 'unsigned-test')
        ->select()
        ->fetchArray();
    $check(
        ($unsignedRows[0][SchemaRelease::schema_fields_STATUS] ?? '') === 'planned',
        '被拒绝的 commit 不得改变 release 状态'
    );

    // release:inspect 只读命令
    $check(\class_exists(Inspect::class), 'Console/Release/Inspect 类不存在');
    $inspectReflection = new ReflectionClass(Inspect::class);
    $check(
        $inspectReflection->implementsInterface(CommandInterface::class),
        'Inspect 必须实现 CommandInterface'
    );
    foreach (['execute', 'tip', 'help'] as $method) {
        $check($inspectReflection->hasMethod($method), 'Inspect 缺少方法：' . $method);
    }
    $inspect = new Inspect(
        $factory,
        new SchemaRelease(),
        new SchemaCheckpoint(),
        new SchemaScopeRecord(),
        new TableOwner(),
    );
    \ob_start();
    $inspectOutput = $inspect->execute(['release' => 'baseline-test', 'format' => 'json']);
    \ob_end_clean();
    $inspectPayload = \json_decode($inspectOutput, true);
    $check(\is_array($inspectPayload), 'release:inspect --format=json 应输出合法 JSON');
    $check(
        ($inspectPayload['release']['release_id'] ?? '') === 'baseline-test',
        'release:inspect 应输出指定 release'
    );
    $check(
        (int)($inspectPayload['checkpoints'][0]['tables'] ?? 0) === 1,
        'release:inspect 应统计 checkpoint 表数'
    );
    $check(
        (int)($inspectPayload['table_owners']['total'] ?? 0) === 1,
        'release:inspect 应输出 table owner 总数（同表同 owner 去重）'
    );
} finally {
    if ($factory instanceof ConnectionFactory) {
        try {
            $factory->close();
        } catch (Throwable) {
            // ignore close failure during cleanup
        }
    }
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal', $artifact] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
}

if ($failures !== []) {
    \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T43B BASELINE FAIL' . PHP_EOL;
    exit(1);
}

echo 'T43B BASELINE PASS' . PHP_EOL;
exit(0);
