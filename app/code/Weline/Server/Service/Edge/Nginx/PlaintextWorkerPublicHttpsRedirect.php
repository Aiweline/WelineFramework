<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Bounce browsers that hit the Worker listen port straight back to the managed
 * Nginx public HTTPS origin.
 *
 * Only when edge_adapter=nginx. Pure-WLS (edge=wls) owns its listen port and
 * must not bounce. Nginx backends keep Host without the Worker port, so they
 * are unaffected.
 */
final class PlaintextWorkerPublicHttpsRedirect
{
    /**
     * @return non-empty-string|null Absolute https Location, or null when the
     *         request must stay on the Worker.
     */
    public static function locationOrNull(
        string $rawHost,
        int $workerListenPort,
        string $publicOrigin,
        string $requestTarget,
        string $edgeAdapter = '',
    ): ?string {
        if ($workerListenPort < 1 || $workerListenPort > 65535) {
            return null;
        }
        if (!self::isManagedNginxEdge($edgeAdapter)) {
            return null;
        }

        $rawHost = \strtolower(\trim($rawHost));
        if ($rawHost === '' || \str_contains($rawHost, "\0") || \str_contains($rawHost, '@')) {
            return null;
        }

        $hostPort = self::splitAuthority($rawHost);
        if ($hostPort === null || $hostPort['port'] !== $workerListenPort) {
            // Nginx → Worker keeps Host without the Worker listen port.
            return null;
        }

        $pathAndQuery = self::sanitizeTarget($requestTarget);
        if (\str_starts_with($pathAndQuery, '/_wls/')) {
            // Local Worker probes must keep talking to the plaintext listener.
            return null;
        }

        $publicOrigin = \trim($publicOrigin);
        if ($publicOrigin !== '') {
            try {
                $origin = ManagedNginxPublicOrigin::normalize($publicOrigin);
            } catch (\Throwable) {
                $origin = null;
            }
            if (\is_string($origin) && $origin !== '') {
                try {
                    $originParts = \parse_url($origin);
                } catch (\ValueError) {
                    $originParts = false;
                }
                if (\is_array($originParts)) {
                    $originHost = \strtolower(\trim((string)($originParts['host'] ?? ''), '[]'));
                    if ($originHost !== '' && \hash_equals($originHost, $hostPort['host'])) {
                        return $origin . $pathAndQuery;
                    }
                    // Explicit public_origin that does not match this Host must
                    // not invent a second canonical site.
                    return null;
                }
            }
        }

        // Fallback when Master did not inject WLS_PUBLIC_ORIGIN: bounce any
        // non-loopback Host that explicitly names the Worker listen port.
        if (\filter_var($hostPort['host'], FILTER_VALIDATE_IP) !== false
            || $hostPort['host'] === 'localhost'
        ) {
            return null;
        }

        return 'https://' . $hostPort['host'] . $pathAndQuery;
    }

    public static function responseOrNull(
        string $rawHost,
        int $workerListenPort,
        string $publicOrigin,
        string $requestTarget,
        string $edgeAdapter = '',
    ): ?string {
        $location = self::locationOrNull(
            $rawHost,
            $workerListenPort,
            $publicOrigin,
            $requestTarget,
            $edgeAdapter,
        );
        if ($location === null) {
            return null;
        }

        return "HTTP/1.1 308 Permanent Redirect\r\n"
            . 'Location: ' . $location . "\r\n"
            . "Content-Length: 0\r\n"
            . "Connection: close\r\n\r\n";
    }

    public static function isManagedNginxEdge(string $edgeAdapter = ''): bool
    {
        $edge = \strtolower(\trim($edgeAdapter));
        if ($edge === '') {
            $edge = \strtolower(\trim((string)(
                $_SERVER['WLS_EDGE_ADAPTER']
                ?? $_ENV['WLS_EDGE_ADAPTER']
                ?? \getenv('WLS_EDGE_ADAPTER')
                ?: ''
            )));
        }

        return $edge === 'nginx';
    }

    /**
     * @return array{host:string,port:int}|null
     */
    private static function splitAuthority(string $authority): ?array
    {
        if (\str_starts_with($authority, '[')) {
            return null;
        }
        $colon = \strrpos($authority, ':');
        if ($colon === false) {
            return null;
        }
        $portToken = \substr($authority, $colon + 1);
        if ($portToken === '' || \preg_match('/\A[0-9]{1,5}\z/D', $portToken) !== 1) {
            return null;
        }
        $port = (int)$portToken;
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $host = \substr($authority, 0, $colon);
        if ($host === ''
            || \in_array($host, ['0.0.0.0', '::', '*'], true)
            || (\filter_var($host, FILTER_VALIDATE_IP) === false
                && \preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host) !== 1)
        ) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    private static function sanitizeTarget(string $target): string
    {
        $target = \trim($target);
        if ($target === '' || \preg_match('/[\r\n\0]/', $target) === 1) {
            return '/';
        }
        try {
            $path = \parse_url($target, PHP_URL_PATH);
            $query = \parse_url($target, PHP_URL_QUERY);
        } catch (\ValueError) {
            return '/';
        }
        if (!\is_string($path) || $path === '' || \preg_match('/[\r\n\0]/', $path) === 1) {
            $path = '/';
        }
        if (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (!\is_string($query) || $query === '' || \preg_match('/[\r\n\0]/', $query) === 1) {
            return $path;
        }

        return $path . '?' . $query;
    }
}
