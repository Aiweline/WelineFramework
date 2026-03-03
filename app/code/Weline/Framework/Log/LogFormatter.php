<?php

declare(strict_types=1);

/**
 * Weline Framework 日志格式化器
 * 
 * 统一的日志格式化，支持紧凑模式和详细模式
 * 支持链路追踪（Trace ID, Span ID）
 */

namespace Weline\Framework\Log;

use Weline\Framework\Log\Context\TraceContext;

class LogFormatter
{
    /**
     * 日期格式
     */
    private string $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * 是否包含微秒
     */
    private bool $includeMicroseconds = true;

    /**
     * 是否包含进程 ID
     */
    private bool $includeProcessId = false;

    /**
     * 是否包含内存使用
     */
    private bool $includeMemory = false;

    /**
     * 是否包含链路追踪信息
     */
    private bool $includeTrace = true;

    public function __construct(array $options = [])
    {
        if (isset($options['date_format'])) {
            $this->dateFormat = $options['date_format'];
        }
        if (isset($options['include_microseconds'])) {
            $this->includeMicroseconds = (bool)$options['include_microseconds'];
        }
        if (isset($options['include_process_id'])) {
            $this->includeProcessId = (bool)$options['include_process_id'];
        }
        if (isset($options['include_memory'])) {
            $this->includeMemory = (bool)$options['include_memory'];
        }
        if (isset($options['include_trace'])) {
            $this->includeTrace = (bool)$options['include_trace'];
        }
    }

    /**
     * 格式化日志条目
     *
     * @param LogLevel $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     * @param string $channel 通道名
     * @param bool $compact 是否紧凑模式
     * @return string 格式化后的日志行
     */
    public function format(
        LogLevel $level,
        string $message,
        array $context,
        string $channel,
        bool $compact = true
    ): string {
        $timestamp = $this->getTimestamp();
        $levelName = $level->name;
        
        // 插值上下文变量到消息中
        $message = $this->interpolate($message, $context);
        
        // 获取调用位置
        $caller = $this->getCaller();
        
        if ($compact) {
            // 紧凑格式：[timestamp] [LEVEL] [trace_id] channel:source:line - message
            $line = "[{$timestamp}] [{$levelName}]";
            
            // 链路追踪 ID
            if ($this->includeTrace) {
                $traceId = $this->getShortTraceId();
                if ($traceId) {
                    $line .= " [{$traceId}]";
                }
            }
            
            $line .= " {$channel}";
            
            if ($caller) {
                $line .= ":{$caller['file']}:{$caller['line']}";
            }
            
            $line .= " - {$message}";
            
            if ($this->includeProcessId) {
                $line .= " [pid:" . getmypid() . "]";
            }
            
            if ($this->includeMemory) {
                $line .= " [mem:" . $this->formatBytes(memory_get_usage(true)) . "]";
            }
            
            // 附加上下文（如果有非插值的数据）
            $extraContext = $this->getExtraContext($context);
            if (!empty($extraContext)) {
                $line .= " " . json_encode($extraContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            
            return $line . PHP_EOL;
        }
        
        // 详细格式：多行输出
        $output = str_repeat('=', 80) . PHP_EOL;
        $output .= "[{$timestamp}] [{$levelName}] [{$channel}]" . PHP_EOL;
        
        // 链路追踪信息
        if ($this->includeTrace) {
            $traceInfo = $this->getTraceInfo();
            if (!empty($traceInfo)) {
                $output .= "Trace ID: {$traceInfo['trace_id']}" . PHP_EOL;
                $output .= "Span ID: {$traceInfo['span_id']}" . PHP_EOL;
                if (isset($traceInfo['parent_span_id'])) {
                    $output .= "Parent Span ID: {$traceInfo['parent_span_id']}" . PHP_EOL;
                }
                if (isset($traceInfo['duration_ms'])) {
                    $output .= "Request Duration: {$traceInfo['duration_ms']}ms" . PHP_EOL;
                }
            }
        }
        
        if ($caller) {
            $output .= "File: {$caller['file']}" . PHP_EOL;
            $output .= "Line: {$caller['line']}" . PHP_EOL;
        }
        
        if ($this->includeProcessId) {
            $output .= "PID: " . getmypid() . PHP_EOL;
        }
        
        if ($this->includeMemory) {
            $output .= "Memory: " . $this->formatBytes(memory_get_usage(true)) . PHP_EOL;
        }
        
        $output .= "Message: {$message}" . PHP_EOL;
        
        if (!empty($context)) {
            $output .= "Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        }
        
        $output .= str_repeat('-', 80) . PHP_EOL;
        
        return $output;
    }

    /**
     * 获取时间戳
     */
    private function getTimestamp(): string
    {
        if ($this->includeMicroseconds) {
            $now = \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
            if ($now === false) {
                $now = new \DateTime();
            }
            return $now->format($this->dateFormat);
        }
        
        return date($this->dateFormat);
    }

    /**
     * 插值上下文变量到消息中
     * 
     * 支持 {key} 格式的占位符
     */
    private function interpolate(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            
            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string)$val;
            } elseif (is_array($val)) {
                $replace['{' . $key . '}'] = json_encode($val, JSON_UNESCAPED_UNICODE);
            } elseif (is_object($val)) {
                $replace['{' . $key . '}'] = '[object ' . get_class($val) . ']';
            }
        }

        return strtr($message, $replace);
    }

