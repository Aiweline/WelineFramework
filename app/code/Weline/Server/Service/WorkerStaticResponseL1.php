<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Security\WorkerPolicyDecision;

/**
 * Process-local, preformatted static-response L1 for persistent Workers.
 *
 * Mandatory request policy runs before this cache. A cold request is still
 * resolved and read by the transport's canonical static-file handler; only a
 * successfully formatted 200 response is published here. Cache invalidation
 * is epoch/command driven, so a hot hit never performs stat/filemtime calls.
 */
final class WorkerStaticResponseL1
{
    private const MAX_ENTRY_BYTES = 262_144;
    private const MAX_TOTAL_BYTES = 16_777_216;
    private const DEFAULT_MAX_AGE_SECONDS = 604_800;

    /**
     * @var array<string, array{
     *     response:string,
     *     etag:string,
     *     last_modified:string,
     *     path:string,
     *     cached_at:int,
     *     expires_at:int,
     *     hits:int,
     *     last_access:int,
     *     size:int
     * }>
     */
    private static array $entries = [];

    private static int $totalBytes = 0;

    /**
     * Resolve a hot static response from the immutable mandatory-policy result.
     *
     * The Worker has already parsed and normalized the request once. Keeping
     * this API decision-only prevents every transport from rescanning the raw
     * request line and headers before Static L1.
     */
    public static function lookup(WorkerPolicyDecision $decision): ?string
    {
        if (!$decision->allowed
            || !$decision->staticProcessCacheEnabled()
            || !\in_array($decision->method, ['GET', 'HEAD'], true)
        ) {
            return null;
        }

        // Byte ranges and write-style preconditions require the canonical file
        // handler's 206/412/416 semantics. Never turn them into a cached 200.
        foreach (['range', 'if-range', 'if-match', 'if-unmodified-since'] as $headerName) {
            if (\trim((string)($decision->headers[$headerName] ?? '')) !== '') {
                return null;
            }
        }

        $key = self::cacheKey($decision->target);
        if ($key === null || !isset(self::$entries[$key])) {
            return null;
        }

        $now = \time();
        $entry = &self::$entries[$key];
        if (($entry['expires_at'] ?? 0) <= $now) {
            self::remove($key);
            return null;
        }

        $entry['hits']++;
        // LRU only needs coarse recency; avoid mutating the cache line on every hit.
        if (($entry['hits'] & 63) === 0) {
            $entry['last_access'] = $now;
        }

        $keepAlive = $decision->keepAlive();
        $ifNoneMatch = \trim((string)($decision->headers['if-none-match'] ?? ''));
        if ($ifNoneMatch !== '') {
            // RFC 9110: If-None-Match takes precedence over If-Modified-Since.
            if (self::etagListMatches($ifNoneMatch, $entry['etag'])) {
                return self::notModifiedResponse($entry['etag'], $keepAlive);
            }
        } else {
            $ifModifiedSince = \trim((string)($decision->headers['if-modified-since'] ?? ''));
            if ($ifModifiedSince !== '') {
                $requestedAt = \strtotime($ifModifiedSince);
                $lastModifiedAt = \strtotime($entry['last_modified']);
                if ($requestedAt !== false && $lastModifiedAt !== false && $lastModifiedAt <= $requestedAt) {
                    return self::notModifiedResponse($entry['etag'], $keepAlive);
                }
            }
        }

        $response = $keepAlive
            ? $entry['response']
            : self::withConnection($entry['response'], 'close');
        if ($decision->method === 'HEAD') {
            return self::withoutBody($response);
        }

        return $response;
    }

