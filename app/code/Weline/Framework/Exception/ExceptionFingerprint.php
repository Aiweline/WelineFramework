<?php

declare(strict_types=1);

/**
 * Weline Framework 异常指纹
 * 
 * 为异常生成唯一指纹，用于聚合相同类型的异常
 */

namespace Weline\Framework\Exception;

class ExceptionFingerprint
{
    /**
     * 生成异常指纹
     *
     * @param \Throwable $exception 异常对象
     * @return string 指纹（MD5 哈希）
     */
    public static function generate(\Throwable $exception): string
    {
        $components = [
            get_class($exception),
            self::simplifyPath($exception->getFile()),
            (string)$exception->getLine(),
            self::normalizeMessage($exception->getMessage()),
        ];
        
        return md5(implode('|', $components));
    }

    /**
     * 生成短指纹（用于日志显示）
     *
     * @param \Throwable $exception 异常对象
     * @return string 8 字符的短指纹
     */
    public static function generateShort(\Throwable $exception): string
    {
        return substr(self::generate($exception), 0, 8);
    }

    /**
     * 生成异常签名（更宽松的分组）
     *
     * 只考虑异常类型和文件位置，不考虑具体消息
     *
     * @param \Throwable $exception 异常对象
     * @return string 签名
     */
    public static function generateSignature(\Throwable $exception): string
    {
        $components = [
            get_class($exception),
            self::simplifyPath($exception->getFile()),
            (string)$exception->getLine(),
        ];
        
        return md5(implode('|', $components));
    }

    /**
     * 获取异常的详细指纹信息
     *
     * @param \Throwable $exception 异常对象
     * @return array
     */
    public static function getDetails(\Throwable $exception): array
    {
        return [
            'fingerprint' => self::generate($exception),
            'short_fingerprint' => self::generateShort($exception),
            'signature' => self::generateSignature($exception),
            'class' => get_class($exception),
            'file' => self::simplifyPath($exception->getFile()),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'message_hash' => md5($exception->getMessage()),
            'trace_hash' => self::hashTrace($exception->getTrace()),
        ];
    }

    /**
     * 简化文件路径
     */
    private static function simplifyPath(string $path): string
    {
        if (defined('BP') && str_starts_with($path, BP)) {
            return substr($path, strlen(BP));
        }
        return $path;
    }

    /**
     * 规范化消息
     *
     * 移除可能变化的部分（如 ID、时间戳等）
     */
    private static function normalizeMessage(string $message): string
    {
        // 移除数字 ID
        $message = preg_replace('/\b\d+\b/', '{id}', $message);
        
        // 移除 UUID
        $message = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', '{uuid}', $message);
        
        // 移除时间戳
        $message = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', '{timestamp}', $message);
        
        // 移除文件路径中的变化部分
        $message = preg_replace('/\/[a-zA-Z]:[^:]+/', '{path}', $message);
        
        return $message;
    }

    /**
     * 对堆栈追踪生成哈希
     *
     * 只使用前 3 个调用帧
     */
    private static function hashTrace(array $trace): string
    {
        $frames = [];
        $limit = min(3, count($trace));
        
        for ($i = 0; $i < $limit; $i++) {
            $frame = $trace[$i];
            $frames[] = ($frame['class'] ?? '') 
                . ($frame['type'] ?? '') 
                . ($frame['function'] ?? '')
                . ':' . ($frame['line'] ?? 0);
        }
        
        return substr(md5(implode('|', $frames)), 0, 8);
    }

    /**
     * 比较两个异常是否相似
     *
     * @param \Throwable $a 第一个异常
     * @param \Throwable $b 第二个异常
     * @return bool
     */
    public static function isSimilar(\Throwable $a, \Throwable $b): bool
    {
        return self::generateSignature($a) === self::generateSignature($b);
    }

    /**
     * 比较两个异常是否完全相同
     *
     * @param \Throwable $a 第一个异常
     * @param \Throwable $b 第二个异常
     * @return bool
     */
    public static function isIdentical(\Throwable $a, \Throwable $b): bool
    {
        return self::generate($a) === self::generate($b);
    }
}
