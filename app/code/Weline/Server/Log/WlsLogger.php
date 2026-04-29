<?php
declare(strict_types=1);

namespace Weline\Server\Log;

use Weline\Server\Log\Error\ErrorContext;
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
    private ?string $devDebugLogFile = null;
    private ?string $processLogFile = null;
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
    /** @var list<string> */
    private array $pendingBatchFiles = [];
    private int $maxPendingBatchFiles = 32;
    private float $lastBottleneckWarnAt = 0.0;
    private float $bottleneckWarnInterval = 5.0;

    /** 开发模式多进程同时打 debug/info 时，经 IPC 汇聚易形成 Master 端消息风暴 */
    private static float $ipcSinkWindowStart = 0.0;

    private static int $ipcSinkWindowCount = 0;

    private static int $ipcSinkDroppedBursty = 0;

    private const IPC_SINK_WINDOW_SEC = 1.0;

    /** 每窗口内允许转发到 Master 的「低优先级」行数（WARNING 及以上不受此限） */
    private const IPC_SINK_MAX_LOW_PRI_PER_WINDOW = 120;

    private function __construct()
    {
        $this->lastFlushTime = \microtime(true);
        $resolvedTag = $this->resolveFallbackProcessTag();
        if ($resolvedTag !== null) {
            $this->processTag = $resolvedTag;
        }

        $this->instanceName = WlsLogService::resolveInstanceName(null, $this->processTag);
        $this->logDir = LogConfig::getLogDir($this->instanceName, $this->processTag);
        $this->minLevel = LogConfig::getMinLevel();
        $this->fileEnabled = LogConfig::isEnabled();

        if (LogConfig::isDevMode() && LogConfig::isVerboseWlsLog()) {
            $this->stdoutEnabled = true;
            $this->fileEnabled = true;
            $this->devDebugLogFile = WlsLogService::getDebugLogFile();
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
        $normalizedTag = self::normalizeProcessTag($tag) ?? 'Unknown';
        $this->processTag = $normalizedTag;
        $this->setInstanceName(WlsLogService::resolveInstanceName(null, $normalizedTag));
        return $this;
    }

    public function getProcessTag(): string
    {
        $this->ensureProcessTagResolved();
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

    public function setProcessLogFile(?string $path): self
    {
        $path = $path !== null ? \trim($path) : '';
        if ($path === '') {
            $this->processLogFile = null;
            return $this;
        }

        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        if (!\is_file($path)) {
            @\touch($path);
        }

        $this->processLogFile = $path;
        if ($this->bufferSize > 0 || $this->pendingBatchFiles !== []) {
            $this->flush(true);
        }
        return $this;
    }

    public function getProcessLogFile(): ?string
    {
        return $this->processLogFile;
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

    /**
     * @return array{emit: bool, low_pri_ipc_rate_limited: bool}
     */
    private function evaluateIpcSink(string $level): array
    {
        if ($this->ipcLogSink === null || !LogConfig::isDevMode() || !LogConfig::isVerboseWlsLog()) {
            return ['emit' => false, 'low_pri_ipc_rate_limited' => false];
        }

        if (LogLevel::isAtLeast($level, LogLevel::WARNING)) {
            return ['emit' => true, 'low_pri_ipc_rate_limited' => false];
        }

        $now = \microtime(true);
        if ($now - self::$ipcSinkWindowStart >= self::IPC_SINK_WINDOW_SEC) {
            self::$ipcSinkWindowStart = $now;
            self::$ipcSinkWindowCount = 0;
        }

        if (self::$ipcSinkWindowCount >= self::IPC_SINK_MAX_LOW_PRI_PER_WINDOW) {
            self::$ipcSinkDroppedBursty++;
            // P2 观测性：把 IPC 节流"被丢弃的低优先级日志"同步到 MetricsRegistry。
            // 使用 class_exists 守护是因为 WlsLogger 可能在 composer autoload 启用前就被调用
            // （framework bootstrap 早期日志），此时 MetricsRegistry 还不可见。
            if (\class_exists(\Weline\Server\Observability\MetricsRegistry::class, false)) {
                \Weline\Server\Observability\MetricsRegistry::inc('wls.log.ipc_dropped_bursty');
            }
            if (self::$ipcSinkDroppedBursty % 200 === 1) {
                @\error_log(
                    '[WlsLogger] IPC log sink: low-priority rate limited (not forwarded to Master); '
                    . 'lines appended immediately to local wls-*.log, ipc_skipped≈' . self::$ipcSinkDroppedBursty
                );
            }

            return ['emit' => false, 'low_pri_ipc_rate_limited' => true];
        }

        self::$ipcSinkWindowCount++;

        return ['emit' => true, 'low_pri_ipc_rate_limited' => false];
    }

    /**
     * 与 buffer 刷新写入同一主日志文件；用于 IPC 低优先级限流时立即落盘，避免长时间滞留于内存 buffer。
     */
    private function appendImmediateToMainLog(string $line, string $level): void
    {
        if (!$this->fileEnabled || $this->logDir === '') {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $mainLog = $this->logDir . 'wls-' . \date('Y-m-d') . '.log';
        if (!\str_ends_with($line, "\n")) {
            $line .= "\n";
        }

        @\file_put_contents($mainLog, $line, FILE_APPEND);
        $this->writeProcessLogMirror($line);

        if (LogLevel::isAtLeast($level, LogLevel::ERROR)) {
            $this->writeErrorLog($line);
        }
    }

    private function getBatchSpoolDir(): string
    {
        return $this->logDir . '_batch' . DIRECTORY_SEPARATOR;
    }

    private function ensureBatchSpoolDir(): bool
    {
        $dir = $this->getBatchSpoolDir();
        if (\is_dir($dir)) {
            return true;
        }

        return @\mkdir($dir, 0755, true);
    }

    private function warnBottleneck(string $message): void
    {
        $now = \microtime(true);
        if (($now - $this->lastBottleneckWarnAt) < $this->bottleneckWarnInterval) {
            return;
        }

        $this->lastBottleneckWarnAt = $now;
        @\error_log('[WlsLogger] ' . $message);
    }

    private function spillBufferToBatchFile(string $reason): void
    {
        if ($this->buffer === '' || !$this->fileEnabled || $this->logDir === '') {
            return;
        }

        if (!$this->ensureBatchSpoolDir()) {
            $this->appendImmediateToMainLog($this->buffer, LogLevel::WARNING);
            $this->buffer = '';
            $this->bufferSize = 0;
            $this->bufferLineCount = 0;
            $this->warnBottleneck('batch spool dir create failed, fallback to direct append, reason=' . $reason);
            return;
        }

        if (\count($this->pendingBatchFiles) >= $this->maxPendingBatchFiles) {
            $this->appendImmediateToMainLog($this->buffer, LogLevel::WARNING);
            $this->buffer = '';
            $this->bufferSize = 0;
            $this->bufferLineCount = 0;
            $this->warnBottleneck(
                'pending batch files reached limit=' . $this->maxPendingBatchFiles
                . ', fallback to direct append, reason=' . $reason
            );
            return;
        }

        $file = $this->getBatchSpoolDir()
            . \date('Ymd-His')
            . '-'
            . \substr(\bin2hex(\random_bytes(4)), 0, 8)
            . '.batch.log';

        if (@\file_put_contents($file, $this->buffer, LOCK_EX) === false) {
            $this->appendImmediateToMainLog($this->buffer, LogLevel::WARNING);
            $this->warnBottleneck('spill batch write failed, fallback to direct append, reason=' . $reason);
        } else {
            $this->pendingBatchFiles[] = $file;
            $this->warnBottleneck(
                'spill buffer to batch file, reason=' . $reason
                . ', pending_batches=' . \count($this->pendingBatchFiles)
                . ', bytes=' . $this->bufferSize
            );
        }

        $this->buffer = '';
        $this->bufferSize = 0;
        $this->bufferLineCount = 0;
    }

    private function flushPendingBatchFiles(string $mainLog): void
    {
        if ($this->pendingBatchFiles === []) {
            return;
        }

        $remaining = [];
        foreach ($this->pendingBatchFiles as $file) {
            if (!\is_file($file)) {
                continue;
            }

            $content = @\file_get_contents($file);
            if ($content === false || $content === '') {
                @\unlink($file);
                continue;
            }

            if (@\file_put_contents($mainLog, $content, FILE_APPEND) === false) {
                $remaining[] = $file;
                $this->warnBottleneck('flush batch file failed, keep pending file: ' . \basename($file));
                continue;
            }

            $this->writeProcessLogMirror($content);
            @\unlink($file);
        }

        $this->pendingBatchFiles = $remaining;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = LogLevel::normalize($level);
        $this->ensureProcessTagResolved();

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
                // P2 观测性：内存压力下丢弃的日志也同步到 MetricsRegistry，
                // 与 ipc_dropped_bursty 一起构成"日志系统健康度"面板。
                if (\class_exists(\Weline\Server\Observability\MetricsRegistry::class, false)) {
                    \Weline\Server\Observability\MetricsRegistry::inc('wls.log.memory_pressure_dropped');
                }
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
                    // context 太大时，提取重点字段而不是直接丢弃
                    $keyFields = [
                        'exception_class',
                        'exception_message',
                        'exception_code',
                        'exception_file',
                        'exception_line',
                        '_exception_class',
                        '_exception_message',
                        '_exception_code',
                        '_exception_file',
                        '_exception_line',
                        '_previous_exception',
                        'message',
                        'error',
                    ];
                    $extracted = [];
                    foreach ($keyFields as $key) {
                        if (isset($context[$key]) && \strlen((string)$context[$key]) < 500) {
                            $extracted[$key] = $context[$key];
                        }
                    }
                    if (!empty($extracted)) {
                        $extracted['_truncated'] = true;
                        $extractedJson = @\json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                        if ($extractedJson !== false && \strlen($extractedJson) <= 4096) {
                            $contextStr = ' ' . $extractedJson;
                        } else {
                            $contextStr = ' [context too large: ' . \strlen($encoded) . ' bytes]';
                        }
                    } else {
                        $contextStr = ' [context too large: ' . \strlen($encoded) . ' bytes]';
                    }
                } else {
                    $contextStr = ' ' . $encoded;
                }
            }
        }
        $line = "[{$timestamp}] [{$this->processTag}] [{$level}] {$message}{$contextStr}\n";

        // IPC 上报给 Master（如果已连接；低优先级限流防风暴）
        $ipc = $this->evaluateIpcSink($level);
        if ($ipc['emit']) {
            ($this->ipcLogSink)($line, $level, $this->processTag);
        }

        // Worker 本地输出（stdout + 文件）
        if ($this->stdoutEnabled) {
            $this->writeStdout($line, $level);
        }

        if ($this->fileEnabled) {
            if ($ipc['low_pri_ipc_rate_limited']) {
                // 先刷出 buffer 内尚未落盘的行，再追加本行，避免「限流后直接写盘」跑到时间更早的缓冲行之前
                $this->flush(true);
                $this->appendImmediateToMainLog($line, $level);
            } else {
                $this->writeBuffer($line, $level);
            }
        } elseif (LogLevel::isAtLeast($level, LogLevel::ERROR)) {
            $this->writeErrorLog($line);
        }

        $this->writeDevDebugMirror($line);

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
        try {
            self::getInstance()->flush($force);
        } catch (\Throwable $e) {
            // 捕获并重新抛出，添加更多上下文
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $trace[1] ?? ['file' => 'unknown', 'line' => 0];
            error_log("[WlsLogger] flush_() 调用失败，调用者: {$caller['file']}:{$caller['line']}, 错误: {$e->getMessage()}");
            throw $e;
        }
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
        if ($this->bufferSize === 0 && $this->pendingBatchFiles === []) {
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
        $this->flushPendingBatchFiles($mainLog);
        if ($this->buffer !== '') {
            @\file_put_contents($mainLog, $this->buffer, FILE_APPEND);
            $this->writeProcessLogMirror($this->buffer);
        }

        // 日志轮转：清理过期日志文件
        $this->rotateOldLogs();

        // 重置所有计数器，防止内存泄漏
        $this->buffer = '';
        $this->bufferSize = 0;
        $this->bufferLineCount = 0;
        $this->lastFlushTime = \microtime(true);
    }

    private function writeDevDebugMirror(string $line): void
    {
        if ($this->devDebugLogFile === null || $line === '') {
            return;
        }

        $logDir = \dirname($this->devDebugLogFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        @\file_put_contents($this->devDebugLogFile, $line, FILE_APPEND);
    }

    private function writeProcessLogMirror(string $content): void
    {
        if ($this->processLogFile === null || $content === '') {
            return;
        }

        $logDir = \dirname($this->processLogFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        @\file_put_contents($this->processLogFile, $content, FILE_APPEND);
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
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int) \constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }
        $json = \json_encode($crashData, $flags);
        if ($json === false) {
            $json = \json_encode([
                'time' => $crashData['time'] ?? \date('Y-m-d H:i:s'),
                'process' => $crashData['process'] ?? $this->processTag,
                'pid' => $crashData['pid'] ?? \getmypid(),
                'error' => ['message' => 'crash_json_encode_failed', 'detail' => \json_last_error_msg()],
            ], $flags) ?: '{"error":"crash_json_encode_failed"}';
        }
        $maxCrashJsonBytes = 524288;
        $jsonLen = \strlen($json);
        if ($jsonLen > $maxCrashJsonBytes) {
            $json = \substr($json, 0, $maxCrashJsonBytes)
                . "\n…crash_json_truncated_total_len={$jsonLen}…\n";
        }
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
            $this->spillBufferToBatchFile('memory_pressure');
            $this->warnBottleneck(
                'memory pressure detected, usage=' . \round($memoryUsage / 1024 / 1024, 1)
                . 'MB limit=' . \round($memoryLimit / 1024 / 1024, 1) . 'MB'
            );
        }

        // 防止缓冲区无限增长：检查行数和字节数双重限制
        if ($this->bufferLineCount >= $this->maxBufferLines || $this->bufferSize >= $this->maxBufferBytes) {
            $this->spillBufferToBatchFile('buffer_threshold');
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
        if (!$this->fileEnabled) {
            return;
        }

        // Master 汇聚多子进程日志时若走内存 buffer，高频 IPC 下易与 EventsManager 等叠加撑爆 memory_limit。
        // 子进程行已带换行；直接落盘与 flush() 使用同一主日志文件，避免重复缓冲。
        if ($this->fileEnabled && $this->logDir !== '') {
            if (!\is_dir($this->logDir)) {
                @\mkdir($this->logDir, 0755, true);
            }
            $mainLog = $this->logDir . 'wls-' . \date('Y-m-d') . '.log';
            if (!\str_ends_with($line, "\n")) {
                $line .= "\n";
            }
            @\file_put_contents($mainLog, $line, FILE_APPEND);
            $this->writeDevDebugMirror($line);
            return;
        }

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
            foreach (self::$instance->pendingBatchFiles as $file) {
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
            self::$instance->pendingBatchFiles = [];
        }
        self::$instance = null;
        LogConfig::clearCache();
    }

    private function ensureProcessTagResolved(): void
    {
        if (!self::isUnknownProcessTag($this->processTag)) {
            return;
        }

        $resolvedTag = $this->resolveFallbackProcessTag();
        if ($resolvedTag === null) {
            return;
        }

        $this->setProcessTag($resolvedTag);
    }

    private function resolveFallbackProcessTag(): ?string
    {
        $candidates = [
            self::getProcessTagFromErrorContext(),
            self::getProcessTagFromEnvironment(),
            self::getProcessTagFromCli(),
            self::getProcessTagFromSapi(),
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeProcessTag($candidate);
            if ($normalized !== null && !self::isUnknownProcessTag($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private static function getProcessTagFromErrorContext(): ?string
    {
        if (!\class_exists(ErrorContext::class)) {
            return null;
        }

        $tag = ErrorContext::getProcessTag();
        return self::isUnknownProcessTag($tag) ? null : $tag;
    }

    private static function getProcessTagFromEnvironment(): ?string
    {
        $candidates = [
            \getenv('WLS_PROCESS_TAG'),
            $_ENV['WLS_PROCESS_TAG'] ?? null,
            $_SERVER['WLS_PROCESS_TAG'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && \trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function getProcessTagFromCli(): ?string
    {
        if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
            return null;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (!\is_array($argv)) {
            return 'CLI';
        }

        $arg0 = isset($argv[0]) && \is_string($argv[0]) ? $argv[0] : '';
        $scriptName = self::sanitizeCliProcessSegment(
            \pathinfo(\str_replace('\\', '/', $arg0), PATHINFO_FILENAME)
        );

        if (\in_array($scriptName, ['w', 'bin_w'], true)) {
            $command = self::findFirstCliCommand($argv);
            if ($command !== null) {
                return 'Cli:' . self::sanitizeCliProcessSegment($command);
            }

            return 'Cli:w';
        }

        if ($scriptName !== '' && \str_contains(\strtolower($scriptName), 'phpunit')) {
            return 'PHPUnit';
        }

        if ($scriptName !== '' && \str_contains(\strtolower($scriptName), 'pest')) {
            return 'Pest';
        }

        if (\defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return 'PHPUnit';
        }

        if ($scriptName !== '') {
            return 'Cli:' . $scriptName;
        }

        return 'CLI';
    }

    private static function findFirstCliCommand(array $argv): ?string
    {
        foreach (\array_slice($argv, 1) as $argument) {
            if (!\is_string($argument)) {
                continue;
            }

            $argument = \trim($argument);
            if ($argument === '' || \str_starts_with($argument, '-')) {
                continue;
            }

            return $argument;
        }

        return null;
    }

    private static function getProcessTagFromSapi(): ?string
    {
        return match (\PHP_SAPI) {
            'cli' => 'CLI',
            'phpdbg' => 'PHPDBG',
            default => null,
        };
    }

    private static function normalizeProcessTag(?string $tag): ?string
    {
        if (!\is_string($tag)) {
            return null;
        }

        $tag = \trim($tag);
        if ($tag === '') {
            return null;
        }

        return (string) (\preg_replace('/\s+/', '_', $tag) ?? $tag);
    }

    private static function sanitizeCliProcessSegment(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $value = (string) (\preg_replace('/[^A-Za-z0-9._:@#-]+/', '_', $value) ?? $value);
        return \trim($value, '._-');
    }

    private static function isUnknownProcessTag(?string $tag): bool
    {
        if (!\is_string($tag)) {
            return true;
        }

        $tag = \trim($tag);
        return $tag === '' || \strcasecmp($tag, 'Unknown') === 0;
    }
}
