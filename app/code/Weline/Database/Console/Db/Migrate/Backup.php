<?php

declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Framework\Database\Migration\MigrationInterface;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Backup implements CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? '';
        $tables     = !empty($args['tables']) ? explode(',', $args['tables']) : [];
        $file       = $args['file'] ?? '';

        if (empty($moduleName) && empty($tables)) {
            $this->printing->error(__('请指定 --module=ModuleName 或 --tables=table1,table2'));
            return;
        }

        if (!empty($tables)) {
            $this->backupTablesDirect($tables, $moduleName);
            return;
        }

        if (!empty($file)) {
            $this->backupSingleFile($moduleName, $file);
            return;
        }

        $this->backupPendingMigrations($moduleName);
    }

    private function getMigrationService(): MigrationService
    {
        return ObjectManager::getInstance(MigrationService::class);
    }

    private function getBackupService(): BackupService
    {
        return ObjectManager::getInstance(BackupService::class);
    }

    private function getMigrationModel(): Migration
    {
        return ObjectManager::getInstance(Migration::class);
    }

    private function backupTablesDirect(array $tables, string $moduleName): void
    {
        $migrationModel = $this->getMigrationModel();
        $backupService  = $this->getBackupService();

        $migrationId = $migrationModel->recordMigration([
            'module_name'    => $moduleName ?: '_manual_',
            'version'        => '0.0.0',
            'migration_file' => '_manual_backup_' . date('YmdHis'),
            'description'    => __('手动表备份: %{1}', implode(', ', $tables)),
            'status'         => Migration::STATUS_MANUAL,
            'dependencies'   => [],
            'executed_at'    => date('Y-m-d H:i:s'),
        ]);

        if ($migrationId <= 0) {
            $this->printing->error(__('创建备份记录失败'));
            return;
        }

        $backupIds = [];
        foreach ($tables as $table) {
            $table = trim($table);
            if (empty($table)) {
                continue;
            }
            try {
                $backupService->backupTableData($table, $migrationId);
                $backupIds[] = $table;
            } catch (\Exception $e) {
                $this->printing->error(__("备份表 %{1} 失败: %{2}", [$table, $e->getMessage()]));
            }
        }

        $this->printing->success(__("手动备份完成 (migration_id: %{1})，已备份表: %{2}",
            [$migrationId, implode(', ', $backupIds)]));
    }

    private function backupSingleFile(string $moduleName, string $file): void
    {
        $migrationService = $this->getMigrationService();
        $migrationModel   = $this->getMigrationModel();
        $backupService    = $this->getBackupService();

        $migrations = $migrationService->getMigrationsByVersion(
            $moduleName,
            $this->extractVersionFromFile($file),
            $file
        );

        if (empty($migrations)) {
            $allMigrations = $migrationService->getModuleMigrations($moduleName);
            $migrations = array_filter($allMigrations, fn(array $m) => $m['filename'] === $file);
            $migrations = array_values($migrations);
        }

        if (empty($migrations)) {
            $this->printing->error(__("未找到迁移文件: %{1}", $file));
            return;
        }

        $migrationFile = $migrations[0]['file'];
        if (!file_exists($migrationFile)) {
            $this->printing->error(__("迁移文件不存在: %{1}", $migrationFile));
            return;
        }

        require_once $migrationFile;
        $className = $migrations[0]['class'] ?? '';
        if (!class_exists($className)) {
            $this->printing->error(__("迁移类不存在: %{1}", $className));
            return;
        }

        $instance = new $className();
        if (!$instance instanceof MigrationInterface) {
            $this->printing->error(__("迁移类未实现 MigrationInterface"));
            return;
        }

        if (!method_exists($instance, 'requiresBackup') || !$instance->requiresBackup()) {
            $this->printing->warning(__("迁移脚本 %{1} 未要求备份 (requiresBackup=false)", $file));
            return;
        }

        $migrationId = $migrationModel->recordMigration([
            'module_name'    => $moduleName,
            'version'        => $instance->getVersion(),
            'migration_file' => '_pre_backup_' . basename($file),
            'description'    => __('预备份: %{1}', $instance->getDescription()),
            'status'         => Migration::STATUS_MANUAL,
            'dependencies'   => [],
            'executed_at'    => date('Y-m-d H:i:s'),
        ]);

        if ($migrationId <= 0) {
            $this->printing->error(__('创建备份记录失败'));
            return;
        }

        $this->performStrategyBackup($backupService, $instance, $migrationId);
        $this->printing->success(__("迁移文件备份完成 (migration_id: %{1})", $migrationId));
    }

    private function backupPendingMigrations(string $moduleName): void
    {
        $migrationService = $this->getMigrationService();
        $migrationModel   = $this->getMigrationModel();
        $backupService    = $this->getBackupService();

        $pending = $migrationService->getPendingMigrations($moduleName);
        if (empty($pending)) {
            $this->printing->info(__("模块 %{1} 没有待执行的迁移", $moduleName));
            return;
        }

        $count = 0;
        foreach ($pending as $migration) {
            $migrationFile = $migration['file'];
            if (!file_exists($migrationFile)) {
                continue;
            }

            require_once $migrationFile;
            $className = $migration['class'] ?? '';
            if (!class_exists($className)) {
                continue;
            }

            $instance = new $className();
            if (!$instance instanceof MigrationInterface) {
                continue;
            }
            if (!method_exists($instance, 'requiresBackup') || !$instance->requiresBackup()) {
                continue;
            }

            $migrationId = $migrationModel->recordMigration([
                'module_name'    => $moduleName,
                'version'        => $instance->getVersion(),
                'migration_file' => '_pre_backup_' . $migration['filename'],
                'description'    => __('预备份: %{1}', $instance->getDescription()),
                'status'         => Migration::STATUS_MANUAL,
                'dependencies'   => [],
                'executed_at'    => date('Y-m-d H:i:s'),
            ]);

            if ($migrationId <= 0) {
                continue;
            }

            $this->performStrategyBackup($backupService, $instance, $migrationId);
            $this->printing->info(__("已备份: %{1} (migration_id: %{2})", [$migration['filename'], $migrationId]));
            $count++;
        }

        if ($count === 0) {
            $this->printing->info(__("模块 %{1} 没有需要备份的迁移", $moduleName));
        } else {
            $this->printing->success(__("共备份 %{1} 个迁移", $count));
        }
    }

    private function performStrategyBackup(BackupService $backupService, MigrationInterface $instance, int $migrationId): void
    {
        $strategy = method_exists($instance, 'getBackupStrategy')
            ? $instance->getBackupStrategy()
            : ['strategy' => 'none', 'tables' => [], 'columns' => []];

        if ($strategy['strategy'] === 'none' || empty($strategy['tables'])) {
            return;
        }

        $tables  = $strategy['tables'] ?? [];
        $columns = $strategy['columns'] ?? [];

        foreach ($tables as $table) {
            if ($strategy['strategy'] === 'column' && !empty($columns)) {
                foreach ($columns as $column) {
                    $backupService->backupColumnData($table, $column, $migrationId);
                }
            } else {
                $backupService->backupTableData($table, $migrationId);
            }
        }
    }

    private function extractVersionFromFile(string $filename): string
    {
        if (preg_match('/-v([\d.]+)\.php$/i', $filename, $m)) {
            return $m[1];
        }
        return '';
    }

    public function tip(): string
    {
        return __('数据库迁移备份命令');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--module'   => __('模块名称'),
                '--file'     => __('迁移文件名（可选）'),
                '--tables'   => __('手动备份的表名列表，逗号分隔（可选）'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'php bin/w db:migrate:backup --module=Weline_Ai',
                'php bin/w db:migrate:backup --module=Weline_Ai --file=drop_column__raw_data_20250101-v1.0.1.php',
                'php bin/w db:migrate:backup --tables=users,orders',
            ],
            []
        );
    }
}
