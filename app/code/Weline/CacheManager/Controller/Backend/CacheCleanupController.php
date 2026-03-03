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
use Weline\Cron\Model\CronTask;
use Weline\Framework\Acl\Acl;

/**
 * 缓存清理管理控制器
 */
#[Acl('Weline_CacheManager::cache_cleanup', '缓存清理管理', 'mdi mdi-broom', '缓存清理管理模块')]
class CacheCleanupController extends BackendController
{
    /**
     * 缓存清理管理页面
     */
    #[Acl('Weline_CacheManager::cache_cleanup_index', '缓存清理首页', 'mdi mdi-view-dashboard', '查看缓存清理管理页面')]
    public function index()
    {
        $this->assign('title', __('缓存清理管理'));
        
        // 获取缓存统计信息
        $stats = $this->getCacheStats();
        $this->assign('stats', $stats);
        $this->assign('total_files', $stats['total_files']);
        $this->assign('total_size', $this->formatBytes($stats['total_size']));
        $this->assign('expired_files', $stats['expired_files']);
        $this->assign('expired_size', $this->formatBytes($stats['expired_size']));
        
        // 获取缓存目录详情
        $cacheDetails = $this->getCacheDirectoryDetails();
        $this->assign('cache_details', $cacheDetails);
        
        return $this->fetch();
    }

