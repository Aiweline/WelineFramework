<?php
declare(strict_types=1);

/**
 * WLS 统一日志器
 *
 * 单例模式，支持终端输出 + 文件写入双模式。
 * 所有 WLS 进程（Master、Worker、Dispatcher、SessionServer 等）统一使用此类记录日志。
 *
 * 特性：
 * - 缓冲写入：减少 I/O 次数，提升性能
 * - 即时刷新：ERROR/FATAL 级别立即写入
 * - 环境适配：开发环境全量，生产环境按级别过滤
 * - 终端着色：不同级别不同颜色
 *
 * @author Aiweline
 */

namespace Weline\Server\Log;

class WlsLogger
{
    private static ?self $instance = null;

    /** 进程标识（如 Worker#1, Dispatcher, SessionServer:19970） */
    private string $processTag = 'Unknown';

    /** 最小日志级别 */
    private string $minLevel = LogLevel::INFO;

    /** 是否输出到终端 */
    private bool $stdoutEnabled = false;

    /** 是否写入文件 */
    private bool $fileEnabled = true;

    /** 日志目录 */
    private string $logDir = '';

    /** 文件缓冲区 */
    private string $buffer = '';

    /** 缓冲区大小（字节） */
    private int $bufferSize = 0;

    /** 缓冲区最大字节数 */
    private int $maxBufferBytes = 8192;

    /** 刷新间隔（秒） */
    private float $flushInterval = 0.5;

    /** 上次刷新时间 */
    private float $lastFlushTime = 0.0;

    /** 是否已注册 shutdown 函数 */
    private bool $shutdownRegistered = false;

    private function __construct()
    {
        $this->lastFlushTime = \microtime(true);
        $this->logDir = LogConfig::getLogDir();
        $this->minLevel = LogConfig::getMinLevel();
        $this->fileEnabled = LogConfig::isEnabled();
        // 开发模式：所有进程同时输出到控制台并写入日志文件
        if (LogConfig::isDevMode()) {
            $this->stdoutEnabled = true;
            $this->fileEnabled = true;
        }
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 设置进程标识
     */
    public function setProcessTag(string $tag): self
    {
        $this->processTag = $tag;
        return $this;
    }

    /**
     * 获取进程标识
     */
    public function getProcessTag(): string
    {
        return $this->processTag;
    }

    /**
     * 设置最小日志级别
     */
    public function setMinLevel(string $level): self
    {
        $this->minLevel = LogLevel::normalize($level);
        return $this;
    }

    /**
     * 设置是否输出到终端
     */
    public function setStdoutEnabled(bool $enabled): self
    {
        $this->stdoutEnabled = $enabled;
        return $this;
    }

    /**
     * 设置是否写入文件
     */
    public function setFileEnabled(bool $enabled): self
    {
        $this->fileEnabled = $enabled;
        return $this;
    }

    /**
     * 记录日志
     *
     * @param string $level 日志级别
     * @param string $message 日志内容
     * @param array $context 上下文数据（可选）
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = LogLevel::normalize($level);

        // 级别过滤
        if (!LogLevel::isAtLeast($level, $this->minLevel)) {
            return;
        }

        // 格式化日志行
        $timestamp = \date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . \json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$timestamp}] [{$this->processTag}] [{$level}] {$message}{$contextStr}\n";

        // 输出到终端
        if ($this->stdoutEnabled) {
            $this->writeStdout($line, $level);
        }

        // 写入文件缓冲
        if ($this->fileEnabled) {
            $this->writeBuffer($line, $level);
        }

        // ERROR/FATAL 立即刷新
        if ($level === LogLevel::ERROR || $level === LogLevel::FATAL) {
            $this->flush(true);
        }

        // 确保注册 shutdown 函数
        $this->ensureShutdownRegistered();
    }

    /**
     * 快捷方法
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function fatal(string $message, array $context = []): void
    {
        $this->log(LogLevel::FATAL, $message, $context);
    }

    /**
     * 静态快捷方法（自动获取单例）
     */
    public static function log_(string $level, string $message, array $context = []): void
    {
        self::getInstance()->log($level, $message, $context);
    }

    public static function debug_(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }

    public static function info_(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function notice_(string $message, array $context = []): void
    {
        self::getInstance()->notice($message, $context);
    }

    public static function warning_(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function error_(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function fatal_(string $message, array $context = []): void
    {
        self::getInstance()->fatal($message, $context);
    }

    public static function flush_(bool $force = false): void
    {
        self::getInstance()->flush($force);
    }

    public static function tick_(): bool
    {
        return self::getInstance()->tick();
    }

    /**
     * 定时刷新检查（在主循环中调用）
     */
    public function tick(): bool
    {
        if ($this->bufferSize === 0) {
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
     * 刷新缓冲区
     */
    public function flush(bool $force = false): void
    {
        if ($this->bufferSize === 0) {
            return;
        }

        if (!$force) {
            $now = \microtime(true);
            if (($now - $this->lastFlushTime) < $this->flushInterval) {
                return;
            }
        }

        // 确保日志目录存在
        if (!empty($this->logDir) && !\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        // 写入主日志文件
        $mainLog = $this->logDir . 'wls.log';
        @\file_put_contents($mainLog, $this->buffer, FILE_APPEND | LOCK_EX);

        // 清空缓冲
        $this->buffer = '';
        $this->bufferSize = 0;
        $this->lastFlushTime = \microtime(true);
    }

    /**
     * 写入错误日志（ERROR 及以上单独写一份）
     */
    public function writeErrorLog(string $line): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $errorLog = $this->logDir . 'error.log';
        @\file_put_contents($errorLog, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 写入崩溃日志（FATAL 级别，JSON 格式）
     */
    public function writeCrashLog(array $crashData): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $crashLog = $this->logDir . 'crash.log';
        $json = \json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @\file_put_contents($crashLog, $json . "\n\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * 输出到终端（带颜色）
     */
    private function writeStdout(string $line, string $level): void
    {
        $color = LogLevel::getColor($level);
        $reset = LogLevel::getReset();

        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            @\fwrite(STDOUT, $color . $line . $reset);
            @\fflush(STDOUT);
        } else {
            echo $color . $line . $reset;
            @\flush();
        }
    }

    /**
     * 写入文件缓冲
     */
    private function writeBuffer(string $line, string $level): void
    {
        $this->buffer .= $line;
        $this->bufferSize += \strlen($line);

        // ERROR 及以上同时写入 error.log
        if (LogLevel::isAtLeast($level, LogLevel::ERROR)) {
            $this->writeErrorLog($line);
        }

        // 缓冲区满则刷新
        if ($this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }
    }

    /**
     * 确保注册 shutdown 函数
     */
    private function ensureShutdownRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        \register_shutdown_function(function () {
            $this->flush(true);
        });

        $this->shutdownRegistered = true;
    }

    /**
     * 重置单例（用于测试或热重载）
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->flush(true);
        }
        self::$instance = null;
        LogConfig::clearCache();
    }
}