    /**
     * 获取调用者信息
     *
     * @return array{file: string, line: int}|null
     */
    private function getCaller(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        
        // 跳过日志系统内部的调用
        $skipClasses = [
            'Weline\\Framework\\Log\\',
            'Weline\\Framework\\App\\Env',
        ];
        
        $skipFunctions = [
            'w_log', 'w_log_error', 'w_log_warning', 'w_log_info', 
            'w_log_debug', 'w_log_notice', 'w_log_critical', 'w_log_alert',
        ];
        
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            
            // 跳过日志系统内部调用
            $skip = false;
            foreach ($skipClasses as $skipClass) {
                if (str_starts_with($class, $skipClass)) {
                    $skip = true;
                    break;
                }
            }
            
            if (!$skip && in_array($function, $skipFunctions, true)) {
                $skip = true;
            }
            
            if (!$skip && isset($frame['file'], $frame['line'])) {
                // 简化文件路径（移除项目根目录）
                $file = $frame['file'];
                if (defined('BP') && str_starts_with($file, BP)) {
                    $file = substr($file, strlen(BP));
                    $file = ltrim($file, DIRECTORY_SEPARATOR);
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
     * 获取额外的上下文（不用于插值的）
     */
    private function getExtraContext(array $context): array
    {
        $extra = [];
        foreach ($context as $key => $value) {
            if (str_starts_with($key, '_')) {
                $extra[substr($key, 1)] = $value;
            }
        }
        return $extra;
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);
        
        return sprintf('%.2f%s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * 获取短格式的 Trace ID（前 8 位）
     */
    private function getShortTraceId(): ?string
    {
        try {
            $traceId = TraceContext::getTraceId();
            return substr($traceId, 0, 8);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取完整的链路追踪信息
     */
    private function getTraceInfo(): array
    {
        try {
            $info = [
                'trace_id' => TraceContext::getTraceId(),
                'span_id' => TraceContext::getSpanId(),
            ];
            
            $parentSpanId = TraceContext::getParentSpanId();
            if ($parentSpanId !== null) {
                $info['parent_span_id'] = $parentSpanId;
            }
            
            $duration = TraceContext::getRequestDuration();
            if ($duration !== null) {
                $info['duration_ms'] = round($duration, 2);
            }
            
            return $info;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 设置日期格式
     */
    public function setDateFormat(string $format): self
    {
        $this->dateFormat = $format;
        return $this;
    }

    /**
     * 启用/禁用进程 ID
     */
    public function withProcessId(bool $include = true): self
    {
        $clone = clone $this;
        $clone->includeProcessId = $include;
        return $clone;
    }

    /**
     * 启用/禁用内存使用
     */
    public function withMemory(bool $include = true): self
    {
        $clone = clone $this;
        $clone->includeMemory = $include;
        return $clone;
    }

    /**
     * 启用/禁用链路追踪
     */
    public function withTrace(bool $include = true): self
    {
        $clone = clone $this;
        $clone->includeTrace = $include;
        return $clone;
    }
}
