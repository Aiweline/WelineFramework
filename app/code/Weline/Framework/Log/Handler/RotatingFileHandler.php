<?php

declare(strict_types=1);

/**
 * Weline Framework 轮转文件日志处理器
 * 
 * 支持日志轮转：
 * - 按日期轮转（daily）
 * - 按大小轮转（size）
 * - 保留文件数限制
 */

namespace Weline\Framework\Log\Handler;

use Weline\Framework\Log\LogLevel;

class RotatingFileHandler implements HandlerInterface
{
    /**
     * 日志根目录
     */
    private string $logPath;

    /**
     * 轮转策略
     */
    private string $strategy = 'daily';

    /**
     * 最大保留文件数
     */
    private int $maxFiles = 7;

    /**
     * 单文件最大大小（字节）
     */
    private int $maxSize = 52428800; // 50MB

    /**
     * 当前日期（用于检测日期变化）
     */
    private string $currentDate;

    /**
     * 内部文件处理器
     */
    private FileHandler $fileHandler;

    /**
     * 已检查的文件
     * @var array<string, bool>
     */
    private array $rotatedFiles = [];

    public function __construct(?string $logPath = null, array $options = [])
    {
        $this->logPath = $logPath ?? $this->getDefaultLogPath();
        
        if (isset($options['strategy'])) {
            $this->strategy = $options['strategy'];
        }
        if (isset($options['max_files'])) {
            $this->maxFiles = (int)$options['max_files'];
        }
        if (isset($options['max_size'])) {
            $this->maxSize = (int)$options['max_size'];
        }
        
        $this->currentDate = date('Y-m-d');
        $this->fileHandler = new FileHandler($this->logPath, $options);
    }

    /**
     * 获取默认日志路径
     */
    private function getDefaultLogPath(): string
    {
        if (defined('BP')) {
            return BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_log' . DIRECTORY_SEPARATOR;
    }

    /**
     * 写入日志
     */
    public function write(LogLevel $level, string $formattedMessage, string $channel): bool
    {
        // 检查是否需要轮转
        $this->checkRotation($channel);
        
        return $this->fileHandler->write($level, $formattedMessage, $channel);
    }

    /**
     * 检查是否需要轮转
     */
    private function checkRotation(string $channel): void
    {
        $fileKey = $channel;
        
        // 每个文件只检查一次（每次请求）
        if (isset($this->rotatedFiles[$fileKey])) {
            return;
        }
        $this->rotatedFiles[$fileKey] = true;

        switch ($this->strategy) {
            case 'daily':
                $this->rotateDailyIfNeeded($channel);
                break;
            case 'size':
                $this->rotateBySizeIfNeeded($channel);
                break;
        }
    }

    /**
     * 按日期轮转
     */
    private function rotateDailyIfNeeded(string $channel): void
    {
        $today = date('Y-m-d');
        
        if ($today === $this->currentDate) {
            return;
        }
        
        $this->currentDate = $today;
        
        // 关闭当前句柄
        $this->fileHandler->close();
        
        // 轮转文件
        $this->rotateFile($channel, $today);
        
        // 清理旧文件
        $this->cleanOldFiles($channel);
    }

    /**
     * 按大小轮转
     */
    private function rotateBySizeIfNeeded(string $channel): void
    {
        $filePath = $this->getFilePath($channel);
        
        if (!file_exists($filePath)) {
            return;
        }
        
        $size = @filesize($filePath);
        if ($size === false || $size < $this->maxSize) {
            return;
        }
        
        // 关闭当前句柄
        $this->fileHandler->close();
        
        // 轮转文件
        $timestamp = date('Y-m-d_H-i-s');
        $this->rotateFile($channel, $timestamp);
        
        // 清理旧文件
        $this->cleanOldFiles($channel);
    }

    /**
     * 获取日志文件路径
     *
     * 支持层级通道名，如 wls/fiber_scheduler 会写入 var/log/wls/fiber_scheduler.log
     */
    private function getFilePath(string $channel): string
    {
        $relativePath = str_ends_with($channel, '.log')
            ? $this->sanitizeChannelAsPath($channel)
            : $this->sanitizeChannelAsPath($channel . '.log');
        return $this->logPath . $relativePath;
    }

    /**
     * 将通道名转换为安全的相对路径（支持层级）
     */
    private function sanitizeChannelAsPath(string $channel): string
    {
        $separator = DIRECTORY_SEPARATOR;
        $slash = '/';
        $parts = array_filter(
            preg_split('/[\\\\\/]+/', $channel),
            fn(string $p) => $p !== '' && $p !== '.' && $p !== '..'
        );
        $sanitized = [];
        foreach ($parts as $part) {
            $sanitized[] = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $part);
        }
        $path = implode($slash, $sanitized);
        return str_replace($slash, $separator, $path);
    }

    /**
     * 轮转文件
     */
    private function rotateFile(string $channel, string $suffix): void
    {
        $filePath = $this->getFilePath($channel);
        
        if (!file_exists($filePath)) {
            return;
        }
        
        $pathInfo = pathinfo($filePath);
        $rotatedPath = $pathInfo['dirname'] . DIRECTORY_SEPARATOR 
            . $pathInfo['filename'] . '-' . $suffix . '.' . ($pathInfo['extension'] ?? 'log');
        
        @rename($filePath, $rotatedPath);
    }

    /**
     * 清理旧文件
     */
    private function cleanOldFiles(string $channel): void
    {
        $pattern = $this->getFilePath($channel);
        $pathInfo = pathinfo($pattern);
        
        $dir = $pathInfo['dirname'];
        $baseName = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';
        
        // 查找所有轮转文件
        $files = glob($dir . DIRECTORY_SEPARATOR . $baseName . '-*.' . $ext);
        
        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }
        
        // 按修改时间排序
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        
        // 删除最旧的文件
        $toDelete = count($files) - $this->maxFiles;
        for ($i = 0; $i < $toDelete; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * 刷新缓冲区
     */
    public function flush(): void
    {
        $this->fileHandler->flush();
    }

    /**
     * 关闭处理器
     */
    public function close(): void
    {
        $this->fileHandler->close();
    }

    /**
     * 重置轮转检查状态（用于 WLS）
     */
    public function resetRotationCheck(): void
    {
        $this->rotatedFiles = [];
    }
}
