<?php

declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Model\Migration;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Status implements CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = []): void
    {
        /** @var MigrationService $migrationService */
        $migrationService = ObjectManager::getInstance(MigrationService::class);
        /** @var Migration $migrationModel */
        $migrationModel = ObjectManager::getInstance(Migration::class);

        $moduleName = $args['module'] ?? '';
        $version    = $args['version'] ?? '';

        if (empty($moduleName)) {
            $this->printing->error(__('请指定模块名称: --module=ModuleName'));
            return;
        }

        if (!empty($version)) {
            $this->printing->note(__("查询模块迁移状态: %{1} -> 版本 %{2}", [$moduleName, $version]));
            $this->printing->printing('');

            $versionMigrations = $migrationService->getMigrationsByVersion($moduleName, $version);

            if (empty($versionMigrations)) {
                $this->printing->warning(__("未找到版本 %{1} 的迁移文件", $version));
                return;
            }

            $this->printing->printing(__("=== 版本 %{1} 的迁移文件 ===", $version));
            foreach ($versionMigrations as $migration) {
                $this->printing->printing(__("○ %{1} - 待检查状态", $migration['filename']));
            }
            $this->printing->printing('');
        } else {
            $this->printing->note(__("查询模块迁移状态: %{1}", $moduleName));
            $this->printing->printing('');

            $stats = $migrationModel->getMigrationStats($moduleName);

            $this->printing->printing(__("=== 迁移统计 ==="));
            $this->printing->printing(__("总迁移数: %{1}", $stats['total']));
            $this->printing->printing(__("已安装: %{1}", $stats['installed']));
            $this->printing->printing(__("待执行: %{1}", $stats['pending']));
            $this->printing->printing(__("失败: %{1}", $stats['failed']));
            $this->printing->printing('');

            $installedMigrations = $migrationModel->getInstalledMigrations($moduleName);
            $pendingMigrations   = $migrationService->getPendingMigrations($moduleName);

            if (!empty($installedMigrations)) {
                $this->printing->printing(__("=== 已安装的迁移 ==="));
                foreach ($installedMigrations as $migration) {
                    $status = $this->getStatusText($migration->getData(Migration::schema_fields_STATUS));
                    $this->printing->printing(__("✓ %{1} - %{2}", [$migration->getData(Migration::schema_fields_FILE), $status]));
                }
                $this->printing->printing('');
            }

            if (!empty($pendingMigrations)) {
                $this->printing->printing(__("=== 待执行的迁移 ==="));
                foreach ($pendingMigrations as $migration) {
                    $this->printing->printing(__("○ %{1} - 待执行", $migration['filename']));
                }
                $this->printing->printing('');
            }

            if ($stats['failed'] > 0) {
                $this->printing->error(__("发现 %{1} 个失败的迁移，请检查日志", $stats['failed']));
            }
        }
    }

    private function getStatusText(string $status): string
    {
        return match ($status) {
            Migration::STATUS_INSTALLED   => __('已安装'),
            Migration::STATUS_ROLLED_BACK => __('已回滚'),
            Migration::STATUS_FAILED      => __('失败'),
            Migration::STATUS_PENDING     => __('待执行'),
            default                       => $status,
        };
    }

    public function tip(): string
    {
        return __('数据库迁移状态查询命令');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--module'   => __('模块名称 (必需)'),
                '--version'  => __('版本号 (可选)'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'php bin/w db:migrate:status --module=Weline_Ai',
                'php bin/w db:migrate:status --module=Weline_Ai --version=1.0.0',
            ],
            []
        );
    }
}
