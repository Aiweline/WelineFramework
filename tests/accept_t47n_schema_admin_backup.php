<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
define('DS', \DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/bootstrap.php';

use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\ColumnBackupService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaParser;

/**
 * T47n 严格验收：SchemaAdminService 改用 ColumnBackupService/BackupCatalog。
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
        echo 'T47N SCHEMA-ADMIN FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T47N SCHEMA-ADMIN PASS' . PHP_EOL;
    exit(0);
};

$dbName = 'weline_t47n_' . \getmypid() . '_' . \strtolower(\bin2hex(\random_bytes(4)));
$adminPdo = null;
$factory = null;
$connector = null;
$created = false;

try {
    if (\preg_match('/\Aweline_t47n_\d+_[a-f0-9]{8}\z/', $dbName) !== 1) {
        throw new \RuntimeException('invalid temp database name: ' . $dbName);
    }

    $adminPdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=postgres',
        'weline',
        'weline',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $adminPdo->exec('CREATE DATABASE "' . $dbName . '"');
    $created = true;

    $config = new ConfigProvider([
        'type' => 'pgsql',
        'hostname' => '127.0.0.1',
        'hostport' => 5432,
        'database' => $dbName,
        'username' => 'weline',
        'password' => 'weline',
        'prefix' => '',
        'charset' => 'utf8',
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();
    $check(\strtolower((string)$connector->getConfigProvider()->getDbType()) === 'pgsql', '验收连接必须是 pgsql');

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $installed = $installer->install();
    $check(\count($installed['created']) === 11, 'RSSE 备份目录表必须安装');

    $connector->query(
        'CREATE TABLE legacy_orders (id integer PRIMARY KEY, status varchar(32), customer varchar(64))'
    )->fetch();
    $connector->query(
        "INSERT INTO legacy_orders (id, status, customer) VALUES"
        . " (1, 'paid', 'alice'), (2, 'new', 'bob'), (3, 'paid', 'carol')"
    )->fetch();
    $reader = new DbSchemaReader();
    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $columnBackup = new ColumnBackupService($factory, $catalog, $reader);
    $service = new SchemaAdminService($factory, $columnBackup, $catalog);

    // ---- 0. 源码/构造器：旧 BackupService 必须消失 ----
    $source = (string)\file_get_contents(
        BP . 'app/code/Weline/Database/Service/Admin/SchemaAdminService.php'
    );
    $check($source !== '', '无法读取 SchemaAdminService 源码');
    $check(!\str_contains($source, 'Weline\\Framework\\Database\\Service\\BackupService'), '不得引用旧 Framework BackupService');
    $check(\preg_match('/\bBackupService\b/', $source) !== 1, '不得残留独立 BackupService 符号');
    $reflection = new ReflectionClass(SchemaAdminService::class);
    $constructor = $reflection->getConstructor();
    $check($constructor !== null, 'SchemaAdminService 缺少构造函数');
    if ($constructor !== null) {
        $types = \array_map(
            static fn(ReflectionParameter $parameter): string => (string)$parameter->getType(),
            $constructor->getParameters(),
        );
        $check(
            $types === [
                ConnectionFactory::class,
                ColumnBackupService::class,
                BackupCatalog::class,
            ],
            'SchemaAdminService 构造依赖应为 ConnectionFactory/ColumnBackupService/BackupCatalog'
        );
    }

    $sets = static function () use ($factory): array {
        return (new BackupSet())->setConnection($factory)->reset()
            ->order(BackupSet::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    };
    $columnNames = static function (string $table) use ($reader, $connector): array {
        $schema = $reader->readTable($connector, $table);

        return \array_map(static fn($column): string => $column->name, $schema?->columns ?? []);
    };

    // ---- 1. add_column / index 不备份 ----
    $before = \count($sets());
    $service->addColumn('public', 'legacy_orders', 'note', 'varchar(255)');
    $check(\in_array('note', $columnNames('legacy_orders'), true), 'add_column 应成功');
    $check(\count($sets()) === $before, 'add_column 不应创建数据备份');

    $service->addIndex('public', 'legacy_orders', 'idx_admin_note', ['note']);
    $service->dropIndex('public', 'legacy_orders', 'idx_admin_note');
    $check(\count($sets()) === $before, 'add/drop index 不应创建数据备份');

    // ---- 2. modify_column 先备份 ----
    $service->modifyColumn('public', 'legacy_orders', 'status', 'varchar(64)');
    $modifySets = $sets();
    $check(\count($modifySets) === $before + 1, 'modify_column 应创建一个数据备份集');
    $modifySet = $modifySets[\array_key_last($modifySets)] ?? [];
    $check(
        \preg_match('/\Aadmin-\d{14}-[a-f0-9]{8}\z/', (string)($modifySet[BackupSet::schema_fields_RELEASE_ID] ?? '')) === 1,
        'modify_column release_id 格式错误'
    );
    $check(
        ($modifySet[BackupSet::schema_fields_SCOPE_KEY] ?? '') === 'admin:public',
        'modify_column scope_key 应为 admin:public'
    );
    $check(
        (string)($modifySet[BackupSet::schema_fields_OPERATION_ID] ?? '') !== '',
        'modify_column operation_id 不能为空'
    );
    $modifyObjects = $catalog->objects((int)($modifySet[BackupSet::schema_fields_ID] ?? 0), [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'public.legacy_orders',
        'column_name' => 'status',
    ]);
    $check($modifyObjects !== [], 'modify_column 应写入 ColumnBackup 对象');
    $modifyData = (string)($modifyObjects[0][BackupObject::schema_fields_DATA_JSON] ?? '');
    $check(
        \str_contains($modifyData, 'paid') && \str_contains($modifyData, 'new'),
        'modify_column 备份数据应包含 status 原值'
    );

    // ---- 3. drop_column 先备份 ----
    $service->dropColumn('public', 'legacy_orders', 'customer');
    $dropSets = $sets();
    $check(\count($dropSets) === $before + 2, 'drop_column 应再创建一个数据备份集');
    $dropSet = $dropSets[\array_key_last($dropSets)] ?? [];
    $check(
        ($dropSet[BackupSet::schema_fields_SCOPE_KEY] ?? '') === 'admin:public',
        'drop_column scope_key 应为 admin:public'
    );
    $dropObjects = $catalog->objects((int)($dropSet[BackupSet::schema_fields_ID] ?? 0), [
        'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
        'table_name' => 'public.legacy_orders',
        'column_name' => 'customer',
    ]);
    $check($dropObjects !== [], 'drop_column 应写入 ColumnBackup 对象');
    $dropData = (string)($dropObjects[0][BackupObject::schema_fields_DATA_JSON] ?? '');
    $check(
        \str_contains($dropData, 'alice') && \str_contains($dropData, 'bob') && \str_contains($dropData, 'carol'),
        'drop_column 备份数据应包含 customer 原值'
    );
    $check(!\in_array('customer', $columnNames('legacy_orders'), true), 'drop_column 应删除列');
    $check(\in_array('status', $columnNames('legacy_orders'), true), '其他列必须保留');

    // ---- 4. 视图变更不写旧 backups ----
    $connector->query('CREATE VIEW legacy_view AS SELECT id, status FROM legacy_orders')->fetch();
    $beforeViews = \count($sets());
    $service->createOrReplaceView('public', 'legacy_view', 'SELECT id, status FROM legacy_orders');
    $service->dropView('public', 'legacy_view');
    $check(\count($sets()) === $beforeViews, 'view 变更不应创建数据备份集');
    $check(
        !$connector->tableExist('weline_database_backups'),
        '临时库不应出现旧 weline_database_backups 表'
    );

    // ---- 5. 备份失败时 DDL 必须不执行 ----
    $connector->query('CREATE TABLE no_pk_table (id integer, legacy varchar(32))')->fetch();
    $connector->query("INSERT INTO no_pk_table (id, legacy) VALUES (1, 'keep')")->fetch();
    $beforeFail = \count($sets());
    $thrown = false;
    try {
        $service->dropColumn('public', 'no_pk_table', 'legacy');
    } catch (\RuntimeException $exception) {
        $thrown = true;
    }
    $check($thrown, '无主键表备份应失败并抛 RuntimeException');
    $check(\count($sets()) === $beforeFail, '备份失败不应登记备份集');
    $check(\in_array('legacy', $columnNames('no_pk_table'), true), '备份失败后不得执行 DROP COLUMN');

    echo 'OK SchemaAdminService uses ColumnBackupService/BackupCatalog' . PHP_EOL;
    echo 'OK drop/modify column backed up; add/index/view skipped' . PHP_EOL;
    echo 'OK backup failure blocks destructive DDL' . PHP_EOL;
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
            $statement = $adminPdo->prepare(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()'
            );
            $statement->execute([$dbName]);
            $adminPdo->exec('DROP DATABASE IF EXISTS "' . $dbName . '"');
        } catch (Throwable $exception) {
            $failures[] = 'cleanup: DROP DATABASE failed: ' . $exception->getMessage();
        }
    }
    $adminPdo = null;
}

$finish();
