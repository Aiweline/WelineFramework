<?php

declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Upgrade implements CommandInterface
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

        $moduleName    = $args['module'] ?? '';
        $version       = $args['version'] ?? '';
        $migrationFile = $args['file'] ?? '';

        if (empty($moduleName)) {
            $this->printing->error(__('请指定模块名称: --module=ModuleName'));
            return;
        }

        if (empty($version)) {
            $this->printing->error(__('请指定版本号: --version=1.0.0'));
            return;
        }

        $this->printing->note(__("开始升级迁移: %{1} -> 版本 %{2}", [$moduleName, $version]) .
            (!empty($migrationFile) ? __(" -> 文件 %{1}", $migrationFile) : ""));

        $result = $migrationService->upgradeMigrationsByVersion($moduleName, $version, $migrationFile);

        if ($result) {
            $this->printing->success(__("迁移升级完成"));
        } else {
            $this->printing->error(__("迁移升级失败"));
        }
    }

    public function tip(): string
    {
        return __('数据库迁移升级命令');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--module'   => __('模块名称 (必需)'),
                '--version'  => __('版本号 (必需)'),
                '--file'     => __('迁移文件名 (可选)'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'php bin/w db:migrate:upgrade --module=Weline_Ai --version=1.0.0',
                'php bin/w db:migrate:upgrade --module=Weline_Ai --version=1.0.0 --file=create_table__users_20250101-v1.0.0.php',
            ],
            []
        );
    }
}
