<?php

declare(strict_types=1);

/**
 * T47s 验收：删除旧 Migration/Bootstrap/Executor 栈；SQLite 新装不再创建旧账本表。
 */

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
define('DS', \DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/bootstrap.php';

use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaDiffExecutor;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Setup\Stage\FrameworkDbBootstrapStage;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

// 1. 删除清单（A 版：14 个文件）
$deleted = [
    'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php',
    'app/code/Weline/Framework/Database/Service/BackupService.php',
    'app/code/Weline/Database/Model/Migration.php',
    'app/code/Weline/Database/Model/MigrationBackup.php',
    'app/code/Weline/Database/Model/ModuleVersionOperation.php',
    'app/code/Weline/Framework/Setup/Model/Migration.php',
    'app/code/Weline/Framework/Setup/Model/MigrationBackup.php',
    'app/code/Weline/Database/Interface/MigrationInterface.php',
    'app/code/Weline/Database/Interface/RollbackBackupStrategyInterface.php',
    'app/code/Weline/Database/AbstractMigration.php',
    'app/code/Weline/Framework/Database/Migration/AbstractMigration.php',
    'app/code/Weline/Framework/Database/Migration/MigrationInterface.php',
    'app/code/Weline/Framework/Database/Migration/MigrationMetadataInterface.php',
    'app/code/Weline/Framework/Database/Migration/RollbackBackupStrategyInterface.php',
];
foreach ($deleted as $relative) {
    $check(!\file_exists(BP . $relative), '应删除但文件仍存在：' . $relative);
}

$kept = [
    'app/code/Weline/Framework/Database/Migration/MigrationManifest.php',
    'app/code/Weline/Framework/Database/Migration/Model/MigrationCheckpoint.php',
    'app/code/Weline/Framework/Database/Migration/Service/MigrationCloneService.php',
    'app/code/Weline/Database/Service/Release/SchemaDiffExecutor.php',
    'app/code/Weline/Framework/Setup/Model/ModuleTable.php',
    'app/code/Weline/Framework/Setup/Model/ModuleBackup.php',
];
foreach ($kept as $relative) {
    $check(\file_exists(BP . $relative), '应保留但文件缺失：' . $relative);
}

// 2. 改造点
$bootstrap = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Setup/Stage/FrameworkDbBootstrapStage.php');
$check(!\str_contains($bootstrap, 'Setup\\Model\\Migration'), 'bootstrap 仍引用旧 Migration');
$check(!\str_contains($bootstrap, 'ensureMigrationsSchemaDdlColumns'), 'bootstrap 仍有 Migration 列迁移逻辑');
$check(!\str_contains($bootstrap, 'ensureBackupsTableStructure'), 'bootstrap 仍有 MigrationBackup 重建逻辑');
$check(\str_contains($bootstrap, 'SchemaDiffExecutor'), 'bootstrap 未使用 SchemaDiffExecutor');

$eav = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Setup/Stage/EavSchemaStage.php');
$check(!\str_contains($eav, 'Setup\\Model\\Migration'), 'EavSchemaStage 仍引用旧 Migration');
$check(\str_contains($eav, 'ConnectionFactory'), 'EavSchemaStage 未改用 ConnectionFactory');

$upgrade = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(!\str_contains($upgrade, 'migrationModel'), 'Upgrade 仍传 migrationModel');
$check(!\str_contains($upgrade, 'releaseSucceededSetupSchemaDiffBindings'), 'Upgrade 仍调用旧绑定释放');

$benchmark = (string)\file_get_contents(BP . 'app/code/Weline/Benchmark/Controller/Db.php');
$check(!\str_contains($benchmark, 'Setup\\Model\\Migration'), 'Benchmark 仍引用旧 Migration');
$check(\str_contains($benchmark, 'DatabaseAdminAuditLog'), 'Benchmark 未改用 DatabaseAdminAuditLog');

// 3. app/code 全量扫描：旧 FQCN/旧 executor/旧 BackupService 运行引用 = 0（接口名允许）
$patterns = [
    '/Setup\\\\Model\\\\Migration/' => '旧 Framework Migration',
    '/Database\\\\Model\\\\Migration/' => '旧 Database Migration',
    '/Database\\\\AbstractMigration/' => '旧 Database AbstractMigration',
    '/Database\\\\Interface\\\\MigrationInterface/' => '旧 Database MigrationInterface',
    '/SchemaMigrationExecutor(?!Interface)/' => '旧 SchemaMigrationExecutor',
    '/\bBackupService\b/' => '旧 BackupService',
    '/ModuleVersionOperation/' => '旧 ModuleVersionOperation',
    '/MigrationMetadataInterface/' => '旧 MigrationMetadataInterface',
    '/RollbackBackupStrategyInterface/' => '旧 RollbackBackupStrategyInterface',
    '/Migration\\\\AbstractMigration/' => '旧 Migration AbstractMigration',
    '/Migration\\\\MigrationInterface/' => '旧 Migration MigrationInterface',
];
$appCode = BP . 'app/code/Weline';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appCode, FilesystemIterator::SKIP_DOTS),
);
$scanned = 0;
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = @\file_get_contents($file->getPathname());
    if (!\is_string($contents)) {
        continue;
    }
    ++$scanned;
    foreach ($patterns as $pattern => $label) {
        if (\preg_match($pattern, $contents) === 1) {
            $failures[] = '仍引用' . $label . '：' . $file->getPathname();
        }
    }
}
$check($scanned > 0, 'app/code 扫描结果为空');

