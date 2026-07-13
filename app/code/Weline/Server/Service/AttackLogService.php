<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击日志记录服务
 * 
 * 将攻击检测结果持久化到数据库
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\AttackLog;

/**
 * 攻击日志记录服务
 * 
 * 提供静态方法用于记录攻击，适用于 WLS 常驻内存环境
 */
class AttackLogService
{
    private const DEFAULT_MAX_BUFFER_SIZE = 512;
    private const DEFAULT_FLUSH_BATCH_SIZE = 16;
    private const MAX_FLUSH_BACKOFF_SECONDS = 60;
    private const DEFAULT_SHUTDOWN_BUDGET_MS = 25;
    private const DEFAULT_SHUTDOWN_MAX_RECORDS = 8;
    private const REDACTED_HEADER_VALUE = '[REDACTED]';

    /**
     * 是否启用数据库日志
     */
    private static bool $enabled = true;
    
    /**
     * 内存缓冲区（用于批量写入）
     * @var array<int, array>
     */
    private static array $buffer = [];

    /** Ring buffer head and live record count. */
    private static int $bufferHead = 0;
    private static int $bufferCount = 0;

    /** Hard memory bound; a full ring overwrites the oldest record. */
    private static int $maxBufferSize = self::DEFAULT_MAX_BUFFER_SIZE;

    /** Maximum records persisted by one lifecycle/idle tick. */
    private static int $flushBatchSize = self::DEFAULT_FLUSH_BATCH_SIZE;

    private static int $droppedCount = 0;
    private static int $consecutiveFlushFailures = 0;
    private static float $nextFlushAt = 0.0;
    private static bool $flushInProgress = false;
    
    /**
     * 缓冲区大小（达到此数量时批量写入）
     */
    private static int $bufferSize = 10;
    
    /**
     * 上次刷新时间
     */
    private static int $lastFlushTime = 0;
    
    /**
     * 刷新间隔（秒）
     */
    private static int $flushInterval = 30;
    
    /**
     * 攻击日志模型实例（单例）
     */
    private static ?AttackLog $model = null;
    
    /**
     * 记录攻击
     * 
     * @param array $detection 检测结果（来自 AttackDetector::detect）
     * @param array $requestInfo 请求信息
     */
    public static function log(array $detection, array $requestInfo = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        // 只记录攻击
        if (!($detection['is_attack'] ?? false)) {
            return;
        }
        
        $logData = self::buildLogData($detection, $requestInfo);
        
        // O(1) bounded ring write. Attack request evaluation never performs ORM,
        // file I/O or synchronous IPC in persistent WLS processes.
        self::enqueue($logData);
        
        // 检查是否需要刷新
        $now = \time();
        if (self::$lastFlushTime === 0) {
            self::$lastFlushTime = $now;
        }
        $shouldFlush = (
            self::$bufferCount >= self::$bufferSize ||
            ($now - self::$lastFlushTime >= self::$flushInterval && self::$bufferCount > 0)
        );
        
        // Persistent WLS request paths must never run ORM/file I/O while
        // evaluating an attack. Worker/Dispatcher lifecycle ticks call
        // flushIfDue() after the response or during idle time.
        $persistent = \defined('WLS_MODE') && WLS_MODE;
        if ($shouldFlush && !$persistent) {
            self::flush();
        }
    }

    /**
     * Flush a due batch from a lifecycle/idle tick, never from policy match.
     */
    public static function flushIfDue(bool $force = false): bool
    {
        if (self::$bufferCount === 0 || self::$flushInProgress) {
            return false;
        }
        $now = \microtime(true);
        if (!$force && $now < self::$nextFlushAt) {
            return false;
        }
        $due = $force
            || self::$bufferCount >= self::$bufferSize
            || (\time() - self::$lastFlushTime) >= self::$flushInterval;
        if (!$due) {
            return false;
        }
        return self::flushBatch(self::$flushBatchSize) > 0;
    }
    
    /**
     * 刷新缓冲区到数据库
     */
    public static function flush(): void
    {
        if (self::$bufferCount === 0 || self::$flushInProgress || \microtime(true) < self::$nextFlushAt) {
            return;
        }
        self::flushBatch(self::$flushBatchSize);
    }

    /**
     * Best-effort final drain. Work is bounded by record count and a soft wall
     * clock budget; it never retries a database failure during process exit.
     */
    public static function flushForShutdown(
        int $budgetMs = self::DEFAULT_SHUTDOWN_BUDGET_MS,
        int $maxRecords = self::DEFAULT_SHUTDOWN_MAX_RECORDS
    ): int {
        if (
            self::$bufferCount === 0
            || self::$flushInProgress
            // Never bootstrap a database connection while the process is
            // already exiting; only reuse a model proven by an idle flush.
            || self::$model === null
            || \microtime(true) < self::$nextFlushAt
        ) {
            return 0;
        }

        $deadline = \microtime(true) + (\max(1, $budgetMs) / 1000);
        $remaining = \max(1, $maxRecords);
        $flushed = 0;
        while (
            $remaining > 0
            && self::$bufferCount > 0
            && \microtime(true) < $deadline
            && \microtime(true) >= self::$nextFlushAt
        ) {
            $count = self::flushBatch(\min(self::$flushBatchSize, $remaining), $deadline);
            if ($count <= 0) {
                break;
            }
            $flushed += $count;
            $remaining -= $count;
        }
        return $flushed;
    }

