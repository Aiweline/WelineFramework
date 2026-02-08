<?php
declare(strict_types=1);

/**
 * WLS 日志缓冲服务
 *
 * 所有 WLS 进程（Master、Dispatcher、Worker、HTTP Redirect）统一使用此类管理日志输出。
 *
 * 核心原则：
 * - 未启用日志时：log() 直接 return，零开销
 * - 启用日志时：缓冲到内存，每 5 秒批量写磁盘
 * - ERROR 级别立即刷新
 * - 进程退出时强制 flush
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

class LogBuffer
{
    /** 日志级别常量 */
    public const LEVEL_DEBUG = 'DEBUG';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARN = 'WARN';
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_FATAL = 'FATAL';

    /** 是否启用日志 */
    private bool $enabled;

    /** 刷新间隔（秒） */
    private float $flushInterval;

    /** 缓冲区最大字节数（超过立即刷新） */
    private int $maxBufferBytes;

    /** 日志文件路径 */
    private string $filePath = '';

    /** 缓冲区 */
    private string $buffer = '';

    /** 缓冲区当前字节数 */
    private int $bufferSize = 0;

    /** 上次刷新时间 */
    private float $lastFlushTime;

    /** 是否同时输出到 STDOUT */
    private bool $stdout;

    /** STDOUT 缓冲区（独立于文件缓冲） */
    private string $stdoutBuffer = '';

    /** 进程标识前缀（用于日志行） */
    private string $prefix = '';

    /**
     * @param bool  $enabled       是否启用日志（false 时 log() 零开销）
     * @param float $flushInterval 刷新间隔秒数（默认 5.0）
     * @param bool  $stdout        是否同时输出到 STDOUT（默认 false）
     * @param int   $maxBufferBytes 缓冲区最大字节数（默认 65536 = 64KB）
     */
    public function __construct(
        bool  $enabled = false,
        float $flushInterval = 5.0,
        bool  $stdout = false,
        int   $maxBufferBytes = 65536
    ) {
        $this->enabled        = $enabled;
        $this->flushInterval  = $flushInterval;
        $this->stdout         = $stdout;
        $this->maxBufferBytes = $maxBufferBytes;
        $this->lastFlushTime  = \microtime(true);
    }

    /**
     * 设置日志文件路径
     */
    public function setOutput(string $filePath): void
    {
        $this->filePath = $filePath;
    }

    /**
     * 设置进程标识前缀
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 动态开启/关闭
     */
    public function setEnabled(bool $enabled): void
    {
        if (!$enabled && $this->enabled) {
            // 关闭前刷出剩余缓冲
            $this->flush(true);
        }
        $this->enabled = $enabled;
    }

    /**
     * 写入一条日志
     *
     * 未启用时直接 return，零开销（不格式化、不拼字符串）。
     *
     * @param string $level   日志级别（INFO/WARN/ERROR/FATAL/DEBUG）
     * @param string $message 日志内容
     */
    public function log(string $level, string $message): void
    {
        // 未启用 → 零开销
        if (!$this->enabled) {
            return;
        }

        $timestamp = \date('Y-m-d H:i:s');
        $line = $this->prefix !== ''
            ? "[{$timestamp}] [{$this->prefix}] [{$level}] {$message}\n"
            : "[{$timestamp}] [{$level}] {$message}\n";

        $lineLen = \strlen($line);

        // 写入文件缓冲
        if ($this->filePath !== '') {
            $this->buffer     .= $line;
            $this->bufferSize += $lineLen;
        }

        // 写入 STDOUT 缓冲
        if ($this->stdout) {
            $this->stdoutBuffer .= $line;
        }

        // ERROR / FATAL 立即刷新
        if ($level === self::LEVEL_ERROR || $level === self::LEVEL_FATAL) {
            $this->flush(true);
            return;
        }

        // 缓冲区满 → 刷新
        if ($this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }
    }

    /**
     * 快捷方法
     */
    public function info(string $message): void
    {
        $this->log(self::LEVEL_INFO, $message);
    }

    public function warn(string $message): void
    {
        $this->log(self::LEVEL_WARN, $message);
    }

    public function error(string $message): void
    {
        $this->log(self::LEVEL_ERROR, $message);
    }

    public function debug(string $message): void
    {
        $this->log(self::LEVEL_DEBUG, $message);
    }

    /**
     * 检查是否需要定时刷新（在主循环中调用）
     *
     * 返回 true 表示已到刷新时间并已执行 flush。
     * 未启用日志时直接返回 false。
     */
    public function tick(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->bufferSize === 0 && $this->stdoutBuffer === '') {
            return false;
        }

        $now = \microtime(true);
        if (($now - $this->lastFlushTime) >= $this->flushInterval) {
            $this->flush(true);
            return true;
        }

        return false;
    }

    /**
     * 刷新缓冲区到文件和 STDOUT
     *
     * @param bool $force 是否强制刷新（忽略时间间隔检查）
     */
    public function flush(bool $force = false): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$force) {
            $now = \microtime(true);
            if (($now - $this->lastFlushTime) < $this->flushInterval) {
                return;
            }
        }

        // 刷新文件缓冲
        if ($this->buffer !== '' && $this->filePath !== '') {
            $dir = \dirname($this->filePath);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0755, true);
            }
            @\file_put_contents($this->filePath, $this->buffer, FILE_APPEND | LOCK_EX);
            $this->buffer     = '';
            $this->bufferSize = 0;
        }

        // 刷新 STDOUT 缓冲
        if ($this->stdoutBuffer !== '' && $this->stdout) {
            @\fwrite(\STDOUT, $this->stdoutBuffer);
            @\fflush(\STDOUT);
            $this->stdoutBuffer = '';
        }

        $this->lastFlushTime = \microtime(true);
    }

    /**
     * 析构时强制刷新（进程退出保障）
     */
    public function __destruct()
    {
        if ($this->enabled && ($this->bufferSize > 0 || $this->stdoutBuffer !== '')) {
            $this->flush(true);
        }
    }
}
