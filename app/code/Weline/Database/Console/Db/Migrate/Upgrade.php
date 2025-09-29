<?php
/**
 * 数据库迁移升级命令
 * 
 * @author WelineFramework
 * @package Weline\Database\Console\Db\Migrate
 */

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Upgrade implements CommandInterface
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
        
        $this->printing->info("开始升级迁移: {$moduleName} -> {$migrationFile}");
        
        $result = $this->migrationService->upgradeMigration($moduleName, $migrationFile);
        
        if ($result) {
            $this->printing->success("迁移升级完成");
        } else {
            $this->printing->error("迁移升级失败");
        }
    }
    
    /**
     * 获取命令名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'Weline:Database:Migrate:Upgrade';
    }
    
    /**
     * 获取命令描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return '升级数据库迁移';
    }
    
    /**
     * 获取帮助信息
     * 
     * @return string
     */
    public function getHelp(): string
    {
        return <<<HELP
数据库迁移升级命令

用法:
  php bin/w db:migrate:upgrade --module=ModuleName --file=MigrationFile.php

参数:
  --module    模块名称 (必需)
  --file      迁移文件路径 (必需)

示例:
  php bin/w db:migrate:upgrade --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php

HELP;
    }
}
