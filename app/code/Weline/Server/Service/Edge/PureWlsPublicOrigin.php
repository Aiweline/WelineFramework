<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * Canonical browser-visible origin for an explicit pure-WLS endpoint.
 */
final class PureWlsPublicOrigin
{
    public static function fromHostAndPort(string $rawHost, int $port, bool $https): string
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Pure WLS public port must be in 1..65535.');
        }
        $host = \strtolower(\trim($rawHost, " \t\n\r\0\x0B[]"));
        if ($host === ''
            || \in_array($host, ['0.0.0.0', '::', '*'], true)
            || (\filter_var($host, FILTER_VALIDATE_IP) === false
                && \preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host) !== 1)
        ) {
            throw new \InvalidArgumentException('Pure WLS public host must be a concrete hostname or IP address.');
        }

        $scheme = $https ? 'https' : 'http';
        $authority = \filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;
        $defaultPort = $https ? 443 : 80;
        if ($port !== $defaultPort) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }

    public static function normalize(string $origin): string
    {
        $origin = \trim($origin);
        try {
            $parts = $origin !== '' ? \parse_url($origin) : false;
        } catch (\ValueError) {
            $parts = false;
        }
        if (!\is_array($parts)) {
            throw new \InvalidArgumentException('Persisted pure WLS public origin is invalid.');
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array((string)($parts['path'] ?? ''), ['', '/'], true)
        ) {
            throw new \InvalidArgumentException(
                'Pure WLS public origin must be HTTP/HTTPS without credentials, path, query, or fragment.'
            );
        }
        $port = isset($parts['port'])
            ? (int)$parts['port']
            : ($scheme === 'https' ? 443 : 80);

        return self::fromHostAndPort(
            (string)($parts['host'] ?? ''),
            $port,
            $scheme === 'https',
        );
    }
}
