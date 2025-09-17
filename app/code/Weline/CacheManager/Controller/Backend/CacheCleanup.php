<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\CacheManager\Cron\CacheCleanup;

/**
 * 缓存清理管理控制器
 */
class CacheCleanupController extends BackendController
{
    /**
     * 缓存清理管理页面
     */
    public function index()
    {
        $this->assign('title', __('缓存清理管理'));
        return $this->fetch();
    }

    /**
     * 手动执行缓存清理
     */
    public function executeCleanup()
    {
        try {
            // 直接调用框架的缓存刷新命令
            $command = 'php bin/w cache:flush';
            $output = shell_exec($command);
            
            if ($output) {
                $this->getMessageManager()->addSuccess(__('缓存清理完成！'));
                $this->getMessageManager()->addNotice(trim($output));
            } else {
                $this->getMessageManager()->addSuccess(__('缓存清理完成！'));
            }
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('缓存清理失败: %{1}', $e->getMessage()));
        }
        
        $this->redirect('*/cachecleanup/index');
    }

    /**
     * 获取缓存统计信息（AJAX）
     */
    public function getStats()
    {
        try {
            $stats = $this->getCacheStats();
            
            $this->getResponse()->setHeader('Content-Type', 'application/json');
            return $this->getResponse()->setBody(json_encode([
                'success' => true,
                'data' => [
                    'total_files' => $stats['total_files'],
                    'total_size' => $this->formatBytes($stats['total_size']),
                    'expired_files' => $stats['expired_files'],
                    'expired_size' => $this->formatBytes($stats['expired_size']),
                    'expired_percentage' => $stats['total_files'] > 0 ? 
                        round(($stats['expired_files'] / $stats['total_files']) * 100, 2) : 0
                ]
            ]));
            
        } catch (\Exception $e) {
            $this->getResponse()->setHeader('Content-Type', 'application/json');
            return $this->getResponse()->setBody(json_encode([
                'success' => false,
                'message' => __('获取缓存统计信息失败: %{1}', $e->getMessage())
            ]));
        }
    }

    /**
     * 获取缓存统计信息
     */
    private function getCacheStats(): array
    {
        $cacheDir = BP . 'var' . DS . 'cache';
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'expired_files' => 0,
            'expired_size' => 0
        ];

        if (!is_dir($cacheDir)) {
            return $stats;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $stats['total_files']++;
                $stats['total_size'] += $file->getSize();
                
                // 检查是否过期（7天前）
                if ($file->getMTime() < (time() - 7 * 24 * 3600)) {
                    $stats['expired_files']++;
                    $stats['expired_size'] += $file->getSize();
                }
            }
        }

        return $stats;
    }

    /**
     * 格式化字节大小
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 查看定时任务状态
     */
    public function cronStatus()
    {
        try {
            $command = 'php bin/w cron:task:listing';
            $output = shell_exec($command);
            
            $this->assign('cronOutput', $output);
            $this->assign('title', __('定时任务状态'));
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('获取定时任务状态失败: %{1}', $e->getMessage()));
            $this->redirect('*/cachecleanup/index');
        }
    }

    /**
     * 手动执行定时任务
     */
    public function runCronTask()
    {
        try {
            $command = 'php bin/w cron:task:run cache_cleanup';
            $output = shell_exec($command);
            
            if ($output) {
                $this->getMessageManager()->addSuccess(__('定时任务执行完成！'));
                $this->getMessageManager()->addNotice(trim($output));
            } else {
                $this->getMessageManager()->addSuccess(__('定时任务执行完成！'));
            }
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('定时任务执行失败: %{1}', $e->getMessage()));
        }
        
        $this->redirect('*/cachecleanup/index');
    }

}
