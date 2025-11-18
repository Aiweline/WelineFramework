<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Flush implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $cleanedCount = 0;
        $totalSize = 0;
        $errors = [];

        // 获取缓存目录
        $cacheDir = BP . 'var' . DS . 'cache';
        
        if (!is_dir($cacheDir)) {
            $this->printing->errorIcon(__('缓存目录不存在: %{1}', [$cacheDir]));
            return;
        }

        // 收集所有需要清理的目录
        $allDirs = $this->getAllCacheDirectories($cacheDir);
        $totalDirs = count($allDirs);
        
        if ($totalDirs === 0) {
            $this->printing->infoIcon(__('没有找到需要清理的缓存目录'));
            return;
        }

        // 显示进度条
        $this->printing->progressBar(0, $totalDirs, __('过期缓存清理中...'), 30);
        
        $currentIndex = 0;
        foreach ($allDirs as $dirInfo) {
            $currentIndex++;
            $dirPath = $dirInfo['path'];
            $dirName = $dirInfo['name'];
            
            // 更新进度条
            $this->printing->progressBar($currentIndex, $totalDirs, __('过期缓存清理中...'), 30);
            
            if (!is_dir($dirPath)) {
                continue;
            }

            try {
                $result = $this->cleanupExpiredCache($dirPath);
                $cleanedCount += $result['count'];
                $totalSize += $result['size'];
            } catch (\Exception $e) {
                $errors[] = __('清理 %{1} 缓存失败: %{2}', [$dirName, $e->getMessage()]);
            }
        }

        // 输出结果
        if ($cleanedCount > 0) {
            $sizeFormatted = $this->formatBytes($totalSize);
            $this->printing->successIcon(__('过期缓存清理完成！'));
            $this->printing->coloredText(__('   📁 清理文件: %{1} 个', [$cleanedCount]), $this->printing::NOTE);
            $this->printing->coloredText(__('   🗂️  释放空间: %{1}', [$sizeFormatted]), $this->printing::NOTE);
            $this->printing->coloredText(__('   📊 处理目录: %{1} 个', [$totalDirs]), $this->printing::NOTE);
        } else {
            $this->printing->infoIcon(__('没有发现过期的缓存文件'));
        }
        
        if (!empty($errors)) {
            $this->printing->separator('─', 50, $this->printing::WARNING);
            $this->printing->warningIcon(__('清理过程中出现 %{1} 个错误:', [count($errors)]));
            foreach ($errors as $error) {
                $this->printing->coloredText("   • {$error}", $this->printing::ERROR);
            }
        }
    }
    
    /**
     * 获取所有缓存目录
     * 
     * @param string $cacheDir 缓存根目录
     * @return array 目录信息数组
     */
    private function getAllCacheDirectories(string $cacheDir): array
    {
        $dirs = [];
        
        // 主要缓存类型
        $cacheTypes = ['file', 'backend_cache', 'frontend_cache', 'system_cache'];
        foreach ($cacheTypes as $cacheType) {
            $typeDir = $cacheDir . DS . $cacheType;
            if (is_dir($typeDir)) {
                $dirs[] = ['name' => $cacheType, 'path' => $typeDir];
            }
        }
        
        // 其他缓存目录
        $otherDirs = [
            'cache_hooks', 'cache_system', 'config', 'database_model', 'eav_cache',
            'framework_controller', 'framework_event', 'framework_hooks', 'framework_object',
            'framework_phrase', 'framework_plugin', 'framework_router', 'framework_view',
            'i18n', 'request_cache', 'system_config', 'taglib_cache'
        ];
        
        foreach ($otherDirs as $dir) {
            $dirPath = $cacheDir . DS . $dir;
            if (is_dir($dirPath)) {
                $dirs[] = ['name' => $dir, 'path' => $dirPath];
            }
        }
        
        return $dirs;
    }

    /**
     * 清理过期缓存
     * 
     * @param string $cacheDir 缓存目录路径
     * @return array 返回清理结果 ['count' => 文件数量, 'size' => 文件大小]
     */
    private function cleanupExpiredCache(string $cacheDir): array
    {
        $cleanedCount = 0;
        $totalSize = 0;
        
        if (!is_dir($cacheDir)) {
            return ['count' => 0, 'size' => 0];
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // 检查文件是否过期（超过24小时）
                if (time() - $file->getMTime() > 86400) {
                    $fileSize = $file->getSize();
                    if (unlink($file->getPathname())) {
                        $cleanedCount++;
                        $totalSize += $fileSize;
                    }
                }
            }
        }
        
        return ['count' => $cleanedCount, 'size' => $totalSize];
    }
    
    /**
     * 格式化字节大小
     * 
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('缓存刷新。清理掉过期的缓存文件。');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
