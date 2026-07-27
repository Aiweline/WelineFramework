<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Canonical public origin owned by the project-managed Nginx instance.
 *
 * The WLS backend is always plaintext loopback H1, so its listener state must
 * never be used to infer the browser-visible scheme or port.
 */
final class ManagedNginxPublicOrigin
{
    public static function fromHostAndPort(string $rawHost, int $httpsPort): string
    {
        if ($httpsPort < 1 || $httpsPort > 65535) {
            throw new \InvalidArgumentException('Managed Nginx HTTPS port must be in 1..65535.');
        }

        $rawHost = \trim($rawHost);
        if ($rawHost === '') {
            throw new \InvalidArgumentException('Managed Nginx public host must not be empty.');
        }
        $rawIpv6 = \trim($rawHost, '[]');
        if (!\str_contains($rawHost, '://')
            && \filter_var($rawIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ) {
            $parts = ['host' => $rawIpv6];
        } else {
            $candidate = \str_contains($rawHost, '://') ? $rawHost : 'https://' . $rawHost;
            try {
                $parts = \parse_url($candidate);
            } catch (\ValueError) {
                $parts = false;
            }
        }
        if (!\is_array($parts)) {
            throw new \InvalidArgumentException('Managed Nginx public host is invalid.');
        }

        $scheme = \strtolower(\trim((string)($parts['scheme'] ?? 'https')));
        $path = (string)($parts['path'] ?? '');
        if ($scheme !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            throw new \InvalidArgumentException('Managed Nginx public origin must be an HTTPS origin without credentials, path, query, or fragment.');
        }
        if (isset($parts['port']) && (int)$parts['port'] !== $httpsPort) {
            throw new \InvalidArgumentException('Managed Nginx public host port does not match listen_https.');
        }

        $host = \strtolower(\trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === ''
            || \in_array($host, ['0.0.0.0', '::', '*'], true)
            || (\filter_var($host, FILTER_VALIDATE_IP) === false
                && \preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host) !== 1)
        ) {
            throw new \InvalidArgumentException('Managed Nginx public host is not a concrete hostname or IP address.');
        }

        $authority = \filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;
        if ($httpsPort !== 443) {
            $authority .= ':' . $httpsPort;
        }

        return 'https://' . $authority;
    }

    public static function normalize(string $origin): string
    {
        $origin = \trim($origin);
        try {
            $parts = $origin !== '' ? \parse_url($origin) : false;
        } catch (\ValueError) {
            $parts = false;
        }
        if (!\is_array($parts) || \strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('Persisted managed Nginx public origin must use HTTPS.');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;

        return self::fromHostAndPort($origin, $port);
    }
}
