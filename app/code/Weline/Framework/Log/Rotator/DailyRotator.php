<?php

declare(strict_types=1);

/**
 * Weline Framework 按日期轮转器
 * 
 * 支持按日期自动轮转日志文件
 */

namespace Weline\Framework\Log\Rotator;

class DailyRotator
{
    /**
     * 最大保留文件数
     */
    private int $maxFiles;

    /**
     * 日期格式
     */
    private string $dateFormat;

    public function __construct(int $maxFiles = 7, string $dateFormat = 'Y-m-d')
    {
        $this->maxFiles = $maxFiles;
        $this->dateFormat = $dateFormat;
    }

    /**
     * 检查并执行轮转
     *
     * @param string $logFile 日志文件路径
     * @return string 当前应使用的日志文件路径
     */
    public function rotate(string $logFile): string
    {
        $pathInfo = pathinfo($logFile);
        $dir = $pathInfo['dirname'];
        $baseName = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';
        
        $today = date($this->dateFormat);
        $currentFile = "{$dir}/{$baseName}-{$today}.{$ext}";
        
        // 清理旧文件
        $this->cleanup($dir, $baseName, $ext);
        
        return $currentFile;
    }

    /**
     * 获取带日期的文件名
     */
    public function getRotatedFilename(string $logFile, ?string $date = null): string
    {
        $date = $date ?? date($this->dateFormat);
        $pathInfo = pathinfo($logFile);
        $dir = $pathInfo['dirname'];
        $baseName = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';
        
        return "{$dir}/{$baseName}-{$date}.{$ext}";
    }

    /**
     * 清理过期的日志文件
     */
    public function cleanup(string $dir, string $baseName, string $ext): void
    {
        $pattern = "{$dir}/{$baseName}-*.{$ext}";
        $files = glob($pattern);
        
        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        // 按修改时间排序（最旧的在前）
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        
        // 删除超出保留数量的文件
        $toDelete = count($files) - $this->maxFiles;
        for ($i = 0; $i < $toDelete; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * 获取所有轮转的日志文件
     *
     * @param string $logFile 原始日志文件路径
     * @return array 所有轮转文件列表
     */
    public function getRotatedFiles(string $logFile): array
    {
        $pathInfo = pathinfo($logFile);
        $dir = $pathInfo['dirname'];
        $baseName = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';
        
        $pattern = "{$dir}/{$baseName}-*.{$ext}";
        $files = glob($pattern) ?: [];
        
        // 按日期排序（最新的在前）
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        
        return $files;
    }
}
