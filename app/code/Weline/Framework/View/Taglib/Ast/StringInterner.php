<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 字符串去重（Interning）
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 字符串去重
 * 
 * 相同内容的字符串共享同一内存引用，减少内存占用
 * 
 * @example
 * ```php
 * $a = StringInterner::intern("hello");
 * $b = StringInterner::intern("hello");
 * // $a 和 $b 指向同一内存
 * ```
 */
final class StringInterner
{
    /**
     * 字符串缓存池
     * @var array<string, string>
     */
    private static array $strings = [];

    /**
     * 最大缓存条目数（防止内存溢出）
     */
    private const MAX_ENTRIES = 10000;

    /**
     * 短字符串阈值（小于此长度才缓存）
     */
    private const MAX_LENGTH = 1024;

    /**
     * 禁止实例化
     */
    private function __construct() {}

    /**
     * 将字符串加入缓存池
     * 
     * @param string $s 要缓存的字符串
     * @return string 缓存后的字符串引用
     */
    public static function intern(string $s): string
    {
        // 跳过过长的字符串
        if (strlen($s) > self::MAX_LENGTH) {
            return $s;
        }

        // 已存在则返回缓存
        if (isset(self::$strings[$s])) {
            return self::$strings[$s];
        }

        // 防止内存溢出
        if (count(self::$strings) >= self::MAX_ENTRIES) {
            // 清除一半缓存（LRU 简化版）
            self::$strings = array_slice(self::$strings, self::MAX_ENTRIES / 2, null, true);
        }

        // 加入缓存
        self::$strings[$s] = $s;
        return $s;
    }

    /**
     * 批量缓存字符串
     * 
     * @param array<string> $strings 字符串数组
     * @return array<string> 缓存后的字符串数组
     */
    public static function internAll(array $strings): array
    {
        return array_map(self::intern(...), $strings);
    }

    /**
     * 获取当前缓存大小
     */
    public static function size(): int
    {
        return count(self::$strings);
    }

    /**
     * 清空缓存
     */
    public static function reset(): void
    {
        self::$strings = [];
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array{count: int, memory: int}
     */
    public static function stats(): array
    {
        $memory = 0;
        foreach (self::$strings as $s) {
            $memory += strlen($s);
        }
        return [
            'count' => count(self::$strings),
            'memory' => $memory,
        ];
    }
}
