<?php
declare(strict_types=1);

/**
 * WLS 日志级别常量
 *
 * 遵循 PSR-3 日志级别规范，提供级别比较能力。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log;

final class LogLevel
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const NOTICE = 'NOTICE';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';
    public const FATAL = 'FATAL';

    /**
     * 级别优先级映射（数值越大越严重）
     */
    private const PRIORITIES = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::NOTICE => 250,
        self::WARNING => 300,
        self::ERROR => 400,
        self::FATAL => 500,
    ];

    /**
     * 终端颜色映射
     */
    private const COLORS = [
        self::DEBUG => "\033[90m",      // 灰色
        self::INFO => "\033[36m",       // 青色
        self::NOTICE => "\033[34m",     // 蓝色
        self::WARNING => "\033[93m",    // 亮黄色
        self::ERROR => "\033[91m",      // 亮红色
        self::FATAL => "\033[91;1m",    // 亮红色+粗体
    ];

    private const RESET = "\033[0m";

    /** 时间戳颜色（暗灰） */
    private const DIM = "\033[90m";

    /**
     * 进程标签颜色映射
     */
    private const PROCESS_COLORS = [
        'worker'      => "\033[32m",    // 绿色 — 请求处理
        'dispatcher'  => "\033[95m",    // 亮洋红 — 流量调度
        'passthrough' => "\033[95m",    // 同 Dispatcher
        'session'     => "\033[33m",    // 黄色 — 会话
        'memory'      => "\033[36m",    // 青色 — 内存缓存
        'master'      => "\033[97;1m",  // 亮白粗体 — 主控
        'orchestrator'=> "\033[97m",    // 亮白
        'redirect'    => "\033[34m",    // 蓝色 — HTTP 跳转
    ];

    /**
     * 根据进程标签获取颜色
     */
    public static function getProcessColor(string $processTag): string
    {
        $lower = \strtolower($processTag);
        foreach (self::PROCESS_COLORS as $keyword => $color) {
            if (\str_contains($lower, $keyword)) {
                // redirect 必须在 worker 之前匹配，避免 "http_redirect_worker" 命中 worker
                if ($keyword === 'worker' && \str_contains($lower, 'redirect')) {
                    continue;
                }
                return $color;
            }
        }
        return "\033[37m"; // 默认白色
    }

    /**
     * 获取时间戳暗色代码
     */
    public static function getDim(): string
    {
        return self::DIM;
    }

    /**
     * 将一行日志按 [timestamp] [tag] [LEVEL] message 格式着色
     *
     * @param string $line         原始纯文本日志行
     * @param string $level        日志级别
     * @param string $processTag   进程标签（可选，正则提取不到时使用）
     */
    public static function colorLine(string $line, string $level = '', string $processTag = ''): string
    {
        $reset = self::RESET;
        $dim   = self::DIM;

        // 尝试解析标准格式
        if (\preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.*)/s', $line, $m)) {
            $ts   = $m[1];
            $tag  = $m[2];
            $lvl  = $m[3];
            $msg  = $m[4];
            $tagColor = self::getProcessColor($tag);
            $lvlColor = self::getColor($lvl !== '' ? $lvl : $level);
            return "{$dim}[{$ts}]{$reset} {$tagColor}[{$tag}]{$reset} {$lvlColor}[{$lvl}]{$reset} {$msg}";
        }

        // 退路：尝试匹配 [tag] message（如 [PassthroughCore] ...）
        if (\preg_match('/^\[([^\]]+)\]\s+(.*)/s', $line, $m)) {
            $tag = $m[1];
            $msg = $m[2];
            $tagColor = self::getProcessColor($processTag !== '' ? $processTag : $tag);
            return "{$tagColor}[{$tag}]{$reset} {$msg}";
        }

        // 再退路：按级别整行着色
        $lvlColor = self::getColor($level);
        return $lvlColor !== '' ? ($lvlColor . $line . $reset) : $line;
    }

    /**
     * 获取级别优先级
     */
    public static function getPriority(string $level): int
    {
        return self::PRIORITIES[\strtoupper($level)] ?? 0;
    }

    /**
     * 比较两个级别
     *
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::getPriority($a);
        $pb = self::getPriority($b);
        return $pa <=> $pb;
    }

    /**
     * 检查 $level 是否 >= $minLevel
     */
    public static function isAtLeast(string $level, string $minLevel): bool
    {
        return self::compare($level, $minLevel) >= 0;
    }

    /**
     * 获取终端颜色代码
     */
    public static function getColor(string $level): string
    {
        return self::COLORS[\strtoupper($level)] ?? '';
    }

    /**
     * 获取颜色重置代码
     */
    public static function getReset(): string
    {
        return self::RESET;
    }

    /**
     * 将级别字符串标准化为大写
     */
    public static function normalize(string $level): string
    {
        $upper = \strtoupper($level);
        return isset(self::PRIORITIES[$upper]) ? $upper : self::INFO;
    }

    /**
     * 获取所有有效级别
     *
     * @return string[]
     */
    public static function all(): array
    {
        return \array_keys(self::PRIORITIES);
    }
}
