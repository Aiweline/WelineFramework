<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\ScopeIdentity;

/** 已编译策略矩阵的纯内存解释器。 */
final class FpcPolicySnapshot
{
    public static function declarationId(array $row): string
    {
        return hash('sha256', implode("\n", [(string)($row['module'] ?? ''), ltrim((string)($row['class'] ?? ''), '\\'),
            strtolower((string)($row['method'] ?? '')), strtolower((string)($row['type'] ?? 'fpc'))]));
    }

    public static function normalizePath(string $uri, string $websiteUrl = ''): string
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = State::stripWebsitePathPrefix($path, $websiteUrl);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
        $localized = State::resolveLocalizationFromPathSegments($segments);
        return '/' . implode('/', (array)$localized['remaining']);
    }

    public static function resolve(array $snapshot, string $path, ScopeIdentity $scope, ?string $storeMode = null): ?array
    {
        $candidates = self::matching($snapshot['declarations'] ?? [], $path, 'fpc');
        $resolved = [];
        foreach ($candidates as $candidate) {
            $row = $candidate['row'];
            $id = (string)($row['declaration_id'] ?? self::declarationId($row));
            $attrs = $row['attrs'] ?? [];
            $codeEnabled = (bool)($attrs['enabled'] ?? true);
            $codeTtl = max(0, (int)($attrs['ttl'] ?? 31536000));
            $enabled = $codeEnabled;
            $ttl = $codeTtl;
            $enabledSource = $ttlSource = 'code';
            $mode = $scope->storeMode ?? $storeMode ?? ScopeIdentity::MODE_NORMAL;
            $chain = $snapshot['scope_chains'][$mode][$scope->canonicalKey()] ?? self::scopeChain($scope);
            foreach ($chain as $key) {
                $override = $snapshot['overrides'][$mode][$key][$id] ?? [];
                if ($enabledSource === 'code' && isset($override['enabled'])) {
                    $enabled = (bool)$override['enabled'];
                    $enabledSource = $key;
                }
                if ($ttlSource === 'code' && isset($override['ttl']) && (int)$override['ttl'] > 0) {
                    $ttl = (int)$override['ttl'];
                    $ttlSource = $key;
                }
            }
            if (!$codeEnabled || $codeTtl === 0) {
                $enabled = false;
                $enabledSource = 'code';
                if ($codeTtl === 0) {
                    $ttl = 0;
                    $ttlSource = 'code';
                }
            }
            $namespaces = array_values(array_unique(array_map('strval', (array)($row['namespaces'] ?? $attrs['namespaces'] ?? []))));
            sort($namespaces, SORT_STRING);
            $paths = array_values(array_unique([(string)($row['path_pattern'] ?? ''), ...(array)($row['public_path_patterns'] ?? [])]));
            sort($paths, SORT_STRING);
            $queryKeys = self::queryKeysFromAttrs($attrs);
            $resolved[] = ['rank' => $candidate['rank'], 'policy' => [
                'declaration_id' => $id, 'enabled' => $enabled, 'ttl' => $ttl, 'namespaces' => $namespaces,
                'query_keys' => $queryKeys,
                'policy_fingerprint' => hash('sha256', json_encode([$id, $codeEnabled, $codeTtl, $enabled, $ttl, $namespaces, $paths, $queryKeys, $scope->canonicalKey(), $mode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'snapshot_revision' => (string)($snapshot['revision'] ?? ''), 'source_version' => (int)($snapshot['source_version'] ?? 0),
                'enabled_source' => $enabledSource, 'ttl_source' => $ttlSource,
            ]];
        }
        usort($resolved, static fn(array $a, array $b): int => ($a['rank'] <=> $b['rank'])
            ?: ($a['policy']['enabled'] <=> $b['policy']['enabled'])
            ?: strcmp($a['policy']['declaration_id'], $b['policy']['declaration_id']));
        return $resolved[0]['policy'] ?? null;
    }

    /** @return list<array{row:array,rank:array}> */
    public static function matching(array $rows, string $path, string $type): array
    {
        $path = '/' . ltrim($path, '/');
        $matches = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? 'fpc') !== $type) {
                continue;
            }
            $best = null;
            foreach ([(string)($row['path_pattern'] ?? ''), ...(array)($row['public_path_patterns'] ?? [])] as $pattern) {
                if ($pattern === '') {
                    continue;
                }
                $pattern = '/' . ltrim($pattern, '/');
                $regex = '#^' . str_replace(['\\*\\*', '\\*'], ['.*', '[^/]+'], preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $path) !== 1) {
                    continue;
                }
                $star = strpos($pattern, '*');
                $rank = [$star === false ? 0 : 1, -($star === false ? strlen($pattern) : $star), substr_count($pattern, '*')];
                if ($best === null || $rank < $best) {
                    $best = $rank;
                }
            }
            if ($best !== null) {
                $matches[] = ['row' => $row, 'rank' => $best];
            }
        }
        usort($matches, static fn(array $a, array $b): int => ($a['rank'] <=> $b['rank'])
            ?: ((bool)($a['row']['attrs']['enabled'] ?? true) <=> (bool)($b['row']['attrs']['enabled'] ?? true))
            ?: strcmp(self::declarationId($a['row']), self::declarationId($b['row'])));
        return $matches;
    }

    /**
     * Guest-shared allowlist from a resolved policy (empty = path-only).
     *
     * @param array<string, mixed>|null $policy
     * @return list<string>
     */
    public static function queryKeysFromPolicy(?array $policy): array
    {
        if (!\is_array($policy)) {
            return [];
        }

        return self::queryKeysFromAttrs($policy);
    }

    /**
     * True when the request query carries at least one allowlisted key (β edge bypass gate).
     *
     * @param list<string> $queryKeys
     * @param array<string, mixed> $query
     */
    public static function requestCarriesAllowlistedQuery(array $queryKeys, array $query): bool
    {
        if ($queryKeys === [] || $query === []) {
            return false;
        }
        $lookup = [];
        foreach ($query as $rawKey => $_value) {
            $lookup[\strtolower(\trim((string)$rawKey))] = true;
        }
        foreach ($queryKeys as $key) {
            $key = \strtolower(\trim((string)$key));
            if ($key !== '' && isset($lookup[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<string>
     */
    private static function queryKeysFromAttrs(array $attrs): array
    {
        $raw = $attrs['query_keys'] ?? [];
        if (\is_string($raw)) {
            $raw = \array_map('trim', \explode(',', $raw));
        }
        if (!\is_array($raw)) {
            return [];
        }
        $keys = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $key = \strtolower(\trim((string)$item));
            if ($key === '' || $key === '*' || \str_contains($key, '*')) {
                continue;
            }
            $keys[$key] = true;
        }
        $list = \array_keys($keys);
        \sort($list, \SORT_STRING);

        return $list;
    }

    private static function scopeChain(ScopeIdentity $scope): array
    {
        $chain = [$scope->canonicalKey()];
        if ($scope->scopeKind === ScopeIdentity::KIND_CHANNEL) {
            $chain[] = ScopeIdentity::store($scope->websiteId, $scope->websiteCode, $scope->storeCode, $scope->storeMode)->canonicalKey();
        }
        if ($scope->scopeKind === ScopeIdentity::KIND_CHANNEL || $scope->scopeKind === ScopeIdentity::KIND_STORE) {
            $chain[] = ScopeIdentity::website($scope->websiteId, $scope->websiteCode)->canonicalKey();
        }
        $chain[] = ScopeIdentity::global()->canonicalKey();
        return array_values(array_unique($chain));
    }
}
