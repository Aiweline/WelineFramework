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