    public static function publish(
        string $requestTarget,
        string $response,
        string $path,
        string $etag,
        string $lastModified,
        int $cachedAt,
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
    ): void {
        $key = self::cacheKey($requestTarget);
        if ($key === null || $response === '') {
            return;
        }

        $hitResponse = (string)\preg_replace(
            '/\r\nX-WLS-Static-Cache:\s*(?:MISS|HIT)[^\r\n]*/i',
            "\r\nX-WLS-Static-Cache: HIT",
            $response,
            1,
        );
        if ($hitResponse === $response && \stripos($response, "\r\nX-WLS-Static-Cache: HIT") === false) {
            return;
        }

        $hitResponse = self::withConnection($hitResponse, 'keep-alive');
        $size = \strlen($hitResponse);
        if ($size <= 0 || $size > self::MAX_ENTRY_BYTES || $size > self::MAX_TOTAL_BYTES) {
            return;
        }

        self::remove($key);
        self::evictUntilFits($size);
        if (self::$totalBytes + $size > self::MAX_TOTAL_BYTES) {
            return;
        }

        $cachedAt = $cachedAt > 0 ? $cachedAt : \time();
        $maxAgeSeconds = \max(1, $maxAgeSeconds);
        self::$entries[$key] = [
            'response' => $hitResponse,
            'etag' => $etag,
            'last_modified' => $lastModified,
            'path' => $path,
            'cached_at' => $cachedAt,
            'expires_at' => $cachedAt + $maxAgeSeconds,
            'hits' => 0,
            'last_access' => $cachedAt,
            'size' => $size,
        ];
        self::$totalBytes += $size;
    }

    /** @return array{count:int,size:int,max_total:int,max_entry:int} */
    public static function status(): array
    {
        return [
            'count' => \count(self::$entries),
            'size' => self::$totalBytes,
            'max_total' => self::MAX_TOTAL_BYTES,
            'max_entry' => self::MAX_ENTRY_BYTES,
        ];
    }

    /** @return array{count:int,size:int} */
    public static function clear(): array
    {
        $result = [
            'count' => \count(self::$entries),
            'size' => self::$totalBytes,
        ];
        self::$entries = [];
        self::$totalBytes = 0;
        return $result;
    }

    private static function cacheKey(string $requestTarget): ?string
    {
        $path = WlsStaticUriPathResolver::resolvePath($requestTarget);
        return $path === null ? null : '/' . \trim($path, '/');
    }

    private static function withConnection(string $response, string $connection): string
    {
        $normalized = \preg_replace(
            '/\r\nConnection:\s*[^\r\n]*/i',
            "\r\nConnection: {$connection}",
            $response,
            1,
        );
        return \is_string($normalized) ? $normalized : $response;
    }

    private static function notModifiedResponse(string $etag, bool $keepAlive): string
    {
        return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\n"
            . 'X-WLS-Static-Cache: HIT' . "\r\nConnection: "
            . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\n";
    }

    private static function etagListMatches(string $condition, string $etag): bool
    {
        $normalize = static fn(string $value): string => \preg_replace('/^W\//i', '', \trim($value)) ?? '';
        $expected = $normalize($etag);
        foreach (\explode(',', $condition) as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '*' || ($expected !== '' && $normalize($candidate) === $expected)) {
                return true;
            }
        }

        return false;
    }

    private static function withoutBody(string $response): string
    {
        $headerEnd = \strpos($response, "\r\n\r\n");
        return $headerEnd === false ? $response : \substr($response, 0, $headerEnd + 4);
    }

    private static function evictUntilFits(int $neededBytes): void
    {
        while (self::$entries !== [] && self::$totalBytes + $neededBytes > self::MAX_TOTAL_BYTES) {
            $coldestKey = null;
            $coldestAccess = PHP_INT_MAX;
            $coldestHits = PHP_INT_MAX;
            foreach (self::$entries as $key => $entry) {
                $lastAccess = (int)($entry['last_access'] ?? 0);
                $hits = (int)($entry['hits'] ?? 0);
                if ($lastAccess < $coldestAccess || ($lastAccess === $coldestAccess && $hits < $coldestHits)) {
                    $coldestKey = $key;
                    $coldestAccess = $lastAccess;
                    $coldestHits = $hits;
                }
            }
            if ($coldestKey === null) {
                return;
            }
            self::remove($coldestKey);
        }
    }

    private static function remove(string $key): void
    {
        if (!isset(self::$entries[$key])) {
            return;
        }
        self::$totalBytes = \max(0, self::$totalBytes - (int)self::$entries[$key]['size']);
        unset(self::$entries[$key]);
    }
}
