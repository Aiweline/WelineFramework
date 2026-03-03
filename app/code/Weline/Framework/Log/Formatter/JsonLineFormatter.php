<?php

declare(strict_types=1);

/**
 * Weline Framework JSON Lines 格式化器
 * 
 * 输出 JSON Lines 格式的日志，便于 ELK 等工具分析
 * 支持链路追踪（Trace ID, Span ID）
 */

namespace Weline\Framework\Log\Formatter;

use Weline\Framework\Log\Context\TraceContext;
use Weline\Framework\Log\LogLevel;

class JsonLineFormatter
{
    /**
     * 是否包含堆栈追踪
     */
    private bool $includeTrace;

    /**
     * 最大追踪深度
     */
    private int $traceDepth;

    /**
     * 附加字段
     */
    private array $extraFields = [];

    public function __construct(bool $includeTrace = false, int $traceDepth = 5)
    {
        $this->includeTrace = $includeTrace;
        $this->traceDepth = $traceDepth;
    }

    /**
     * 格式化日志条目为 JSON Line
     *
     * @param LogLevel $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     * @param string $channel 通道名
     * @return string JSON 行（带换行符）
     */
    public function format(
        LogLevel $level,
        string $message,
        array $context,
        string $channel
    ): string {
        $record = [
            '@timestamp' => $this->getTimestamp(),
            'level' => $level->toLowerCase(),
            'level_value' => $level->value,
            'channel' => $channel,
            'message' => $message,
            'trace_id' => TraceContext::getTraceId(),
            'span_id' => TraceContext::getSpanId(),
        ];
        
        // 添加父 Span ID（如果有）
        $parentSpanId = TraceContext::getParentSpanId();
        if ($parentSpanId !== null) {
            $record['parent_span_id'] = $parentSpanId;
        }
        
        // 添加请求耗时
        $duration = TraceContext::getRequestDuration();
        if ($duration !== null) {
            $record['request_duration_ms'] = round($duration, 2);
        }

        // 添加上下文（移除下划线前缀的私有字段）
        $contextData = [];
        $privateData = [];
        
        foreach ($context as $key => $value) {
            if (str_starts_with($key, '_')) {
                $privateData[substr($key, 1)] = $this->normalizeValue($value);
            } else {
                $contextData[$key] = $this->normalizeValue($value);
            }
        }
        
        if (!empty($contextData)) {
            $record['context'] = $contextData;
        }
        
        if (!empty($privateData)) {
            $record['extra'] = $privateData;
        }

        // 添加调用位置
        $caller = $this->getCaller();
        if ($caller) {
            $record['file'] = $caller['file'];
            $record['line'] = $caller['line'];
        }

        // 添加堆栈追踪
        if ($this->includeTrace) {
            $record['trace'] = $this->getTrace();
        }

        // 添加进程信息
        $record['pid'] = getmypid();
        
        // 添加额外字段
        foreach ($this->extraFields as $key => $value) {
            $record[$key] = $value;
        }

        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /**
     * 获取 ISO 8601 时间戳
     */
    private function getTimestamp(): string
    {
        $now = \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if ($now === false) {
            $now = new \DateTime();
        }
        return $now->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * 规范化值为可 JSON 序列化的格式
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }
        
        if (is_array($value)) {
            return array_map([$this, 'normalizeValue'], $value);
        }
        
        if ($value instanceof \Throwable) {
            return [
                'class' => get_class($value),
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }
        
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return '[object ' . get_class($value) . ']';
        }
        
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }
        
        return '[unknown type]';
    }

    /**
     * 获取调用者信息
     */
    private function getCaller(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        
        $skipClasses = [
            'Weline\\Framework\\Log\\',
        ];
        
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            
            $skip = false;
            foreach ($skipClasses as $skipClass) {
                if (str_starts_with($class, $skipClass)) {
                    $skip = true;
                    break;
                }
            }
            
            if (!$skip && isset($frame['file'], $frame['line'])) {
                $file = $frame['file'];
                if (defined('BP') && str_starts_with($file, BP)) {
                    $file = substr($file, strlen(BP));
                }
                
                return [
                    'file' => $file,
                    'line' => $frame['line'],
                ];
            }
        }
        
        return null;
    }

    /**
     * 获取堆栈追踪
     */
    private function getTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->traceDepth + 5);
        $result = [];
        
        $skipClasses = [
            'Weline\\Framework\\Log\\',
        ];
        
        $count = 0;
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            
            $skip = false;
            foreach ($skipClasses as $skipClass) {
                if (str_starts_with($class, $skipClass)) {
                    $skip = true;
                    break;
                }
            }
            
            if (!$skip && isset($frame['file'])) {
                $file = $frame['file'];
                if (defined('BP') && str_starts_with($file, BP)) {
                    $file = substr($file, strlen(BP));
                }
                
                $result[] = [
                    'file' => $file,
                    'line' => $frame['line'] ?? 0,
                    'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
                ];
                
                $count++;
                if ($count >= $this->traceDepth) {
                    break;
                }
            }
        }
        
        return $result;
    }

    /**
     * 添加额外字段到所有日志
     */
    public function addExtraField(string $key, mixed $value): self
    {
        $this->extraFields[$key] = $value;
        return $this;
    }

    /**
     * 设置服务标识
     */
    public function setService(string $service): self
    {
        $this->extraFields['service'] = $service;
        return $this;
    }

    /**
     * 设置环境标识
     */
    public function setEnvironment(string $env): self
    {
        $this->extraFields['environment'] = $env;
        return $this;
    }
}
