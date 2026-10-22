<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;

/**
 * 主题更新代次的运行时读取口。
 *
 * 渲染期只需要三个东西：
 * - 静态前缀：当前代次的批次ID（`/static/<batch>/…`，批次目录是完整静态树快照，框架资源也能解析）；
 * - 实体根：`generated/theme-layout-entities/<batch>/`；
 * - epoch：用于缓存隔离（FPC key 已含 `static_generation`，覆盖静态ID即自动生效）。
 *
 * 数据源优先 Env（promote 时写入，Worker ≤10s 感知），避免每请求读库。
 */
final class ThemeUpdateRuntime
{
    private const REQUEST_STATE_KEY = 'theme_update.runtime.active';
    private const POINTER_FILE_TTL_SECONDS = 10;

    /** @var array{at:int,map:array<string,mixed>,epoch:int}|null */
    private static ?array $pointerFileCache = null;

    /** 构建期强制代次（让编译产物烘焙「正在构建的批次」前缀）。 */
    private static ?array $forced = null;

    /** @param array{batch_id:string,static_ns:string,entity_root:string,theme_prefix:string,epoch:int}|null $active */
    public static function forceBatch(?array $active): void
    {
        self::$forced = $active;
        RequestContext::remove(self::REQUEST_STATE_KEY);
    }

    /**
     * 当前请求生效的代次；无代次返回 null。
     *
     * @return array{batch_id:string,static_ns:string,entity_root:string,epoch:int}|null
     */
    public static function active(): ?array
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        if (RequestContext::has(self::REQUEST_STATE_KEY)) {
            $cached = RequestContext::get(self::REQUEST_STATE_KEY);
            return \is_array($cached) && $cached !== [] ? $cached : null;
        }

        $resolved = self::resolve();
        RequestContext::set(self::REQUEST_STATE_KEY, $resolved ?? []);

