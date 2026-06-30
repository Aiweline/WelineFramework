<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

final class BinQueryCachePolicy
{
    public const MARKER_PARAM = '__wq_cache';
    private const MARKER_PREFIX = 'wq1';

    /**
     * @param array<string, mixed> $operation
     */
    public function isCacheableOperation(array $operation): bool
    {
        $cache = $operation['cache'] ?? null;
        if (!\is_array($cache)) {
            return false;
        }
        if (($operation['external'] ?? false) !== true || (string)($operation['mode'] ?? '') !== 'read') {
            return false;
        }
        if (($cache['cdn'] ?? false) !== true || (string)($cache['visibility'] ?? 'public') !== 'public') {
            return false;
        }

        return $this->parseTtlSeconds($cache['ttl'] ?? null) > 0;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $operation
     */
    public function buildMarker(string $area, string $provider, string $operationName, array $params, array $operation): string
    {
        $cache = \is_array($operation['cache'] ?? null) ? $operation['cache'] : [];
        $keyParams = $this->normalizeStringList($cache['key_params'] ?? []);
        $vary = $this->normalizeStringList($cache['vary'] ?? ['area', 'locale', 'currency']);

        $keyData = [
            'area' => $area,
            'provider' => $provider,
            'operation' => $operationName,
            'params' => $this->pickParams($params, $keyParams),
            'vary' => $this->pickVaryValues($area, $params, $vary),
        ];
        $json = \json_encode($keyData, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $hash = \substr(\hash('sha256', \is_string($json) ? $json : ''), 0, 24);

        return \implode('.', [self::MARKER_PREFIX, $area, $provider, $operationName, $hash]);
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, string>
     */
    public function cacheHeaders(array $operation, string $marker): array
    {
        $cache = \is_array($operation['cache'] ?? null) ? $operation['cache'] : [];
        $ttl = $this->parseTtlSeconds($cache['ttl'] ?? null);
        if ($ttl <= 0) {
            return $this->noStoreHeaders('not-cacheable');
        }

        return [
            'Cache-Control' => 'public, max-age=' . $ttl . ', s-maxage=' . $ttl,
            'X-Weline-BinQuery-Cache' => 'cdn',
            'X-Weline-BinQuery-Cache-Marker' => $marker,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function noStoreHeaders(string $reason = 'no-store'): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Weline-BinQuery-Cache' => $reason,
        ];
    }

    public function parseTtlSeconds(mixed $ttl): int
    {
        if (\is_int($ttl)) {
            return \max(0, $ttl);
        }
        $value = \trim((string)$ttl);
        if ($value === '') {
            return 0;
        }
        if (\preg_match('/^\d+$/', $value) === 1) {
            return (int)$value;
        }
        if (\preg_match('/^(\d+)([smhd])$/', $value, $matches) !== 1) {
            return 0;
        }

        $amount = (int)$matches[1];
        return match ($matches[2]) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
            default => 0,
        };
    }

    /**
     * @param mixed $list
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        return \array_values(\array_unique(\array_filter(\array_map(
            static fn(mixed $value): string => \trim((string)$value),
            $list
        ))));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function pickParams(array $params, array $keys): array
    {
        if ($keys === []) {
            $picked = $params;
        } else {
            $picked = [];
            foreach ($keys as $key) {
                if (\array_key_exists($key, $params)) {
                    $picked[$key] = $params[$key];
                }
            }
        }
        \ksort($picked);

        return $picked;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $vary
     * @return array<string, mixed>
     */
    private function pickVaryValues(string $area, array $params, array $vary): array
    {
        $values = [];
        foreach ($vary as $name) {
            $values[$name] = $name === 'area' ? $area : ($params[$name] ?? null);
        }
        \ksort($values);

        return $values;
    }
}
