<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

/**
 * 请求期只读：按 path 匹配 Extra 侧车声明（含 public_path_patterns）。
 */
final class ExtraPolicyResolver
{
    /** @var list<array<string, mixed>>|null */
    private ?array $declarations = null;

    public function __construct(
        private readonly ExtraCollector $collector,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveForPath(string $path, string $type = 'fpc'): ?array
    {
        $path = '/' . ltrim($path, '/');
        $type = strtolower(trim($type));
        foreach ($this->declarations() as $row) {
            if (($row['type'] ?? '') !== $type) {
                continue;
            }
            $pattern = (string)($row['path_pattern'] ?? '');
            if ($pattern !== '' && $this->match($pattern, $path)) {
                return $row;
            }
            foreach ((array)($row['public_path_patterns'] ?? []) as $extraPattern) {
                if ($this->match((string)$extraPattern, $path)) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * 读指纹命名空间：Extra.namespaces ∪ 全局 storefront 向量（调用方再合并）。
     *
     * @return list<string>
     */
    public function namespacesForPath(string $path, string $type = 'fpc'): array
    {
        $row = $this->resolveForPath($path, $type);
        if ($row === null) {
            return [];
        }
        $ns = $row['namespaces'] ?? [];
        return is_array($ns) ? array_values(array_filter(array_map('strval', $ns))) : [];
    }

    /** @return list<array<string, mixed>> */
    private function declarations(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }
        $loaded = $this->collector->loadSidecar();
        $this->declarations = $loaded ?? [];
        return $this->declarations;
    }

    private function match(string $pattern, string $path): bool
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($pattern === $path) {
            return true;
        }
        // 简单通配：* → [^/]+，** → .*
        $regex = '#^' . str_replace(
            ['\*\*', '\*'],
            ['.*', '[^/]+'],
            preg_quote($pattern, '#')
        ) . '$#';
        return preg_match($regex, $path) === 1;
    }
}
