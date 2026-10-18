<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Shared allowlisted query identity for Extra FPC (HTML + BinQuery disk leaves).
 *
 * Pure canonicalize: key sort, first-value wins, rawurlencode(canonical scalar).
 * Caller supplies path-scoped allowlist map; storm keys (_wb_nc / wls_tpl_*) only
 * when $retainStormKeys is true (pierce present). Used by Coordinator and mirrored
 * by Server edge njs — do not fork algorithms.
 */
final class FpcAllowlistedQueryIdentity
{
    /**
     * Scalar string for allowlisted query identity (arrays → first scalar).
     */
    public static function canonicalValue(mixed $value): string
    {
        if (\is_array($value)) {
            $first = \reset($value);
            $value = $first;
        }
        if (!\is_scalar($value) && $value !== null) {
            return '';
        }

        return \trim((string)$value);
    }

    /**
     * @param array<string, bool> $allowlistMap lowercase key → true
     */
    public static function isRetainedKey(string $key, array $allowlistMap, bool $retainStormKeys): bool
    {
        $key = \strtolower(\trim($key));
        if ($key === '') {
            return false;
        }
        if ($key === '_wb_nc' || $key === 'wls_tpl_perf' || $key === 'wls_tpl_route') {
            return $retainStormKeys;
        }

        return $allowlistMap[$key] ?? false;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, bool> $allowlistMap
     * @return array<string, string>
     */
    public static function filterGetParams(array $params, array $allowlistMap, bool $retainStormKeys): array
    {
        $kept = [];
        foreach ($params as $key => $value) {
            $normalized = \strtolower(\trim((string)$key));
            if ($normalized === '' || !self::isRetainedKey($normalized, $allowlistMap, $retainStormKeys)) {
                continue;
            }
            if (\array_key_exists($normalized, $kept)) {
                continue;
            }
            $canonicalValue = self::canonicalValue($value);
            // Empty allowlisted values must not fork identity (e.g. category_id=
            // when switching search type). Align with Search/Listing fromRequest.
            if ($canonicalValue === '') {
                continue;
            }
            $kept[$normalized] = $canonicalValue;
        }
        \ksort($kept, \SORT_STRING);

        return $kept;
    }

    /**
     * @param array<string, bool> $allowlistMap
     */
    public static function filterQueryString(string $query, array $allowlistMap, bool $retainStormKeys): string
    {
        $segments = \preg_split('/[&;]/', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if ($segments === false || $segments === []) {
            return '';
        }

        $keptByKey = [];
        foreach ($segments as $segment) {
            $parts = \explode('=', $segment, 2);
            $rawKey = $parts[0] ?? '';
            $decodedKey = \strtolower(\trim((string)\urldecode((string)$rawKey)));
            if ($decodedKey === '' || !self::isRetainedKey($decodedKey, $allowlistMap, $retainStormKeys)) {
                continue;
            }
            if (!isset($keptByKey[$decodedKey])) {
                $rawValue = $parts[1] ?? '';
                $canonicalValue = self::canonicalValue(\urldecode((string)$rawValue));
                if ($canonicalValue === '') {
                    continue;
                }
                $keptByKey[$decodedKey] = \rawurlencode($decodedKey) . '=' . \rawurlencode($canonicalValue);
            }
        }
        \ksort($keptByKey, \SORT_STRING);

        return \implode('&', \array_values($keptByKey));
    }

    /**
     * Disk leaf segment: sha1 of Coordinator-canonical allowlisted query string.
     */
    public static function querySha1(string $canonicalQuery): string
    {
        return \sha1($canonicalQuery);
    }
}
