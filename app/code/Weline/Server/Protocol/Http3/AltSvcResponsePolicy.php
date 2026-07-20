<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Adds HTTP/3 discovery only after the response has left Static/FPC storage.
 */
final class AltSvcResponsePolicy
{
    private static bool $enabled = false;
    private static int $port = 0;
    private static int $maxAge = 300;
    /** @var list<string> */
    private static array $certificateHostPatterns = [];

    /** @param list<string> $certificateHostPatterns */
    public static function configure(bool $enabled, int $port, int $maxAge = 300, array $certificateHostPatterns = []): void
    {
        $patterns = [];
        foreach ($certificateHostPatterns as $pattern) {
            $pattern = \strtolower(\rtrim(\trim((string)$pattern), '.'));
            if ($pattern !== '' && \preg_match('/^(?:\*\.)?[a-z0-9.-]+$|^[a-f0-9:.]+$/D', $pattern) === 1) {
                $patterns[] = $pattern;
            }
        }
        self::$certificateHostPatterns = \array_values(\array_unique($patterns));
        self::$enabled = $enabled && $port > 0 && $port <= 65535 && self::$certificateHostPatterns !== [];
        self::$port = self::$enabled ? $port : 0;
        self::$maxAge = \max(0, $maxAge);
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function decorate(string $response, string $requestHost): string
    {
        if ($response === '' || !\str_starts_with($response, 'HTTP/')) {
            return $response;
        }
        $headerEnd = \strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }
        $headers = \substr($response, 0, $headerEnd);
        $body = \substr($response, $headerEnd + 4);
        $headers = (string)\preg_replace('/\r\nAlt-Svc:[^\r\n]*/i', '', $headers);
        if (!self::$enabled || !self::hostCovered($requestHost)) {
            return $headers . "\r\n\r\n" . $body;
        }
        $headers .= "\r\nAlt-Svc: h3=\":" . self::$port . "\"; ma=" . self::$maxAge;
        return $headers . "\r\n\r\n" . $body;
    }

    private static function hostCovered(string $host): bool
    {
        $host = \strtolower(\rtrim(\trim($host), '.'));
        if ($host === '') {
            return false;
        }
        foreach (self::$certificateHostPatterns as $pattern) {
            if ($pattern === $host) {
                return true;
            }
            if (!\str_starts_with($pattern, '*.')) {
                continue;
            }
            $suffix = \substr($pattern, 1);
            if (\str_ends_with($host, $suffix)) {
                $prefix = \substr($host, 0, -\strlen($suffix));
                if ($prefix !== '' && !\str_contains($prefix, '.')) {
                    return true;
                }
            }
        }
        return false;
    }
}