    /**
     * Persist at most one bounded batch. Successfully written rows are removed
     * even when a later row fails, preventing duplicate replay after recovery.
     */
    private static function flushBatch(int $limit, ?float $deadline = null): int
    {
        if (self::$bufferCount === 0 || self::$flushInProgress || $limit <= 0) {
            return 0;
        }

        self::$flushInProgress = true;
        $batch = self::peekBatch($limit);
        $persisted = 0;
        try {
            $model = self::getModel();

            foreach ($batch as $logData) {
                if ($deadline !== null && \microtime(true) >= $deadline) {
                    break;
                }
                $model->clearQuery()->logAttack($logData);
                $persisted++;
            }

            if ($persisted > 0) {
                self::discard($persisted);
                self::$lastFlushTime = \time();
                self::$consecutiveFlushFailures = 0;
                self::$nextFlushAt = 0.0;
            }
        } catch (\Throwable $e) {
            if ($persisted > 0) {
                self::discard($persisted);
                self::$lastFlushTime = \time();
            }
            self::$consecutiveFlushFailures++;
            $exponent = \min(6, self::$consecutiveFlushFailures - 1);
            $delay = \min(self::MAX_FLUSH_BACKOFF_SECONDS, 1 << $exponent);
            self::$nextFlushAt = \microtime(true) + $delay;
            // Do not retain a potentially broken connection/model across retries.
            self::$model = null;

            // Failure logging occurs only on the idle lifecycle tick and is
            // naturally throttled by the exponential retry deadline.
            $first = self::peekBatch(1)[0] ?? ($batch[$persisted] ?? []);
            self::logToFile($e->getMessage(), \is_string($first['instance'] ?? null) ? $first['instance'] : null);
        } finally {
            self::$flushInProgress = false;
        }

        return $persisted;
    }
    
    /**
     * 直接记录（不使用缓冲区）
     * 
     * @param array $detection 检测结果
     * @param array $requestInfo 请求信息
     */
    public static function logImmediately(array $detection, array $requestInfo = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        if (!($detection['is_attack'] ?? false)) {
            return;
        }
        
        try {
            $logData = self::buildLogData($detection, $requestInfo);
            
            self::getModel()->clearQuery()->logAttack($logData);
        } catch (\Throwable $e) {
            self::logToFile(
                $e->getMessage(),
                \is_string($requestInfo['instance'] ?? null) ? (string)$requestInfo['instance'] : null
            );
        }
    }
    
    /**
     * 获取模型实例
     */
    private static function getModel(): AttackLog
    {
        if (self::$model === null) {
            self::$model = ObjectManager::getInstance(AttackLog::class);
        }
        return self::$model;
    }

    /** @param array<string, mixed> $logData */
    private static function enqueue(array $logData): void
    {
        if (self::$bufferCount < self::$maxBufferSize) {
            $index = (self::$bufferHead + self::$bufferCount) % self::$maxBufferSize;
            self::$buffer[$index] = $logData;
            self::$bufferCount++;
            return;
        }

        // Full ring: keep the newest security signal without growing memory.
        self::$buffer[self::$bufferHead] = $logData;
        self::$bufferHead = (self::$bufferHead + 1) % self::$maxBufferSize;
        self::$droppedCount++;
    }

