<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

/** Removes credential-shaped fragments before an async error reaches DB, log, or UI. */
final class AsyncErrorRedactor
{
    private const SENSITIVE = '(?:[a-z0-9_.-]*(?:password|passwd|secret|token|cookie|session|csrf|authorization|credential|private[_-]?key|api[_-]?key|access[_-]?key|signature)[a-z0-9_.-]*)';

    public function redact(string $error, int $maxBytes = 8192): string
    {
        $error = preg_replace(
            '/-----BEGIN [^-\r\n]+-----.*?-----END [^-\r\n]+-----/si',
            '[redacted-pem]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/\bBearer[ \t]+[A-Za-z0-9._~+\/=:-]+/i',
            'Bearer [redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/((?:Authorization|Proxy-Authorization)[ \t]*[:=][ \t]*)[^\r\n]*(?:\r?\n[ \t]+[^\r\n]*)*/i',
            '$1[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/((?:Cookie|Set-Cookie)[ \t]*[:=][ \t]*)[^\r\n]*(?:\r?\n[ \t]+[^\r\n]*)*/i',
            '$1[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/(\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:)[^@\/\s]+(@)/i',
            '$1[redacted]$2',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/("(?:' . self::SENSITIVE . ')"\s*:\s*)("(?:\\\\.|[^"\\\\])*"|[^,}\]\s]+)/i',
            '$1"[redacted]"',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/((?:^|[?&;,\s])(?>["\']?)(?:' . self::SENSITIVE . ')(?>["\']?)[ \t]*(?:=>|[=:])[ \t]*)(?!["\']?\[redacted\]["\']?(?:$|[&;,\s}\]\r\n]))(["\']?)[^&;,\s}\]\r\n]+\2/im',
            '$1[redacted]',
            $error,
        ) ?? $error;

        $maxBytes = max(0, min(8192, $maxBytes));
        return substr($error, 0, $maxBytes);
    }
}
