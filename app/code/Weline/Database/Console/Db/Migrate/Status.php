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
        $version = $args['version'] ?? '';
        
        if (empty($moduleName)) {
            $this->printing->error(__('请指定模块名称: --module=ModuleName'));
            return;
        }
        
        if (!empty($version)) {
            // 查询特定版本的迁移状态
            $this->printing->note(__("查询模块迁移状态: %{1} -> 版本 %{2}", [$moduleName, $version]));
            $this->printing->printing('');
            
            $versionMigrations = $this->migrationService->getMigrationsByVersion($moduleName, $version);
            
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
            // 查询所有迁移状态
            $this->printing->note(__("查询模块迁移状态: %{1}", $moduleName));
            $this->printing->printing('');
            
            // 获取迁移统计
            $stats = $this->migrationModel->getMigrationStats($moduleName);
            
            $this->printing->printing(__("=== 迁移统计 ==="));
            $this->printing->printing(__("总迁移数: %{1}", $stats['total']));
            $this->printing->printing(__("已安装: %{1}", $stats['installed']));
            $this->printing->printing(__("待执行: %{1}", $stats['pending']));
            $this->printing->printing(__("失败: %{1}", $stats['failed']));
            $this->printing->printing('');
            
            // 获取所有迁移
            $allMigrations = $this->migrationService->getModuleMigrations($moduleName);
            $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
            $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
            
            // 显示已安装的迁移
            if (!empty($installedMigrations)) {
                $this->printing->printing(__("=== 已安装的迁移 ==="));
                foreach ($installedMigrations as $migration) {
                    $status = $this->getStatusText($migration->getData(Migration::fields_STATUS));
                    $this->printing->printing(__("✓ %{1} - %{2}", [$migration->getData(Migration::fields_FILE), $status]));
                }
                $this->printing->printing('');
            }
            
            // 显示待执行的迁移
            if (!empty($pendingMigrations)) {
                $this->printing->printing(__("=== 待执行的迁移 ==="));
                foreach ($pendingMigrations as $migration) {
                    $this->printing->printing(__("○ %{1} - 待执行", $migration['filename']));
                }
                $this->printing->printing('');
            }
            
            // 显示失败状态
            if ($stats['failed'] > 0) {
                $this->printing->error(__("发现 %{1} 个失败的迁移，请检查日志", $stats['failed']));
            }
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
        return __('查询数据库迁移状态');
    }
    
    /**
     * 获取帮助信息
     * 
     * @return string
     */
    public function getHelp(): string
    {
        return __("数据库迁移状态查询命令

用法:
  php bin/w db:migrate:status --module=ModuleName [--version=1.0.0]

参数:
  --module    模块名称 (必需)
  --version   版本号 (可选，指定时只查询该版本的迁移)

示例:
  php bin/w db:migrate:status --module=Weline_Ai
  php bin/w db:migrate:status --module=Weline_Ai --version=1.0.0");
    }
    
    /**
     * 获取命令提示
     * 
     * @return string
     */
    public function tip(): string
    {
        return __('数据库迁移状态查询命令');
    }
}
