<?php
/**
 * 数据库迁移回滚命令
 * 
 * @author WelineFramework
 * @package Weline\Database\Console\Db\Migrate
 */

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Rollback implements CommandInterface
{
    private MigrationService $migrationService;
    private Printing $printing;
    
    public function __construct(
        MigrationService $migrationService,
        Printing $printing
    ) {
        $this->migrationService = $migrationService;
        $this->printing = $printing;
    }
    
    /**
     * 执行命令
     * 
     * @param array $args 命令参数
     * @param array $data 数据
     */
    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? '';
        $version = $args['version'] ?? '';
        $migrationFile = $args['file'] ?? '';
        
        if (empty($moduleName)) {
            $this->printing->error(__('请指定模块名称: --module=ModuleName'));
            return;
        }
        
        if (empty($version)) {
            $this->printing->error(__('请指定版本号: --version=1.0.0'));
            return;
        }
        
        $this->printing->note(__("开始回滚迁移: %{1} -> 版本 %{2}", [$moduleName, $version]) . 
            (!empty($migrationFile) ? __(" -> 文件 %{1}", $migrationFile) : ""));
        
        $result = $this->migrationService->rollbackMigrationsByVersion($moduleName, $version, $migrationFile);
        
        if ($result) {
            $this->printing->success(__("迁移回滚完成"));
        } else {
            $this->printing->error(__("迁移回滚失败"));
        }
    }
    
    /**
     * 获取命令名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'Weline:Database:Migrate:Rollback';
    }
    
    /**
     * 获取命令描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return __('回滚数据库迁移');
    }
    
    /**
     * 获取帮助信息
     * 
     * @return string
     */
    public function getHelp(): string
    {
        return __("数据库迁移回滚命令

用法:
  php bin/w db:migrate:rollback --module=ModuleName --version=1.0.0 [--file=MigrationFile.php]

参数:
  --module    模块名称 (必需)
  --version   版本号 (必需)
  --file      迁移文件路径 (可选，指定时只回滚该文件)

示例:
  php bin/w db:migrate:rollback --module=Weline_Ai --version=1.0.0
  php bin/w db:migrate:rollback --module=Weline_Ai --version=1.0.0 --file=create_table__users_20250101-v1.0.0.php");
    }
    
    /**
     * 获取命令提示
     * 
     * @return string
     */
    public function tip(): string
    {
        return __('数据库迁移回滚命令');
    }
}
