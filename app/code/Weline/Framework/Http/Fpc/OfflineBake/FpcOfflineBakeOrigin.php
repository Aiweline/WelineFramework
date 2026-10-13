<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\OfflineBake;

use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;

/**
 * Exact scheme://host[:port] identity for offline FPC bake packages.
 * Port rules match FpcPayloadPathIndex::normalizeIndexKey.
 */
final class FpcOfflineBakeOrigin
{
    public static function normalize(string $originOrUrl): string
    {
        $raw = \trim($originOrUrl);
        if ($raw === '') {
            return '';
        }
        if (!\str_contains($raw, '://')) {
            $raw = 'https://' . \ltrim($raw, '/');
        }
        $parts = \parse_url($raw);
        if (!\is_array($parts)) {
            return '';
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = \strtolower((string)($parts['host'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return '';
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        $authority = $host;
        if ($port > 0) {
            $defaultPort = $scheme === 'https' ? 443 : 80;
            if ($port !== $defaultPort) {
                $authority .= ':' . $port;
            }
        }

        return $scheme . '://' . $authority;
    }

    public static function equals(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        return $na !== '' && $nb !== '' && \hash_equals($na, $nb);
    }

    /**
     * True when host-scoped URL's scheme+authority equals primary origin (exact).
     */
    public static function urlBelongsTo(string $fullUriOrIndexKey, string $primaryOrigin): bool
    {
        $origin = self::normalize($primaryOrigin);
        $indexKey = FpcPayloadPathIndex::normalizeIndexKey($fullUriOrIndexKey);
        if ($origin === '' || $indexKey === '' || !FpcPayloadPathIndex::isHostScopedIndexKey($indexKey)) {
            return false;
        }
        $urlOrigin = self::normalize($indexKey);

        return $urlOrigin !== '' && \hash_equals($origin, $urlOrigin);
    }
}
