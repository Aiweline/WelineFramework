<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

final class StaticBuildId
{
    public const MANIFEST = '.build.json';
    public const BUILD_MARKER = '.weline-build-id';

    /** Worker 进程常驻；发布后最多 10s 内必须能看到新代次。 */
    private const CACHE_TTL_SECONDS = 10;

    /** @var array<string, array{id:string,at:int}> */
    private static array $cache = [];

    public static function current(?string $staticRoot = null): string
    {
        $root = self::normalizeRoot($staticRoot ?? self::defaultRoot());
        $cached = self::$cache[$root] ?? null;
        if (\is_array($cached) && (\time() - (int)$cached['at']) < self::CACHE_TTL_SECONDS) {
            return (string)$cached['id'];
        }
        $id = '';
        $raw = @file_get_contents($root . '/' . self::MANIFEST);
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                $candidate = $decoded['build_id'] ?? '';
                if (\is_string($candidate) && \preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/', $candidate)) {
                    $id = $candidate;
                }
            }
        }
        self::$cache[$root] = ['id' => $id, 'at' => \time()];

        return $id;
    }

    public static function forget(?string $root = null): void
    {
        if ($root === null) {
            self::$cache = [];
            return;
        }
        unset(self::$cache[self::normalizeRoot($root)]);
    }

    public static function prefix(?string $buildId = null): string
    {
        $buildId = $buildId ?? self::current();
        if ($buildId === '') {
            return '';
        }
        return '/static/' . $buildId;
    }

    public static function versionedPath(string $path, ?string $buildId = null): string
    {
        $buildId = $buildId ?? self::current();
        if ($buildId === '' || !\str_starts_with($path, '/static/')) {
            return $path;
        }
        [$urlPath, $query] = \str_contains($path, '?') ? \explode('?', $path, 2) : [$path, null];
        $parts = \explode('/', trim((string)$urlPath, '/'));
        if (($parts[1] ?? '') === $buildId) {
            return $path;
        }
        $rest = \implode('/', \array_slice($parts, 1));
        $result = '/static/' . $buildId . ($rest === '' ? '' : '/' . $rest);
        return $query === null ? $result : $result . '?' . $query;
    }

    private static function defaultRoot(): string
    {
        return self::normalizeRoot(\rtrim(PUB, '/\\') . '/static');
    }

    private static function normalizeRoot(string $root): string
    {
        return \rtrim($root, '/\\');
    }
}
