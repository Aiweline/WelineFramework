<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * URL 规范化与身份哈希：探测参数不计入身份，避免每轮产生新记录。
 */
final class UrlHealthKey
{
    public const PROBE_QUERY_KEY = 'w_seo_probe';

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return mb_strtolower($url);
        }

        $scheme = mb_strtolower((string)($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string)$parts['query'], $params);
            unset($params[self::PROBE_QUERY_KEY]);
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $normalized = $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . $query;

        return $normalized;
    }

    public static function withProbeParam(string $url, string $runId): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . self::PROBE_QUERY_KEY . '=' . rawurlencode($runId);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = mb_strtolower((string)($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . mb_strtolower((string)$parts['host']) . $port;
    }

    public static function sameOrigin(string $left, string $right): bool
    {
        $leftOrigin = self::origin($left);
        $rightOrigin = self::origin($right);

        return $leftOrigin !== '' && $leftOrigin === $rightOrigin;
    }
}
