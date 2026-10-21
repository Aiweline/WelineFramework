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

    /**
     * 请求级覆盖（主题更新代次用）：非空时 {@see current()} 直接返回它，
     * 使所有静态 URL 前缀、FPC/缓存维度自动跟随当前代次。
     */
    private static ?string $override = null;

    public static function override(?string $buildId): void
    {
        $id = trim((string)$buildId);
        self::$override = $id === '' ? null : $id;
    }

    public static function currentOverride(): ?string
    {
        return self::$override;
    }

    public static function current(?string $staticRoot = null): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
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
        if ($buildId === null) {
            $buildId = self::themeUpdateBuildId($path);
        }
        $buildId = $buildId ?? self::current();
        if ($buildId === '' || !\str_starts_with($path, '/static/')) {
            return $path;
        }
        // 主题更新代次根（/static/tu-...）自带代次命名空间，不再套构建前缀。
        $firstSegment = \explode('/', \trim((string)\strtok($path, '?'), '/'))[1] ?? '';
        if (\str_starts_with($firstSegment, 'tu-')) {
            return $path;
        }
        // 内容寻址文件（32~64 位 hex 文件名）URL 与字节天然绑定，不需要构建前缀。
        $basename = \basename((string)\strtok($path, '?'));
        if (\preg_match('/^[0-9a-f]{32,64}\.(?:css|js|mjs|map|json|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|eot|txt|xml|wasm|mp4|webm|mp3|ogg|wav)$/i', $basename) === 1) {
            return $path;
        }
        [$urlPath, $query] = \str_contains($path, '?') ? \explode('?', $path, 2) : [$path, null];
        $trailingSlash = \str_ends_with((string)$urlPath, '/');
        $parts = \explode('/', trim((string)$urlPath, '/'));
        if (($parts[1] ?? '') === $buildId) {
            return $path;
        }
        $rest = \implode('/', \array_slice($parts, 1));
        $result = '/static/' . $buildId . ($rest === '' ? '' : '/' . $rest) . ($trailingSlash ? '/' : '');
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

    /**
     * 主题更新代次：主题自有资源用批次前缀，其余返回 null（沿用部署构建号）。
     * 弱依赖 Theme（class_exists + 静默），避免 Framework 硬依赖。
     */
    private static function themeUpdateBuildId(string $path): ?string
    {
        if (!class_exists('\\Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateRuntime')) {
            return null;
        }
        try {
            return \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::staticPrefixForPath($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
