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
        $migrationFile = $args['file'] ?? '';
        
        if (empty($moduleName)) {
            $this->printing->error('请指定模块名称: --module=ModuleName');
            return;
        }
        
        if (empty($migrationFile)) {
            $this->printing->error('请指定迁移文件: --file=MigrationFile.php');
            return;
        }
        
        $this->printing->info("开始回滚迁移: {$moduleName} -> {$migrationFile}");
        
        $result = $this->migrationService->rollbackMigration($moduleName, $migrationFile);
        
        if ($result) {
            $this->printing->success("迁移回滚完成");
        } else {
            $this->printing->error("迁移回滚失败");
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
        return '回滚数据库迁移';
    }
    
    /**
     * 获取帮助信息
     * 
     * @return string
     */
    public function getHelp(): string
    {
        return <<<HELP
数据库迁移回滚命令

用法:
  php bin/w db:migrate:rollback --module=ModuleName --file=MigrationFile.php

参数:
  --module    模块名称 (必需)
  --file      迁移文件路径 (必需)

示例:
  php bin/w db:migrate:rollback --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php

HELP;
    }
}
