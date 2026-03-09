<?php
declare(strict_types=1);

/**
 * WLS 日志聚合器
 *
 * 聚合来自多个子进程的日志，统一格式化后写入日志文件。
 * 主要处理 PipeCapture 捕获的 stdout/stderr 输出。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Master;

use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogConfig;

class LogAggregator
{
    /**
     * 进程标签映射 [id => tag]
     */
    private array $processTags = [];

    /**
     * 是否输出到终端
     */
    private bool $stdoutEnabled = true;

    /**
     * 错误关键词（用于识别 stderr 中的错误级别）
     */
    private const ERROR_PATTERNS = [
        'fatal' => LogLevel::FATAL,
        'Fatal' => LogLevel::FATAL,
        'FATAL' => LogLevel::FATAL,
        'E_ERROR' => LogLevel::FATAL,
        'E_PARSE' => LogLevel::FATAL,
        'Parse error' => LogLevel::FATAL,
        'Segmentation fault' => LogLevel::FATAL,
        'Segfault' => LogLevel::FATAL,
        'OOM' => LogLevel::FATAL,
        'Out of memory' => LogLevel::FATAL,
        'Allowed memory size' => LogLevel::FATAL,
        'Maximum execution time' => LogLevel::FATAL,
        'error' => LogLevel::ERROR,
        'Error' => LogLevel::ERROR,
        'ERROR' => LogLevel::ERROR,
        'exception' => LogLevel::ERROR,
        'Exception' => LogLevel::ERROR,
        'EXCEPTION' => LogLevel::ERROR,
        'warning' => LogLevel::WARNING,
        'Warning' => LogLevel::WARNING,
        'WARNING' => LogLevel::WARNING,
        'notice' => LogLevel::NOTICE,
        'Notice' => LogLevel::NOTICE,
        'NOTICE' => LogLevel::NOTICE,
        'deprecated' => LogLevel::NOTICE,
        'Deprecated' => LogLevel::NOTICE,
        'DEPRECATED' => LogLevel::NOTICE,
    ];

    public function __construct(bool $stdoutEnabled = true)
    {
        $this->stdoutEnabled = $stdoutEnabled;
    }

    /**
     * 设置进程标签
     */
    public function setProcessTag(string $id, string $tag): void
    {
        $this->processTags[$id] = $tag;
    }

    /**
     * 获取进程标签
     */
    public function getProcessTag(string $id): string
    {
        return $this->processTags[$id] ?? $id;
    }

    /**
     * 移除进程标签
     */
    public function removeProcessTag(string $id): void
    {
        unset($this->processTags[$id]);
    }

    /**
     * 设置是否输出到终端
     */
    public function setStdoutEnabled(bool $enabled): void
    {
        $this->stdoutEnabled = $enabled;
    }

    /**
     * 收集并处理子进程输出
     *
     * @param string $id 进程标识
     * @param string $content 输出内容
     * @param bool $isStderr 是否为 stderr（影响默认日志级别）
     */
    public function collect(string $id, string $content, bool $isStderr = true): void
    {
        if (\trim($content) === '') {
            return;
        }

        $tag = $this->getProcessTag($id);
        $timestamp = \date('Y-m-d H:i:s');

        // 按行处理
        $lines = \explode("\n", $content);

        foreach ($lines as $line) {
            $line = \rtrim($line);
            if ($line === '') {
                continue;
            }

            // 检测日志级别
            $level = $this->detectLevel($line, $isStderr);

            // 格式化日志行
            $formattedLine = $this->formatLine($timestamp, $tag, $level, $line);

            // 输出到终端
            if ($this->stdoutEnabled) {
                $this->writeStdout($formattedLine, $level);
            }

            // 写入日志文件
            $this->writeToLogger($tag, $level, $line);
        }
    }

    /**
     * 收集进程死亡信息
     */
    public function collectDeath(string $id, array $deathInfo): void
    {
        $tag = $this->getProcessTag($id);
        $timestamp = \date('Y-m-d H:i:s');

        $message = \sprintf(
            '进程死亡: %s (PID: %d) - %s',
            $deathInfo['reason'] ?? '未知原因',
            $deathInfo['pid'] ?? 0,
            $this->formatDeathDetails($deathInfo)
        );

        // 如果有最终输出，先处理
        if (!empty($deathInfo['final_stderr'])) {
            $this->collect($id, $deathInfo['final_stderr'], true);
        }
        if (!empty($deathInfo['final_stdout'])) {
            $this->collect($id, $deathInfo['final_stdout'], false);
        }

        // 记录死亡事件
        $level = LogLevel::ERROR;
        $formattedLine = $this->formatLine($timestamp, $tag, $level, $message);

        if ($this->stdoutEnabled) {
            $this->writeStdout($formattedLine, $level);
        }

        $this->writeToLogger($tag, $level, $message, [
            'exit_code' => $deathInfo['exit_code'] ?? null,
            'signal' => $deathInfo['signal'] ?? null,
        ]);
    }

    /**
     * 检测日志级别
     */
    private function detectLevel(string $line, bool $isStderr): string
    {
        // 检查已有的级别标记 [LEVEL]
        if (\preg_match('/\[(DEBUG|INFO|NOTICE|WARNING|WARN|ERROR|FATAL)\]/i', $line, $matches)) {
            $level = \strtoupper($matches[1]);
            if ($level === 'WARN') {
                $level = LogLevel::WARNING;
            }
            return $level;
        }

        // 根据关键词检测
        foreach (self::ERROR_PATTERNS as $pattern => $level) {
            if (\str_contains($line, $pattern)) {
                return $level;
            }
        }

        // 默认级别
        return $isStderr ? LogLevel::ERROR : LogLevel::INFO;
    }

    /**
     * 格式化日志行
     */
    private function formatLine(string $timestamp, string $tag, string $level, string $message): string
    {
        return "[{$timestamp}] [{$tag}] [{$level}] {$message}\n";
    }

    /**
     * 格式化死亡详情
     */
    private function formatDeathDetails(array $info): string
    {
        $parts = [];

        if (isset($info['exit_code']) && $info['exit_code'] !== null) {
            $parts[] = "exit_code={$info['exit_code']}";
        }
        if (isset($info['signal']) && $info['signal'] !== null) {
            $parts[] = "signal={$info['signal']}";
        }
        if (isset($info['runtime'])) {
            $parts[] = "runtime={$info['runtime']}s";
        }

        return $parts ? \implode(', ', $parts) : '无详细信息';
    }

    /**
     * 输出到终端（分段着色）
     */
    private function writeStdout(string $line, string $level): void
    {
        $colored = LogLevel::colorLine($line, $level);

        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            @\fwrite(STDOUT, $colored);
            @\fflush(STDOUT);
        }
    }

    /**
     * 写入 WlsLogger
     */
    private function writeToLogger(string $tag, string $level, string $message, array $context = []): void
    {
        try {
            $logger = WlsLogger::getInstance();
            $originalTag = $logger->getProcessTag();

            // 临时切换 tag
            $logger->setProcessTag($tag);
            $logger->log($level, $message, $context);

            // 恢复原 tag
            $logger->setProcessTag($originalTag);
        } catch (\Throwable $e) {
            // 日志失败不应影响聚合器
            $this->fallbackWrite($tag, $level, $message);
        }
    }

    /**
     * Fallback 写入
     */
    private function fallbackWrite(string $tag, string $level, string $message): void
    {
        $timestamp = \date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$tag}] [{$level}] {$message}\n";

        $logDir = LogConfig::getLogDir();
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        @\file_put_contents($logDir . 'wls.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 清空所有进程标签
     */
    public function clear(): void
    {
        $this->processTags = [];
    }
}
