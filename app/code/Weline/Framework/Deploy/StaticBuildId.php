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

    /** 进程内契约开关覆盖（测试/单请求强制）；null = 读 Env dev 配置。 */
    private static ?bool $flatUrlsOverride = null;

    public static function setFlatUrlsOverride(?bool $enabled): void
    {
        self::$flatUrlsOverride = $enabled;
    }

    /**
     * 契约开关（灰度）：静态 URL 收敛为「单权威 flat 树 + ?v= 纯 query 破缓存」。
     * 开启后 {@see versionedPath()} 只做剥段归一，不再注入构建号/主题对路径段；
     * 关闭即回滚到旧的路径段世代形态。
     */
    public static function flatUrlsEnabled(): bool
    {
        if (self::$flatUrlsOverride !== null) {
            return self::$flatUrlsOverride;
        }
        try {
            return (bool) \Weline\Framework\App\Env::dev('static_flat_urls', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /** {@see flatUrlsEnabled()} 的语义别名：flat 单权威树模式下发布侧应原地覆盖、禁删被引用目录。 */
    public static function isFlatMode(): bool
    {
        return self::flatUrlsEnabled();
    }

    /**
     * 剥掉 URL 中的世代路径段，还原 flat 权威树路径。
     * 识别 12hex 构建段、tu- 批次段、主题对段（code/vN/deploy）三类前缀；
     * 非世代形状原样返回。幂等。query 保留。
     */
    public static function stablePath(string $path): string
    {
        if (!\str_starts_with($path, '/static/')) {
            return $path;
        }
        if (\str_contains($path, '/__preview/') || \str_starts_with($path, '/static/__preview')) {
            return $path;
        }
        [$urlPath, $query] = \str_contains($path, '?') ? \explode('?', $path, 2) : [$path, null];
        $trailingSlash = \str_ends_with($urlPath, '/');
        $parts = \explode('/', trim($urlPath, '/'));
        // $parts[0] === 'static'
        $first = $parts[1] ?? '';
        $strip = 0;
        if (\preg_match('/^[0-9a-f]{12}$/i', $first) === 1 || \str_starts_with($first, 'tu-')) {
            $strip = 1;
        } elseif (\preg_match('#^v[0-9]+$#', $parts[2] ?? '') === 1
            && ($parts[3] ?? '') !== '' && \preg_match('#^[0-9a-f]{12}$#i', $parts[3]) === 1) {
            // {code}/v{n}/{deploy12hex} 三段世代前缀
            $strip = 3;
        }
        if ($strip === 0) {
            return $path;
        }
        $rest = \implode('/', \array_slice($parts, 1 + $strip));
        $result = '/static/' . $rest . ($rest !== '' && $trailingSlash ? '/' : '');

        return $query === null ? $result : $result . '?' . $query;
    }

    public static function versionedPath(string $path, ?string $buildId = null): string
    {
        // 预览静态命名空间（/static/__preview/<key>/… 或 /static/<ns>/__preview/…）不可被构建号/代次前缀改写，
        // 否则预览会丢 __preview 段、看到正式资源。
        if (\str_contains($path, '/__preview/') || \str_starts_with($path, '/static/__preview')) {
            return $path;
        }
        if (self::flatUrlsEnabled()) {
            // 单权威树契约：URL 只认稳定 flat 路径，破缓存交给 ?v= query；不再制造路径段世代。
            return self::stablePath($path);
        }
        if ($buildId === null) {
            $deploy = self::current();
            if ($deploy === '') {
                $deploy = self::themePinnedStaticBuild();
            }
            // 主题自有资源 → 主题对目录 `/{code}/v{n}/{buildId}`；其余沿用部署构建号。
            // 两边都没有构建号时不改写，避免发出没有对应目录的 `/{code}/v{n}/`。
            $pair = self::themePairPrefix($path);
            if ($pair !== null && $pair['pair'] !== '') {
                if ($deploy === '') {
                    $buildId = '';
                } else {
                    [$code, $version] = explode('/v', $pair['pair'], 2);
                    $buildId = $code . '/v' . $version . '/' . $deploy;
                }
            } else {
                $buildId = self::themeUpdateBuildId($path) ?? $deploy;
            }
        }
        $buildId = $buildId ?? self::current();
        if ($buildId === '' || !\str_starts_with($path, '/static/')) {
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
        $first = $parts[1] ?? '';
        // 主题对目录：/static/{code}/v{n}/{buildId}/…（第二段是 v{n}）。
        // $buildId 可能是纯部署号，也可能是上面拼好的 {code}/v{n}/{部署号}。
        // 后者出现时，URL 里旧的 code/v{n} 要换成当前主题对，不能再叠一层。
        $isThemePair = \preg_match('/^v[0-9]+$/', $parts[2] ?? '') === 1;
        if ($isThemePair) {
            $pairPrefix = $first . '/' . ($parts[2] ?? '');
            $deployOnly = (string)$buildId;
            if (\preg_match('#^([A-Za-z0-9_.-]+/v[0-9]+)/([A-Za-z0-9_-]+)$#', (string)$buildId, $composed) === 1) {
                $pairPrefix = $composed[1];
                $deployOnly = $composed[2];
            }
            if ($pairPrefix === $first . '/' . ($parts[2] ?? '') && ($parts[3] ?? '') === $deployOnly) {
                return $path;
            }
            $rest = \implode('/', \array_slice($parts, 4));
            $result = '/static/' . $pairPrefix . '/' . $deployOnly . ($rest === '' ? '' : '/' . $rest) . ($trailingSlash ? '/' : '');

            return $query === null ? $result : $result . '?' . $query;
        }
        // 代次段形状（旧构建号 / tu-*）→ 替换；命名空间（Weline/...）→ 前置当前代次。
        $isGenerationSegment = \preg_match('/^[0-9a-f]{12}$/i', $first) === 1
            || \str_starts_with($first, 'tu-');
        if ($isGenerationSegment) {
            if ($first === $buildId) {
                return $path;
            }
            $rest = \implode('/', \array_slice($parts, 2));
        } else {
            $rest = \implode('/', \array_slice($parts, 1));
        }
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

    /** 主题更新指针上的 12 位构建号；没有部署 `.build.json` 时给 versionedPath 用。 */
    private static function themePinnedStaticBuild(): string
    {
        if (!class_exists('\\Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateRuntime')) {
            return '';
        }
        try {
            return \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pinnedStaticBuild();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 主题自有资源 → 主题对前缀段（`{code}/v{n}`），非主题资源返回 null。
     * 弱依赖 Theme；内容寻址文件名不参与。
     *
     * @return array{pair:string}|null
     */
    private static function themePairPrefix(string $path): ?array
    {
        if (!\class_exists('\\Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateRuntime')) {
            return null;
        }
        $normalized = '/' . \ltrim(\str_replace('\\', '/', (string)\strtok($path, '?')), '/');
        try {
            $prefix = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::activeThemePrefix();
        } catch (\Throwable) {
            $prefix = '';
        }
        $code = $prefix !== '' ? \basename($prefix) : '';
        $isThemeOwned = \str_contains($normalized, '/Weline/Theme/')
            || ($prefix !== '' && \str_contains($normalized, '/' . $prefix . '/'))
            || ($code !== '' && \preg_match('#^/static/' . \preg_quote($code, '#') . '(?:/|$)#i', $normalized) === 1);
        if (!$isThemeOwned) {
            return null;
        }
        try {
            $pair = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pairSegment();
        } catch (\Throwable) {
            $pair = '';
        }

        return ['pair' => $pair];
    }
}
