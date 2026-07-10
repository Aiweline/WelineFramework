<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Resolves an HTTP request target to one safe, single-decoded URI path.
 *
 * Static-file callers must reject a null result instead of rewriting an
 * unsafe target. Decoding per segment keeps encoded separators from changing
 * the directory boundary and preserves a literal plus sign.
 */
final class WlsStaticUriPathResolver
{
    private const MAX_REQUEST_TARGET_BYTES = 8192;
    private const MAX_DECODED_PATH_BYTES = 4096;

    public static function resolvePath(string $requestTarget): ?string
    {
        if ($requestTarget === ''
            || \strlen($requestTarget) > self::MAX_REQUEST_TARGET_BYTES
            || \preg_match('/[\x00-\x1F\x7F]/', $requestTarget) === 1
        ) {
            return null;
        }

        $rawPath = self::extractRawPath($requestTarget);
        if ($rawPath === null
            || $rawPath === ''
            || \strlen($rawPath) > self::MAX_REQUEST_TARGET_BYTES
            || \str_contains($rawPath, '\\')
            || \preg_match('/%(?![0-9A-Fa-f]{2})/', $rawPath) === 1
            || \preg_match('/%(?:2[fF]|5[cC])/', $rawPath) === 1
        ) {
            return null;
        }

        $decodedSegments = [];
        foreach (\explode('/', $rawPath) as $segment) {
            $decoded = \rawurldecode($segment);
            if ($decoded === '.'
                || $decoded === '..'
                || \str_contains($decoded, '/')
                || \str_contains($decoded, '\\')
                || \preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || \preg_match('//u', $decoded) !== 1
            ) {
                return null;
            }
            $decodedSegments[] = $decoded;
        }

        $decodedPath = \implode('/', $decodedSegments);
        if ($decodedPath === '' || $decodedPath[0] !== '/') {
            $decodedPath = '/' . $decodedPath;
        }

        return \strlen($decodedPath) <= self::MAX_DECODED_PATH_BYTES
            ? $decodedPath
            : null;
    }

    private static function extractRawPath(string $requestTarget): ?string
    {
        if ($requestTarget[0] === '/') {
            $end = \strcspn($requestTarget, '?#');
            return \substr($requestTarget, 0, $end);
        }

        try {
            $parts = \parse_url($requestTarget);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array(\strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        return \is_string($path) && $path !== '' ? $path : '/';
    }
}