        return $resolved;
    }

    /**
     * 缓存代次：当前代次存在时用批次ID，否则用部署构建号。
     * 只用于缓存键隔离，不改变 StaticBuildId 的全局行为（避免框架资源被带到批次命名空间）。
     */
    public static function cacheGeneration(): string
    {
        $active = self::active();
        if ($active !== null) {
            return $active['static_ns'];
        }

        return StaticBuildId::current();
    }

    /**
     * 该静态路径是否属于「主题自有资源」；是则返回批次前缀，否则 null（沿用部署构建号）。
     * 主题自有 = 主题设计命名空间（如 Weline/hanfu）或 Theme 模块资源。
     */
    public static function staticPrefixForPath(string $urlPath): ?string
    {
        $active = self::active();
        if ($active === null) {
            return null;
        }
        $prefix = trim((string)($active['theme_prefix'] ?? ''));
        $normalized = '/' . ltrim(str_replace('\\', '/', $urlPath), '/');
        if (str_contains($normalized, '/Weline/Theme/')) {
            return $active['static_ns'];
        }
        if ($prefix !== '' && str_contains($normalized, '/' . $prefix . '/')) {
            return $active['static_ns'];
        }

        return null;
    }

    /** @deprecated 不再全局覆盖静态代次；保留兼容旧调用（无副作用）。 */
    public static function applyToStaticSurface(): void
    {
        // no-op：静态前缀改由 {@see staticPrefixForPath()} 按 URL 选择
    }

    /** 当前代次的实体根（绝对路径）；无代次返回 null。 */
    public static function entityRoot(): ?string
    {
        $active = self::active();
        if ($active === null) {
            return null;
        }

        return ThemeUpdateBatchService::entityRoot($active['entity_root']);
    }

    public static function epoch(): int
    {
        $active = self::active();

        return $active === null ? 0 : (int)$active['epoch'];
    }

    // ---------------------------------------------------------------- 发布脏标记

    public static function dirtyFile(int $themeId): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'theme-update' . DIRECTORY_SEPARATOR . 'dirty' . DIRECTORY_SEPARATOR . max(0, $themeId) . '.json';
    }

    /**
     * 发布成功后打脏标记：表示「已发布的新内容尚未进入更新代次」。
     *
     * @param list<array{type?:string,option?:string}> $layouts 受影响布局（用于增量固化；空 = 全量）
     */
    public static function markDirty(int $themeId, string $reason = 'publish', array $layouts = []): void
    {
        if ($themeId < 1) {
            return;
        }
        // 开发环境：由既有「自动固化编译」（theme_layout_origin_watch）就地刷新，无需代次脏标记
        try {
            if ((string)(Env::system('deploy') ?? '') === 'dev') {
                return;
            }
        } catch (\Throwable) {
            // 忽略：按非 dev 处理
        }
        $file = self::dirtyFile($themeId);
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        $merged = self::mergeDirtyLayouts($file, $layouts);
        @\file_put_contents($file, \json_encode([
            'at' => \date('c'),
            'reason' => $reason,
            'layouts' => $merged['layouts'],
            'all' => $merged['all'],
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function isDirty(int $themeId): bool
    {
        return $themeId > 0 && \is_file(self::dirtyFile($themeId));
    }

    public static function clearDirty(int $themeId): void
    {
        $file = self::dirtyFile($themeId);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    /** 供测试/Worker 边界重置。 */
    public static function reset(): void
    {
        RequestContext::remove(self::REQUEST_STATE_KEY);
        self::$forced = null;
        StaticBuildId::override(null);
    }

    /**
     * @return array{batch_id:string,static_ns:string,entity_root:string,epoch:int}|null
     */
    private static function resolve(): ?array
    {
        $area = self::currentArea();
        $themeId = self::currentThemeId();

        $env = null;
        try {
            $env = Env::getInstance();
        } catch (\Throwable) {
            $env = null;
        }
        if ($env === null) {
            return null;
        }

        $map = self::pointerMapFromFile();
        if ($map === []) {
            $map = $env->getConfig(ThemeUpdateBatchService::CONFIG_CURRENT_MAP, []);
        }
        if (!\is_array($map)) {
            return null;
        }
        if ($themeId < 1) {
            // 上下文尚未就绪（早于主题解析）：若该区域只有一个代次指针，直接采用。
            $only = self::singleAreaEntry($map, $area);
            if ($only !== null) {
                return $only;
            }

            return null;
        }

        // 站点维度先查具体站点，再回落到全站默认（0）。
        foreach ([self::currentWebsiteId(), 0] as $websiteId) {
            $key = ThemeUpdateBatchService::pointerConfigKey($websiteId, $themeId, $area);
            $row = $map[$key] ?? null;
            if (!\is_array($row)) {
                continue;
            }
            $batchId = \trim((string)($row['batch_id'] ?? ''));
            if ($batchId === '') {
                continue;
            }

            return [
                'batch_id' => $batchId,
                'static_ns' => \trim((string)($row['static_ns'] ?? $batchId)),
                'entity_root' => \trim((string)($row['entity_root'] ?? $batchId)),
                'theme_prefix' => \trim((string)($row['theme_prefix'] ?? '')),
                'epoch' => \max(1, (int)($row['epoch'] ?? 1)),
            ];
        }

        // 上下文里的主题ID与指针不一致（例如默认主题1 vs 站点主题3）时，
        // 若该区域只有一个代次，仍采用它（单站点/单主题场景）。
        return self::singleAreaEntry($map, $area);
    }

    private static function currentThemeId(): int
    {
        foreach (['frontend', 'backend'] as $area) {
            $ctx = ThemeApplicationContext::current($area, 'runtime') ?? ThemeApplicationContext::current($area, 'editor');
            if ($ctx instanceof ThemeApplicationContext) {
                return \max(0, (int)$ctx->themeId);
            }
        }

        return 0;
    }

    private static function currentArea(): string
    {
        $ctx = ThemeApplicationContext::current('frontend', 'runtime');
        if ($ctx instanceof ThemeApplicationContext) {
            return $ctx->area !== '' ? $ctx->area : ThemeUpdateBatchService::AREA_FRONTEND;
        }

        return ThemeUpdateBatchService::AREA_FRONTEND;
    }

    private static function currentWebsiteId(): int
    {
        try {
            $websiteId = Env::getInstance()->getConfig('website_id');

            return \is_numeric($websiteId) ? \max(0, (int)$websiteId) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 读指针文件（≤10s TTL，与 StaticBuildId 对齐）。失败回落空数组。
     *
     * @return array<string,mixed>
     */
    private static function pointerMapFromFile(): array
    {
        $now = \time();
        if (self::$pointerFileCache !== null && ($now - self::$pointerFileCache['at']) < self::POINTER_FILE_TTL_SECONDS) {
            return self::$pointerFileCache['map'];
        }
        $file = ThemeUpdateBatchService::pointerFile();
        $map = [];
        $raw = @\file_get_contents($file);
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded) && \is_array($decoded['map'] ?? null)) {
                $map = $decoded['map'];
            }
        }
        self::$pointerFileCache = ['at' => $now, 'map' => $map, 'epoch' => (int)($decoded['epoch'] ?? 0)];

        return $map;
    }

    /**
     * map 中该区域只有一个代次时返回它（多站点时返回 null，必须等上下文）。
     *
     * @param array<string,mixed> $map
     * @return array{batch_id:string,static_ns:string,entity_root:string,epoch:int}|null
     */
    private static function singleAreaEntry(array $map, string $area): ?array
    {
        $matches = [];
        foreach ($map as $key => $row) {
            if (!\is_string($key) || !\is_array($row)) {
                continue;
            }
            $parts = \explode('|', $key);
            if (\count($parts) !== 3 || (string)\end($parts) !== $area) {
                continue;
            }
            $batchId = \trim((string)($row['batch_id'] ?? ''));
            if ($batchId === '') {
                continue;
            }
            $matches[] = [
                'batch_id' => $batchId,
                'static_ns' => \trim((string)($row['static_ns'] ?? $batchId)),
                'entity_root' => \trim((string)($row['entity_root'] ?? $batchId)),
                'theme_prefix' => \trim((string)($row['theme_prefix'] ?? '')),
                'epoch' => \max(1, (int)($row['epoch'] ?? 1)),
            ];
        }

        return \count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * 合并受影响布局；条目过多或命中「全量」时置 all=true。
     *
     * @param list<array{type?:string,option?:string}> $layouts
     * @return array{layouts:list<array{type:string,option:string}>,all:bool}
     */
    private static function mergeDirtyLayouts(string $file, array $layouts): array
    {
        $existing = [];
        $all = false;
        if (\is_file($file)) {
            $raw = (string)@\file_get_contents($file);
            $decoded = $raw !== '' ? \json_decode($raw, true) : null;
            if (\is_array($decoded)) {
                $all = (bool)($decoded['all'] ?? false);
                foreach ((array)($decoded['layouts'] ?? []) as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $type = \trim((string)($row['type'] ?? ''));
                    if ($type !== '') {
                        $existing[$type . '/' . \trim((string)($row['option'] ?? ''))] = [
                            'type' => $type,
                            'option' => \trim((string)($row['option'] ?? '')),
                        ];
                    }
                }
            }
        }
        foreach ($layouts as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $type = \trim((string)($row['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $option = \trim((string)($row['option'] ?? ''));
            $existing[$type . '/' . $option] = ['type' => $type, 'option' => $option];
        }
        $list = \array_values($existing);
        if (\count($list) > 12) {
            $all = true;
        }

        return ['layouts' => $all ? [] : $list, 'all' => $all];
    }

    /**
     * 读取脏标记里的受影响布局与全量标记。
     *
     * @return array{layouts:list<array{type:string,option:string}>,all:bool}
     */
    public static function dirtyLayouts(int $themeId): array
    {
        $file = self::dirtyFile($themeId);
        if (!\is_file($file)) {
            return ['layouts' => [], 'all' => false];
        }
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($decoded)) {
            return ['layouts' => [], 'all' => false];
        }
        $layouts = [];
        foreach ((array)($decoded['layouts'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $type = \trim((string)($row['type'] ?? ''));
            if ($type !== '') {
                $layouts[] = ['type' => $type, 'option' => \trim((string)($row['option'] ?? ''))];
            }
        }

        return ['layouts' => $layouts, 'all' => (bool)($decoded['all'] ?? false)];
    }
}
