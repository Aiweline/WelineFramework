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
 *    - 写入 var/log/wls_monitor.log（独立告警日志）
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

    /** 去重缓存文件 */
    private const SIGNATURE_CACHE = 'weline/cache/wls_error_last_signature.json';

    /** 告警日志 */
    private const ALERT_LOG = 'weline/log/wls_monitor.log';

    /** 任务池文件 */
    private const TASKS_FILE = 'dev/ai/agents/tasks.json';

    /** 去重有效期：秒（30分钟内同类错误不重复记录） */
    private const DEDUP_TTL_SECONDS = 1800;

    /** 每次最多写入任务数 */
    private const MAX_TASKS_PER_RUN = 50;

    public function execute(array $args = [], array $data = []): mixed
    {
        $verbose = isset($args['v']) || isset($args['verbose']);
        $dryRun = isset($args['dry-run']) || isset($args['dry_run']);

        if ($verbose) {
            $this->out('=== WLS 错误扫描开始 ===');
        }

        $signatureFile = $this->getSignatureCachePath();
        $alertLogPath = $this->getAlertLogPath();

        // 1. 收集所有目标日志文件的最新错误行
        $allErrors = $this->scanLogFiles($verbose);

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

    private function getSignatureCachePath(): string
    {
        return BP . '/var/' . self::SIGNATURE_CACHE;
    }

    private function getAlertLogPath(): string
    {
        return BP . '/var/' . self::ALERT_LOG;
    }

    private function getTaskPoolService(): void
    {
        // 已废弃：任务直接写入 tasks.json，不再依赖 AutoTaskGeneratorService
    }

    /**
     * 扫描所有目标日志文件，提取匹配错误模式的行
     *
     * @return array<int, array{file:string, line:int, type:string, message:string, timestamp:string, hash:string}>
     */
    private function scanLogFiles(bool $verbose): array
    {
        $errors = [];

        foreach (self::LOG_FILES as $logFile) {
            $filePath = BP . '/' . ltrim($logFile, '/');
            if (!file_exists($filePath)) {
                if ($verbose) {
                    $this->out('跳过不存在文件: ' . $logFile);
                }
                continue;
            }

            $content = @file_get_contents($filePath);
            if ($content === false || $content === '') {
                continue;
            }

            $lines = explode("\n", $content);
            $lineNumber = 0;
            foreach ($lines as $line) {
                $lineNumber++;
                foreach (self::ERROR_PATTERNS as $pattern) {
                    if (str_contains($line, $pattern)) {
                        $hash = md5($filePath . $lineNumber . $pattern . mb_substr($line, 0, 200));
                        $timestamp = $this->extractTimestamp($line);
                        $type = $this->classifyError($line);
                        $errors[] = [
                            'file' => $filePath,
                            'line' => $lineNumber,
                            'type' => $type,
                            'message' => trim($line),
                            'timestamp' => $timestamp,
                            'hash' => $hash,
                        ];
                        // 每行只匹配一次 pattern，避免同一条错误被多次添加
                        break;
                    }
                }
            }
        }

        return $errors;
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
            mkdir($dir, 0755, true);
        }

        $written = file_put_contents($signatureFile, json_encode($lastSignatures, JSON_UNESCAPED_UNICODE));
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
            mkdir($dir, 0755, true);
        }

        $lines = [];
        foreach ($newErrors as $err) {
            $lines[] = json_encode([
                'ts' => date('Y-m-d H:i:s'),
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
        $tasksFile = BP . '/' . self::TASKS_FILE;
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
        $this->saveTaskPool($tasksFile, $pool);

        if ($verbose) {
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
    private function saveTaskPool(string $tasksFile, array $pool): void
    {
        $dir = dirname($tasksFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($tasksFile, json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
