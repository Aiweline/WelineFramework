<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 像素事件供应商范围：路径包含/排除 + 应用面（frontend|backend）。
 */
final class PixelEventVendorScope
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';

    /**
     * @param array<string, mixed> $raw
     * @return array{path_include:list<string>,path_exclude:list<string>,areas:list<string>}
     */
    public static function normalize(array $raw): array
    {
        $include = self::normalizePathList($raw['path_include'] ?? ['*']);
        if ($include === []) {
            $include = ['*'];
        }
        $exclude = self::normalizePathList($raw['path_exclude'] ?? []);
        $areas = [];
        foreach ((array)($raw['areas'] ?? [self::AREA_FRONTEND]) as $area) {
            $a = \strtolower(\trim((string)$area));
            if ($a === self::AREA_FRONTEND || $a === self::AREA_BACKEND) {
                $areas[$a] = $a;
            }
        }
        if ($areas === []) {
            $areas[self::AREA_FRONTEND] = self::AREA_FRONTEND;
        }

        return [
            'path_include' => \array_values($include),
            'path_exclude' => \array_values($exclude),
            'areas' => \array_values($areas),
        ];
    }

    public static function defaultScope(): array
    {
        return self::normalize([]);
    }

    /**
     * @param array{path_include?:list<string>,path_exclude?:list<string>,areas?:list<string>} $scope
     */
    public static function matches(array $scope, string $path, string $area): bool
    {
        $scope = self::normalize($scope);
        $area = \strtolower(\trim($area));
        if (!\in_array($area, $scope['areas'], true)) {
            return false;
        }
        $path = self::normalizePath($path);
        if ($path === '') {
            $path = '/';
        }
        if (!self::pathListMatches($scope['path_include'], $path)) {
            return false;
        }
        if (self::pathListMatches($scope['path_exclude'], $path)) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function normalizePathList(mixed $list): array
    {
        if (\is_string($list)) {
            $list = \preg_split('/\r\n|\r|\n/', $list) ?: [];
        }
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $p = self::normalizePathPattern((string)$item);
            if ($p !== '') {
                $out[$p] = $p;
            }
        }

        return \array_values($out);
    }

    private static function normalizePath(string $path): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (($q = \strpos($path, '?')) !== false) {
            $path = \substr($path, 0, $q);
        }
        if ($path[0] !== '/' && $path !== '*') {
            $path = '/' . $path;
        }

        return $path === '' ? '/' : $path;
    }

    private static function normalizePathPattern(string $pattern): string
    {
        $pattern = \trim($pattern);
        if ($pattern === '') {
            return '';
        }
        if ($pattern === '*') {
            return '*';
        }

        return self::normalizePath($pattern);
    }

    /**
     * @param list<string> $patterns
     */
    private static function pathListMatches(array $patterns, string $path): bool
    {
        if ($patterns === []) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private static function pathMatches(string $pattern, string $path): bool
    {
        if ($pattern === '*' || $pattern === '/*') {
            return true;
        }
        if (\str_ends_with($pattern, '*')) {
            $prefix = \substr($pattern, 0, -1);

            return $prefix === '' || \str_starts_with($path, $prefix);
        }

        return $path === $pattern || \str_starts_with($path, \rtrim($pattern, '/') . '/');
    }
}
