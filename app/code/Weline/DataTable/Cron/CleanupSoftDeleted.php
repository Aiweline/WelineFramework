<?php

namespace Weline\DataTable\Cron;

use Weline\Framework\Cron\CronInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 清理过期软删除记录的定时任务
 * 每天执行一次，清理超过180天的软删除记录
 */
class CleanupSoftDeleted implements CronInterface
{
    /**
     * 保留天数，默认180天
     */
    private int $retentionDays = 180;

    /**
     * 支持软删除的模型列表
     */
    private array $softDeleteModels = [];

    public function __construct()
    {
        // 可以通过配置文件或其他方式获取需要清理的模型列表
        $this->loadSoftDeleteModels();
    }

    /**
     * 执行定时任务
     */
    public function execute(): bool
    {
        try {
            $totalCleaned = 0;
            $results = [];

            foreach ($this->softDeleteModels as $modelClass) {
                try {
                    if (!class_exists($modelClass)) {
                        continue;
                    }

                    $modelInstance = new $modelClass();
                    
                    // 检查模型是否支持软删除
                    if (!method_exists($modelInstance, 'cleanupExpiredSoftDeleted')) {
                        continue;
                    }

                    $cleaned = $modelInstance->cleanupExpiredSoftDeleted($this->retentionDays);
                    $totalCleaned += $cleaned;
                    
                    $results[$modelClass] = $cleaned;
                    
                    if ($cleaned > 0) {
                        $this->log("Cleaned {$cleaned} expired soft deleted records from {$modelClass}");
                    }
                    
                } catch (\Exception $e) {
                    $this->log("Error cleaning {$modelClass}: " . $e->getMessage(), 'error');
                }
            }

            $this->log("Soft delete cleanup completed. Total cleaned: {$totalCleaned} records");
            
            // 记录清理结果到数据库（可选）
            $this->recordCleanupResult($results, $totalCleaned);
            
            return true;
            
        } catch (\Exception $e) {
            $this->log("Soft delete cleanup failed: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 获取定时任务配置
     */
    public function getConfig(): array
    {
        return [
            'name' => 'cleanup_soft_deleted',
            'schedule' => '0 2 * * *', // 每天凌晨2点执行
            'description' => '清理过期的软删除记录',
            'enabled' => true
        ];
    }

    /**
     * 加载支持软删除的模型列表
     */
    private function loadSoftDeleteModels(): void
    {
        // 这里可以从配置文件或数据库中加载模型列表
        // 暂时硬编码一些常见的模型
        $this->softDeleteModels = [
            // 可以在这里添加需要清理的模型类
            // 'App\Model\User',
            // 'App\Model\Product',
            // 'App\Model\Order',
        ];

        // 也可以通过扫描目录自动发现使用软删除Trait的模型
        $this->discoverSoftDeleteModels();
    }

    /**
     * 自动发现使用软删除功能的模型
     */
    private function discoverSoftDeleteModels(): void
    {
        try {
            // 扫描常见的模型目录
            $modelDirs = [
                BP . '/app/code/*/Model',
                BP . '/app/code/*/*/Model',
            ];

            foreach ($modelDirs as $pattern) {
                $dirs = glob($pattern, GLOB_ONLYDIR);
                foreach ($dirs as $dir) {
                    $this->scanModelDirectory($dir);
                }
            }
        } catch (\Exception $e) {
            $this->log("Error discovering soft delete models: " . $e->getMessage(), 'error');
        }
    }

    /**
     * 扫描模型目录
     */
    private function scanModelDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->checkModelFile($file->getPathname());
            }
        }
    }

    /**
     * 检查模型文件是否使用软删除功能
     */
    private function checkModelFile(string $filePath): void
    {
        try {
            $content = file_get_contents($filePath);
            
            // 检查是否使用了SoftDelete trait
            if (strpos($content, 'use SoftDelete') !== false || 
                strpos($content, 'SoftDelete;') !== false) {
                
                // 提取命名空间和类名
                $className = $this->extractClassName($content);
                if ($className && !in_array($className, $this->softDeleteModels)) {
                    $this->softDeleteModels[] = $className;
                }
            }
        } catch (\Exception $e) {
            // 静默处理文件读取错误
        }
    }

    /**
     * 从文件内容中提取完整的类名
     */
    private function extractClassName(string $content): ?string
    {
        // 提取命名空间
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = trim($namespaceMatches[1]);
        } else {
            return null;
        }

        // 提取类名
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = trim($classMatches[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * 记录清理结果
     */
    private function recordCleanupResult(array $results, int $totalCleaned): void
    {
        try {
            // 可以将清理结果记录到数据库中，用于监控和统计
            $logData = [
                'cleanup_date' => date('Y-m-d H:i:s'),
                'total_cleaned' => $totalCleaned,
                'retention_days' => $this->retentionDays,
                'results' => json_encode($results),
                'status' => 'completed'
            ];

            // 这里可以保存到专门的日志表中
            // $logModel = ObjectManager::getInstance(CleanupLogModel::class);
            // $logModel->setData($logData)->save();
            
        } catch (\Exception $e) {
            $this->log("Error recording cleanup result: " . $e->getMessage(), 'error');
        }
    }

    /**
     * 记录日志
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] SoftDelete Cleanup: {$message}";
        
        // 写入日志文件
        error_log($logMessage);
        
        // 也可以使用框架的日志系统
        // Logger::log($level, $message, ['context' => 'soft_delete_cleanup']);
    }

    /**
     * 设置保留天数
     */
    public function setRetentionDays(int $days): self
    {
        $this->retentionDays = max(1, $days);
        return $this;
    }

    /**
     * 获取保留天数
     */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * 添加需要清理的模型
     */
    public function addSoftDeleteModel(string $modelClass): self
    {
        if (!in_array($modelClass, $this->softDeleteModels)) {
            $this->softDeleteModels[] = $modelClass;
        }
        return $this;
    }

    /**
     * 获取支持软删除的模型列表
     */
    public function getSoftDeleteModels(): array
    {
        return $this->softDeleteModels;
    }

    /**
     * 手动执行清理（用于测试或手动触发）
     */
    public function manualCleanup(string $modelClass = null, int $days = null): array
    {
        $retentionDays = $days ?? $this->retentionDays;
        $results = [];

        if ($modelClass) {
            // 清理指定模型
            if (class_exists($modelClass)) {
                $modelInstance = new $modelClass();
                if (method_exists($modelInstance, 'cleanupExpiredSoftDeleted')) {
                    $cleaned = $modelInstance->cleanupExpiredSoftDeleted($retentionDays);
                    $results[$modelClass] = $cleaned;
                }
            }
        } else {
            // 清理所有模型
            foreach ($this->softDeleteModels as $model) {
                if (class_exists($model)) {
                    $modelInstance = new $model();
                    if (method_exists($modelInstance, 'cleanupExpiredSoftDeleted')) {
                        $cleaned = $modelInstance->cleanupExpiredSoftDeleted($retentionDays);
                        $results[$model] = $cleaned;
                    }
                }
            }
        }

        return $results;
    }
}
