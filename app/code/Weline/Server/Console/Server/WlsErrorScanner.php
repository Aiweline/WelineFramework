<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;

/**
 * server:wls_error_scan - WLS 错误扫描命令
 *
 * 职责：
 * 1. 扫描 var/log/wls 下的 wls.log / exception.log / error.log / php_error.log
 * 2. 匹配 Fatal/ParseError/E_COMPILE_ERROR/TypeError/PDOException 等关键错误
 * 3. 与上次记录去重（hash 签名，缓存于 var/cache/wls_error_last_signature.json）
 * 4. 命中新错误时：
 *    - 写入 var/log/wls/wls_monitor.log（独立告警日志，与其它 WLS 日志同目录）
 *    - 调用 Agent_CursorSupervisor AutoTaskGeneratorService 生成修复任务
 */
class WlsErrorScanner extends CommandAbstract
{
    public const SIGNATURE = 'server:wls_error_scan';
    public const DESCRIPTION = 'WLS 错误扫描：扫描日志文件，检测 Fatal/ParseError/TypeError，生成修复任务';

    /** 监控的日志文件 */
    private const LOG_FILES = [
        'var/log/wls/wls.log',
        'var/log/exception.log',
        'var/log/php_error.log',
        'var/log/error.log',
    ];

    /** 关键错误匹配模式 */
    private const ERROR_PATTERNS = [
        'Fatal error',
        'ParseError',
        'E_COMPILE_ERROR',
        'TypeError',
        'Uncaught',
        'PDOException',
    ];

    /** 去重缓存文件（相对 var/） */
    private const SIGNATURE_CACHE = 'cache/wls_error_last_signature.json';

    private const CURSOR_CACHE = 'cache/wls_error_scan_cursors.json';

    /** 告警日志（相对 var/，与 WlsLogService 默认 var/log/wls 一致） */
    private const ALERT_LOG = 'log/wls/wls_monitor.log';

    /** 任务池文件 */
    private const TASKS_FILE = 'dev/ai/agents/tasks.json';

    /** 去重有效期：秒（30分钟内同类错误不重复记录） */
    private const DEDUP_TTL_SECONDS = 1800;

    /** 每次最多写入任务数 */
    private const MAX_TASKS_PER_RUN = 50;

    /** One invocation is deliberately bounded even when a log grows without limit. */
    private const SCAN_MAX_BYTES_PER_RUN = 32 * 1024 * 1024;
    private const SCAN_MAX_BYTES_PER_FILE = 8 * 1024 * 1024;
    private const SCAN_DEADLINE_SECONDS = 0.25;
    private const SCAN_READ_BYTES = 64 * 1024;
    private const SCAN_CAPTURE_BYTES = 8 * 1024;
    private const SCAN_PATTERN_TAIL_BYTES = 64;

    public function execute(array $args = [], array $data = []): mixed
    {
        $verbose = $this->isFlagEnabled($args, 'v', 'verbose');
        $dryRun = $this->isFlagEnabled($args, 'dry-run', 'dry_run');

        if ($verbose) {
            $this->out('=== WLS 错误扫描开始 ===');
        }

        $signatureFile = $this->getSignatureCachePath();
        $cursorFile = $this->getCursorCachePath();
        $alertLogPath = $this->getAlertLogPath();
        $cursors = $this->loadCursors($cursorFile);

        // 1. Collect newly appended error lines from target logs.
        $allErrors = $this->scanLogFiles($verbose, $cursors);

        if (!$dryRun) {
            $this->saveCursors($cursorFile, $cursors, $verbose);
        }

        if (empty($allErrors)) {
            if ($verbose) {
                $this->out('未检测到新的 WLS 关键错误。');
            }
            return 0;
        }

        // 2. 读取已有签名，进行去重
        $lastSignatures = $this->loadSignatures($signatureFile);
        $newErrors = $this->filterNewErrors($allErrors, $lastSignatures, $verbose);

        if (empty($newErrors)) {
            if ($verbose) {
                $this->out('所有错误均已在去重窗口内，跳过。');
            }
            return 0;
        }

        if ($dryRun) {
            $this->out('=== Dry Run：检测到 ' . count($newErrors) . ' 条新错误（未写入） ===');
            foreach ($newErrors as $err) {
                $this->out('  [' . $err['file'] . ':' . $err['line'] . '] ' . $err['type'] . ': ' . mb_substr($err['message'], 0, 120));
            }
            return 0;
        }

        // 3. 写入告警日志
        $this->writeAlertLog($alertLogPath, $newErrors, $verbose);

        // 4. 更新签名缓存
        $this->saveSignatures($signatureFile, $lastSignatures, $newErrors, $verbose);

        // 5. 生成修复任务（调用 AutoTaskGeneratorService）
        $this->generateTasks($newErrors, $verbose);

        $this->out('WLS 错误扫描完成，检测到 ' . count($newErrors) . ' 条新错误，已记录。');

        return 0;
    }

