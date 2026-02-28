<?php

declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Rollback implements CommandInterface
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
        $toVersion     = $args['to-version'] ?? '';
        $steps         = isset($args['steps']) ? (int)$args['steps'] : 0;
        $dryRun        = isset($args['dry-run']) || isset($args['d']);

        if (empty($moduleName)) {
            $this->printing->error(__('请指定模块名称: --module=ModuleName'));
            return;
        }

        // 模式1：回滚最近 N 个迁移
        if ($steps > 0) {
            $this->printing->note(__("开始回滚最近 %{1} 个迁移: %{2}", [$steps, $moduleName]) . 
                ($dryRun ? ' ' . __('(预演模式)') : ''));
            
            $result = $migrationService->rollbackSteps($moduleName, $steps, $dryRun);
            
            if ($result['success']) {
                $this->printing->success(__("回滚完成，共 %{1} 个迁移", count($result['rolled_back_migrations'])));
            } else {
                foreach ($result['errors'] as $error) {
                    $this->printing->error($error);
                }
            }
            return;
        }

        // 模式2：跨版本回滚
        if (!empty($toVersion)) {
            $this->printing->note(__("开始跨版本回滚: %{1} -> %{2}", [$moduleName, $toVersion]) . 
                ($dryRun ? ' ' . __('(预演模式)') : ''));
            
            $result = $migrationService->rollbackToVersion($moduleName, $toVersion, $dryRun);
            
            if ($result['success']) {
                $this->printing->success(__("跨版本回滚完成: %{1} -> %{2}", [$result['current_version'], $toVersion]));
            } else {
                foreach ($result['errors'] as $error) {
                    $this->printing->error($error);
                }
            }
            return;
        }

        // 模式3：传统模式 - 按版本回滚
        if (empty($version)) {
            $this->printing->error(__('请指定版本号: --version=1.0.0，或使用 --to-version/--steps 参数'));
            return;
        }

        $this->printing->note(__("开始回滚迁移: %{1} -> 版本 %{2}", [$moduleName, $version]) .
            (!empty($migrationFile) ? __(" -> 文件 %{1}", $migrationFile) : ""));

        $result = $migrationService->rollbackMigrationsByVersion($moduleName, $version, $migrationFile);

        if ($result) {
            $this->printing->success(__("迁移回滚完成"));
        } else {
            $this->printing->error(__("迁移回滚失败"));
        }
    }

    public function tip(): string
    {
        return __('数据库迁移回滚命令');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--module'     => __('模块名称 (必需)'),
                '--version'    => __('回滚指定版本的迁移'),
                '--file'       => __('指定迁移文件名 (可选)'),
                '--to-version' => __('跨版本回滚：从当前版本回滚到目标版本'),
                '--steps'      => __('回滚最近 N 个迁移'),
                '--dry-run, -d'=> __('预演模式，只显示将执行的操作'),
                '-h, --help'   => __('显示帮助信息'),
            ],
            [
                'php bin/w db:migrate:rollback --module=Weline_Ai --version=1.0.0',
                'php bin/w db:migrate:rollback --module=Weline_Ai --version=1.0.0 --file=create_table__users_20250101-v1.0.0.php',
                'php bin/w db:migrate:rollback --module=Weline_Ai --to-version=1.0.0',
                'php bin/w db:migrate:rollback --module=Weline_Ai --steps=3',
                'php bin/w db:migrate:rollback --module=Weline_Ai --to-version=1.0.0 --dry-run',
            ],
            []
        );
    }
}
