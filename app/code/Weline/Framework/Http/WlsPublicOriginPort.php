<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Public listen-port authority for getBaseHost.
 *
 * - edge_adapter=nginx: managed Nginx owns the browser origin — never keep the
 *   Worker listen port (even if WLS_PUBLIC_ORIGIN is misconfigured with one).
 * - edge_adapter=wls: Pure-WLS self-decides; keep the non-default port from
 *   WLS_PUBLIC_ORIGIN when it matches the request Host port.
 */
final class WlsPublicOriginPort
{
    /**
     * @param array<string, mixed>|null $server
     */
    public static function edgeAdapter(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $raw = \strtolower(\trim((string)(
            $server['WLS_EDGE_ADAPTER']
            ?? $_ENV['WLS_EDGE_ADAPTER']
            ?? \getenv('WLS_EDGE_ADAPTER')
            ?: ''
        )));
        if ($raw === 'nginx' || $raw === 'wls') {
            return $raw;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public static function nonDefaultListenPort(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $raw = \trim((string)(
            $server['WLS_PUBLIC_ORIGIN']
            ?? $_ENV['WLS_PUBLIC_ORIGIN']
            ?? \getenv('WLS_PUBLIC_ORIGIN')
            ?: ''
        ));
        if ($raw === '') {
            return null;
        }

        try {
            $parts = \parse_url($raw);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $port = isset($parts['port'])
            ? (string)(int)$parts['port']
            : ($scheme === 'https' ? '443' : '80');

        if ($port === '80' || $port === '443' || $port === '0') {
            return null;
        }

        return $port;
    }

    /**
     * Whether getBaseHost may emit $currentPort as the public authority.
     *
     * @param array<string, mixed>|null $server
     */
    public static function keepNonDefaultListenPort(string $currentPort, ?array $server = null): bool
    {
        if ($currentPort === '' || $currentPort === '80' || $currentPort === '443') {
            return false;
        }
        if (self::edgeAdapter($server) !== 'wls') {
            return false;
        }
        $publicPort = self::nonDefaultListenPort($server);

        return $publicPort !== null && $publicPort === $currentPort;
    }
}
