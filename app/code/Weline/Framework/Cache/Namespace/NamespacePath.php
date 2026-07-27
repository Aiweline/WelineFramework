<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Namespace;

/**
 * Canonical namespace path parser and typed builder.
 *
 * Public paths are already raw-url-encoded, NFC-normalized paths rooted at
 * website/{code} or global/{scope}. The reserved authority row is never a
 * valid public path.
 */
final class NamespacePath
{
    public const AUTHORITY_CLOCK = '@clock';
    public const MAX_SEGMENTS = 16;
    public const MAX_BYTES = 512;

    public function canonicalize(string $path): string
    {
        if ($path === '' || $path === self::AUTHORITY_CLOCK) {
            throw new \InvalidArgumentException(__('缓存命名空间不能为空或使用内部保留名称'));
        }
        if (strlen($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(__('缓存命名空间不能超过 %{1} 字节', [self::MAX_BYTES]));
        }
        if (!preg_match('//u', $path)) {
            throw new \InvalidArgumentException(__('缓存命名空间必须是有效 UTF-8'));
        }
        if (str_contains($path, '\\')) {
            throw new \InvalidArgumentException(__('缓存命名空间不能包含反斜杠'));
        }
        if (str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '//')) {
            throw new \InvalidArgumentException(__('缓存命名空间不能包含首尾或重复斜杠'));
        }
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $path) === 1) {
            throw new \InvalidArgumentException(__('缓存命名空间不能包含控制字符'));
        }

        $segments = explode('/', $path);
        if (count($segments) < 2 || count($segments) > self::MAX_SEGMENTS) {
            throw new \InvalidArgumentException(
                __('缓存命名空间必须包含 2 到 %{1} 个路径段', [self::MAX_SEGMENTS])
            );
        }
        if ($segments[0] !== 'website' && $segments[0] !== 'global') {
            throw new \InvalidArgumentException(__('缓存命名空间根仅支持 website 或 global'));
        }

        foreach ($segments as $segment) {
            $this->assertCanonicalEncodedSegment($segment);
        }

        return $path;
    }

    /** @param list<string> $paths @return list<string> */
    public function canonicalizeMany(array $paths): array
    {
        if ($paths === []) {
            throw new \InvalidArgumentException(__('至少需要一个缓存命名空间'));
        }

        $canonical = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new \InvalidArgumentException(__('缓存命名空间必须是字符串'));
            }
            $value = $this->canonicalize($path);
            $canonical[$value] = true;
        }
        $result = array_keys($canonical);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    public function ancestors(string $path): array
    {
        $segments = explode('/', $this->canonicalize($path));
        $ancestors = [];
        for ($depth = 2, $count = count($segments); $depth <= $count; $depth++) {
            $ancestors[] = implode('/', array_slice($segments, 0, $depth));
        }
        return $ancestors;
    }

    /** @param list<string> $paths @return list<string> */
    public function expandAncestors(array $paths): array
    {
        $expanded = [];
        foreach ($this->canonicalizeMany($paths) as $path) {
            foreach ($this->ancestors($path) as $ancestor) {
                $expanded[$ancestor] = true;
            }
        }
        $result = array_keys($expanded);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $segments */
    public function website(string $websiteCode, array $segments = []): string
    {
        return $this->fromRawSegments('website', $websiteCode, $segments);
    }

    /** @param list<string> $segments */
    public function global(string $scope, array $segments = []): string
    {
        return $this->fromRawSegments('global', $scope, $segments);
    }

    public function hash(string $path): string
    {
        return hash('sha256', $this->canonicalize($path));
    }

    /** @param list<string> $segments */
    private function fromRawSegments(string $root, string $scope, array $segments): string
    {
        $raw = array_merge([$root, $scope], $segments);
        $encoded = [];
        foreach ($raw as $segment) {
            if (!is_string($segment)) {
                throw new \InvalidArgumentException(__('缓存命名空间路径段必须是字符串'));
            }
            $normalized = $this->normalizeRawSegment($segment);
            if ($normalized === '' || $normalized === '.' || $normalized === '..') {
                throw new \InvalidArgumentException(__('缓存命名空间不能包含空段、点段或双点段'));
            }
            $encoded[] = rawurlencode($normalized);
        }
        return $this->canonicalize(implode('/', $encoded));
    }

    private function assertCanonicalEncodedSegment(string $segment): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new \InvalidArgumentException(__('缓存命名空间不能包含空段、点段或双点段'));
        }
        if (preg_match('/%(?![0-9A-F]{2})/', $segment) === 1) {
            throw new \InvalidArgumentException(__('缓存命名空间百分号编码必须使用两位大写十六进制'));
        }

        $decoded = rawurldecode($segment);
        $normalized = $this->normalizeRawSegment($decoded);
        if ($normalized !== $decoded || rawurlencode($normalized) !== $segment) {
            throw new \InvalidArgumentException(__('缓存命名空间路径段不是规范 raw-url 编码'));
        }
    }

    private function normalizeRawSegment(string $segment): string
    {
        if (!preg_match('//u', $segment)) {
            throw new \InvalidArgumentException(__('缓存命名空间路径段必须是有效 UTF-8'));
        }
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $segment) === 1) {
            throw new \InvalidArgumentException(__('缓存命名空间路径段不能包含控制字符'));
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($segment, \Normalizer::FORM_C);
            if (!is_string($normalized)) {
                throw new \InvalidArgumentException(__('缓存命名空间路径段无法完成 NFC 规范化'));
            }
            return $normalized;
        }
        if (preg_match('/[^\x00-\x7F]/', $segment) === 1) {
            throw new \RuntimeException(__('当前运行环境不能验证非 ASCII 命名空间的 NFC 规范'));
        }
        return $segment;
    }
}