    /** @return array<int, array<string, mixed>> */
    private static function peekBatch(int $limit): array
    {
        $count = \min(\max(0, $limit), self::$bufferCount);
        $batch = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $index = (self::$bufferHead + $offset) % self::$maxBufferSize;
            if (isset(self::$buffer[$index])) {
                $batch[] = self::$buffer[$index];
            }
        }
        return $batch;
    }

    private static function discard(int $count): void
    {
        $count = \min(\max(0, $count), self::$bufferCount);
        for ($offset = 0; $offset < $count; $offset++) {
            $index = (self::$bufferHead + $offset) % self::$maxBufferSize;
            unset(self::$buffer[$index]);
        }
        self::$bufferCount -= $count;
        if (self::$bufferCount === 0) {
            self::$buffer = [];
            self::$bufferHead = 0;
            return;
        }
        self::$bufferHead = (self::$bufferHead + $count) % self::$maxBufferSize;
    }

    private static function boundedString(mixed $value, int $maxLength): string
    {
        if (!\is_scalar($value) && $value !== null) {
            $encoded = @\json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = \is_string($encoded) ? $encoded : '';
        }
        return \substr((string)$value, 0, \max(0, $maxLength));
    }

    /**
     * Both buffered and immediate persistence pass through this boundary so a
     * fallback path cannot accidentally retain credentials that the ring path
     * redacts.
     *
     * @return array<string, mixed>
     */
    private static function buildLogData(array $detection, array $requestInfo): array
    {
        return [
            'instance' => self::boundedString($requestInfo['instance'] ?? 'default', 50),
            'attack_type' => self::boundedString($detection['type'] ?? 'unknown', 50),
            'ip' => self::boundedString($requestInfo['ip'] ?? '', 45),
            'domain' => self::boundedString($requestInfo['domain'] ?? '', 255),
            'uri' => self::boundedString($requestInfo['uri'] ?? '', 2000),
            'method' => self::boundedString($requestInfo['method'] ?? 'GET', 10),
            'user_agent' => self::boundedString($requestInfo['user_agent'] ?? '', 500),
            'reason' => self::boundedString($detection['reason'] ?? '', 500),
            'blocked' => (bool)($detection['should_block'] ?? true),
            'block_duration' => (int)($requestInfo['block_duration'] ?? 0),
            'request_count' => (int)($requestInfo['request_count'] ?? 1),
            'unique_paths' => (int)($requestInfo['unique_paths'] ?? 0),
            'extra_data' => [
                'headers' => self::boundedHeaders($requestInfo['headers'] ?? []),
                'detection_time' => \microtime(true),
            ],
        ];
    }

    /** @return array<string, string> */
    private static function boundedHeaders(mixed $headers): array
    {
        if (!\is_array($headers)) {
            return [];
        }

        $bounded = [];
        foreach ($headers as $name => $value) {
            if (\count($bounded) >= 24) {
                break;
            }
            $headerName = self::boundedString($name, 128);
            if ($headerName === '') {
                continue;
            }
            $bounded[$headerName] = self::isSensitiveHeaderName($headerName)
                ? self::REDACTED_HEADER_VALUE
                : self::boundedString($value, 1024);
        }
        return $bounded;
    }

    private static function isSensitiveHeaderName(string $name): bool
    {
        $normalized = \preg_replace(
            '/[^a-z0-9]+/',
            '-',
            \strtolower(\trim($name)),
        ) ?? '';
        $normalized = \trim($normalized, '-');
        if (\in_array($normalized, [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-weline-origin-token',
            'x-api-key',
        ], true)) {
            return true;
        }

        return \preg_match(
            '/(?:^|-)(?:auth|authorization|authentication|token|secret|credential|password|passwd|api-key|apikey|access-key|client-key|consumer-key|private-key|session-key|key)(?:$|-)/D',
            $normalized,
        ) === 1;
    }
    
    /**
     * 写入文件日志（备份）
     */
    private static function logToFile(string $error, ?string $instance = null): void
    {
        $logDir = WlsLogService::getLogDir($instance);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DS . 'attack_log_error.log';
        $message = \date('Y-m-d H:i:s') . ' [ERROR] ' . $error . "\n";
        $message .= 'Buffer count: ' . self::$bufferCount . "\n";
        $message .= 'Dropped count: ' . self::$droppedCount . "\n";
        $message .= 'Consecutive failures: ' . self::$consecutiveFlushFailures . "\n";
        $message .= "---\n";
        
        @\file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 启用/禁用数据库日志
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * 是否启用
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * 设置缓冲区大小
     */
    public static function setBufferSize(int $size): void
    {
        self::$bufferSize = \max(1, $size);
    }

    /**
     * Configure the hard ring capacity. Shrinking retains the newest records.
     */
    public static function setMaxBufferSize(int $size): void
    {
        $size = \max(1, $size);
        if ($size === self::$maxBufferSize) {
            return;
        }

        $records = self::peekBatch(self::$bufferCount);
        if (\count($records) > $size) {
            $drop = \count($records) - $size;
            self::$droppedCount += $drop;
            $records = \array_slice($records, -$size);
        }

        self::$buffer = [];
        self::$bufferHead = 0;
        self::$bufferCount = 0;
        self::$maxBufferSize = $size;
        foreach ($records as $record) {
            self::enqueue($record);
        }
    }

    public static function setFlushBatchSize(int $size): void
    {
        self::$flushBatchSize = \max(1, $size);
    }
    
    /**
     * 设置刷新间隔
     */
    public static function setFlushInterval(int $seconds): void
    {
        self::$flushInterval = \max(1, $seconds);
    }
    
    /**
     * 获取缓冲区数量
     */
    public static function getBufferCount(): int
    {
        return self::$bufferCount;
    }

    public static function getDroppedCount(): int
    {
        return self::$droppedCount;
    }

    /** @return array<string, int|float> */
    public static function getBufferStats(): array
    {
        return [
            'buffered' => self::$bufferCount,
            'capacity' => self::$maxBufferSize,
            'dropped' => self::$droppedCount,
            'flush_failures' => self::$consecutiveFlushFailures,
            'next_flush_at' => self::$nextFlushAt,
        ];
    }
    
    /**
     * 重置（用于测试）
     */
    public static function reset(): void
    {
        self::$buffer = [];
        self::$bufferHead = 0;
        self::$bufferCount = 0;
        self::$droppedCount = 0;
        self::$consecutiveFlushFailures = 0;
        self::$nextFlushAt = 0.0;
        self::$flushInProgress = false;
        self::$lastFlushTime = 0;
        self::$model = null;
    }
}
