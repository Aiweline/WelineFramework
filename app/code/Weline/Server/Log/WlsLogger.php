<?php
declare(strict_types=1);

namespace Weline\Server\Log;

use Weline\Server\Service\WlsLogService;

class WlsLogger
{
    private static ?self $instance = null;

    private string $processTag = 'Unknown';
    private string $instanceName = 'default';
    private string $minLevel = LogLevel::INFO;
    private bool $stdoutEnabled = false;
    private bool $fileEnabled = true;
    private string $logDir = '';
    private string $buffer = '';
    private int $bufferSize = 0;
    private int $maxBufferBytes = 65536; // 从 8KB 增加到 64KB，减少刷新频率
    private int $maxBufferLines = 1000; // 最大缓冲行数，防止单行过小但行数过多导致内存泄漏
    private int $bufferLineCount = 0;
    private float $flushInterval = 5.0; // 每 5 秒刷新一次，大幅减少磁盘 I/O
    private float $lastFlushTime = 0.0;
    private bool $shutdownRegistered = false;
    private int $droppedLogCount = 0; // 因内存压力丢弃的日志数

    /**
     * callable(string $line, string $level, string $processTag): void
     */
    private $ipcLogSink = null;

    private function __construct()
    {
        $this->lastFlushTime = \microtime(true);
        $this->instanceName = WlsLogService::resolveInstanceName();
        $this->logDir = LogConfig::getLogDir($this->instanceName, $this->processTag);
        $this->minLevel = LogConfig::getMinLevel();
        $this->fileEnabled = LogConfig::isEnabled();

        if (LogConfig::isDevMode()) {
            $this->stdoutEnabled = true;
            $this->fileEnabled = true;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function setProcessTag(string $tag): self
    {
        $this->processTag = $tag;
        $this->setInstanceName(WlsLogService::resolveInstanceName(null, $tag));
        return $this;
    }

    public function getProcessTag(): string
    {
        return $this->processTag;
    }

    public function setInstanceName(string $instanceName): self
    {
        $resolved = WlsLogService::sanitizeInstanceName($instanceName);
        if ($resolved === $this->instanceName) {
            return $this;
        }

        $this->flush(true);
        $this->instanceName = $resolved;
        $this->logDir = LogConfig::getLogDir($this->instanceName, $this->processTag);

        return $this;
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function setMinLevel(string $level): self
    {
        $this->minLevel = LogLevel::normalize($level);
        return $this;
    }

    public function setStdoutEnabled(bool $enabled): self
    {
        $this->stdoutEnabled = $enabled;
        return $this;
    }

    public function setFileEnabled(bool $enabled): self
    {
        $this->fileEnabled = $enabled;
        return $this;
    }

    /**
     * @param callable|null $sink function(string $line, string $level, string $processTag): void
     */
    public function setIpcLogSink(?callable $sink): self
    {
        $this->ipcLogSink = $sink;
        if ($sink !== null && LogConfig::isDevMode()) {
            $this->stdoutEnabled = false;
        }
        return $this;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = LogLevel::normalize($level);

        $contextInstance = $context['instance'] ?? null;
        if (\is_scalar($contextInstance) && (string)$contextInstance !== '') {
            $this->setInstanceName((string)$contextInstance);
        }

        if (!LogLevel::isAtLeast($level, $this->minLevel)) {
            return;
        }

        // 内存压力检测：如果内存使用超过 90%，丢弃非关键日志
        $memoryUsage = \memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.9)) {
            // 仅保留 ERROR 和 FATAL 级别日志
            if ($level !== LogLevel::ERROR && $level !== LogLevel::FATAL) {
                $this->droppedLogCount++;
                // 每丢弃 100 条日志输出一次警告
                if ($this->droppedLogCount % 100 === 1) {
                    $this->writeStdout("[WARN] Memory pressure: dropped {$this->droppedLogCount} logs\n", LogLevel::WARNING);
                }
                return;
            }
        }

        $timestamp = \date('Y-m-d H:i:s');
        $contextStr = '';
        if (!empty($context)) {
            // 防止大型 context 导致内存溢出：限制深度和长度
            if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.8)) {
                // 内存使用超过 80%，跳过 context 序列化
                $contextStr = ' [context skipped: memory pressure]';
            } else {
                // 限制 context 数组大小：最多 10 个键
                if (\count($context) > 10) {
                    $context = \array_slice($context, 0, 10, true);
                    $context['_truncated'] = true;
                }

                $encoded = @\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR, 3);
                if ($encoded === false || \strlen($encoded) > 4096) {
                    // 从 8192 降低到 4096，减少内存占用
                    $contextStr = ' [context too large]';
                } else {
                    $contextStr = ' ' . $encoded;
                }
            }
        }
        $line = "[{$timestamp}] [{$this->processTag}] [{$level}] {$message}{$contextStr}\n";

        if (LogConfig::isDevMode() && $this->ipcLogSink !== null) {
            ($this->ipcLogSink)($line, $level, $this->processTag);
            $this->ensureShutdownRegistered();
            return;
        }

        if ($this->stdoutEnabled) {
            $this->writeStdout($line, $level);
        }

        if ($this->fileEnabled) {
            $this->writeBuffer($line, $level);
        }

        if ($level === LogLevel::ERROR || $level === LogLevel::FATAL) {
            $this->flush(true);
        }

        $this->ensureShutdownRegistered();
    }

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

        if (!empty($this->logDir) && !\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $mainLog = $this->logDir . 'wls-' . \date('Y-m-d') . '.log';
        // 移除 LOCK_EX 避免锁竞争，使用非阻塞写入提升性能
        // 在 WLS 多 Worker 模式下，各 Worker 写入自己的日志文件，不会产生冲突
        @\file_put_contents($mainLog, $this->buffer, FILE_APPEND);

        // 日志轮转：清理过期日志文件
        $this->rotateOldLogs();

        // 重置所有计数器，防止内存泄漏
        $this->buffer = '';
        $this->bufferSize = 0;
        $this->bufferLineCount = 0;
        $this->lastFlushTime = \microtime(true);
    }

    public function writeErrorLog(string $line): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $errorLog = $this->logDir . 'error-' . \date('Y-m-d') . '.log';
        // 移除 LOCK_EX 避免锁竞争
        @\file_put_contents($errorLog, $line, FILE_APPEND);
    }

    public function writeCrashLog(array $crashData): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $crashLog = $this->logDir . 'crash-' . \date('Y-m-d') . '.log';
        $json = \json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // 移除 LOCK_EX 避免锁竞争
        @\file_put_contents($crashLog, $json . "\n\n", FILE_APPEND);
    }

    private function writeStdout(string $line, string $level): void
    {
        $colored = LogLevel::colorLine($line, $level, $this->processTag);

        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            @\fwrite(STDOUT, $colored);
            @\fflush(STDOUT);
        } else {
            echo $colored;
            @\flush();
        }
    }

    private function writeBuffer(string $line, string $level): void
    {
        // 内存压力检测：如果内存使用超过 85%，立即刷新 buffer
        $memoryUsage = \memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.85)) {
            $this->flush(true);
        }

        // 防止缓冲区无限增长：检查行数和字节数双重限制
        if ($this->bufferLineCount >= $this->maxBufferLines || $this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }

        $this->buffer .= $line;
        $this->bufferSize += \strlen($line);
        $this->bufferLineCount++;

        if (LogLevel::isAtLeast($level, LogLevel::ERROR)) {
            $this->writeErrorLog($line);
        }
    }

    public function appendLineForMaster(string $line): void
    {
        if ($line === '') {
            return;
        }

        // 防止缓冲区无限增长
        if ($this->bufferLineCount >= $this->maxBufferLines || $this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }

        $this->buffer .= $line;
        $this->bufferSize += \strlen($line);
        $this->bufferLineCount++;
    }

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
     * 获取 PHP 内存限制（字节）
     *
     * @return int 返回 0 表示无限制
     */
    private function getMemoryLimit(): int
    {
        static $limit = null;

        if ($limit !== null) {
            return $limit;
        }

        $memoryLimit = \ini_get('memory_limit');
        if ($memoryLimit === false || $memoryLimit === '' || $memoryLimit === '-1') {
            $limit = 0;
            return 0;
        }

        $memoryLimit = \trim($memoryLimit);
        $last = \strtolower($memoryLimit[\strlen($memoryLimit) - 1]);
        $value = (int)$memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        $limit = $value;
        return $value;
    }

    /**
     * 清理过期日志文件（保留最近 N 天）
     */
    private function rotateOldLogs(): void
    {
        static $lastRotateCheck = 0;
        $now = \time();

        // 每小时检查一次，避免频繁扫描目录
        if (($now - $lastRotateCheck) < 3600) {
            return;
        }

        $lastRotateCheck = $now;

        if (empty($this->logDir) || !\is_dir($this->logDir)) {
            return;
        }

        $maxFiles = (int)LogConfig::getValue('max_files', 7);
        if ($maxFiles <= 0) {
            return;
        }

        $cutoffTime = $now - ($maxFiles * 86400);

        $files = @\glob($this->logDir . '*.log');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $mtime = @\filemtime($file);
            if ($mtime !== false && $mtime < $cutoffTime) {
                @\unlink($file);
            }
        }
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->flush(true);
        }
        self::$instance = null;
        LogConfig::clearCache();
    }
}

