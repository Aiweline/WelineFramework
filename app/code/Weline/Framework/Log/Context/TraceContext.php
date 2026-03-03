<?php

declare(strict_types=1);

/**
 * Weline Framework 链路追踪上下文
 * 
 * 为每个请求生成 TraceId，实现日志的链路追踪
 */

namespace Weline\Framework\Log\Context;

class TraceContext
{
    /**
     * 是否已注册 StateManager 重置
     */
    private static bool $stateManagerRegistered = false;

    /**
     * 当前请求的 Trace ID
     */
    private static ?string $traceId = null;

    /**
     * 当前 Span ID
     */
    private static ?string $spanId = null;

    /**
     * 父 Span ID
     */
    private static ?string $parentSpanId = null;

    /**
     * 请求开始时间
     */
    private static ?float $requestStartTime = null;

    /**
     * Trace ID 请求头名称
     */
    private const TRACE_ID_HEADER = 'X-Trace-Id';

    /**
     * Span ID 请求头名称
     */
    private const SPAN_ID_HEADER = 'X-Span-Id';

    /**
     * 初始化追踪上下文
     *
     * @param string|null $traceId 外部传入的 Trace ID（用于分布式追踪）
     */
    public static function init(?string $traceId = null): void
    {
        // 注册到 StateManager（仅一次）
        self::registerStateManager();
        
        self::$requestStartTime = microtime(true);
        
        // 尝试从请求头获取 Trace ID
        if ($traceId === null) {
            $traceId = self::getTraceIdFromHeader();
        }
        
        // 如果没有外部 Trace ID，生成新的
        if ($traceId === null) {
            $traceId = self::generateTraceId();
        }
        
        self::$traceId = $traceId;
        self::$spanId = self::generateSpanId();
        
        // 从请求头获取父 Span ID
        self::$parentSpanId = self::getSpanIdFromHeader();
    }

    /**
     * 注册到 StateManager（WLS 模式下每个请求结束后自动重置）
     */
    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        
        if (class_exists('Weline\\Framework\\Runtime\\StateManager')) {
            \Weline\Framework\Runtime\StateManager::registerResetCallback(
                'TraceContext',
                [self::class, 'reset']
            );
            self::$stateManagerRegistered = true;
        }
    }

    /**
     * 获取当前 Trace ID
     */
    public static function getTraceId(): string
    {
        if (self::$traceId === null) {
            self::init();
        }
        return self::$traceId;
    }

    /**
     * 获取当前 Span ID
     */
    public static function getSpanId(): string
    {
        if (self::$spanId === null) {
            self::$spanId = self::generateSpanId();
        }
        return self::$spanId;
    }

    /**
     * 获取父 Span ID
     */
    public static function getParentSpanId(): ?string
    {
        return self::$parentSpanId;
    }

    /**
     * 创建子 Span
     *
     * @return array{span_id: string, parent_span_id: string}
     */
    public static function createChildSpan(): array
    {
        $parentSpanId = self::$spanId;
        self::$parentSpanId = $parentSpanId;
        self::$spanId = self::generateSpanId();
        
        return [
            'span_id' => self::$spanId,
            'parent_span_id' => $parentSpanId,
        ];
    }

    /**
     * 获取请求耗时（毫秒）
     */
    public static function getRequestDuration(): ?float
    {
        if (self::$requestStartTime === null) {
            return null;
        }
        return (microtime(true) - self::$requestStartTime) * 1000;
    }

    /**
     * 获取追踪上下文数组（用于日志）
     */
    public static function getContext(): array
    {
        $context = [
            '_trace_id' => self::getTraceId(),
            '_span_id' => self::getSpanId(),
        ];
        
        if (self::$parentSpanId !== null) {
            $context['_parent_span_id'] = self::$parentSpanId;
        }
        
        $duration = self::getRequestDuration();
        if ($duration !== null) {
            $context['_request_duration_ms'] = round($duration, 2);
        }
        
        return $context;
    }

    /**
     * 重置上下文（用于 WLS 状态管理）
     */
    public static function reset(): void
    {
        self::$traceId = null;
        self::$spanId = null;
        self::$parentSpanId = null;
        self::$requestStartTime = null;
    }

    /**
     * 生成 Trace ID
     *
     * 格式：32 位十六进制（类似 W3C Trace Context 标准）
     */
    private static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 生成 Span ID
     *
     * 格式：16 位十六进制
     */
    private static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 从请求头获取 Trace ID
     */
    private static function getTraceIdFromHeader(): ?string
    {
        // 标准 HTTP 头
        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::TRACE_ID_HEADER));
        
        if (isset($_SERVER[$headerKey]) && !empty($_SERVER[$headerKey])) {
            return self::sanitizeId($_SERVER[$headerKey]);
        }
        
        // W3C Traceparent 头
        if (isset($_SERVER['HTTP_TRACEPARENT'])) {
            return self::parseTraceparent($_SERVER['HTTP_TRACEPARENT']);
        }
        
        return null;
    }

    /**
     * 从请求头获取 Span ID
     */
    private static function getSpanIdFromHeader(): ?string
    {
        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::SPAN_ID_HEADER));
        
        if (isset($_SERVER[$headerKey]) && !empty($_SERVER[$headerKey])) {
            return self::sanitizeId($_SERVER[$headerKey]);
        }
        
        return null;
    }

    /**
     * 解析 W3C Traceparent 头
     *
     * 格式：{version}-{trace-id}-{parent-id}-{flags}
     * 例如：00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01
     */
    private static function parseTraceparent(string $traceparent): ?string
    {
        $parts = explode('-', $traceparent);
        if (count($parts) >= 2 && strlen($parts[1]) === 32) {
            return $parts[1];
        }
        return null;
    }

    /**
     * 清理 ID（只保留十六进制字符）
     */
    private static function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-fA-F0-9]/', '', $id) ?: '';
    }

    /**
     * 生成响应头
     */
    public static function getResponseHeaders(): array
    {
        return [
            self::TRACE_ID_HEADER => self::getTraceId(),
            self::SPAN_ID_HEADER => self::getSpanId(),
        ];
    }

    /**
     * 设置响应头
     */
    public static function setResponseHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        foreach (self::getResponseHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }
    }
}