    protected function getSignatureCachePath(): string
    {
        return BP . '/var/' . self::SIGNATURE_CACHE;
    }

    protected function getCursorCachePath(): string
    {
        return BP . '/var/' . self::CURSOR_CACHE;
    }

    protected function getAlertLogPath(): string
    {
        return BP . '/var/' . self::ALERT_LOG;
    }

    protected function getTasksFilePath(): string
    {
        return BP . '/' . self::TASKS_FILE;
    }

    protected function getLogFiles(): array
    {
        return self::LOG_FILES;
    }

    protected function getScanByteBudget(): int
    {
        return self::SCAN_MAX_BYTES_PER_RUN;
    }

    protected function getPerFileScanByteBudget(): int
    {
        return self::SCAN_MAX_BYTES_PER_FILE;
    }

    protected function getScanDeadlineSeconds(): float
    {
        return self::SCAN_DEADLINE_SECONDS;
    }

    protected function getMaxErrorsPerRun(): int
    {
        return self::MAX_TASKS_PER_RUN;
    }

    private function isFlagEnabled(array $args, string ...$names): bool
    {
        foreach ($names as $name) {
            if (!array_key_exists($name, $args)) {
                continue;
            }
            $value = $args[$name];
            if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * 扫描所有目标日志文件，提取匹配错误模式的行
     *
     * @return array<int, array{file:string, line:int, type:string, message:string, timestamp:string, hash:string}>
     */
    private function scanLogFiles(bool $verbose, array &$cursors): array
    {
        $errors = [];
        $logFiles = \array_values($this->getLogFiles());
        $fileCount = \count($logFiles);
        if ($fileCount === 0) {
            return $errors;
        }

        $remainingRunBytes = \max(1, $this->getScanByteBudget());
        $deadline = \hrtime(true) + (int)(\max(0.001, $this->getScanDeadlineSeconds()) * 1_000_000_000);
        $maxErrors = \max(1, $this->getMaxErrorsPerRun());
        $meta = \is_array($cursors['_meta'] ?? null) ? $cursors['_meta'] : [];
        $startIndex = \max(0, (int)($meta['next_file_index'] ?? 0)) % $fileCount;

        for ($processedFiles = 0; $processedFiles < $fileCount; $processedFiles++) {
            $fileIndex = ($startIndex + $processedFiles) % $fileCount;
            if ($remainingRunBytes <= 0 || \hrtime(true) >= $deadline || \count($errors) >= $maxErrors) {
                $meta['next_file_index'] = $fileIndex;
                break;
            }

            $logFile = $logFiles[$fileIndex];
            $filePath = $this->resolveLogPath($logFile);
            if (!is_file($filePath)) {
                if ($verbose) {
                    $this->out('跳过不存在文件: ' . $logFile);
                }
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }

            \clearstatcache(true, $filePath);
            $stat = @\stat($filePath);
            if (!\is_array($stat)) {
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }
            $fileSize = \max(0, (int)($stat['size'] ?? 0));
            $mtime = \max(0, (int)($stat['mtime'] ?? \time()));
            $inode = \max(0, (int)($stat['ino'] ?? 0));
            $device = \max(0, (int)($stat['dev'] ?? 0));
            $cursorKey = md5($filePath);
            $cursor = $cursors[$cursorKey] ?? null;

            if (!is_array($cursor)) {
                $cursors[$cursorKey] = [
                    'path' => $filePath,
                    'size' => $fileSize,
                    'offset' => $fileSize,
                    'mtime' => $mtime,
                    'line' => 0,
                    'inode' => $inode,
                    'device' => $device,
                    'line_origin' => 'cursor_bootstrap',
                ];
                if ($verbose) {
                    $this->out('WLS error scan cursor initialized: ' . $logFile);
                }
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }

            $offset = max(0, (int)($cursor['offset'] ?? $cursor['size'] ?? 0));
            $lineNumber = max(0, (int)($cursor['line'] ?? 0));
            $partial = $this->normalizePartialLine($cursor['partial'] ?? null);
            $rotated = $fileSize < $offset
                || ($inode > 0 && isset($cursor['inode']) && (int)$cursor['inode'] !== $inode)
                || ($device > 0 && isset($cursor['device']) && (int)$cursor['device'] !== $device);

            if ($rotated) {
                $offset = 0;
                $lineNumber = 0;
                $partial = $this->emptyPartialLine();
                if ($verbose) {
                    $this->out('WLS error scan log rotated/truncated: ' . $logFile);
                }
            }

            if ($fileSize === $offset) {
                $cursors[$cursorKey] = $this->buildCursor(
                    $filePath,
                    $offset,
                    $mtime,
                    $lineNumber,
                    $inode,
                    $device,
                    $partial,
                    (string)($cursor['line_origin'] ?? 'file_start'),
                );
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }

            $handle = @fopen($filePath, 'rb');
            if ($handle === false) {
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }
            if ($offset > 0 && @fseek($handle, $offset) !== 0) {
                fclose($handle);
                $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
                continue;
            }

            $remainingFileBytes = \min(
                \max(1, $this->getPerFileScanByteBudget()),
                $fileSize - $offset,
            );
            while ($remainingFileBytes > 0
                && $remainingRunBytes > 0
                && $offset < $fileSize
                && \count($errors) < $maxErrors
                && \hrtime(true) < $deadline
            ) {
                $readBytes = \min(
                    self::SCAN_READ_BYTES,
                    $remainingFileBytes,
                    $remainingRunBytes,
                    $fileSize - $offset,
                );
                if ($readBytes <= 0) {
                    break;
                }

                // fgets with a fixed maximum keeps memory bounded even when a
                // corrupt log contains a multi-megabyte line without a LF.
                $fragment = @fgets($handle, $readBytes + 1);
                if (!\is_string($fragment) || $fragment === '') {
                    break;
                }
                $fragmentBytes = \strlen($fragment);
                $offset += $fragmentBytes;
                $remainingFileBytes -= $fragmentBytes;
                $remainingRunBytes -= $fragmentBytes;
                $complete = \str_ends_with($fragment, "\n");
                $content = $complete ? \substr($fragment, 0, -1) : $fragment;
                if ($complete && \str_ends_with($content, "\r")) {
                    $content = \substr($content, 0, -1);
                }
                $partial = $this->appendPartialLine($partial, $content);

                if (!$complete) {
                    continue;
                }

                $lineNumber++;
                $pattern = $partial['pattern'];
                if (\is_string($pattern) && $pattern !== '') {
                    $message = \trim($partial['capture']);
                    if ($partial['bytes'] > \strlen($partial['capture'])) {
                        $message .= ' … [line truncated]';
                    }
                    $errors[] = [
                        'file' => $filePath,
                        'line' => $lineNumber,
                        'type' => $this->classifyError($pattern),
                        'message' => $message,
                        'timestamp' => $this->extractTimestamp($partial['capture']),
                        'hash' => md5($filePath . $lineNumber . $pattern . \substr($partial['capture'], 0, 200)),
                    ];
                }
                $partial = $this->emptyPartialLine();
            }
            fclose($handle);

            $cursors[$cursorKey] = $this->buildCursor(
                $filePath,
                $offset,
                $mtime,
                $lineNumber,
                $inode,
                $device,
                $partial,
                $rotated ? 'file_start' : (string)($cursor['line_origin'] ?? 'file_start'),
            );
            $meta['next_file_index'] = ($fileIndex + 1) % $fileCount;
        }

        $cursors['_meta'] = $meta + ['next_file_index' => 0];

        return $errors;
    }

    /** @return array{capture:string,tail:string,pattern:?string,bytes:int} */
    private function emptyPartialLine(): array
    {
        return ['capture' => '', 'tail' => '', 'pattern' => null, 'bytes' => 0];
    }

    /** @return array{capture:string,tail:string,pattern:?string,bytes:int} */
    private function normalizePartialLine(mixed $value): array
    {
        if (!\is_array($value)) {
            return $this->emptyPartialLine();
        }
        $pattern = \is_string($value['pattern'] ?? null) && \in_array($value['pattern'], self::ERROR_PATTERNS, true)
            ? $value['pattern']
            : null;
        $capture = \substr((string)($value['capture'] ?? ''), 0, self::SCAN_CAPTURE_BYTES);
        $tail = \substr((string)($value['tail'] ?? ''), -self::SCAN_PATTERN_TAIL_BYTES);

        return [
            'capture' => $capture,
            'tail' => $tail,
            'pattern' => $pattern,
            'bytes' => \max(\strlen($capture), (int)($value['bytes'] ?? 0)),
        ];
    }

    /**
     * @param array{capture:string,tail:string,pattern:?string,bytes:int} $partial
     * @return array{capture:string,tail:string,pattern:?string,bytes:int}
     */
    private function appendPartialLine(array $partial, string $fragment): array
    {
        if ($partial['pattern'] === null) {
            $subject = $partial['tail'] . $fragment;
            foreach (self::ERROR_PATTERNS as $pattern) {
                if (\str_contains($subject, $pattern)) {
                    $partial['pattern'] = $pattern;
                    break;
                }
            }
        }
        $captureRemaining = self::SCAN_CAPTURE_BYTES - \strlen($partial['capture']);
        if ($captureRemaining > 0) {
            $partial['capture'] .= \substr($fragment, 0, $captureRemaining);
        }
        $partial['bytes'] += \strlen($fragment);
        $partial['tail'] = \substr($partial['tail'] . $fragment, -self::SCAN_PATTERN_TAIL_BYTES);

        return $partial;
    }

    /**
     * @param array{capture:string,tail:string,pattern:?string,bytes:int} $partial
     * @return array<string, mixed>
     */
    private function buildCursor(
        string $filePath,
        int $offset,
        int $mtime,
        int $lineNumber,
        int $inode,
        int $device,
        array $partial,
        string $lineOrigin,
    ): array {
        $cursor = [
            'path' => $filePath,
            'size' => $offset,
            'offset' => $offset,
            'mtime' => $mtime,
            'line' => $lineNumber,
            'inode' => $inode,
            'device' => $device,
            'line_origin' => $lineOrigin,
        ];
        if ($partial['bytes'] > 0) {
            $cursor['partial'] = $partial;
        }

        return $cursor;
    }

    private function resolveLogPath(string $logFile): string
    {
        $normalized = str_replace('\\', '/', $logFile);
        if (preg_match('/^[A-Za-z]:\//', $normalized) || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        return BP . '/' . ltrim($normalized, '/');
    }

    /**
     * 从日志行提取时间戳
     */
    private function extractTimestamp(string $line): string
    {
        // 典型格式：[2026-03-31 04:40:34.089643]
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2})/', $line, $m)) {
            return $m[1];
        }
        if (preg_match('/^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) ([A-Z]{2,4})\]/', $line, $m)) {
            $timezone = match ($m[2]) {
                'PRC' => 'Asia/Shanghai',
                'UTC', 'GMT' => 'UTC',
                default => $m[2],
            };
            try {
                return (new \DateTimeImmutable($m[1], new \DateTimeZone($timezone)))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
            }
        }
        return date('Y-m-d H:i:s');
    }