// 4. SQLite 新装 bootstrap：11 RSSE + bootstrap 表存在，旧账本表不存在
$tmp = \sys_get_temp_dir() . DS . 'weline_t47s_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.sqlite';
$factory = null;
try {
    $factory = ConnectionFactory::getInstance(new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]));
    $connector = $factory->getConnector();
    $stage = new FrameworkDbBootstrapStage(
        $factory,
        new SchemaParser(),
        new SchemaDiffExecutor(new SchemaDdlExecutor($factory)),
    );
    $stage->prepare([]);
    $stage->commit();

    $rows = $connector->getLink()
        ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $tables = \array_map(static fn($name): string => \strtolower((string)$name), $rows);

    $expectedRssE = [];
    foreach (ReleaseTableInstaller::MODELS as $modelClass) {
        $expectedRssE[] = \strtolower((string)$modelClass::schema_table);
    }
    $missingRssE = [];
    foreach ($expectedRssE as $expected) {
        $found = false;
        foreach ($tables as $table) {
            if ($table === $expected || \str_ends_with($table, '_' . $expected)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missingRssE[] = $expected;
        }
    }
    $check($missingRssE === [], 'RSSE bootstrap 表缺失：' . \implode(',', $missingRssE));

    foreach (['weline_cache_namespace_version', 'weline_module_table', 'weline_module_backup'] as $expected) {
        $found = false;
        foreach ($tables as $table) {
            if ($table === $expected || \str_ends_with($table, '_' . $expected)) {
                $found = true;
                break;
            }
        }
        $check($found, 'bootstrap 表缺失：' . $expected);
    }

    foreach ($tables as $table) {
        $check(
            !\str_ends_with($table, 'weline_database_migrations')
                && !\str_ends_with($table, 'weline_database_backups'),
            '新装仍创建旧账本表：' . $table
        );
    }
} finally {
    if ($factory instanceof ConnectionFactory) {
        try {
            $factory->close();
        } catch (Throwable) {
            // ignore close failure during cleanup
        }
    }
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $path) {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }
}

if ($failures !== []) {
    \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T47S LEGACY-MIGRATION-STACK FAIL' . PHP_EOL;
    exit(1);
}

echo 'OK 14 legacy migration-domain files removed' . PHP_EOL;
echo 'OK bootstrap no longer creates Migration/MigrationBackup ledger tables' . PHP_EOL;
echo 'OK EavSchemaStage uses ConnectionFactory; Benchmark uses audit log' . PHP_EOL;
echo 'OK no legacy migration references in app/code (' . $scanned . ' php files scanned)' . PHP_EOL;
echo 'OK sqlite fresh bootstrap creates RSSE + bootstrap tables and no legacy ledger' . PHP_EOL;
echo 'T47S LEGACY-MIGRATION-STACK PASS' . PHP_EOL;
exit(0);
