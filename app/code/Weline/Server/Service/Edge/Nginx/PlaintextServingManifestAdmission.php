<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Plaintext Worker admission helpers for serving-manifest force_https.
 *
 * Pure WLS: browser → Worker cleartext may 301 to HTTPS.
 * Gateway / managed Nginx: loopback H1 upstream must stay on the wire (no
 * redirect) while the app still learns public HTTPS from X-Forwarded-Proto.
 */
final class PlaintextServingManifestAdmission
{
    /**
     * Local Worker probes and control paths must never be force-HTTPS bounced.
     * Managed Nginx proves the loopback upstream with Host: 127.0.0.1 and
     * GET /_wls/health — that Host is not a site route.
     */
    public static function isLocalWorkerProbePath(string $requestTarget): bool
    {
        $path = self::requestPath($requestTarget);
        return $path === '/_wls' || \str_starts_with($path, '/_wls/');
    }

    /**
     * Trusted edge (Nginx / gateway) already terminated client TLS and forwards
     * the public scheme. Wire stays HTTP; framework URL/isSecure use this.
     *
     * @param array<string, mixed> $headers Lower-cased HTTP header map.
     */
    public static function hasGatewayTerminatedHttps(array $headers): bool
    {
        $raw = $headers['x-forwarded-proto'] ?? null;
        if (!\is_string($raw)) {
            return false;
        }
        $raw = \strtolower(\trim($raw));
        if ($raw === '') {
            return false;
        }
        // Take the left-most hop (edge that speaks to this Worker).
        $comma = \strpos($raw, ',');
        if ($comma !== false) {
            $raw = \trim(\substr($raw, 0, $comma));
        }

        return $raw === 'https';
    }

    /**
     * When force_https would redirect, keep the cleartext response instead.
     *
     * @param array<string, mixed> $headers
     */
    public static function shouldAllowDespiteForceHttps(
        string $requestTarget,
        array $headers,
    ): bool {
        return self::isLocalWorkerProbePath($requestTarget)
            || self::hasGatewayTerminatedHttps($headers);
    }

    private static function requestPath(string $requestTarget): string
    {
        $requestTarget = \trim($requestTarget);
        if ($requestTarget === '') {
            return '/';
        }
        try {
            $path = \parse_url($requestTarget, PHP_URL_PATH);
        } catch (\ValueError) {
            return '/';
        }
        if (!\is_string($path) || $path === '' || \preg_match('/[\r\n]/', $path) === 1) {
            return '/';
        }

        return $path;
    }
}
