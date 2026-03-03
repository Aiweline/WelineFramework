<?php

declare(strict_types=1);

/**
 * Weline Framework 文件日志处理器
 * 
 * 将日志写入文件，支持：
 * - 按通道分文件
 * - 自动创建目录
 * - 文件锁定
 */

namespace Weline\Framework\Log\Handler;

use Weline\Framework\Log\LogLevel;

class FileHandler implements HandlerInterface
{
    /**
     * 日志根目录
     */
    private string $logPath;

    /**
     * 文件句柄缓存
     * @var array<string, resource>
     */
    private array $handles = [];

    /**
     * 默认日志文件名映射
     * @var array<string, string>
     */
    private array $levelFiles = [
        'EMERGENCY' => 'error.log',
        'ALERT'     => 'error.log',
        'CRITICAL'  => 'error.log',
        'ERROR'     => 'error.log',
        'WARNING'   => 'warning.log',
        'NOTICE'    => 'notice.log',
        'INFO'      => 'info.log',
        'DEBUG'     => 'debug.log',
    ];

    /**
     * 文件权限
     */
    private int $filePermission = 0644;

    /**
     * 目录权限
     */
    private int $dirPermission = 0755;

    /**
     * 是否使用文件锁
     */
    private bool $useLocking = true;

    public function __construct(?string $logPath = null, array $options = [])
    {
        $this->logPath = $logPath ?? $this->getDefaultLogPath();
        
        if (isset($options['level_files'])) {
            $this->levelFiles = array_merge($this->levelFiles, $options['level_files']);
        }
        if (isset($options['file_permission'])) {
            $this->filePermission = $options['file_permission'];
        }
        if (isset($options['dir_permission'])) {
            $this->dirPermission = $options['dir_permission'];
        }
        if (isset($options['use_locking'])) {
            $this->useLocking = (bool)$options['use_locking'];
        }
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
        $filePath = $this->getFilePath($level, $channel);
        
        // 确保目录存在
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, $this->dirPermission, true) && !is_dir($dir)) {
                return false;
            }
        }

        // 获取或创建文件句柄
        $handle = $this->getHandle($filePath);
        if ($handle === null) {
            return false;
        }

        // 写入（带锁）
        if ($this->useLocking) {
            flock($handle, LOCK_EX);
        }
        
        $result = fwrite($handle, $formattedMessage);
        
        if ($this->useLocking) {
            flock($handle, LOCK_UN);
        }

        return $result !== false;
    }

    /**
     * 获取日志文件路径
     */
    private function getFilePath(LogLevel $level, string $channel): string
    {
        // 如果通道名看起来像文件名，直接使用
        if (str_ends_with($channel, '.log')) {
            return $this->logPath . $channel;
        }

        // 特殊通道映射到独立文件
        if ($channel !== 'app' && $channel !== 'default') {
            // 通道名作为子目录或文件前缀
            $sanitizedChannel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $channel);
            return $this->logPath . $sanitizedChannel . '.log';
        }

        // 使用级别默认文件
        $fileName = $this->levelFiles[$level->name] ?? 'app.log';
        return $this->logPath . $fileName;
    }

    /**
     * 获取文件句柄
     *
     * @return resource|null
     */
    private function getHandle(string $filePath)
    {
        if (!isset($this->handles[$filePath])) {
            $handle = @fopen($filePath, 'a');
            if ($handle === false) {
                return null;
            }
            $this->handles[$filePath] = $handle;
        }
        return $this->handles[$filePath];
    }

    /**
     * 刷新所有缓冲区
     */
    public function flush(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fflush($handle);
            }
        }
    }

    /**
     * 关闭所有文件句柄
     */
    public function close(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->handles = [];
    }

    /**
     * 析构时关闭句柄
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * 获取日志根目录
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }

    /**
     * 设置日志根目录
     */
    public function setLogPath(string $path): self
    {
        $this->close();
        $this->logPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $this;
    }
}