    /**
     * 手动执行缓存清理（AJAX）
     */
    #[Acl('Weline_CacheManager::cache_cleanup_execute', '执行缓存清理', 'mdi mdi-delete-sweep', '手动执行缓存清理操作')]
    public function postExecuteCleanup()
    {
        try {
            // 使用框架内置的缓存清理方法
            $clearedCount = 0;
            $clearedSize = 0;
            $cacheDir = BP . 'var' . DS . 'cache';
            
            if (is_dir($cacheDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $size = $file->getSize();
                        if (@unlink($file->getPathname())) {
                            $clearedCount++;
                            $clearedSize += $size;
                        }
                    }
                }
            }
            
            // 清理编译的模板缓存
            $tplDirs = glob(BP . 'app' . DS . 'code' . DS . '*' . DS . '*' . DS . 'view' . DS . 'tpl');
            if ($tplDirs) {
                foreach ($tplDirs as $tplDir) {
                    if (is_dir($tplDir)) {
                        $this->deleteDirectory($tplDir, $clearedCount, $clearedSize);
                    }
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('缓存清理完成！共清理 %{count} 个文件，释放 %{size} 空间。', [
                    'count' => $clearedCount,
                    'size' => $this->formatBytes($clearedSize)
                ])
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缓存清理失败: %{1}。请检查文件权限或手动清理 var/cache 目录。', $e->getMessage())
            ]);
        }
    }
    
    /**
     * 递归删除目录内容
     */
    private function deleteDirectory(string $dir, int &$count, int &$size): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileSize = $file->getSize();
                if (@unlink($file->getPathname())) {
                    $count++;
                    $size += $fileSize;
                }
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }
    }

    /**
     * 获取缓存统计信息（AJAX）
     */
    #[Acl('Weline_CacheManager::cache_cleanup_stats', '获取缓存统计', 'mdi mdi-chart-bar', '获取缓存统计信息')]
    public function getStats()
    {
        try {
            $stats = $this->getCacheStats();
            
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'total_files' => $stats['total_files'],
                    'total_size' => $this->formatBytes($stats['total_size']),
                    'total_size_bytes' => $stats['total_size'],
                    'expired_files' => $stats['expired_files'],
                    'expired_size' => $this->formatBytes($stats['expired_size']),
                    'expired_size_bytes' => $stats['expired_size'],
                    'expired_percentage' => $stats['total_files'] > 0 ? 
                        round(($stats['expired_files'] / $stats['total_files']) * 100, 2) : 0
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取缓存统计信息失败: %{1}', $e->getMessage())
            ]);
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
     * 获取各缓存目录的详细信息
     */
    private function getCacheDirectoryDetails(): array
    {
        $cacheDir = BP . 'var' . DS . 'cache';
        $details = [];
        
        if (!is_dir($cacheDir)) {
            return $details;
        }
        
        $dirs = glob($cacheDir . DS . '*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                $name = basename($dir);
                $stats = $this->getDirectoryStats($dir);
                $details[$name] = [
                    'name' => $name,
                    'path' => 'var/cache/' . $name,
                    'files' => $stats['files'],
                    'size' => $this->formatBytes($stats['size']),
                    'size_bytes' => $stats['size'],
                    'description' => $this->getCacheTypeDescription($name)
                ];
            }
        }
        
        // 按大小排序
        uasort($details, fn($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);
        
        return $details;
    }
    
    /**
     * 获取目录统计
     */
    private function getDirectoryStats(string $dir): array
    {
        $stats = ['files' => 0, 'size' => 0];
        
        if (!is_dir($dir)) {
            return $stats;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $stats['files']++;
                $stats['size'] += $file->getSize();
            }
        }
        
        return $stats;
    }
    
    /**
     * 获取缓存类型描述
     */
    private function getCacheTypeDescription(string $name): string
    {
        $descriptions = [
            'framework_view' => __('视图/模板编译缓存'),
            'framework_phrase' => __('翻译短语缓存'),
            'framework_event' => __('事件系统缓存'),
            'framework_config' => __('配置文件缓存'),
            'framework_route' => __('路由缓存'),
            'database_model' => __('数据库模型缓存'),
            'eav_cache' => __('EAV属性缓存'),
            'taglib_cache' => __('标签库编译缓存'),
            'i18n' => __('国际化翻译缓存'),
            'currency_cache' => __('货币汇率缓存'),
            'backend_cache' => __('后台系统缓存'),
            'frontend_cache' => __('前台系统缓存'),
            'system_cache' => __('系统通用缓存'),
            'config' => __('模块配置缓存'),
        ];
        
        return $descriptions[$name] ?? __('其他缓存');
    }

    /**
     * 格式化字节大小
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 查看定时任务状态
     */
    #[Acl('Weline_CacheManager::cache_cleanup_cron', '查看定时任务', 'mdi mdi-clock-outline', '查看缓存清理定时任务状态')]
    public function cronStatus()
    {
        try {
            /** @var CronTask $cronTaskModel */
            $cronTaskModel = ObjectManager::getInstance(CronTask::class);
            $tasks = $cronTaskModel->where('name', '%cache%', 'like')
                ->order('last_run_time', 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            $this->assign('cronTasks', $tasks);
            $this->assign('title', __('缓存清理定时任务'));
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('获取定时任务状态失败: %{1}', $e->getMessage()));
            $this->redirect('*/cachecleanup/index');
        }
    }

    /**
     * 手动执行定时任务（AJAX）
     */
    #[Acl('Weline_CacheManager::cache_cleanup_run_cron', '执行定时任务', 'mdi mdi-play', '手动执行缓存清理定时任务')]
    public function postRunCronTask()
    {
        try {
            // 直接调用缓存清理 Cron
            /** @var CacheCleanup $cacheCleanup */
            $cacheCleanup = ObjectManager::getInstance(CacheCleanup::class);
            $result = $cacheCleanup->execute();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('定时任务执行完成！已清理过期缓存文件。')
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('定时任务执行失败: %{1}。请检查 Cron 配置和日志。', $e->getMessage())
            ]);
        }
    }
    
    /**
     * 清理指定类型的缓存（AJAX）
     */
    #[Acl('Weline_CacheManager::cache_cleanup_type', '清理指定缓存', 'mdi mdi-folder-remove', '清理指定类型的缓存')]
    public function postClearType()
    {
        $type = $this->request->getPost('type');
        
        if (!$type) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请指定要清理的缓存类型')
            ]);
        }
        
        try {
            $cacheDir = BP . 'var' . DS . 'cache' . DS . $type;
            
            if (!is_dir($cacheDir)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('缓存目录不存在: %{1}', $type)
                ]);
            }
            
            $clearedCount = 0;
            $clearedSize = 0;
            $this->deleteDirectory($cacheDir, $clearedCount, $clearedSize);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('缓存类型 %{type} 清理完成！共清理 %{count} 个文件，释放 %{size} 空间。', [
                    'type' => $type,
                    'count' => $clearedCount,
                    'size' => $this->formatBytes($clearedSize)
                ])
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('清理缓存类型 %{type} 失败: %{error}', [
                    'type' => $type,
                    'error' => $e->getMessage()
                ])
            ]);
        }
    }
}
