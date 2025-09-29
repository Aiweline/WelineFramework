<?php
/**
 * 数据库迁移状态查询命令
 * 
 * @author WelineFramework
 * @package Weline\Database\Console\Db\Migrate
 */

namespace Weline\Database\Console\Db\Migrate;

use Weline\Database\Service\MigrationService;
use Weline\Database\Model\Migration;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Status implements CommandInterface
{
    private MigrationService $migrationService;
    private Migration $migrationModel;
    private Printing $printing;
    
    public function __construct(
        MigrationService $migrationService,
        Migration $migrationModel,
        Printing $printing
    ) {
        $this->migrationService = $migrationService;
        $this->migrationModel = $migrationModel;
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
        
        if (empty($moduleName)) {
            $this->printing->error('请指定模块名称: --module=ModuleName');
            return;
        }
        
        $this->printing->info("查询模块迁移状态: {$moduleName}");
        $this->printing->println('');
        
        // 获取迁移统计
        $stats = $this->migrationModel->getMigrationStats($moduleName);
        
        $this->printing->println("=== 迁移统计 ===");
        $this->printing->println("总迁移数: {$stats['total']}");
        $this->printing->println("已安装: {$stats['installed']}");
        $this->printing->println("待执行: {$stats['pending']}");
        $this->printing->println("失败: {$stats['failed']}");
        $this->printing->println('');
        
        // 获取所有迁移
        $allMigrations = $this->migrationService->getModuleMigrations($moduleName);
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
        
        // 显示已安装的迁移
        if (!empty($installedMigrations)) {
            $this->printing->println("=== 已安装的迁移 ===");
            foreach ($installedMigrations as $migration) {
                $status = $this->getStatusText($migration->getData(Migration::fields_STATUS));
                $this->printing->println("✓ {$migration->getData(Migration::fields_FILE)} - {$status}");
            }
            $this->printing->println('');
        }
        
        // 显示待执行的迁移
        if (!empty($pendingMigrations)) {
            $this->printing->println("=== 待执行的迁移 ===");
            foreach ($pendingMigrations as $migration) {
                $this->printing->println("○ {$migration['filename']} - 待执行");
            }
            $this->printing->println('');
        }
        
        // 显示失败状态
        if ($stats['failed'] > 0) {
            $this->printing->error("发现 {$stats['failed']} 个失败的迁移，请检查日志");
        }
    }
    
    /**
     * 获取状态文本
     * 
     * @param string $status 状态
     * @return string
     */
    private function getStatusText(string $status): string
    {
        $statusMap = [
            Migration::STATUS_INSTALLED => '已安装',
            Migration::STATUS_ROLLED_BACK => '已回滚',
            Migration::STATUS_FAILED => '失败',
            Migration::STATUS_PENDING => '待执行'
        ];
        
        return $statusMap[$status] ?? $status;
    }
    
    /**
     * 获取命令名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'Weline:Database:Migrate:Status';
    }
    
    /**
     * 获取命令描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return '查询数据库迁移状态';
    }
    
    /**
     * 获取帮助信息
     * 
     * @return string
     */
    public function getHelp(): string
    {
        return <<<HELP
数据库迁移状态查询命令

用法:
  php bin/w db:migrate:status --module=ModuleName

参数:
  --module    模块名称 (必需)

示例:
  php bin/w db:migrate:status --module=Weline_Ai

HELP;
    }
}
