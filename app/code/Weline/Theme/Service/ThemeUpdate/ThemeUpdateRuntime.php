<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Manager\ObjectManager;
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

    /** @var array{at:int,mtime:int,map:array<string,mixed>,epoch:int}|null */
    private static ?array $pointerFileCache = null;

    /** 构建期强制代次（让编译产物烘焙「正在构建的批次」前缀）。 */
    private static ?array $forced = null;


    /** @param array{batch_id:string,static_ns:string,entity_root:string,theme_prefix:string,epoch:int,pair_segment?:string,static_build?:string}|null $active */
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
     *
     * @deprecated 用 {@see pageGeneration()}（主题 code + 版本号 + 部署号三元组）。
     */
    /**
     * 缓存键隔离用的代次。只读指针和部署构建号，不查发布版本库。
     * 发布版本库会占用本请求的命名空间时钟，缓存键这条路不能碰它。
     */
    public static function pointerCacheGeneration(): string
    {
        $active = self::$forced ?? self::active();
        $pinned = \trim((string)($active['pair_segment'] ?? ''));
        if ($pinned !== '') {
            $base = $pinned;
        } else {
            $deploy = StaticBuildId::current();
            $base = $deploy !== '' ? $deploy : self::pinnedStaticBuild();
        }
        // Global-scope fallback keys must also move on TU promote/rollback even
        // when pair/build stay the same; epochForTheme avoids the resolver clock.
        $themeId = (int)($active['theme_id'] ?? 0);
        $epoch = self::epochForTheme(
            $themeId > 0 ? $themeId : self::resolvedThemeId(),
            self::currentArea(),
        );

        return $epoch > 0 ? $base . '~e' . $epoch : $base;
    }

    public static function cacheGeneration(): string
    {
        $pair = self::pairSegment();
        if ($pair !== '') {
            return $pair;
        }

        return StaticBuildId::current();
    }

    /** 当前主题的路径前缀（如 `Weline/hanfu` / `Weline/Theme`），无上下文返回 ''。 */
    public static function activeThemePrefix(): string
    {
        $active = self::$forced;
        if ($active !== null) {
            return \trim((string)($active['theme_prefix'] ?? ''));
        }
        $identity = self::generationIdentity();
        $code = \trim((string)($identity['themeCode'] ?? ''));

        return $code !== '' ? 'Weline/' . $code : '';
    }

    /**
     * 主题世代段：`{主题code}/v{版本号}`。
     *
     * 与磁盘镜像 {@see ThemeUpdateBatchService::mirrorThemeStaticToDeploy()} 同一主题：
     * 已写入代次指针的 pair_segment 优先；否则用当前请求已应用主题
     * （ThemeApplicationContext，缺省再用指针上的 theme_id）。
     * 禁止改问进程默认主题 Env::getTheme()——那是模块包 view/theme，会得到另一套 code/版本。
     */
    public static function pairSegment(): string
    {
        $active = self::$forced;
        if ($active !== null && \trim((string)($active['pair_segment'] ?? '')) !== '') {
            return \trim((string)$active['pair_segment']);
        }
        $resolved = self::active();
        $pinned = \trim((string)($resolved['pair_segment'] ?? ''));
        if ($pinned !== '') {
            return $pinned;
        }

        // 指针上没有世代段就留空，调用方改用部署构建号。
        // 不能在这里查发布版本：那会先占用本请求的命名空间时钟。
        return '';
    }

    /**
     * 页面世代键：主题 code + 发布版本号 + TU epoch + 静态构建号。
     * TU epoch 让 promote/rollback 即使版本号与部署号不变也换 token；
     * 无 TU 指针（epoch=0）时保持旧的 `pair~build` 形状。
     */
    public static function pageGeneration(): string
    {
        $pair = self::pairSegment();
        $build = self::staticBuildSegment();
        $identity = self::generationIdentity();
        $epochThemeId = (int)($identity['themeId'] ?? 0);
        $epoch = self::epochForTheme(
            $epochThemeId > 0 ? $epochThemeId : self::resolvedThemeId(),
            self::currentArea(),
        );
        $epochSegment = $epoch > 0 ? '~e' . $epoch : '';
        if ($pair !== '' && $build !== '') {
            return $pair . $epochSegment . '~' . $build;
        }
        if ($pair !== '') {
            return $pair . $epochSegment;
        }
        if ($build !== '') {
            // Even without a theme pair, the epoch must move the token so a TU
            // rollback invalidates build-keyed FPC entries.
            return $build . $epochSegment;
        }

        return $epoch > 0 ? 'e' . $epoch : '';
    }

    /**
     * 当前静态构建段：部署构建号优先，否则取指针上主题更新写下的 12 位号。
     */
    public static function staticBuildSegment(): string
    {
        $deploy = StaticBuildId::current();
        if ($deploy !== '') {
            return $deploy;
        }

        return self::pinnedStaticBuild();
    }

    /** 指针（或构建期强制代次）上的 12 位主题静态构建号；没有则 ''。 */
    public static function pinnedStaticBuild(): string
    {
        $active = self::$forced ?? self::active();
        $pinned = \strtolower(\trim((string)($active['static_build'] ?? '')));

        return \preg_match('/^[0-9a-f]{12}$/', $pinned) === 1 ? $pinned : '';
    }

    /**
     * 静态 URL 要用的主题：跟本请求实际选中的代次指针走（镜像目录就是按它写的）。
     * 指针上没有 theme_id 时才用请求上下文里的应用主题。
     * 两者都没有时返回 0，解析器才回落进程默认主题。
     */
    private static function appliedThemeId(): int
    {
        $active = self::active();
        $fromPointer = (int)($active['theme_id'] ?? 0);
        if ($fromPointer > 0) {
            return $fromPointer;
        }

        return self::currentThemeId();
    }

    /** Applied theme, then Env theme (header/API may run before ThemeApplicationContext). */
    private static function resolvedThemeId(): int
    {
        $themeId = self::appliedThemeId();
        if ($themeId > 0) {
            return $themeId;
        }
        try {
            $theme = Env::getInstance()->getTheme();

            return (int)($theme['id'] ?? $theme['theme_id'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{themeCode:string,versionNumber:int,themeId:int}|null */
    private static function generationIdentity(): ?array
    {
        if (!\class_exists('\\Weline\\Theme\\Service\\ThemePublishedVersionRuntimeResolver')) {
            return null;
        }
        try {
            $themeId = self::appliedThemeId();
            /** @var \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver $resolver */
            $resolver = ObjectManager::getInstance(\Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class);
            $identity = $resolver->resolveGenerationIdentity($themeId > 0 ? $themeId : null);

            return [
                'themeCode' => \trim((string)($identity['themeCode'] ?? '')),
                'versionNumber' => (int)($identity['versionNumber'] ?? 0),
                'themeId' => (int)($identity['themeId'] ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 该静态路径是否属于「主题自有资源」；是则返回批次前缀，否则 null（沿用部署构建号）。
     * 主题自有 = 主题设计命名空间（如 Weline/hanfu）或 Theme 模块资源。
     */
    public static function staticPrefixForPath(string $urlPath): ?string
    {
        // 本次重构：主题自有资源走 `/{code}/v{n}/{buildId}` 主题对目录（StaticBuildId::themePairPrefix）。
        // 保留 tu-* 批次前缀只为预览/旧产物兜底，正式渲染不再产出。
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
        if (self::previewRequested()) {
            return null;
        }
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

    /**
     * Epoch for a known theme owner, without relying on the request theme context.
     * Footer/header/API code often resolves the published version from Env theme
     * before ThemeApplicationContext exists; epoch() would then see multiple pointers
     * and return 0, silently dropping the TU generation from wire id / FPC token.
     */
    public static function epochForTheme(int $themeId, string $area = ThemeUpdateBatchService::AREA_FRONTEND): int
    {
        if (self::$forced !== null) {
            return \max(0, (int)(self::$forced['epoch'] ?? 0));
        }
        if ($themeId < 1) {
            return self::epoch();
        }
        $area = \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
        $map = self::pointerMapFromFile();
        if ($map === []) {
            try {
                $map = Env::getInstance()->getConfig(ThemeUpdateBatchService::CONFIG_CURRENT_MAP, []);
            } catch (\Throwable) {
                $map = [];
            }
        }
        if (!\is_array($map)) {
            return 0;
        }
        foreach ([self::currentWebsiteId(), 0] as $websiteId) {
            $key = ThemeUpdateBatchService::pointerConfigKey($websiteId, $themeId, $area);
            $row = $map[$key] ?? null;
            if (!\is_array($row)) {
                continue;
            }
            $epoch = (int)($row['epoch'] ?? 0);
            if ($epoch > 0) {
                return $epoch;
            }
        }

        return 0;
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
        $payload = \json_encode([
            'at' => \date('c'),
            'reason' => $reason,
            'layouts' => $merged['layouts'],
            'all' => $merged['all'],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false || \file_put_contents($file, $payload) === false) {
            return;
        }
    }

    /** 脏标记原文。构建前后对比：变了就说明这一轮里又有更新，不能切走旧快照。 */
    public static function dirtyToken(int $themeId): string
    {
        $file = self::dirtyFile($themeId);
        if ($themeId < 1 || !\is_file($file)) {
            return '';
        }
        $raw = \file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('theme_update_dirty_unreadable');
        }

        return $raw;
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

            $entry = self::pointerEntry($key, $row);
            if ($entry !== null) {
                return $entry;
            }
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
     * 读指针文件。文件 mtime 变了立刻重读；没变则 ≤10s TTL。失败回落空数组。
     *
     * @return array<string,mixed>
     */
    private static function pointerMapFromFile(): array
    {
        $file = ThemeUpdateBatchService::pointerFile();
        $mtime = @\filemtime($file);
        $mtime = $mtime === false ? 0 : $mtime;
        $now = \time();
        if (self::$pointerFileCache !== null
            && (self::$pointerFileCache['mtime'] ?? -1) === $mtime
            && ($now - self::$pointerFileCache['at']) < self::POINTER_FILE_TTL_SECONDS
        ) {
            return self::$pointerFileCache['map'];
        }
        $map = [];
        $decoded = [];
        $raw = @\file_get_contents($file);
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded) && \is_array($decoded['map'] ?? null)) {
                $map = $decoded['map'];
            }
        }
        self::$pointerFileCache = [
            'at' => $now,
            'mtime' => $mtime,
            'map' => $map,
            'epoch' => (int)($decoded['epoch'] ?? 0),
        ];

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
            $entry = self::pointerEntry($key, $row);
            if ($entry !== null) {
                $matches[] = $entry;
            }
        }

        return \count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * 代次指针行。theme_id 优先行内字段，旧指针只写在 key 的中段（website|theme|area）。
     *
     * @param array<string,mixed> $row
     * @return array{batch_id:string,static_ns:string,entity_root:string,theme_prefix:string,epoch:int,theme_id:int,pair_segment:string}|null
     */
    private static function pointerEntry(string $key, array $row): ?array
    {
        $batchId = \trim((string)($row['batch_id'] ?? ''));
        if ($batchId === '') {
            return null;
        }
        $parts = \explode('|', $key);
        $themeId = (int)($row['theme_id'] ?? 0);
        if ($themeId < 1 && \count($parts) === 3) {
            $themeId = (int)$parts[1];
        }

        return [
            'batch_id' => $batchId,
            'static_ns' => \trim((string)($row['static_ns'] ?? $batchId)),
            'entity_root' => \trim((string)($row['entity_root'] ?? $batchId)),
            'theme_prefix' => \trim((string)($row['theme_prefix'] ?? '')),
            'epoch' => \max(1, (int)($row['epoch'] ?? 1)),
            'theme_id' => \max(0, $themeId),
            'pair_segment' => \trim((string)($row['pair_segment'] ?? '')),
        ];
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


    /**
     * 预览请求判断（零依赖、零服务实例化）：
     * 只看 uri 路径与查询参数——预览静态始终带 `__preview` 段，实体根不能被代次接管。
     */
    private static function previewRequested(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($uri !== '' && (str_contains($uri, '/~preview/') || str_contains($uri, '/__preview/'))) {
            return true;
        }
        foreach (['weline_preview_token', 'preview_token'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string)$_GET[$key] !== '') {
                return true;
            }
        }

        return false;
    }
}