    /**
     * 对错误行进行分类
     */
    private function classifyError(string $line): string
    {
        foreach (self::ERROR_PATTERNS as $pattern) {
            if (str_contains($line, $pattern)) {
                return match ($pattern) {
                    'Fatal error' => 'Fatal',
                    'ParseError' => 'ParseError',
                    'E_COMPILE_ERROR' => 'E_COMPILE_ERROR',
                    'TypeError' => 'TypeError',
                    'Uncaught' => 'Uncaught',
                    'PDOException' => 'PDOException',
                    default => 'Unknown',
                };
            }
        }
        return 'Unknown';
    }

    /**
     * 加载已有签名
     *
     * @return array<string, array{hash:string, time:int}>
     */
    private function loadSignatures(string $signatureFile): array
    {
        if (!file_exists($signatureFile)) {
            return [];
        }
        $content = @file_get_contents($signatureFile);
        if ($content === false || $content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        // 清理过期签名
        $now = time();
        $valid = [];
        foreach ($decoded as $hash => $entry) {
            if (isset($entry['time']) && ($now - (int)$entry['time']) < self::DEDUP_TTL_SECONDS) {
                $valid[$hash] = $entry;
            }
        }
        return $valid;
    }

    private function loadCursors(string $cursorFile): array
    {
        if (!is_file($cursorFile)) {
            return [];
        }
        $content = @file_get_contents($cursorFile);
        if ($content === false || $content === '') {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveCursors(string $cursorFile, array $cursors, bool $verbose): void
    {
        $dir = dirname($cursorFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $written = @file_put_contents($cursorFile, json_encode($cursors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($verbose && $written === false) {
            $this->out('WLS error scan cursor write failed: ' . $cursorFile);
        }
    }

    /**
     * 过滤出真正的最新错误
     */
    private function filterNewErrors(array $allErrors, array $lastSignatures, bool $verbose): array
    {
        $newErrors = [];
        foreach ($allErrors as $err) {
            if (!isset($lastSignatures[$err['hash']])) {
                $newErrors[] = $err;
            } elseif ($verbose) {
                $this->out('去重命中: ' . mb_substr($err['message'], 0, 80));
            }
        }
        return $newErrors;
    }

    /**
     * 保存新错误的签名
     */
    private function saveSignatures(string $signatureFile, array $lastSignatures, array $newErrors, bool $verbose): void
    {
        $now = time();
        foreach ($newErrors as $err) {
            $lastSignatures[$err['hash']] = [
                'hash' => $err['hash'],
                'time' => $now,
                'type' => $err['type'],
                'file' => $err['file'],
            ];
        }

        $dir = dirname($signatureFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $written = @file_put_contents($signatureFile, json_encode($lastSignatures, JSON_UNESCAPED_UNICODE));
        if ($verbose && $written !== false) {
            $this->out('签名缓存已更新: ' . count($lastSignatures) . ' 条');
        }
    }

    /**
     * 写入告警日志
     */
    private function writeAlertLog(string $alertLogPath, array $newErrors, bool $verbose): void
    {
        $dir = dirname($alertLogPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $lines = [];
        foreach ($newErrors as $err) {
            $lines[] = json_encode([
                'ts' => $err['timestamp'] ?: date('Y-m-d H:i:s'),
                'detected_at' => date('Y-m-d H:i:s'),
                'type' => $err['type'],
                'source' => $err['file'] . ':' . $err['line'],
                'msg' => mb_substr($err['message'], 0, 300),
                'hash' => $err['hash'],
            ], JSON_UNESCAPED_UNICODE);
        }

        $content = implode("\n", $lines) . "\n";
        $written = @file_put_contents($alertLogPath, $content, FILE_APPEND | LOCK_EX);

        if ($verbose && $written !== false) {
            $this->out('告警日志已写入: ' . $alertLogPath . ' (' . count($newErrors) . ' 条)');
        }
    }

    /**
     * 将新错误直接写入 tasks.json，带 error_timestamp 实现有序消费
     *
     * @param array $newErrors
     * @param bool $verbose
     */
    private function generateTasks(array $newErrors, bool $verbose): void
    {
        $tasksFile = $this->getTasksFilePath();
        $pool = $this->loadTaskPool($tasksFile);

        // 确保 agents 节点存在
        if (!isset($pool['agents'])) {
            $pool['agents'] = [];
        }
        if (!isset($pool['completed'])) {
            $pool['completed'] = [];
        }
        if (!isset($pool['failed'])) {
            $pool['failed'] = [];
        }

        $added = 0;
        $limit = self::MAX_TASKS_PER_RUN;

        foreach ($newErrors as $err) {
            if ($added >= $limit) {
                break;
            }

            // 提取源码文件
            $sourceFile = $this->resolveLogSourceFile($err);
            $fileLabel = $sourceFile ?? $err['file'] . ':' . $err['line'];

            // 生成唯一 agentId：wls_fix_{hash前8位}
            $agentId = 'wls_fix_' . substr($err['hash'], 0, 8);

            // 避免重复写入同一 agentId
            if (isset($pool['agents'][$agentId])) {
                continue;
            }

            // 构建任务描述
            $desc = sprintf(
                '[WLS] %s @ %s:%d | %s',
                $err['type'],
                $err['file'],
                $err['line'],
                mb_substr(trim(strip_tags($err['message'])), 0, 120)
            );

            $pool['agents'][$agentId] = [
                'id' => $agentId,
                'file' => $sourceFile ?? '',
                'description' => $desc,
                'status' => 'todo',
                'dep' => null,
                'priority' => $this->classifyPriority($err['type']),
                'created_at' => date('Y-m-d H:i:s'),
                'error_timestamp' => $err['timestamp'],
                'error_hash' => $err['hash'],
                'error_type' => $err['type'],
                'error_source' => $err['file'] . ':' . $err['line'],
                'started_at' => null,
                'completed_at' => null,
                'error' => null,
                'retries' => 0,
            ];

            $added++;
            if ($verbose) {
                $this->out('写入任务: ' . $agentId . ' - ' . mb_substr($desc, 0, 80));
            }
        }

        $pool['updated_at'] = date('Y-m-d H:i:s');
        $saved = $this->saveTaskPool($tasksFile, $pool);

        if ($verbose && !$saved) {
            $this->out('WLS error scan task write failed: ' . $tasksFile);
        }
        if ($verbose && $saved) {
            $this->out('任务写入完成: ' . $added . ' 条');
        }
    }

    /**
     * 根据错误类型分类优先级
     */
    private function classifyPriority(string $errorType): string
    {
        return match ($errorType) {
            'Fatal', 'E_COMPILE_ERROR' => 'critical',
            'ParseError', 'TypeError', 'Uncaught' => 'high',
            'PDOException' => 'high',
            default => 'normal',
        };
    }

    /**
     * 加载 tasks.json
     */
    private function loadTaskPool(string $tasksFile): array
    {
        if (!file_exists($tasksFile)) {
            return $this->defaultPool();
        }
        $content = @file_get_contents($tasksFile);
        if ($content === false || $content === '') {
            return $this->defaultPool();
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $this->defaultPool();
    }

    /**
     * 保存 tasks.json
     */
    private function saveTaskPool(string $tasksFile, array $pool): bool
    {
        $dir = dirname($tasksFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return @file_put_contents($tasksFile, json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    }

    /**
     * 默认任务池结构
     */
    private function defaultPool(): array
    {
        return [
            'project' => basename(BP),
            'version' => '1.0',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'master' => ['status' => 'idle', 'last_task' => null, 'model' => 'deepseek'],
            'agents' => [],
            'completed' => [],
            'failed' => [],
        ];
    }

    /**
     * 从错误信息反推源码文件路径
     */
    private function resolveLogSourceFile(array $err): ?string
    {
        $message = $err['message'];
        if (preg_match('/([A-Za-z]:\\\\[^\s\:]+\.php)/', $message, $m)) {
            $path = str_replace(['\\\\', '\\'], DIRECTORY_SEPARATOR, $m[1]);
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    private function out(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
