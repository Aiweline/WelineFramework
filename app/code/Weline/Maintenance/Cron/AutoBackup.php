<?php

declare(strict_types=1);

/*
 * 维护模式自动备份Cron任务
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Cron
 */

namespace Weline\Maintenance\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Service\BackupManager;

class AutoBackup implements CronTaskInterface
{
    /**
     * 任务名称
     */
    public function name(): string
    {
        return __('维护模式自动备份任务');
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'maintenance_auto_backup';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return __('定时执行系统备份任务（数据库和代码），自动清理过期备份');
    }

    /**
     * Cron时间表达式
     * 从配置读取，默认每天凌晨2点
     */
    public function cron_time(): string
    {
        return Env::getInstance()->getConfig('maintenance.backup.cron_time', '0 2 * * *');
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            $env = Env::getInstance();
            
            // 检查是否启用Cron备份
            if (!$env->getConfig('maintenance.backup.cron_enabled', false)) {
                return __('Cron自动备份未启用');
            }

            /** @var BackupManager $backupManager */
            $backupManager = ObjectManager::getInstance(BackupManager::class);
            
            // 获取备份类型配置
            $backupTypes = $env->getConfig('maintenance.backup.cron_backup_types', ['database', 'code']);
            
            if (empty($backupTypes)) {
                return __('未配置备份类型');
            }

            $results = [];
            
            // 执行备份
            foreach ($backupTypes as $type) {
                try {
                    $result = $backupManager->createBackup($type, 0); // 系统自动备份，用户ID为0
                    $results[] = sprintf(__('%{1}备份成功'), ucfirst($type));
                } catch (\Exception $e) {
                    $results[] = sprintf(__('%{1}备份失败：%{2}'), ucfirst($type), $e->getMessage());
                }
            }

            // 清理过期备份
            $retentionDays = (int)$env->getConfig('maintenance.backup.retention_days', 30);
            if ($retentionDays > 0) {
                try {
                    $cleanResult = $backupManager->cleanExpiredBackups($retentionDays);
                    $results[] = sprintf(
                        __('清理过期备份：删除 %{1} 个备份，释放空间 %{2}'),
                        $cleanResult['deleted_count'],
                        $this->formatBytes($cleanResult['freed_space'])
                    );
                } catch (\Exception $e) {
                    $results[] = sprintf(__('清理过期备份失败：%{1}'), $e->getMessage());
                }
            }

            return implode('; ', $results);
            
        } catch (\Exception $e) {
            return sprintf(__('自动备份任务执行失败：%{1}'), $e->getMessage());
        }
    }

    /**
     * 调度任务超时解锁时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        // 备份任务可能需要较长时间，设置为120分钟
        return 120;
    }

    /**
     * 格式化字节大小
     * 
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
