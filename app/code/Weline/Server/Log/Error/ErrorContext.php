<?php
declare(strict_types=1);

/**
 * WLS 错误上下文管理
 *
 * 存储当前进程的上下文信息，在错误发生时附加到日志中。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

class ErrorContext
{
    /** 进程标识 */
    private static string $processTag = 'Unknown';

    /** 额外上下文数据 */
    private static array $context = [];

    /**
     * 设置进程标识
     */
    public static function setProcessTag(string $tag): void
    {
        self::$processTag = $tag;
    }

    /**
     * 获取进程标识
     */
    public static function getProcessTag(): string
    {
        return self::$processTag;
    }

    /**
     * 设置上下文（覆盖）
     */
    public static function setContext(array $context): void
    {
        self::$context = $context;
    }

    /**
     * 添加单个上下文项
     */
    public static function addContext(string $key, mixed $value): void
    {
        self::$context[$key] = $value;
    }

    /**
     * 获取完整上下文
     */
    public static function getContext(): array
    {
        return self::$context;
    }

    /**
     * 获取单个上下文项
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$context[$key] ?? $default;
    }

    /**
     * 更新请求上下文（在每次请求处理时调用）
     */
    public static function updateRequestContext(
        string $uri = '',
        string $method = '',
        string $clientIp = ''
    ): void {
        if ($uri !== '') {
            self::$context['request_uri'] = $uri;
        }
        if ($method !== '') {
            self::$context['request_method'] = $method;
        }
        if ($clientIp !== '') {
            self::$context['client_ip'] = $clientIp;
        }
    }

    /**
     * 清除请求上下文（请求处理完成后调用）
     */
    public static function clearRequestContext(): void
    {
        unset(
            self::$context['request_uri'],
            self::$context['request_method'],
            self::$context['client_ip']
        );
    }

    /**
     * 重置所有上下文（用于 WLS 状态重置）
     */
    public static function reset(): void
    {
        self::$processTag = 'Unknown';
        self::$context = [];
    }

    /**
     * 获取完整的错误上下文（用于错误记录）
     */
    public static function getFullContext(): array
    {
        return [
            'process_tag' => self::$processTag,
            'pid' => \getmypid(),
            'memory_usage' => \memory_get_usage(true),
            'memory_peak' => \memory_get_peak_usage(true),
        ] + self::$context;
    }
}
