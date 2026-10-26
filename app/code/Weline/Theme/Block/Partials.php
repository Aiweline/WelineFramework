<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;


use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\FooterNestedChromeSourceFingerprint;
use Weline\Theme\Service\NestedChromeSourceFingerprint;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

/**
 * Partials Block
 * 用于在模板中加载配置的 partials
 * 
 * 支持通过 block 标签调用：
 * <w:block class="Weline\Theme\Block\Partials" area="frontend" type="head" default-option="default"/>
 */
class Partials extends Block
{
    /**
     * @var array<string, array>
     */
    private static array $partialsMetaCache = [];
    private static ?\WeakMap $fiberRenderYieldAt = null;
    private const PARTIAL_OUTPUT_CACHE_TTL = 300.0;
    private const WLS_RENDER_YIELD_MIN_INTERVAL_US = 10000;
    /** Entry cap alone is not enough: chrome HTML blobs make ~80 keys ≈ 13MB. */
    private const PARTIAL_OUTPUT_CACHE_MAX = 48;
    /** Hard process-bag byte budget for partial HTML (LRU eviction). */
    private const PARTIAL_OUTPUT_CACHE_MAX_BYTES = 2_097_152;
    /** Skip process bag for a single oversized fragment (still may Shared/MS). */
    private const PARTIAL_OUTPUT_CACHE_ENTRY_MAX_BYTES = 262_144;
    private const PARTIAL_META_CACHE_MAX = 512;
    private const CHROME_POLICY_CACHE_MAX = 512;
    private const PARTIAL_OUTPUT_STALE_TTL = 86400;
    private const PARTIAL_OUTPUT_REFRESH_LOCK_TTL = 10;
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $partialOutputCache = [];
    private static int $partialOutputCacheBytes = 0;
    /** @var array<string, array{mode: string, auth: string, ttl: int}> */
    private static array $chromePolicyCache = [];

    public static function clearMetaCache(): void
    {
        self::$partialsMetaCache = [];
        self::$chromePolicyCache = [];
    }

    /**
     * Drop process-local partial HTML output. Prefer clearMetaCache() under soft memory pressure.
     */
    public static function clearOutputCache(): void
    {
        self::$partialOutputCache = [];
        self::$partialOutputCacheBytes = 0;
    }

    /**
     * Soft memory-pressure trim: FIFO/LRU drop oldest partial HTML until under budget.
     * Shared/MS chrome remains; Layer B may refill Process bags.
     *
     * @return int Bytes freed from the process output bag
     */
    public static function trimOutputCacheToBudget(int $maxBytes): int
    {
        $maxBytes = \max(0, $maxBytes);
        $before = self::$partialOutputCacheBytes;
        if ($before < 0) {
            self::$partialOutputCacheBytes = self::recountPartialOutputCacheBytes();
            $before = self::$partialOutputCacheBytes;
        }
        if ($before <= $maxBytes && \count(self::$partialOutputCache) <= self::PARTIAL_OUTPUT_CACHE_MAX) {
            return 0;
        }
        while (
            self::$partialOutputCache !== []
            && (
                self::$partialOutputCacheBytes > $maxBytes
                || \count(self::$partialOutputCache) > self::PARTIAL_OUTPUT_CACHE_MAX
            )
        ) {
            $oldestKey = \array_key_first(self::$partialOutputCache);
            if (!\is_string($oldestKey)) {
                break;
            }
            $entry = self::$partialOutputCache[$oldestKey] ?? null;
            self::$partialOutputCacheBytes -= self::partialOutputEntryBytes(
                \is_array($entry) ? $entry : null
            );
            unset(self::$partialOutputCache[$oldestKey]);
        }
        if (self::$partialOutputCacheBytes < 0) {
            self::$partialOutputCacheBytes = self::recountPartialOutputCacheBytes();
        }

        return \max(0, $before - self::$partialOutputCacheBytes);
    }

    /**
     * Layer B: peek Shared chrome HTML into HotCache Process L1 + Partials process bag.
     * Miss must not render/seed — returns hit=false.
     *
     * @return array{hit:bool,peeked:int,bags:list<string>,errors:list<string>}
     */
    public function hydrateStorefrontChromeProcessFromShared(
        string $area = 'frontend',
        string $type = 'header',
        array $data = [],
        string $defaultOption = 'default',
    ): array {
        $typeLc = \strtolower(\trim($type));
        $bag = 'theme.partials.fetch.' . ($typeLc !== '' ? $typeLc : 'header');
        $out = ['hit' => false, 'peeked' => 0, 'bags' => [], 'errors' => []];
        try {
            $resolved = $this->resolveStorefrontSharedLogicalKeyAndPolicy(
                $area,
                $type,
                $data,
                $defaultOption,
            );
            if ($resolved === null) {
                $out['errors'][] = $bag . ':key_unresolved';

                return $out;
            }
            /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(
                \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
            );
            $peeked = $hotCache->peekPolicy($resolved['policy'], $resolved['logical_key']);
            if (!\is_string($peeked) || $this->isEmptyPartialHtml($peeked)) {
                $out['errors'][] = $bag . ':shared_miss';

                return $out;
            }
            // Fill Partials process bag so fetchCachedPartialHtml hits before Shared RTT.
            $path = $this->getPartialsPath($area, $type, $defaultOption);
            if (\is_string($path) && $path !== '') {
                $template = Template::getInstance();
                $dictionary = \array_merge([
                    'theme' => $template->getData('theme') ?? [],
                    'colors' => $template->getData('colors') ?? [],
                    'meta' => $template->getData('meta') ?? [],
                ], $data);
                $policyMeta = $this->resolveChromeCachePolicy(
                    $path,
                    \is_array($dictionary['meta'] ?? null) ? (array)$dictionary['meta'] : [],
                    $type,
                );
                if ($policyMeta !== null) {
                    $snapshot = ThemeLayoutSourceSnapshot::current();
                    $captured = $snapshot?->source($path);
                    $sourceFile = $captured === null ? $this->resolveModulePath($path) : null;
                    if ((!is_string($sourceFile) || $sourceFile === '' || !is_file($sourceFile)) && is_file($path)) {
                        $sourceFile = $path;
                    }
                    $sourceStat = $captured === null && is_string($sourceFile) ? @stat($sourceFile) : false;
                    $sourceFingerprint = $this->resolveStorefrontChromeSourceFingerprint(
                        $type,
                        $path,
                        $snapshot,
                        $captured,
                        $sourceStat,
                    );
                    $cacheKey = $this->resolvePartialOutputCacheKey(
                        $path,
                        $sourceFingerprint,
                        $area,
                        $type,
                        $defaultOption,
                        $dictionary,
                        $policyMeta,
                    );
                    if ($cacheKey !== null) {
                        $this->rememberPartialOutput($cacheKey, $peeked, 'fresh', (int)$policyMeta['ttl']);
                    }
                }
            }
            $out['hit'] = true;
            $out['peeked'] = 1;
            $out['bags'][] = $bag;

            return $out;
        } catch (\Throwable $e) {
            $out['errors'][] = $bag . ':' . $e->getMessage();

            return $out;
        }
    }

    /**
     * After a warm render under the same RequestContext identity, drop process L1
     * and peek durable shared L2 for the storefront chrome/head bag.
     *
     * @return array{hit:bool,logical_key:string,resource:string}
     */
    public function verifyStorefrontSharedDurableL2(
        string $area,
        string $type,
        array $data = [],
        string $defaultOption = 'default',
    ): array {
        $miss = ['hit' => false, 'logical_key' => '', 'resource' => ''];
        try {
            $resolved = $this->resolveStorefrontSharedLogicalKeyAndPolicy(
                $area,
                $type,
                $data,
                $defaultOption,
            );
            if ($resolved === null) {
                return $miss;
            }
            /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(
                \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
            );
            // Must purge Policy-keyed L1 (pool|policy:resource:hash). Logical-key
            // substring purge misses those entries → warmer gate_ok false-positive.
            $hotCache->purgeProcessCacheForPolicy($resolved['policy'], $resolved['logical_key']);
            $peeked = $hotCache->peekPolicy($resolved['policy'], $resolved['logical_key']);
            $hit = \is_string($peeked) && !$this->isEmptyPartialHtml($peeked);

            return [
                'hit' => $hit,
                'logical_key' => $resolved['logical_key'],
                'resource' => $resolved['resource'],
            ];
        } catch (\Throwable) {
            return $miss;
        }
    }

    /**
     * Peek route-invariant head assets bags after a composed head warm.
     *
     * @return array{hit:bool,logical_keys:list<string>}
     */
    public function verifyStorefrontHeadAssetsDurableL2(array $data = []): array
    {
        $empty = ['hit' => false, 'logical_keys' => []];
        try {
            $area = 'frontend';
            $prefixPath = $this->resolveForcedPartialsOption($area, 'head', 'assets-prefix');
            $suffixPath = $this->resolveForcedPartialsOption($area, 'head', 'assets-suffix');
            if ($prefixPath === null || $suffixPath === null) {
                return $empty;
            }
            $template = Template::getInstance();
            $dictionary = \array_merge([
                'theme' => $template->getData('theme') ?? [],
                'colors' => $template->getData('colors') ?? [],
                'meta' => $template->getData('meta') ?? [],
            ], $data);
            $assetsKey = $this->resolveStorefrontHeadAssetsCacheKey(
                $area,
                $dictionary,
                $prefixPath,
                $suffixPath,
            );
            $keys = [
                'theme.head.assets.prefix.' . $assetsKey,
                'theme.head.assets.suffix.' . $assetsKey,
            ];
            /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(
                \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class,
            );
            $policy = StorefrontThemeCacheCoordinator::storefrontHeadAssetsPolicy(90, 600);
            $hits = 0;
            foreach ($keys as $logicalKey) {
                $hotCache->purgeProcessCacheForLogicalKey($logicalKey);
                $peeked = $hotCache->peekPolicy($policy, $logicalKey);
                if (\is_string($peeked) && !$this->isEmptyPartialHtml($peeked)) {
                    ++$hits;
                }
            }

            return [
                'hit' => $hits === \count($keys),
                'logical_keys' => $keys,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @return array{logical_key:string,policy:\Weline\Framework\Cache\CachePolicy,resource:string}|null
     */
    private function resolveStorefrontSharedLogicalKeyAndPolicy(
        string $area,
        string $type,
        array $data,
        string $defaultOption,
    ): ?array {
        $path = $this->getPartialsPath($area, $type, $defaultOption);
        if (!\is_string($path) || $path === '') {
            return null;
        }
        $template = Template::getInstance();
        $dictionary = \array_merge([
            'theme' => $template->getData('theme') ?? [],
            'colors' => $template->getData('colors') ?? [],
            'meta' => $template->getData('meta') ?? [],
        ], $data);
        $policyMeta = $this->resolveChromeCachePolicy(
            $path,
            \is_array($dictionary['meta'] ?? null) ? (array)$dictionary['meta'] : [],
            $type,
        );
        if ($policyMeta === null) {
            return null;
        }
        $snapshot = ThemeLayoutSourceSnapshot::current();
        $captured = $snapshot?->source($path);
        $sourceFile = $captured === null ? $this->resolveModulePath($path) : null;
        if ((!is_string($sourceFile) || $sourceFile === '' || !is_file($sourceFile)) && is_file($path)) {
            $sourceFile = $path;
        }
        $sourceStat = $captured === null && is_string($sourceFile) ? @stat($sourceFile) : false;
        $sourceFingerprint = $this->resolveStorefrontChromeSourceFingerprint(
            $type,
            $path,
            $snapshot,
            $captured,
            $sourceStat,
        );
        $cacheKey = $this->resolvePartialOutputCacheKey(
            $path,
            $sourceFingerprint,
            $area,
            $type,
            $defaultOption,
            $dictionary,
            $policyMeta,
        );
        if ($cacheKey === null) {
            return null;
        }
        $typeLc = \strtolower(\trim($type));
        $logicalPrefix = $typeLc === 'head' ? 'theme.head.' : 'theme.chrome.';
        $logicalKey = $logicalPrefix . $typeLc . '.' . $cacheKey;
        $ttl = \max(60, (int)$policyMeta['ttl']);
        $stale = $this->partialOutputStaleTtl();
        $sharedPolicy = $typeLc === 'head'
            ? StorefrontThemeCacheCoordinator::storefrontHeadPolicy($ttl, $stale)
            : StorefrontThemeCacheCoordinator::storefrontChromePolicy($ttl, $stale);

        return [
            'logical_key' => $logicalKey,
            'policy' => $sharedPolicy,
            'resource' => $typeLc === 'head' ? 'theme.storefront_head' : 'theme.storefront_chrome',
        ];
    }

    /**
     * Drop request-memoized backend chrome auth context so a bumped inbox
     * revision (backend_notification_inbox_rev) can participate in partial
     * cache keys on the same response after mark-as-read.
     */
    public static function clearBackendPartialAuthContextMemo(): void
    {
        RequestContext::remove('theme.backend_partial_auth_context');
        RequestContext::remove('theme.backend_partial_auth_context.user');
        RequestContext::remove('theme.backend_partial_auth_context.role');
    }

    public static function clearAllCaches(): void
    {
        self::clearMetaCache();
        self::clearOutputCache();
    }

    public static function processCacheItemCount(): int
    {
        return count(self::$partialsMetaCache)
            + count(self::$partialOutputCache)
            + count(self::$chromePolicyCache);
    }

    public static function processPartialOutputCacheItemCount(): int
    {
        return count(self::$partialOutputCache);
    }

    public static function processPartialOutputCacheBytes(): int
    {
        return self::$partialOutputCacheBytes;
    }

    /** @param array<string,mixed> $cache */
    private static function rememberBounded(array &$cache, string $key, mixed $value, int $maximum): void
    {
        if (array_key_exists($key, $cache)) {
            unset($cache[$key]);
        } elseif (count($cache) >= $maximum) {
            $oldest = array_key_first($cache);
            if ($oldest !== null) {
                unset($cache[$oldest]);
            }
        }
        $cache[$key] = $value;
    }

    /**
     * 初始化 Block
     */
    public function __init()
    {
        parent::__init();
    }
    
    /**
     * 渲染 Block
     * 支持通过属性传递参数：area, type, default-option
     * 支持通过 vars 属性传递模板变量：vars="logo|logoText|navItems"
     * 
     * @return string
     */
    public function render(): string
    {
        // 从属性中获取参数
        $area = $this->getData('area') ?? 'frontend';
        $type = $this->getData('type') ?? '';
        $defaultOption = $this->getData('default-option') ?? $this->getData('defaultOption') ?? 'default';
        
        // 如果指定了 type，则渲染对应的 partials
        if ($type) {
            // 获取传递给模板的数据
            $data = [];
            
            // 处理 vars 属性：从模板变量中提取值
            $vars = $this->getData('vars') ?? [];
            if (is_array($vars) && !empty($vars)) {
                foreach ($vars as $varName => $varValue) {
                    // vars 数组的键是变量名，值是变量的引用
                    // 我们需要从当前模板上下文中获取这些变量的值
                    // 由于 Block 继承自 Template，可以通过 $this->getData() 获取
                    // 但 vars 中的变量名可能不在 Block 的 data 中，需要从父模板获取
                    // 这里我们直接使用 $varValue，因为 framework_view_process_block 已经处理了变量引用
                    if (is_string($varName)) {
                        $data[$varName] = $varValue;
                    }
                }
            }
            
            // 获取其他直接传递的数据（除了 Block 内部使用的参数）
            foreach ($this->getData() as $key => $value) {
                // 排除 Block 内部参数
                if (!in_array($key, ['area', 'type', 'default-option', 'defaultOption', 'class', 'template', 'cache', 'vars'])) {
                    $data[$key] = $value;
                }
            }
            
            $html = $this->renderPartials($area, $type, $data, $defaultOption);
            return $html;
        }
        
        // 如果没有指定 type，返回空（保持向后兼容）
        return '';
    }

    private function fetchCachedPartialHtml(
        string $fileName,
        array $dictionary,
        string $area,
        string $type,
        string $defaultOption
    ): string {
        // Frontend head is page-scoped (request_path + seo_fp in cache data context).
        // wave6-6a: allow CachePolicy / process L1 + shared head Policy — never share
        // one blob across URLs (seo_fp/path still partition keys).

        $policy = $this->resolveChromeCachePolicy($fileName, \is_array($dictionary['meta'] ?? null) ? (array)$dictionary['meta'] : [], $type);
        if ($policy === null) {
            return $this->renderCompiledPartial($fileName, $dictionary);
        }

        // Key from stable source only. Never call getFetchFile before a process hit:
        // compiled paths are request-partitioned and compile/stat work must not sit
        // on the chrome hot path. Shared theme_runtime IPC is also skipped here —
        // each wls.memory get/set/incr currently burns ~200ms under pool pressure,
        // which made chrome caching slower than plain render and produced 0 hits.
        $snapshot = ThemeLayoutSourceSnapshot::current();
        $captured = $snapshot?->source($fileName);
        $sourceFile = $captured === null ? $this->resolveModulePath($fileName) : null;
        if ((!is_string($sourceFile) || $sourceFile === '' || !is_file($sourceFile)) && is_file($fileName)) {
            $sourceFile = $fileName;
        }
        $sourceStat = $captured === null && is_string($sourceFile) ? @stat($sourceFile) : false;
        $sourceFingerprint = $this->resolveStorefrontChromeSourceFingerprint(
            $type,
            $fileName,
            $snapshot,
            $captured,
            $sourceStat,
        );
        $cacheKey = $this->resolvePartialOutputCacheKey(
            $fileName,
            $sourceFingerprint,
            $area,
            $type,
            $defaultOption,
            $dictionary,
            $policy,
        );
        if ($cacheKey === null) {
            return $this->fetchHtml($fileName, $dictionary);
        }

        $cached = $this->readPartialOutputCache($cacheKey);
        if ($cached['status'] !== 'miss') {
            if ($this->isEmptyPartialHtml((string)$cached['html'])) {
                unset(self::$partialOutputCache[$cacheKey]);
                $this->logPartialCacheDiagnostic('skip_empty_partial_output_cache', [
                    'file' => $fileName,
                    'type' => $type,
                    'cache_key' => $cacheKey,
                    'status' => (string)$cached['status'],
                ]);
            } else {
                if ($cached['status'] === 'stale') {
                    $this->queuePartialOutputRefresh($cacheKey, $fileName, $dictionary, $policy['ttl']);
                }

                return $this->finalizeStorefrontChromeHtml($type, (string)$cached['html']);
            }
        }

        // Frontend head/header/footer: L1 miss → scope hot cache (cross-worker SWR).
        // Backend chrome stays process-local; historical theme_runtime IPC was too
        // expensive under pool pressure, so shared writes stay on the storefront path only.
        // Head uses storefrontHeadPolicy + theme.head.* keys (page-scoped via seo_fp).
        // wave9-9s2 P1: solidified complete shell must not dogpile-rebuild chrome via
        // singleflight when a warm L2 exists (布局再生回潮). Head assets Policy stays (9s).
        // Cold-locale after_ms dig (2026-10-09): full bypass→直渲 made sticky fail across
        // workers; solidified path now peekPolicy first, rememberPolicy only on miss.
        if ($this->shouldUseSharedStorefrontChromeCache($area, $type)) {
            try {
                /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache */
                $hotCache = ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class);
                $typeLc = \strtolower($type);
                $sharedPolicy = $typeLc === 'head'
                    ? StorefrontThemeCacheCoordinator::storefrontHeadPolicy(
                        \max(60, (int)$policy['ttl']),
                        $this->partialOutputStaleTtl(),
                    )
                    : StorefrontThemeCacheCoordinator::storefrontChromePolicy(
                        \max(60, (int)$policy['ttl']),
                        $this->partialOutputStaleTtl(),
                    );
                $logicalPrefix = $typeLc === 'head' ? 'theme.head.' : 'theme.chrome.';
                $logicalKey = $logicalPrefix . $typeLc . '.' . $cacheKey;
                $solidifiedPeekFirst = $this->shouldBypassStorefrontChromePolicyForSolidifiedShell($type);
                if ($solidifiedPeekFirst) {
                    $peeked = $hotCache->peekPolicy($sharedPolicy, $logicalKey);
                    if (\is_string($peeked) && !$this->isEmptyPartialHtml($peeked)) {
                        $this->rememberPartialOutput($cacheKey, $peeked, 'fresh', $policy['ttl']);

                        return $this->finalizeStorefrontChromeHtml($type, $peeked);
                    }
                }
                    $html = $hotCache->rememberPolicy(
                    $sharedPolicy,
                    $logicalKey,
                    $typeLc === 'head'
                        ? fn(): string => $this->renderStorefrontHeadComposedOrMonolithic(
                            $fileName,
                            $dictionary,
                            $area,
                            \max(60, (int)$policy['ttl']),
                        )
                        : fn(): string => $this->renderStorefrontChromeShellForSharedBag(
                            $fileName,
                            $dictionary,
                            $area,
                            $type,
                        ),
                );
                if (\is_string($html) && !$this->isEmptyPartialHtml($html)) {
                    $this->rememberPartialOutput($cacheKey, $html, 'fresh', $policy['ttl']);

                    return $this->finalizeStorefrontChromeHtml($type, $html);
                }
            } catch (\Throwable $e) {
                $this->logPartialCacheDiagnostic('shared_chrome_fallback', [
                    'file' => $fileName,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $html = $this->renderStorefrontChromeShellForSharedBag($fileName, $dictionary, $area, $type);
        if ($this->isEmptyPartialHtml($html)) {
            $this->logPartialCacheDiagnostic('skip_empty_partial_output_store', [
                'file' => $fileName,
                'type' => $type,
                'cache_key' => $cacheKey,
            ]);

            return $this->finalizeStorefrontChromeHtml($type, $html);
        }
        $this->rememberPartialOutput($cacheKey, $html, 'fresh', $policy['ttl']);

        return $this->finalizeStorefrontChromeHtml($type, $html);
    }

    /**
     * Header/footer shared bag HTML is keyed by lang×currency (Policy + KeyBuilder).
     * Currency-switcher and header-search facade islands are still stripped so the
     * selected currency UI / tip keys (q/type/category) hydrate live on serve.
     *
     * Frontend header/footer: after strip, fill empty nested chrome carriers
     * (mini-cart footer-extras, footer-*-links, header-nav-extensions, …)
     * from chrome_payload so storefront_chrome HIT bodies already contain nested
     * widgets — outbound fillEmptyNestedChromeSlots stays a safety net only.
     *
     * @param array<string, mixed> $dictionary
     */
    private function renderStorefrontChromeShellForSharedBag(
        string $fileName,
        array $dictionary,
        string $area = 'frontend',
        string $type = '',
    ): string {
        $shellFlag = false;
        if (\class_exists(\Weline\Currency\Service\CurrencySwitcherIslandBuilder::class)) {
            \Weline\Currency\Service\CurrencySwitcherIslandBuilder::beginChromeShellBuild();
            $shellFlag = true;
        }
        try {
            $html = $this->renderCompiledPartial($fileName, $dictionary);
            // Search/currency facade islands are storefront-header chrome only.
            // Backend topbar <w:search area=backend> must stay SSR — stripping it
            // leaves a pending island that topbar finalize never hydrates, and any
            // accidental hydrate would inject storefront header-bar (「全部」UA chrome).
            if (\strtolower(\trim($area)) === 'frontend') {
                $html = $this->stripChromeSearchFacadeIslands(
                    $this->stripChromeCurrencyIslands($html),
                );
            }

            return $this->fillNestedChromeCarriersForSharedBag($html, $area, $type);
        } finally {
            if ($shellFlag) {
                \Weline\Currency\Service\CurrencySwitcherIslandBuilder::endChromeShellBuild();
            }
        }
    }

    /**
     * Bake guest-safe nested chrome carriers into the shared header/footer bag once.
     */
    private function fillNestedChromeCarriersForSharedBag(string $html, string $area, string $type): string
    {
        $typeLc = \strtolower(\trim($type));
        if ($html === ''
            || \strtolower(\trim($area)) !== 'frontend'
            || ($typeLc !== 'header' && $typeLc !== 'footer')
        ) {
            return $html;
        }
        try {
            $theme = $this->resolvePartialsTheme('frontend');
            $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (int)$theme->getId() : 0;
            if ($themeId < 1) {
                return $html;
            }
            /** @var \Weline\Theme\Service\SlotRendererService $slots */
            $slots = ObjectManager::getInstance(\Weline\Theme\Service\SlotRendererService::class);

            return $slots->fillEmptyNestedChromeSlots($html, $themeId, 'frontend');
        } catch (\Throwable $e) {
            $this->logPartialCacheDiagnostic('chrome_nested_carriers_fill_failed', [
                'type' => $typeLc,
                'error' => $e->getMessage(),
            ]);

            return $html;
        }
    }

    /** Serve path: fill currency + search-facade islands from the active request. */
    private function finalizeStorefrontChromeHtml(string $type, string $html): string
    {
        $typeLc = \strtolower(\trim($type));
        // Repair bags poisoned before backend topbar was exempt from search strip.
        if ($typeLc === 'topbar') {
            return $this->repairBackendTopbarSearchIslands($html);
        }
        if ($typeLc !== 'header' && $typeLc !== 'footer') {
            return $html;
        }

        $html = $this->hydrateChromeCurrencyIslands($html);
        if ($typeLc === 'header') {
            $html = $this->hydrateChromeSearchFacadeIslands($html);
        }

        return $html;
    }

    /**
     * Backend topbar must keep area=backend search SSR (w-backend-topbar-search).
     * Replace any leftover storefront header-search pending/live island with a
     * fresh backend search panel — never storefront header-bar.phtml.
     */
    private function repairBackendTopbarSearchIslands(string $html): string
    {
        if ($html === ''
            || !\str_contains($html, 'w-backend-topbar')
            || !\str_contains($html, 'data-weline-chrome-island="header-search"')
        ) {
            return $html;
        }

        try {
            $live = $this->renderBackendTopbarSearchHtml();
        } catch (\Throwable $e) {
            $this->logPartialCacheDiagnostic('backend_topbar_search_repair_failed', [
                'error' => $e->getMessage(),
            ]);

            return $html;
        }

        if ($live === '' || $this->isEmptyPartialHtml($live)) {
            return $html;
        }

        $replaced = \preg_replace(
            '#<weline-chrome-island\b[^>]*\bdata-weline-chrome-island="header-search"[^>]*>.*?</weline-chrome-island>#is',
            $live,
            $html,
            1,
        );

        return \is_string($replaced) ? $replaced : $html;
    }

    /** SSR markup matching Admin top-bar.phtml &lt;w:search area=backend&gt;. */
    private function renderBackendTopbarSearchHtml(): string
    {
        $callback = \Weline\Search\Taglib\Search::callback();

        return (string)$callback('search', [], [], [
            'area' => 'backend',
            'placeholder' => '搜索配置…',
            'show-hot-words' => 'false',
            'navigate-hits' => 'true',
            'auto-complete' => 'true',
            'class' => 'w-backend-topbar-search',
            'id' => 'w-backend-universal-search',
        ]);
    }

    private function stripChromeCurrencyIslands(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (\str_contains($html, 'data-weline-chrome-island="currency-switcher"')) {
            $replaced = \preg_replace(
                '#(<weline-chrome-island\b[^>]*\bdata-weline-chrome-island="currency-switcher"[^>]*>).*?(</weline-chrome-island>)#is',
                '$1<!--weline-chrome-island-pending:currency-switcher-->$2',
                $html,
            );
            $html = \is_string($replaced) ? $replaced : $html;
        }

        // Published-slot / pre-island bags: wrap+clear each switcher root so lang-shell
        // never bakes a selected currency into the shared header.
        if (\str_contains($html, 'data-currency-switcher')) {
            $html = $this->rewriteCurrencySwitcherRoots(
                $html,
                '<!--weline-chrome-island-pending:currency-switcher-->',
            );
        }

        return $html;
    }

    private function hydrateChromeCurrencyIslands(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $needsIsland = \str_contains($html, 'data-weline-chrome-island="currency-switcher"')
            || \str_contains($html, 'data-currency-switcher')
            || \str_contains($html, '<!--weline-chrome-island-pending:currency-switcher-->');
        if (!$needsIsland) {
            return $html;
        }

        $unwrapLiveInner = function (): string {
            try {
                $live = $this->renderCurrencySwitcherIslandHtml();
            } catch (\Throwable $e) {
                $this->logPartialCacheDiagnostic('chrome_currency_island_hydrate_failed', [
                    'error' => $e->getMessage(),
                ]);

                return '';
            }
            if ($live === '') {
                return '';
            }
            // Live builder wraps itself in the island; unwrap to avoid nested islands.
            if (\preg_match(
                '#^\\s*<weline-chrome-island\\b[^>]*\\bdata-weline-chrome-island="currency-switcher"[^>]*>(.*)</weline-chrome-island>\\s*$#is',
                $live,
                $unwrap,
            )) {
                return $unwrap[1];
            }

            return $live;
        };

        if (\str_contains($html, 'data-weline-chrome-island="currency-switcher"')) {
            // Stamp a fresh SwitcherInstanceId per island mount (options bag is memoized).
            $replaced = \preg_replace_callback(
                '#(<weline-chrome-island\b[^>]*\bdata-weline-chrome-island="currency-switcher"[^>]*>).*?(</weline-chrome-island>)#is',
                static function (array $m) use ($unwrapLiveInner): string {
                    $inner = $unwrapLiveInner();
                    if ($inner === '') {
                        return $m[0];
                    }

                    return $m[1] . $inner . $m[2];
                },
                $html,
            );
            $html = \is_string($replaced) ? $replaced : $html;
        }

        // Legacy bag without islands (and not already pending): replace baked roots.
        // Skip when islands already present — rewrite would only re-walk live roots.
        if (\str_contains($html, 'data-currency-switcher')
            && !\str_contains($html, 'data-weline-chrome-island="currency-switcher"')
            && !\str_contains($html, '<!--weline-chrome-island-pending:currency-switcher-->')
        ) {
            $inner = $unwrapLiveInner();
            if ($inner !== '') {
                $html = $this->rewriteCurrencySwitcherRoots($html, $inner);
            }
        }

        return $html;
    }

    /**
     * Shared chrome bag: strip header-search facade so tip q/type/category never bake in.
     */
    private function stripChromeSearchFacadeIslands(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (\str_contains($html, 'data-weline-chrome-island="header-search"')) {
            $replaced = \preg_replace(
                '#(<weline-chrome-island\b[^>]*\bdata-weline-chrome-island="header-search"[^>]*>).*?(</weline-chrome-island>)#is',
                '$1<!--weline-chrome-island-pending:header-search-->$2',
                $html,
            );
            $html = \is_string($replaced) ? $replaced : $html;
        }

        // Legacy bags: wrap+clear each data-w-search panel root.
        if (\str_contains($html, 'data-w-search')
            && !\str_contains($html, '<!--weline-chrome-island-pending:header-search-->')
        ) {
            $html = $this->rewriteHeaderSearchRoots(
                $html,
                '<!--weline-chrome-island-pending:header-search-->',
            );
        }

        return $html;
    }

    /** Serve path: fill header-search islands with the active request search facade. */
    private function hydrateChromeSearchFacadeIslands(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $needs = \str_contains($html, 'data-weline-chrome-island="header-search"')
            || \str_contains($html, '<!--weline-chrome-island-pending:header-search-->')
            || \str_contains($html, 'data-w-search');
        if (!$needs) {
            return $html;
        }

        $placeholderSource = $this->resolveSearchFacadePlaceholderSource($html);
        try {
            $live = $this->renderHeaderSearchFacadeIslandHtml($placeholderSource);
        } catch (\Throwable $e) {
            $this->logPartialCacheDiagnostic('chrome_search_facade_island_hydrate_failed', [
                'error' => $e->getMessage(),
            ]);

            return $html;
        }

        if ($live === '') {
            return $html;
        }

        // Shell attr keeps Chinese source for dictionary lookup; serve path must
        // rewrite it to the active-locale translation so attrs do not leak source.
        $translatedPlaceholder = (string)__($placeholderSource);

        $liveInner = $live;
        if (\preg_match(
            '#^\\s*<weline-chrome-island\\b[^>]*\\bdata-weline-chrome-island="header-search"[^>]*>(.*)</weline-chrome-island>\\s*$#is',
            $live,
            $unwrap,
        )) {
            $liveInner = $unwrap[1];
        }

        if (\str_contains($html, 'data-weline-chrome-island="header-search"')) {
            $replaced = \preg_replace_callback(
                '#(<weline-chrome-island\b[^>]*\bdata-weline-chrome-island="header-search"[^>]*>).*?(</weline-chrome-island>)#is',
                function (array $m) use ($liveInner, $translatedPlaceholder): string {
                    $open = $this->rewriteSearchFacadePlaceholderAttr($m[1], $translatedPlaceholder);

                    return $open . $liveInner . $m[2];
                },
                $html,
            );
            $html = \is_string($replaced) ? $replaced : $html;
        }

        if (\str_contains($html, 'data-w-search')
            && !\str_contains($html, 'data-weline-chrome-island="header-search"')
            && !\str_contains($html, '<!--weline-chrome-island-pending:header-search-->')
        ) {
            $html = $this->rewriteHeaderSearchRoots($html, $liveInner);
        }

        return $html;
    }

    /**
     * Pin translated placeholder onto the island open tag (create or replace attr).
     */
    private function rewriteSearchFacadePlaceholderAttr(string $openTag, string $translatedPlaceholder): string
    {
        $escaped = \htmlspecialchars($translatedPlaceholder, \ENT_QUOTES, 'UTF-8');
        if (\preg_match('/\\bdata-weline-search-placeholder="/i', $openTag) === 1) {
            $rewritten = \preg_replace(
                '/\\bdata-weline-search-placeholder="[^"]*"/i',
                'data-weline-search-placeholder="' . $escaped . '"',
                $openTag,
                1,
            );

            return \is_string($rewritten) ? $rewritten : $openTag;
        }

        $rewritten = \preg_replace(
            '/>\\s*$/',
            ' data-weline-search-placeholder="' . $escaped . '">',
            $openTag,
            1,
        );

        return \is_string($rewritten) ? $rewritten : $openTag;
    }

    /**
     * Placeholder source from shell island attr (design themes may override).
     */
    private function resolveSearchFacadePlaceholderSource(string $shellHtml): string
    {
        if ($shellHtml !== ''
            && \preg_match(
                '/\\bdata-weline-search-placeholder="([^"]*)"/i',
                $shellHtml,
                $m,
            )
        ) {
            $decoded = \html_entity_decode((string)$m[1], \ENT_QUOTES, 'UTF-8');
            $decoded = \trim($decoded);
            if ($decoded !== '') {
                return $decoded;
            }
        }

        return '搜索商品、分类与关键词...';
    }

    private function renderHeaderSearchFacadeIslandHtml(?string $placeholderSource = null): string
    {
        $q = '';
        try {
            $q = \trim((string)($this->request->getQuery('q', '') ?? ''));
        } catch (\Throwable) {
            $q = '';
        }
        if ($q === '') {
            $q = \trim((string)($_GET['q'] ?? $this->request->getParam('q', '') ?? ''));
        }

        $selectedType = '';
        $selectedCategoryId = 0;
        try {
            $selectedType = \trim((string)($this->request->getParam('type') ?? ''));
            $selectedCategoryId = (int)($this->request->getParam('category_id') ?? 0);
        } catch (\Throwable) {
        }
        if ($selectedType === '' && isset($_GET['type'])) {
            $selectedType = \trim((string)$_GET['type']);
        }
        if ($selectedCategoryId <= 0 && isset($_GET['category_id'])) {
            $selectedCategoryId = (int)$_GET['category_id'];
        }

        $placeholderSource = \trim((string)($placeholderSource ?? ''));
        if ($placeholderSource === '') {
            $placeholderSource = '搜索商品、分类与关键词...';
        }
        $panelId = 'header-search-panel';
        $showHotWords = true;
        $live = '';

        try {
            /** @var \Weline\Theme\Service\StorefrontHeaderSearchFacadeFragmentCache $facadeCache */
            $facadeCache = ObjectManager::getInstance(
                \Weline\Theme\Service\StorefrontHeaderSearchFacadeFragmentCache::class
            );
            $stable = $facadeCache->rememberFacadeHtml(
                $panelId,
                $placeholderSource,
                $showHotWords,
                static function () use ($placeholderSource, $panelId, $showHotWords): string {
                    return (string)Template::getInstance()->fetchHtml(
                        'Weline_Theme::theme/frontend/partials/search/header-bar.phtml',
                        [
                            'placeholder' => (string)__($placeholderSource),
                            'query' => '',
                            'show_hot_words' => $showHotWords,
                            'panel_id' => $panelId,
                            'stable_fragment' => true,
                        ],
                    );
                },
            );
            $live = $facadeCache->applyRequestLocalFacade(
                $stable,
                $q,
                $selectedType !== '' ? $selectedType : 'all',
                $selectedCategoryId,
            );
        } catch (\Throwable $e) {
            $this->logPartialCacheDiagnostic('chrome_search_facade_fragment_fallback', [
                'error' => $e->getMessage(),
            ]);
            $live = (string)Template::getInstance()->fetchHtml(
                'Weline_Theme::theme/frontend/partials/search/header-bar.phtml',
                [
                    'placeholder' => (string)__($placeholderSource),
                    'query' => $q,
                    'show_hot_words' => $showHotWords,
                    'panel_id' => $panelId,
                ],
            );
        }

        if ($live === '' || $this->isEmptyPartialHtml($live)) {
            return '';
        }

        $translatedPlaceholder = (string)__($placeholderSource);
        $escapedPlaceholder = \htmlspecialchars($translatedPlaceholder, \ENT_QUOTES, 'UTF-8');

        return '<weline-chrome-island data-weline-chrome-island="header-search"'
            . ' data-weline-search-placeholder="' . $escapedPlaceholder . '"'
            . ' style="display:contents">'
            . $live
            . '</weline-chrome-island>';
    }

    /**
     * Replace each data-w-search panel root with an island wrapping $innerHtml.
     * Skips roots already inside a header-search island.
     */
    private function rewriteHeaderSearchRoots(string $html, string $innerHtml): string
    {
        $needle = 'data-w-search';
        $offset = 0;
        $out = '';
        $len = \strlen($html);
        while (($pos = \strpos($html, $needle, $offset)) !== false) {
            $openStart = \strrpos(\substr($html, 0, $pos), '<');
            if ($openStart === false) {
                $out .= \substr($html, $offset, $pos - $offset + \strlen($needle));
                $offset = $pos + \strlen($needle);
                continue;
            }

            $lookBehind = \substr($html, \max(0, $openStart - 220), \min(220, $openStart));
            if (\str_contains($lookBehind, 'data-weline-chrome-island="header-search"')
                && !\str_contains($lookBehind, '</weline-chrome-island>')
            ) {
                $out .= \substr($html, $offset, ($pos + \strlen($needle)) - $offset);
                $offset = $pos + \strlen($needle);
                continue;
            }

            $range = $this->findHtmlElementRange($html, $openStart);
            if ($range === null) {
                $out .= \substr($html, $offset, $pos - $offset + \strlen($needle));
                $offset = $pos + \strlen($needle);
                continue;
            }

            [$start, $end] = $range;
            $openTagEnd = \strpos($html, '>', $openStart);
            $openTag = $openTagEnd === false
                ? ''
                : \substr($html, $openStart, ($openTagEnd - $openStart) + 1);
            // Backend universal search is not a storefront tip facade — leave SSR.
            if ($this->isBackendTopbarSearchRootOpenTag($openTag)) {
                $out .= \substr($html, $offset, ($pos + \strlen($needle)) - $offset);
                $offset = $pos + \strlen($needle);
                continue;
            }

            $out .= \substr($html, $offset, $start - $offset);
            $out .= '<weline-chrome-island data-weline-chrome-island="header-search" style="display:contents">'
                . $innerHtml
                . '</weline-chrome-island>';
            $offset = $end;
        }
        $out .= \substr($html, $offset);

        return $out;
    }

    /** True when the data-w-search open tag is Admin topbar backend search. */
    private function isBackendTopbarSearchRootOpenTag(string $openTag): bool
    {
        if ($openTag === '') {
            return false;
        }

        return \str_contains($openTag, 'data-search-area="backend"')
            || \str_contains($openTag, "data-search-area='backend'")
            || \str_contains($openTag, 'w-backend-topbar-search');
    }

    /**
     * Replace each data-currency-switcher root with an island wrapping $innerHtml.
     * Skips roots already inside a currency-switcher island.
     */
    private function rewriteCurrencySwitcherRoots(string $html, string $innerHtml): string
    {
        $needle = 'data-currency-switcher';
        $offset = 0;
        $out = '';
        $len = \strlen($html);
        while (($pos = \strpos($html, $needle, $offset)) !== false) {
            $openStart = \strrpos(\substr($html, 0, $pos), '<');
            if ($openStart === false) {
                $out .= \substr($html, $offset, $pos - $offset + \strlen($needle));
                $offset = $pos + \strlen($needle);
                continue;
            }

            // Already inside an island — leave as-is (pending or live).
            // Advance past this needle; stepping by 1 re-hits the same root forever.
            $lookBehind = \substr($html, \max(0, $openStart - 220), \min(220, $openStart));
            if (\str_contains($lookBehind, 'data-weline-chrome-island="currency-switcher"')
                && !\str_contains($lookBehind, '</weline-chrome-island>')
            ) {
                $out .= \substr($html, $offset, ($pos + \strlen($needle)) - $offset);
                $offset = $pos + \strlen($needle);
                continue;
            }

            $range = $this->findHtmlElementRange($html, $openStart);
            if ($range === null) {
                $out .= \substr($html, $offset, $pos - $offset + \strlen($needle));
                $offset = $pos + \strlen($needle);
                continue;
            }

            [$start, $end] = $range;
            $out .= \substr($html, $offset, $start - $offset);
            $out .= '<weline-chrome-island data-weline-chrome-island="currency-switcher" style="display:contents">'
                . $innerHtml
                . '</weline-chrome-island>';
            $offset = $end;
        }
        $out .= \substr($html, $offset);

        return $out;
    }

    /**
     * @return array{0:int,1:int}|null [start, endExclusive]
     */
    private function findHtmlElementRange(string $html, int $openStart): ?array
    {
        if (!\preg_match('/\\G<([a-zA-Z][a-zA-Z0-9:-]*)\\b[^>]*>/s', $html, $m, 0, $openStart)) {
            return null;
        }
        $tag = $m[1];
        $openEnd = $openStart + \strlen($m[0]);
        $trimmedOpen = \rtrim(\substr($m[0], 0, -1));
        if (\str_ends_with($trimmedOpen, '/')) {
            return [$openStart, $openEnd];
        }

        $depth = 1;
        $i = $openEnd;
        $len = \strlen($html);
        $openNeedle = '<' . $tag;
        $closeNeedle = '</' . $tag;
        while ($i < $len && $depth > 0) {
            $nextOpen = \stripos($html, $openNeedle, $i);
            $nextClose = \stripos($html, $closeNeedle, $i);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $after = $nextOpen + \strlen($openNeedle);
                $ch = $html[$after] ?? '';
                if ($ch === '>' || $ch === ' ' || $ch === "\n" || $ch === "\t" || $ch === '/' || $ch === "\r") {
                    if (!\preg_match('/\\G<([a-zA-Z][a-zA-Z0-9:-]*)\\b[^>]*>/s', $html, $om, 0, $nextOpen)) {
                        return null;
                    }
                    $depth++;
                    $i = $nextOpen + \strlen($om[0]);
                    continue;
                }
                $i = $after;
                continue;
            }
            if (!\preg_match('/\\G<\\/([a-zA-Z][a-zA-Z0-9:-]*)\\s*>/s', $html, $cm, 0, $nextClose)) {
                return null;
            }
            $depth--;
            $i = $nextClose + \strlen($cm[0]);
        }

        return $depth === 0 ? [$openStart, $i] : null;
    }

    private function renderCurrencySwitcherIslandHtml(): string
    {
        try {
            if (\class_exists(\Weline\Currency\Service\CurrencySwitcherIslandBuilder::class)) {
                /** @var \Weline\Currency\Service\CurrencySwitcherIslandBuilder $builder */
                $builder = ObjectManager::getInstance(
                    \Weline\Currency\Service\CurrencySwitcherIslandBuilder::class,
                );
                $html = (string)$builder->renderLiveIslandHtml();
                if (\trim($html) !== '') {
                    return $html;
                }
            }
        } catch (\Throwable) {
            // Fall through to empty island rather than fail the whole chrome shell.
        }

        return '';
    }

    private function shouldUseSharedStorefrontChromeCache(string $area, string $type): bool
    {
        // Head is included only with page-scoped keys (request_path + seo_fp).
        // Header/footer remain route-independent chrome; head must never collapse
        // Product vs listing SEO into one bag.
        return \strtolower($area) === 'frontend'
            && \in_array(\strtolower($type), ['header', 'footer', 'head'], true);
    }

    /**
     * wave9-9s2 P1 (+ cold after_ms): when PublishedSlotHost already solidified
     * (CTX_USE_REACTIVE=false), header/footer use peek-first against
     * theme.storefront_chrome — HIT skips singleflight builder (no layout
     * regeneration dogpile); MISS still rememberPolicy to warm L2 for stickiness.
     * Head keeps website Policy (never peek-first gated here).
     */
    private function shouldBypassStorefrontChromePolicyForSolidifiedShell(string $type): bool
    {
        $typeLc = \strtolower(\trim($type));
        if ($typeLc !== 'header' && $typeLc !== 'footer') {
            return false;
        }
        try {
            $reactive = \Weline\Framework\Runtime\RequestContext::get(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE
            );
            if ($reactive !== false) {
                return false;
            }
            // Safety-net / incomplete shells still peek-first; miss falls through to
            // rememberPolicy so L2 warms without abandoning shared chrome entirely.
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * wave9-9s: compose head as assets-prefix + page + assets-suffix when all three
     * resolve; nest assets under storefrontHeadAssetsPolicy so CSS/JS HIT across URLs
     * while page (SEO/geo/Base) stays page-scoped. Legacy monolithic default remains fallback.
     *
     * @param array<string, mixed> $dictionary
     */
    private function renderStorefrontHeadComposedOrMonolithic(
        string $fileName,
        array $dictionary,
        string $area,
        int $freshTtlSeconds,
    ): string {
        $prefixPath = $this->resolveForcedPartialsOption($area, 'head', 'assets-prefix');
        $pagePath = $this->resolveForcedPartialsOption($area, 'head', 'page');
        $suffixPath = $this->resolveForcedPartialsOption($area, 'head', 'assets-suffix');
        if ($prefixPath === null || $pagePath === null || $suffixPath === null) {
            return $this->renderCompiledPartial($fileName, $dictionary);
        }

        try {
            /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache */
            $hotCache = ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class);
            $assetsPolicy = StorefrontThemeCacheCoordinator::storefrontHeadAssetsPolicy(
                $freshTtlSeconds,
                $this->partialOutputStaleTtl(),
            );
            $assetsKey = $this->resolveStorefrontHeadAssetsCacheKey($area, $dictionary, $prefixPath, $suffixPath);
            $prefixHtml = $hotCache->rememberPolicy(
                $assetsPolicy,
                'theme.head.assets.prefix.' . $assetsKey,
                fn(): string => $this->renderCompiledPartial($prefixPath, $dictionary),
            );
            if (!\is_string($prefixHtml)) {
                $prefixHtml = '';
            }
            // Prefix assigns disk-override HTML into the Template bag for suffix reuse.
            $suffixDictionary = $dictionary;
            $diskOverride = '';
            try {
                $diskOverride = (string)(Template::getInstance()->getData('__head_disk_override_html') ?? '');
            } catch (\Throwable) {
                $diskOverride = '';
            }
            if ($diskOverride !== '') {
                $suffixDictionary['__head_disk_override_html'] = $diskOverride;
            }
            $suffixHtml = $hotCache->rememberPolicy(
                $assetsPolicy,
                'theme.head.assets.suffix.' . $assetsKey,
                fn(): string => $this->renderCompiledPartial($suffixPath, $suffixDictionary),
            );
            if (!\is_string($suffixHtml)) {
                $suffixHtml = '';
            }
            $pageHtml = $this->renderCompiledPartial($pagePath, $dictionary);
            $composed = $prefixHtml . $pageHtml . $suffixHtml;
            if (!$this->isEmptyPartialHtml($composed)) {
                return $this->ensureStorefrontHeadCssReadyGate($composed);
            }
        } catch (\Throwable $e) {
            $this->logPartialCacheDiagnostic('storefront_head_compose_fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ensureStorefrontHeadCssReadyGate(
            $this->renderCompiledPartial($fileName, $dictionary),
        );
    }

    /**
     * FOUC pending without unlock leaves body visibility:hidden forever.
     * Default Theme progressive FOUC has no pending → this gate is a no-op.
     * Design themes may still set pending and drop css-ready — Theme owns the gate.
     */
    private function ensureStorefrontHeadCssReadyGate(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $hasPending = \str_contains($html, 'data-weline-css-gate')
            || \str_contains($html, "data-weline-css','pending'")
            || \str_contains($html, 'data-weline-css="pending"');
        if (!$hasPending) {
            return $html;
        }
        // Raw leaf or ThemeHeadChromeJsPack PACK_AFTER_CSS both unlock the FOUC gate.
        if (\str_contains($html, 'weline-css-ready.js')
            || \str_contains($html, 'data-weline-theme-head-pack="after-css"')) {
            return $html;
        }
        try {
            $ready = $this->renderCompiledPartial(
                'Weline_Theme::templates/partials/head/css-ready.phtml',
                [],
            );
            if (\is_string($ready) && \trim($ready) !== '') {
                return $html . $ready;
            }
        } catch (\Throwable) {
            // Fall through to inline fail-open.
        }

        return $html . "\n<script>document.documentElement.setAttribute('data-weline-css','ready');</script>\n";
    }

    /**
     * Resolve a partials option path without ThemeData config[$type] override
     * (config would collapse assets-suffix back to "default").
     */
    private function resolveForcedPartialsOption(string $area, string $type, string $option): ?string
    {
        /** @var ThemeContextService $ctx */
        $ctx = ObjectManager::getInstance(ThemeContextService::class);
        $normalizedArea = $ctx->normalizeArea($area);
        $theme = $this->resolvePartialsTheme($normalizedArea);
        $option = \trim($option);
        if ($option === '') {
            return null;
        }

        if (!$theme || !$theme->getId()) {
            $modulePath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
            $absolute = $this->resolveModulePath($modulePath);

            return (\is_string($absolute) && \is_file($absolute)) ? $modulePath : null;
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($normalizedArea);
        /** @var ThemeDirectoryResolver $dirResolver */
        $dirResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        // Must use Weline_Theme::theme/{area}/... so design overlays under
        // app/design/{Vendor}/{theme}/{area}/partials/... resolve (bare theme/... does not).
        $modulePath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $resolvedPath = $dirResolver->resolveThemeTemplatePath($modulePath, $theme);
        if (\is_string($resolvedPath) && $resolvedPath !== '' && $resolvedPath !== $modulePath) {
            $isAbsolutePath = \strpos($resolvedPath, '://') === false
                && (\preg_match('/^[A-Z]:/i', $resolvedPath)
                    || \strpos($resolvedPath, '/') === 0
                    || \strpos($resolvedPath, '\\') === 0);
            // Prefer the absolute design/theme path so compose + assets cache
            // fingerprints track the real override file (not only the module fallback).
            if ($isAbsolutePath && \is_file($resolvedPath)) {
                return $resolvedPath;
            }
            if (\is_file($resolvedPath)) {
                return $resolvedPath;
            }
        }

        $absolute = $this->resolveModulePath($modulePath);

        return (\is_string($absolute) && \is_file($absolute)) ? $modulePath : null;
    }

    private function isThemeChromePartialType(string $type): bool
    {
        return \in_array(\strtolower(\trim($type)), ['header', 'footer'], true);
    }

    /**
     * Resolve the configured partials option for the active theme.
     * ThemeData metadata wins; theme config is the fallback.
     */
    private function resolveConfiguredPartialsOption(
        string $area,
        string $type,
        string $defaultOption,
        WelineTheme $theme
    ): string {
        /** @var ThemeContextService $ctx */
        $ctx = ObjectManager::getInstance(ThemeContextService::class);
        $scope = $ctx->resolveCurrentScope($area);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $config = ThemeData::getPartialsConfig($area, $scope);
        if (empty($config)) {
            $themeConfig = (array)$theme->getConfig();
            $partialsByArea = (array)($themeConfig['partials'] ?? []);
            $config = (array)($partialsByArea[$area] ?? []);
        }
        $option = \trim((string)($config[$type] ?? ''));

        return $option !== '' ? $option : $defaultOption;
    }

    /**
     * Live theme chrome for the active theme. Used only when a layout entity
     * captured header/footer from a foreign theme/module layer.
     */
    private function resolveLiveThemeChromePartial(string $area, string $type, string $defaultOption): ?string
    {
        $theme = $this->resolvePartialsTheme($area);
        if (!$theme || !$theme->getId()) {
            return null;
        }
        $option = $this->resolveConfiguredPartialsOption($area, $type, $defaultOption, $theme);
        $live = $this->resolveForcedPartialsOption($area, $type, $option);
        if ($live === null && $option !== 'default') {
            $live = $this->resolveForcedPartialsOption($area, $type, 'default');
        }

        return $live;
    }

    /**
     * True when the captured chrome source belongs to the default Theme/module
     * layer instead of the active theme chain (theme + parent themes).
     * Synthetic/non-project sources are left untouched for test fixtures.
     */
    private function isForeignThemeChromeSource(string $selectedPath, string $area): bool
    {
        $snapshot = ThemeLayoutSourceSnapshot::current();
        if ($snapshot === null) {
            return false;
        }
        $source = $snapshot->source($selectedPath);
        $origin = (string)($source['origin'] ?? $selectedPath);
        if ($origin === '') {
            return false;
        }
        $normalizedOrigin = \preg_replace('#/+#', '/', \str_replace('\\', '/', $origin));
        $realOrigin = \realpath($normalizedOrigin);
        if ($realOrigin !== false) {
            $normalizedOrigin = \str_replace('\\', '/', $realOrigin);
        }
        $basePath = \preg_replace('#/+#', '/', \rtrim(\str_replace('\\', '/', (string)BP), '/'));
        if (!\str_starts_with($normalizedOrigin, $basePath . '/app/design/')
            && !\str_starts_with($normalizedOrigin, $basePath . '/app/code/')
        ) {
            return false;
        }

        $theme = $this->resolvePartialsTheme($area);
        if (!$theme || !$theme->getId()) {
            return false;
        }
        /** @var ThemeDirectoryResolver $dirResolver */
        $dirResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        foreach ($dirResolver->getThemeChain($theme, $area) as $layerTheme) {
            $layerPath = \preg_replace('#/+#', '/', \rtrim(\str_replace('\\', '/', (string)$layerTheme->getPath()), '/'));
            $realLayerPath = \realpath($layerPath);
            if ($realLayerPath !== false) {
                $layerPath = \rtrim(\str_replace('\\', '/', $realLayerPath), '/');
            }
            if ($layerPath !== '' && \str_starts_with($normalizedOrigin, $layerPath . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function resolveStorefrontHeadAssetsCacheKey(
        string $area,
        array $dictionary,
        string $prefixPath,
        string $suffixPath,
    ): string {
        $themeData = \is_array($dictionary['theme'] ?? null) ? (array)$dictionary['theme'] : [];
        $theme = $themeData['theme'] ?? null;
        $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';
        $fingerprints = [];
        foreach ([$prefixPath, $suffixPath] as $modulePath) {
            $sourceFile = null;
            if (\is_string($modulePath) && $modulePath !== '' && \is_file($modulePath)) {
                $sourceFile = $modulePath;
            } else {
                $sourceFile = $this->resolveModulePath($modulePath);
            }
            $stat = \is_string($sourceFile) ? @\stat($sourceFile) : false;
            $fingerprints[] = \is_array($stat)
                ? (int)$stat['mtime'] . '|' . (int)$stat['size'] . '|' . \basename((string)$sourceFile)
                : '0|0|';
        }
        $colorsFp = \is_array($dictionary['colors'] ?? null)
            ? \sha1((string)\json_encode(
                $this->normalizePartialCacheData($dictionary['colors']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ))
            : '';

        return \sha1(\implode('|', [
            $area,
            $themeId,
            (string)($themeData['area'] ?? ''),
            (string)($themeData['colorMode'] ?? ''),
            $colorsFp,
            (string)WelineEnv::getLang(),
            \implode(';', $fingerprints),
        ]));
    }

    /**
     * Resolve declarative chrome cache policy from @meta.cache.* on the partial.
     *
     * @param array<string, mixed> $partialsMeta
     * @return array{mode: string, auth: string, ttl: int}|null
     */
    private function resolveChromeCachePolicy(string $modulePath, array $partialsMeta, string $type = ''): ?array
    {
        $cacheKey = $modulePath . "\0" . \strtolower(\trim($type));
        $snapshot = ThemeLayoutSourceSnapshot::current();
        $captured = $snapshot?->source($modulePath);
        if ($captured !== null) { $cacheKey .= "\0" . $snapshot->fingerprint(); }
        if (isset(self::$chromePolicyCache[$cacheKey])) {
            $cached = self::$chromePolicyCache[$cacheKey];
            unset(self::$chromePolicyCache[$cacheKey]);
            self::$chromePolicyCache[$cacheKey] = $cached;
            return $cached['mode'] === 'chrome' ? $cached : null;
        }

        $mode = $this->readCacheMetaDefault($partialsMeta, 'mode');
        $auth = $this->readCacheMetaDefault($partialsMeta, 'auth');
        $ttlRaw = $this->readCacheMetaDefault($partialsMeta, 'ttl');

        if ($mode === null || $mode === '') {
            try {
                $filePath = $captured === null ? $this->resolveModulePath($modulePath) : null;
                if ((!(\is_string($filePath) && $filePath !== '' && \is_file($filePath)))
                    && \is_string($modulePath)
                    && $modulePath !== ''
                    && \is_file($modulePath)
                ) {
                    $filePath = $modulePath;
                }
                if ($captured !== null || (\is_string($filePath) && $filePath !== '' && \is_file($filePath))) {
                    $parsed = $captured !== null
                        ? $this->parseCapturedPartialMeta($captured)
                        : ComponentMetaParser::parse($filePath);
                    $cacheNode = \is_array($parsed['meta']['cache'] ?? null) ? (array)$parsed['meta']['cache'] : [];
                    $mode = $this->readCacheMetaDefault($cacheNode, 'mode')
                        ?? $this->readCacheMetaDefault(['cache' => $cacheNode], 'mode');
                    if ($auth === null || $auth === '') {
                        $auth = $this->readCacheMetaDefault($cacheNode, 'auth');
                    }
                    if ($ttlRaw === null || $ttlRaw === '') {
                        $ttlRaw = $this->readCacheMetaDefault($cacheNode, 'ttl');
                    }
                    // Nested shape: meta.cache.mode.default
                    if (($mode === null || $mode === '') && isset($cacheNode['mode'])) {
                        $modeNode = $cacheNode['mode'];
                        if (\is_array($modeNode)) {
                            $mode = (string)($modeNode['default'] ?? '');
                        } elseif (\is_scalar($modeNode)) {
                            $mode = (string)$modeNode;
                        }
                    }
                    if (($auth === null || $auth === '') && isset($cacheNode['auth'])) {
                        $authNode = $cacheNode['auth'];
                        if (\is_array($authNode)) {
                            $auth = (string)($authNode['default'] ?? '');
                        } elseif (\is_scalar($authNode)) {
                            $auth = (string)$authNode;
                        }
                    }
                    if (($ttlRaw === null || $ttlRaw === '') && isset($cacheNode['ttl'])) {
                        $ttlNode = $cacheNode['ttl'];
                        if (\is_array($ttlNode)) {
                            $ttlRaw = (string)($ttlNode['default'] ?? '');
                        } elseif (\is_scalar($ttlNode)) {
                            $ttlRaw = (string)$ttlNode;
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[PartialChromeCache] policy parse failed: ' . $e->getMessage(), [
                        'file' => $modulePath,
                    ]);
                }
                // Do not poison as permanent off — fall through to type fallback.
                $mode = null;
            }
        }

        $mode = \strtolower(\trim((string)$mode));
        $type = \strtolower(\trim($type));
        if ($mode !== 'chrome') {
            if ($mode !== '') {
                self::rememberBounded(self::$chromePolicyCache, $cacheKey, [
                    'mode' => 'off',
                    'auth' => 'role',
                    'ttl' => (int)self::PARTIAL_OUTPUT_CACHE_TTL,
                ], self::CHROME_POLICY_CACHE_MAX);
                return null;
            }
            // Compatibility fallback for default backend shells while declarative marks propagate.
            // Breadcrumb is page-trail chrome, NOT shared shell — never chrome-cache it.
            // (Shared chrome bag omitted trail fp and poisoned PLP crumbs across routes.)
            $fallbackAuth = match ($type) {
                'topbar' => 'user',
                'head', 'loading', 'scripts', 'topnav', 'sidebar', 'right-sidebar', 'header', 'footer' => 'role',
                default => null,
            };
            if ($fallbackAuth === null) {
                self::rememberBounded(
                    self::$chromePolicyCache,
                    $cacheKey,
                    ['mode' => 'off', 'auth' => 'role', 'ttl' => (int)self::PARTIAL_OUTPUT_CACHE_TTL],
                    self::CHROME_POLICY_CACHE_MAX,
                );
                return null;
            }
            $mode = 'chrome';
            if ($auth === null || $auth === '') {
                $auth = $fallbackAuth;
            }
        }

        $auth = \strtolower(\trim((string)$auth));
        if (!\in_array($auth, ['guest', 'role', 'user'], true)) {
            $auth = $type === 'topbar' ? 'user' : 'role';
        }
        $ttl = (int)$ttlRaw;
        if ($ttl <= 0) {
            $ttl = (int)$this->partialOutputCacheTtl();
        }
        $ttl = \max(1, \min($ttl, 86400));

        $policy = ['mode' => 'chrome', 'auth' => $auth, 'ttl' => $ttl];
        self::rememberBounded(self::$chromePolicyCache, $cacheKey, $policy, self::CHROME_POLICY_CACHE_MAX);
        return $policy;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function readCacheMetaDefault(array $source, string $field): ?string
    {
        if (\array_key_exists('cache.' . $field, $source)) {
            $value = $source['cache.' . $field];
            return \is_scalar($value) ? (string)$value : null;
        }
        if (\array_key_exists('cache_' . $field, $source)) {
            $value = $source['cache_' . $field];
            return \is_scalar($value) ? (string)$value : null;
        }
        $cache = $source['cache'] ?? null;
        if (!\is_array($cache)) {
            return null;
        }
        $node = $cache[$field] ?? null;
        if (\is_array($node)) {
            $default = $node['default'] ?? null;
            return \is_scalar($default) ? (string)$default : null;
        }
        if (\is_scalar($node)) {
            return (string)$node;
        }

        return null;
    }

    /**
     * HTML chrome/head process + HotCache logical payload via system KeyBuilder.
     *
     * @param array{mode: string, auth: string, ttl: int} $policy
     */
    private function resolvePartialOutputCacheKey(
        string $sourcePath,
        string $sourceFingerprint,
        string $area,
        string $type,
        string $defaultOption,
        array $data,
        array $policy
    ): ?string {
        $area = \strtolower($area);
        $type = \strtolower($type);
        if (($area !== 'frontend' && $area !== 'backend') || $this->shouldBypassPartialOutputCache()) {
            return null;
        }

        try {
            $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
            $theme = $themeData['theme'] ?? null;
            $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';
            $authContext = $area === 'backend'
                ? $this->backendAuthCacheContext($policy['auth'])
                : ($type === 'header' ? $this->frontendHeaderAuthCacheContext() : 'frontend-auth:0');
            if ($authContext === null) {
                return null;
            }

            $websiteScope = '';
            if ($area === 'frontend') {
                try {
                    $identity = \Weline\Framework\Runtime\RequestContext::scopeIdentity();
                    if ($identity instanceof \Weline\Framework\Runtime\ScopeIdentity) {
                        $scopes = \Weline\Framework\Manager\ObjectManager::getInstance(
                            \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class
                        );
                        $websiteScope = (string)$scopes->contextFromIdentity($identity)->storageScope;
                    }
                } catch (\Throwable) {
                    $websiteScope = '';
                }
                if ($websiteScope === '') {
                    try {
                        if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                            $websiteScope = \trim((string)(\Weline\Websites\Data\WebsiteData::getCode() ?? ''));
                        }
                    } catch (\Throwable) {
                        $websiteScope = '';
                    }
                }
            }

            $hashPayload = [
                // v9: language-switcher SSR no longer inlines flags; bust stale SVG chrome.
                // v12：账户与购物车共享中性首屏，旧编译时固化的购物车摘要必须失效。
                // v13：CJK identity 不再挡住词典后，旧 header/footer 中文壳必须失效。
                // v14：前台 chrome 按 website/storage scope 分桶，避免静态 404 多站串行串 header logo。
                // v15：frontend head 升格页级 Policy（request_path+seo_fp）；禁跨 URL 串 SEO。
                // v16：guest header 键去掉 cart_count/total（种袋 0 vs 探针车导致 846ms miss）。
                // v17：head Policy website + 瘦键 + assets 片段复用（wave9-9s）。
                // v19：chrome 大壳 lang×currency；货币开关器岛仍后注水（选中币种 UI）。
                // v19：I18n hook 自带岛标记；无岛旧袋按 data-currency-switcher 回退剥离（防错显币种）。
                // v20：嵌套 chrome 源指纹由 mtime|size 升为内容 sha256（NestedChromeSourceFingerprint）。
                // v21：搜索 facade（q/type/category）岛后注水；header 键 guest-neutral（禁按 tip q 分袋）。
                // v22：xscope A — frontend header/footer layout-agnostic（SharedChrome inherit）；
                //     去 layout 分区 + 路径级源指纹，homepage warmer 与 search/plp 同键。
                // v23：HTML 袋改 KeyBuilder::buildEnvironmentScoped，禁 sha1|environmentHash 自建。
                // v24：建袋填实 footer-extras（coupon/留言）；指纹扩 Marketing/Order 嵌套源；拒空壳毒袋。
                // v25：footer 浮层/尾 chrome 源指纹（FooterNestedChromeSourceFingerprint）入 nested_widgets。
                // v26：壳袋恢复 currency 维（Policy + KeyBuilder）；防语言敏感 shared 部件窜语。
                // Frontend header chrome is always guest-SSR; auth no longer splits the bucket.
                'schema' => 'chrome-partial-v26-lang-currency',
                'nested_widgets' => ($area === 'frontend' && $type === 'header')
                    ? $this->frontendHeaderNestedChromeFingerprint()
                    : (($area === 'frontend' && $type === 'footer')
                        ? $this->frontendFooterNestedChromeFingerprint()
                        : ''),
                'area' => $area,
                'type' => $type,
                'option' => $defaultOption,
                'backend_base_url' => $area === 'backend' ? (string)($this->request->getUrlBuilder()->getBackendUrl('/') ?? '') : '',
                'year' => $type === 'footer' ? \date('Y') : '',
                'theme_id' => $themeId,
                'theme_area' => (string)($themeData['area'] ?? ''),
                'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
                'website_id' => $area === 'backend'
                    ? (string)(($this->request->getData('website_id') ?? $this->request->getParam('website_id', '0')) ?: '0')
                    : $websiteScope,
                'auth' => $authContext,
                'auth_mode' => $policy['auth'],
                // Theme-preview request language override must win over storefront
                // Cookie lang used by environmentContext when website=true.
                'lang' => (string)WelineEnv::getLang(),
                'lang_local' => (string)WelineEnv::getLangLocal(),
                // Invalidate chrome when backend installed/active locale catalog changes
                // (language switcher is embedded in topbar/sidebar chrome HTML).
                'locale_catalog' => $area === 'backend'
                    ? (string)(\class_exists(\Weline\I18n\Taglib\LanguageSwitcher::class)
                        ? \Weline\I18n\Taglib\LanguageSwitcher::backendLocaleCatalogFingerprint()
                        : '')
                    : '',
                // Frontend/backend chrome embeds the storefront language switcher; markup
                // contract changes (SSR flags → placeholders) must invalidate shared HTML.
                'i18n_switcher_markup' => \class_exists(\Weline\I18n\Taglib\LanguageSwitcher::class)
                    ? (string)\Weline\I18n\Taglib\LanguageSwitcher::SWITCHER_MARKUP_VERSION
                    : '',
                // Frontend header/footer: SharedChrome non-carrier = inherit homepage shell.
                // Neutralize layout dims so homepage warmer L2 hits search/plp/blog/pdp.
                // Head stays page-scoped (seo_fp) and keeps request layout dims.
                'layout_type' => ($area === 'frontend' && ($type === 'header' || $type === 'footer'))
                    ? 'shared'
                    : (string)($themeData['layoutType'] ?? ''),
                'layout_option' => ($area === 'frontend' && ($type === 'header' || $type === 'footer'))
                    ? 'default'
                    : (string)($themeData['layoutOption'] ?? ''),
                'data' => $this->resolveChromePartialCacheDataContext($area, $type, $data),
            ];
            $hashDims = [
                // Reuse chrome across routes, but retain KeyBuilder's existing
                // website_url/host/base_url dimensions: rendered HTML contains
                // absolute links and cannot cross the actual URL origin.
                // currency=true: shell embeds language-sensitive shared widgets; islands
                // still hydrate the selected currency UI on serve.
                'area' => false,
                'area_route' => false,
                'currency' => true,
            ];

            $identity = $type === 'head' ? 'theme.head.partial' : 'theme.chrome.partial';

            return KeyBuilder::buildEnvironmentScoped(
                $identity,
                $sourcePath . '|' . $type . '|' . $sourceFingerprint,
                $hashPayload,
                $hashDims,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Header chrome HTML embeds placeable widgets (account / mini-cart / nav).
     * Authority: {@see NestedChromeSourceFingerprint} (content sha256; mtime|size memo gate).
     */
    private function frontendHeaderNestedChromeFingerprint(): string
    {
        return NestedChromeSourceFingerprint::compute();
    }

    private function frontendFooterNestedChromeFingerprint(): string
    {
        return FooterNestedChromeSourceFingerprint::compute();
    }

    /**
     * Source fingerprint for storefront chrome HotCache keys (xscope A).
     *
     * Header/footer share one layout-agnostic shell (SharedChrome inherit). Folding the
     * full page {@see ThemeLayoutSourceSnapshot::fingerprint()} into the key forked
     * homepage warmer seeds away from search/plp/blog/pdp — use path-local digest instead.
     * Head remains page-scoped and may keep the layout snapshot fingerprint.
     *
     * @param array{bytes?:string,origin?:string}|null $captured
     * @param array<string,mixed>|false|null $sourceStat
     */
    private function resolveStorefrontChromeSourceFingerprint(
        string $type,
        string $path,
        ?ThemeLayoutSourceSnapshot $snapshot,
        ?array $captured,
        $sourceStat,
    ): string {
        $typeLc = \strtolower(\trim($type));
        if (($typeLc === 'header' || $typeLc === 'footer') && \is_array($captured)) {
            $bytes = (string)($captured['bytes'] ?? '');
            $origin = (string)($captured['origin'] ?? $path);

            return \hash('sha256', $origin . "\0" . $bytes);
        }
        if ($captured !== null && $snapshot instanceof ThemeLayoutSourceSnapshot) {
            return $snapshot->fingerprint();
        }

        return \is_array($sourceStat)
            ? (int)$sourceStat['mtime'] . '|' . (int)$sourceStat['size']
            : '0|0';
    }

    private function resolveChromePartialCacheDataContext(
        string $area,
        string $type,
        array $data,
    ): mixed {
        if ($area === 'backend' && $type === 'head') {
            return $this->normalizeBackendHeadPartialCacheData($data);
        }
        if ($area === 'backend') {
            return $this->normalizeChromePartialCacheData($data);
        }

        return $this->resolvePartialCacheDataContext($type, $data, true);
    }

    private function resolvePartialCacheDataContext(string $type, array $data, bool $isChrome = false): mixed
    {
        if ($type === 'header') {
            // Guest-neutral header key (v21): search facade q/type/category_* hydrate
            // live via header-search islands — do NOT partition the shared chrome bag
            // (same spirit as cart_* removal and currency islands).
            return $this->normalizePartialCacheData([
                'lang' => (string)WelineEnv::getLang(),
                'lang_local' => (string)WelineEnv::getLangLocal(),
                // wave8-8c8: guest-neutral header key — do NOT partition on cart_*
                // (deferred bag-prime is always 0; panel probe carts caused header 846ms miss).
                'logo' => $data['logo'] ?? null,
                'logoText' => $data['logoText'] ?? null,
                'navItems' => $data['navItems'] ?? null,
                'showSearch' => $data['showSearch'] ?? null,
                'showUserMenu' => $data['showUserMenu'] ?? null,
            ]);
        }

        if ($type === 'footer') {
            return $this->normalizePartialCacheData([
                'footerLinks' => $data['footerLinks'] ?? null,
                'socialLinks' => $data['socialLinks'] ?? null,
                'newsletter' => $data['newsletter'] ?? null,
            ]);
        }

        if ($type === 'head' || $isChrome) {
            if ($type === 'head') {
                return $this->normalizeHeadPartialCacheData($data);
            }

            return $this->normalizeChromePartialCacheData($data);
        }

        return $this->normalizePartialCacheData($data);
    }

    private function normalizeChromePartialCacheData(array $data): mixed
    {
        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        unset(
            $meta['content'],
            $meta['contentRenderKey'],
            $meta['child_html'],
            $meta['controller'],
            $meta['request'],
            $meta['req'],
            $meta['session']
        );

        $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
        $theme = $themeData['theme'] ?? null;
        $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';

        // Keep chrome keys stable: ignore request-scoped template bags / object payloads.
        return $this->normalizePartialCacheData([
            'meta_class' => (string)($meta['class'] ?? ''),
            'theme_id' => $themeId,
            'theme_area' => (string)($themeData['area'] ?? ''),
            'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
            'colors_fp' => \is_array($data['colors'] ?? null)
                ? \sha1((string)\json_encode($this->normalizePartialCacheData($data['colors']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : '',
        ]);
    }

    /**
     * Storefront header chrome always SSRs the guest account shell (and empty
     * wishlist badge). Login state is applied client-side — never bucket header
     * HTML by frontend Session user.
     */
    private function frontendHeaderAuthCacheContext(): ?string
    {
        return 'frontend-auth:0';
    }

    private function backendAuthCacheContext(string $authMode = 'user'): ?string
    {
        $authMode = \strtolower($authMode);
        if ($authMode === 'guest') {
            return 'backend-auth:guest';
        }

        $requestKey = 'theme.backend_partial_auth_context.' . $authMode;
        $cached = RequestContext::get($requestKey, null);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $session = SessionFactory::getInstance()->createBackendSession();
            if (!$session->isLoggedIn()) {
                $context = 'backend-auth:0';
            } elseif ($authMode === 'role') {
                $roleId = 0;
                if (\method_exists($session, 'getLoginUser')) {
                    $user = $session->getLoginUser();
                    if (\is_object($user) && \method_exists($user, 'getRoleId')) {
                        $roleId = (int)$user->getRoleId();
                    }
                }
                if ($roleId <= 0) {
                    $userId = \method_exists($session, 'getLoginUserID')
                        ? (string)($session->getLoginUserID() ?? '')
                        : '';
                    $username = \method_exists($session, 'getLoginUsername')
                        ? (string)($session->getLoginUsername() ?? '')
                        : '';
                    if ($userId === '' && $username === '') {
                        return null;
                    }
                    $context = 'backend-auth:1:role-unknown:user:'
                        . \sha1($userId . '|' . $username);
                } else {
                    $context = 'backend-auth:1:role:' . $roleId;
                }
            } else {
                $userId = \method_exists($session, 'getLoginUserID') ? (string)($session->getLoginUserID() ?? '') : '';
                $username = \method_exists($session, 'getLoginUsername') ? (string)($session->getLoginUsername() ?? '') : '';
                if ($userId === '' && $username === '') {
                    return null;
                }
                // Inbox revision is bumped after mark-as-read so chrome topbar
                // (which embeds the unread badge) misses across WLS workers.
                $inboxRev = '';
                if (\method_exists($session, 'getData')) {
                    $inboxRev = (string)($session->getData('backend_notification_inbox_rev') ?? '');
                }
                $context = 'backend-auth:1:user:' . \sha1($userId . '|' . $username . '|n:' . $inboxRev);
            }
        } catch (\Throwable) {
            return null;
        }

        RequestContext::set($requestKey, $context);
        return $context;
    }

    private function normalizeHeadPartialCacheData(array $data): mixed
    {
        // wave9-9s storefront: slim page key — theme/colors + path/seo_fp only.
        // Full meta/layout bags were redundant with seo_fp and could thrash keys.
        $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
        $theme = $themeData['theme'] ?? null;
        $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';

        return $this->normalizePartialCacheData([
            'theme_id' => $themeId,
            'theme_area' => (string)($themeData['area'] ?? ''),
            'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
            'colors_fp' => \is_array($data['colors'] ?? null)
                ? \sha1((string)\json_encode(
                    $this->normalizePartialCacheData($data['colors']),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ))
                : '',
            // Head embeds page SEO/JSON-LD; never share one chrome blob across URLs.
            'request_path' => \class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)
                ? \Weline\Seo\Service\Head\SeoPageProfileBag::currentRequestPath()
                : (string)(\parse_url((string)(\w_env_request_uri() ?? ''), \PHP_URL_PATH) ?: ''),
            'seo_fp' => \class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)
                ? \Weline\Seo\Service\Head\SeoPageProfileBag::fingerprint()
                : '',
        ]);
    }

    private function normalizeBackendHeadPartialCacheData(array $data): mixed
    {
        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        unset(
            $meta['content'],
            $meta['contentRenderKey'],
            $meta['child_html'],
            $meta['controller'],
            $meta['request'],
            $meta['req'],
            $meta['session']
        );

        $layout = \is_array($data['layout'] ?? null) ? $data['layout'] : [];
        unset(
            $layout['content'],
            $layout['contentRenderKey'],
            $layout['child_html'],
            $layout['controller'],
            $layout['request'],
            $layout['req'],
            $layout['session']
        );

        return $this->normalizePartialCacheData([
            'meta' => $meta,
            'layout' => $layout,
            'theme' => $data['theme'] ?? null,
            'colors' => $data['colors'] ?? null,
            'site_name' => $data['site_name'] ?? null,
        ]);
    }

    private function shouldBypassPartialOutputCache(): bool
    {
        try {
            // Path gate via getUri() (same as HeaderNavFragment; no PathInfo accessor on Request).
            $uri = \trim((string)$this->request->getUri());
            if ($uri === '' && \function_exists('w_env_request_uri')) {
                $uri = \trim((string)\w_env_request_uri());
            }
            $path = $uri !== '' ? \parse_url($uri, PHP_URL_PATH) : '';
            $requestPath = \strtolower((string)(\is_string($path) && $path !== '' ? $path : $uri));
            return (string)$this->request->getGet('visual_editor', '') === '1'
                || (string)$this->request->getGet('preview', '') === '1'
                || (string)$this->request->getGet('debug_hooks', '') === '1'
                || \str_contains($requestPath, 'workspace-preview');
        } catch (\Throwable) {
            // Do not force bypass on every storefront hit when path detection fails.
            return false;
        }
    }

    private function normalizePartialCacheData(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '*depth*';
        }
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string)$key] = $this->normalizePartialCacheData($item, $depth + 1);
            }
            \ksort($normalized);
            return $normalized;
        }
        if (\is_object($value)) {
            $id = \method_exists($value, 'getId') ? (string)$value->getId() : '';
            return ['class' => $value::class, 'id' => $id];
        }

        return (string)\gettype($value);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readPartialOutputCache(string $cacheKey): array
    {
        $entry = $this->normalizePartialSwrEntry(self::$partialOutputCache[$cacheKey] ?? null);
        if ($entry === null) {
            if (isset(self::$partialOutputCache[$cacheKey])) {
                self::$partialOutputCacheBytes -= self::partialOutputEntryBytes(self::$partialOutputCache[$cacheKey]);
                unset(self::$partialOutputCache[$cacheKey]);
            }
            return ['status' => 'miss', 'html' => null];
        }
        // LRU: move to end on access
        unset(self::$partialOutputCache[$cacheKey]);
        self::$partialOutputCache[$cacheKey] = $entry;

        return $this->partialSwrEntryStatus($entry);
    }

    private function rememberPartialOutput(string $cacheKey, string $html, string $status = 'fresh', ?int $ttl = null): void
    {
        $htmlBytes = \strlen($html);
        if ($htmlBytes > self::PARTIAL_OUTPUT_CACHE_ENTRY_MAX_BYTES) {
            // Oversized chrome/listing fragments belong in Shared/MS, not the worker bag.
            unset(self::$partialOutputCache[$cacheKey]);
            return;
        }

        if (isset(self::$partialOutputCache[$cacheKey])) {
            self::$partialOutputCacheBytes -= self::partialOutputEntryBytes(self::$partialOutputCache[$cacheKey]);
            unset(self::$partialOutputCache[$cacheKey]);
        }

        $entry = $this->makePartialSwrEntry($html, $status, $ttl);
        $entryBytes = self::partialOutputEntryBytes($entry);
        while (
            self::$partialOutputCache !== []
            && (
                \count(self::$partialOutputCache) >= self::PARTIAL_OUTPUT_CACHE_MAX
                || self::$partialOutputCacheBytes + $entryBytes > self::PARTIAL_OUTPUT_CACHE_MAX_BYTES
            )
        ) {
            $oldestKey = \array_key_first(self::$partialOutputCache);
            if (!\is_string($oldestKey)) {
                break;
            }
            self::$partialOutputCacheBytes -= self::partialOutputEntryBytes(self::$partialOutputCache[$oldestKey]);
            unset(self::$partialOutputCache[$oldestKey]);
        }

        if ($entryBytes > self::PARTIAL_OUTPUT_CACHE_MAX_BYTES) {
            return;
        }

        self::$partialOutputCache[$cacheKey] = $entry;
        self::$partialOutputCacheBytes += $entryBytes;
        if (self::$partialOutputCacheBytes < 0) {
            self::$partialOutputCacheBytes = self::recountPartialOutputCacheBytes();
        }
    }

    /** @param array{fresh_until?: mixed, stale_until?: mixed, html?: mixed}|null $entry */
    private static function partialOutputEntryBytes(?array $entry): int
    {
        if ($entry === null) {
            return 0;
        }

        return \strlen((string)($entry['html'] ?? ''));
    }

    private static function recountPartialOutputCacheBytes(): int
    {
        $bytes = 0;
        foreach (self::$partialOutputCache as $entry) {
            $bytes += self::partialOutputEntryBytes(\is_array($entry) ? $entry : null);
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function queuePartialOutputRefresh(string $cacheKey, string $fileName, array $dictionary, ?int $ttl = null): void
    {
        if (PostResponseTaskQueue::isDraining()) {
            return;
        }
        if (!$this->acquireLocalPartialRefreshLock($cacheKey)) {
            return;
        }

        $ttl ??= $this->partialOutputCacheTtl();
        // SCOPE: same family as StorefrontScopeHotCache SWR; dictionary closed over.
        PostResponseTaskQueue::enqueue(
            'theme-partial-output:' . $cacheKey,
            function () use ($cacheKey, $fileName, $dictionary, $ttl): void {
                // Store lang-shell HTML only; currency islands hydrate on serve.
                $html = $this->renderStorefrontChromeShellForSharedBag($fileName, $dictionary);
                if (!\is_string($html) || $this->isEmptyPartialHtml($html)) {
                    $this->logPartialCacheDiagnostic('skip_empty_partial_output_refresh', [
                        'cache_key' => $cacheKey,
                        'file' => $fileName,
                    ]);
                    return;
                }
                $this->rememberPartialOutput($cacheKey, $html, 'fresh', $ttl);
            },
            0.0,
            PostResponseCaptureMode::Scope,
        );
    }

    private function acquireLocalPartialRefreshLock(string $cacheKey): bool
    {
        static $localLocks = [];
        $lockKey = 'partial.output.' . $cacheKey . '.refresh_lock';
        $now = \microtime(true);
        $expiresAt = (float)($localLocks[$lockKey] ?? 0);
        if ($expiresAt >= $now) {
            return false;
        }
        $localLocks[$lockKey] = $now + $this->partialRefreshLockTtl();

        return true;
    }

    /**
     * @return array{fresh_until: float, stale_until: float, html: string}|null
     */
    private function normalizePartialSwrEntry(mixed $entry): ?array
    {
        $now = \microtime(true);
        if (\is_string($entry)) {
            $ttl = $this->partialOutputCacheTtl();
            return [
                'fresh_until' => $now + $ttl,
                'stale_until' => $now + $ttl + $this->partialOutputStaleTtl(),
                'html' => $entry,
            ];
        }
        if (!\is_array($entry)) {
            return null;
        }
        $html = $entry['html'] ?? $entry['value'] ?? null;
        if (!\is_string($html)) {
            return null;
        }
        $freshUntil = (float)($entry['fresh_until'] ?? $entry['expires_at'] ?? 0);
        if ($freshUntil <= 0) {
            $ttl = $this->partialOutputCacheTtl();
            $freshUntil = $now + $ttl;
        }
        $staleUntil = isset($entry['stale_until'])
            ? (float)$entry['stale_until']
            : $freshUntil + $this->partialOutputStaleTtl();
        if ($staleUntil < $freshUntil) {
            $staleUntil = $freshUntil;
        }

        return [
            'fresh_until' => $freshUntil,
            'stale_until' => $staleUntil,
            'html' => $html,
        ];
    }

    /**
     * @param array{fresh_until: float, stale_until: float, html: string} $entry
     * @return array{status: string, html: ?string}
     */
    private function partialSwrEntryStatus(array $entry): array
    {
        $html = (string)($entry['html'] ?? '');
        $now = \microtime(true);
        if ((float)$entry['fresh_until'] >= $now) {
            return ['status' => 'fresh', 'html' => $html];
        }
        if ((float)$entry['stale_until'] >= $now) {
            return ['status' => 'stale', 'html' => $html];
        }

        return ['status' => 'miss', 'html' => null];
    }

    /**
     * @return array{fresh_until: float, stale_until: float, html: string}
     */
    private function makePartialSwrEntry(string $html, string $status = 'fresh', ?int $ttl = null): array
    {
        $ttl = $ttl !== null && $ttl > 0 ? $ttl : $this->partialOutputCacheTtl();
        $now = \microtime(true);
        $freshUntil = $status === 'stale' ? $now - 0.001 : $now + $ttl;

        return [
            'fresh_until' => $freshUntil,
            'stale_until' => $freshUntil + $this->partialOutputStaleTtl(),
            'html' => $html,
        ];
    }

    private function isEmptyPartialHtml(string $html): bool
    {
        if (\trim($html) === '') {
            return true;
        }

        // Incomplete footer chrome (exclusive root blank, no footer-container) must not
        // poison shared HotCache / L1 — first hit would ship empty footer until refresh.
        // Header with empty footer-extras (no nested coupon/留言) likewise rejects HIT
        // so bag rebuild fills nested chrome instead of relying on outbound SSR each request.
        return $this->isIncompleteStorefrontFooterChrome($html)
            || $this->isIncompleteStorefrontHeaderNestedChrome($html);
    }

    /**
     * True when mini-cart footer-extras destination exists but has no rendered widgets.
     */
    private function isIncompleteStorefrontHeaderNestedChrome(string $html): bool
    {
        if ($html === '') {
            return false;
        }
        $hasFooterExtras = \str_contains($html, 'data-slot-id="footer-extras"')
            || \str_contains($html, "data-slot-id='footer-extras'")
            || \str_contains($html, 'data-wslot="footer-extras"')
            || \str_contains($html, "data-wslot='footer-extras'");
        if (!$hasFooterExtras) {
            return false;
        }
        // Slot present: require at least one widget-code inside a footer-extras wrapper.
        if (\preg_match(
            '/<(?:div|section|aside)\b[^>]*(?:data-slot-id|data-wslot)=(["\'])footer-extras\1[^>]*>(.*?)<\/(?:div|section|aside)>/is',
            $html,
            $m,
        ) === 1) {
            $inner = (string)($m[2] ?? '');

            return $inner === '' || !\str_contains($inner, 'data-widget-code=');
        }

        // Fallback: destination marker present but no coupon/notice widget codes anywhere
        // (published shells may flatten wrappers).
        return !\str_contains($html, 'data-widget-code="checkout-coupon"')
            && !\str_contains($html, "data-widget-code='checkout-coupon'")
            && !\str_contains($html, 'data-widget-code="order-notice"')
            && !\str_contains($html, "data-widget-code='order-notice'");
    }

    private function isIncompleteStorefrontFooterChrome(string $html): bool
    {
        if (!\str_contains($html, 'data-slot-id="footer"')
            && !\str_contains($html, "data-slot-id='footer'")
            && !\str_contains($html, 'weline-footer-slot')
        ) {
            return false;
        }
        if (\str_contains($html, 'data-widget-code="footer-container"')
            || \str_contains($html, "data-widget-code='footer-container'")
            || \str_contains($html, 'data-testid="footer-container"')
            || \str_contains($html, 'wc-theme_widget_footer_container')
        ) {
            return false;
        }

        // Has footer destination but no host widget → treat as empty for cache gates.
        return \preg_match(
            '/data-slot-id=(["\'])footer\1[^>]*>\s*<\/(?:div|footer|section)>/i',
            $html,
        ) === 1
            || (\str_contains($html, 'weline-footer-slot') && !\str_contains($html, 'footer-container'));
    }

    private function logPartialCacheDiagnostic(string $event, array $context = []): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }

        try {
            $context += [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'uri' => \function_exists('w_env_request_uri') ? (string)\w_env_request_uri() : '',
                'lang' => (string)WelineEnv::getLang(),
                'lang_local' => (string)WelineEnv::getLangLocal(),
                'currency' => (string)WelineEnv::getCurrency(),
            ];
        } catch (\Throwable) {
        }

        $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        \w_log_warning('[PartialCacheDiagnostics] ' . $event . ' ' . ($payload ?: '{}'));
    }

    private function partialOutputStaleTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_stale_ttl', self::PARTIAL_OUTPUT_STALE_TTL, 1, 86400);
    }

    private function partialRefreshLockTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_refresh_lock_ttl', self::PARTIAL_OUTPUT_REFRESH_LOCK_TTL, 1, 300);
    }

    private function partialOutputCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_ttl', (int)self::PARTIAL_OUTPUT_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    public function getPartialsPath(string $area, string $type, string $defaultOption = 'default'): ?string
    {
        $snapshot = ThemeLayoutSourceSnapshot::current();
        if ($snapshot !== null && $snapshot->identity->area === strtolower($area)) {
            $selected = $snapshot->selectedPartialPath($type, $defaultOption);
            if ($selected !== null) {
                $normalizedArea = \strtolower(\trim($area));
                if ($this->isThemeChromePartialType($type)) {
                    // Theme chrome (header/footer) is owned by the active theme. A
                    // layout entity may still carry a source captured from the
                    // default Theme/module before the custom theme shipped its own
                    // chrome. Rebase that foreign capture to the active theme; keep
                    // the captured source when it already belongs to the theme chain.
                    if ($this->isForeignThemeChromeSource($selected, $normalizedArea)) {
                        $live = $this->resolveLiveThemeChromePartial($normalizedArea, $type, $defaultOption);
                        if ($live !== null) {
                            return $live;
                        }
                    }

                    return $selected;
                }

                // Non-chrome partials keep the stable logical path so CLI warmers
                // and storefront HTTP share one chrome bag.
                $option = \trim($defaultOption) !== '' ? \trim($defaultOption) : 'default';

                return 'Weline_Theme::theme/' . $normalizedArea . '/partials/'
                    . $type . '/' . $option . '.phtml';
            }
        }
        /** @var ThemeContextService $ctx */
        $ctx = ObjectManager::getInstance(ThemeContextService::class);
        $normalizedArea = $ctx->normalizeArea($area);
        $theme = $this->resolvePartialsTheme($normalizedArea);

        // 如果没有活动主题，直接跳到默认主题回退逻辑
        if (!$theme || !$theme->getId()) {
            $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $defaultOption . '.phtml';
            $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
            if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
                return $defaultPartialsPath;
            }
            return null;
        }

        $option = $this->resolveConfiguredPartialsOption($normalizedArea, $type, $defaultOption, $theme);

        // 使用 ThemeDirectoryResolver 解析主题 partials 路径（支持继承链）
        /** @var ThemeDirectoryResolver $dirResolver */
        $dirResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        $partialPath = 'theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $resolvedPath = $dirResolver->resolveThemeTemplatePath($partialPath, $theme);

        // resolveThemeTemplatePath 返回：
        // - 找到文件时返回绝对路径
        // - 未找到时返回原始路径
        if ($resolvedPath !== $partialPath) {
            // 如果找到了文件（返回绝对路径），转换为 Weline_Theme 模块路径
            // 绝对路径判断：Windows drive/UNC 或 Unix (/ 开头的绝对路径)
            $isAbsolutePath = strpos($resolvedPath, '://') === false
                && (preg_match('/^[A-Z]:/i', $resolvedPath)
                    || strpos($resolvedPath, '/') === 0
                    || strpos($resolvedPath, '\\') === 0);
            if ($isAbsolutePath) {
                return 'Weline_Theme::' . $partialPath;
            }
            // 否则直接返回
            return $resolvedPath;
        }

        // 未找到文件，尝试回退

        // 最终回退：尝试 Weline_Theme 默认 partials
        $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
        if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
            return $defaultPartialsPath;
        }

        return null;
    }
    
    /**
     * 解析模块路径为绝对路径（用于检查文件是否存在）
     * @param string $modulePath 模块路径格式（如 Weline_Theme::theme/frontend/partials/header/default.phtml）
     * @return string|null 绝对路径，如果无法解析则返回null
     */
    private function resolveModulePath(string $modulePath): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        list($moduleName, $relativePath) = explode('::', $modulePath, 2);
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName])) {
            return null;
        }
        
        $module = $modules[$moduleName];
        $basePath = rtrim($module['base_path'], DS);
        $relativePath = str_replace('/', DS, $relativePath);
        
        return $basePath . DS . 'view' . DS . $relativePath;
    }
    
    /**
     * 渲染 partials
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param array $data 传递给模板的数据
     * @param string $defaultOption 默认选项
     * @return string
     */
    public function renderPartials(string $area, string $type, array $data = [], string $defaultOption = 'default'): string
    {
        $tracePrefix = 'theme::partials::' . $type;

        return $this->traceCall($tracePrefix . '::render', function () use ($area, $type, $data, $defaultOption, $tracePrefix) {
            $path = $this->traceCall(
                $tracePrefix . '::resolve_path',
                fn() => $this->getPartialsPath($area, $type, $defaultOption)
            );

            if (!$path) {
                return '';
            }

            $template = Template::getInstance();
            $layoutMeta = $template->getData('meta') ?? [];
            $themeData = $template->getData('theme') ?? [];
            $colorsData = $template->getData('colors') ?? [];
            $contentTemplate = $template->getData('contentTemplate') ?? null;

            // Breadcrumb chrome must see the page trail published by controllers / SEO bag.
            // Block attributes alone do not inherit Template assigns unless vars= is used.
            if (\strtolower($type) === 'breadcrumb') {
                foreach (['storefront_product_breadcrumbs', 'storefront_category_breadcrumbs', 'breadcrumbs'] as $crumbKey) {
                    if (empty($data[$crumbKey]) || !\is_array($data[$crumbKey])) {
                        $fromTpl = $template->getData($crumbKey);
                        if (\is_array($fromTpl) && $fromTpl !== []) {
                            $data[$crumbKey] = $fromTpl;
                        }
                    }
                }
                $seoBag = $template->getData('seo');
                if (\is_array($seoBag)) {
                    if (empty($data['seo']) || !\is_array($data['seo'])) {
                        $data['seo'] = $seoBag;
                    }
                    if (
                        (empty($data['breadcrumbs']) || !\is_array($data['breadcrumbs']))
                        && \is_array($seoBag['breadcrumbs'] ?? null)
                        && $seoBag['breadcrumbs'] !== []
                    ) {
                        $data['breadcrumbs'] = $seoBag['breadcrumbs'];
                    }
                }
            }

            $captured = ThemeLayoutSourceSnapshot::current()?->source($path);
            $scope = $captured !== null ? ThemeLayoutSourceSnapshot::current()->identity->canonicalScope : $this->resolveScope($area);
            $metaIdentify = "partials.{$type}";
            if ($defaultOption && $defaultOption !== 'default') {
                $metaIdentify .= ".{$defaultOption}";
            } else {
                $metaIdentify = "partials.{$type}.default";
            }

            $cacheKey = $area . '|' . $type . '|' . $defaultOption . '|' . $scope . '|' . $path;
            if ($captured !== null) {
                // Saved parameters are emitted in this PHTML; defaults come from
                // the same captured source, never today's scoped DB configuration.
                $parsed = $this->parseCapturedPartialMeta($captured);
                $partialsMeta = [];
                foreach (LayoutPathResolver::formatParsedParams($parsed['params'] ?? []) as $name => $definition) {
                    $value = $definition['default'] ?? null;
                    $partialsMeta[$name] = $value === 'true' ? true : ($value === 'false' ? false : $value);
                }
            } elseif (array_key_exists($cacheKey, self::$partialsMetaCache)) {
                $partialsMeta = self::$partialsMetaCache[$cacheKey];
                unset(self::$partialsMetaCache[$cacheKey]);
                self::$partialsMetaCache[$cacheKey] = $partialsMeta;
            } else {
                $partialsMeta = $this->traceCall(
                    $tracePrefix . '::load_meta',
                    fn() => ThemeData::getFileParams($metaIdentify, $scope)
                );

                if (empty($partialsMeta)) {
                    $partialsMeta = $this->traceCall(
                        $tracePrefix . '::parse_meta_file',
                        fn() => $this->parsePartialsMetaFromFile($path, $area, $type, $defaultOption)
                    );
                }
                // 文件默认 meta 之上叠加 Scope 已存 param.*（如 website 配置的 logoHeight）
                if (is_array($partialsMeta) && $partialsMeta !== []) {
                    $partialsMeta = $this->overlayStoredPartialsParamOverrides(
                        $partialsMeta,
                        $metaIdentify,
                        $scope,
                        $area,
                    );
                }
                self::rememberBounded(
                    self::$partialsMetaCache,
                    $cacheKey,
                    is_array($partialsMeta) ? $partialsMeta : [],
                    self::PARTIAL_META_CACHE_MAX,
                );
            }

            if (empty($partialsMeta)) {
                $partialsMeta = [];
            }

            $data['meta'] = $partialsMeta;
            $data['layout'] = $layoutMeta;
            $data['theme'] = $themeData;
            $data['colors'] = $colorsData;
            if ($contentTemplate) {
                $data['contentTemplate'] = $contentTemplate;
            }

            foreach ($data as $key => $value) {
                $this->assign($key, $value);
            }

            return $this->traceCall(
                $tracePrefix . '::fetch_html',
                fn() => TraceGate::measurePhase(
                    $type === 'header'
                        ? \Weline\Theme\Service\ThemeLayoutBudgetPhases::L2_HEADER
                        : ('theme.partials.fetch.' . match ($type) {
                            'head' => 'head',
                            'footer' => 'footer',
                            default => 'other',
                        }),
                    fn() => $type === 'header'
                        ? TraceGate::measurePhase(
                            'theme.header.bar',
                            fn() => TraceGate::measurePhase(
                                'theme.partials.fetch.header',
                                fn() => $this->fetchCachedPartialHtml($path, $data, $area, $type, $defaultOption),
                                ['partial_type' => $type],
                            ),
                            ['partial_type' => $type],
                        )
                        : $this->fetchCachedPartialHtml($path, $data, $area, $type, $defaultOption),
                    ['partial_type' => $type],
                )
            );
        });
    }

    /**
     * Warm backend chrome partials marked @meta.cache.mode=chrome into process-local LRU.
     * Guest/unauthenticated auth buckets only; logged-in role/user entries fill on first request.
     * Time-boxed so worker bootstrap cannot stall READY/deferred loops.
     * Intentionally does not write theme_runtime — shared-memory IPC is too expensive on the chrome path.
     */
    public function warmChromePartialOutputs(float $budgetSeconds = 2.5): int
    {
        $targets = [
            ['head', 'default'],
            ['loading', 'default'],
            ['scripts', 'default'],
            ['topnav', 'default'],
            ['sidebar', 'left'],
            ['sidebar', 'default'],
            ['right-sidebar', 'default'],
            ['topbar', 'default'],
        ];

        $budgetSeconds = \max(0.2, $budgetSeconds);
        $deadline = \microtime(true) + $budgetSeconds;
        $warmed = 0;
        foreach ($targets as [$type, $option]) {
            if (\microtime(true) >= $deadline) {
                break;
            }
            try {
                $modulePath = 'Weline_Theme::theme/backend/partials/' . $type . '/' . $option . '.phtml';
                $absolute = $this->resolveModulePath($modulePath);
                if (!\is_string($absolute) || $absolute === '' || !\is_file($absolute)) {
                    continue;
                }
                $policy = $this->resolveChromeCachePolicy($modulePath, [], $type);
                if ($policy === null) {
                    continue;
                }
                $html = $this->renderPartials('backend', $type, [], $option);
                if (\is_string($html) && !$this->isEmptyPartialHtml($html)) {
                    $warmed++;
                }
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[PartialChromeCache] warmup failed: ' . $e->getMessage(), [
                        'type' => $type,
                        'option' => $option,
                    ]);
                }
            }
            SchedulerSystem::yield();
        }

        return $warmed;
    }
    /**
     * 重写 fetchHtml 方法，直接使用 Template 的 getFetchFile，避免使用 blocks 类型
     * @param string $fileName 文件名
     * @param array $dictionary 数据字典
     * @return string
     */
    public function fetchHtml(string $fileName, array $dictionary = []): string
    {
        return $this->renderCompiledPartial($fileName, $dictionary);
    }

    /**
     * Compile the partial immediately before rendering it.
     *
     * Partial output caches may outlive view/tpl files (for example after a
     * deploy or a manual cache cleanup). Never pass a cached compiled path to
     * ob_file() unless it still exists. Re-resolving here also closes the
     * small race where another worker removes the compiled file between the
     * first lookup and include().
     *
     * @param array<string, mixed> $dictionary
     */
    private function renderCompiledPartial(
        string $fileName,
        array $dictionary = [],
        ?string $compiledFile = null
    ): string {
        ThemeLayoutSourceSnapshot::current()?->install($this);
        $compiledFile ??= $this->getFetchFile($fileName);
        if (!\is_file($compiledFile)) {
            $compiledFile = $this->getFetchFile($fileName);
        }
        if (!\is_file($compiledFile)) {
            throw new \RuntimeException(
                (string)__('模板编译文件生成失败：%{1}', $fileName)
            );
        }

        $watchdogStartNs = Runtime::isPersistent() ? \hrtime(true) : 0;
        try {
            return $this->ob_file($compiledFile, $dictionary);
        } finally {
            $this->recordPartialRenderWatchdog($fileName, $compiledFile, $watchdogStartNs);
        }
    }

    /**
     * Observable-only guard for compiled partial includes (header, category
     * menu, widgets). A CPU-bound include blocks the Fiber scheduler just like
     * a stream_file include; never changes render output.
     */
    private function recordPartialRenderWatchdog(string $fileName, string $compiledFile, int $startNs): void
    {
        if ($startNs <= 0) {
            return;
        }
        $elapsedMs = (\hrtime(true) - $startNs) / 1_000_000;
        if ($elapsedMs < 1000.0) {
            return;
        }
        try {
            $requestId = RequestContext::getId() ?? '';
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            \w_log_warning(
                '[WLS] template_fiber_hog source=partial'
                . ' elapsed_ms=' . \round($elapsedMs, 2)
                . ' request_id=' . \substr((string)$requestId, 0, 128)
                . ' uri=' . \substr($uri, 0, 200)
                . ' file=' . \substr($fileName, 0, 300)
                . ' compiled=' . \substr($compiledFile, 0, 300),
                [],
                'wls_fiber_hog',
            );
        } catch (\Throwable) {
            // Observation must never alter render output.
        }
    }
    
    /**
     * 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
     * 
     * @param string $area 区域（frontend 或 backend）
     * @return string scope 值
     */
    private function resolveScope(string $area): string
    {
        $ctx = ObjectManager::getInstance(ThemeContextService::class);

        return $ctx->resolveCurrentScope($ctx->normalizeArea($area));
    }

    private function resolvePartialsTheme(string $area): ?WelineTheme
    {
        $preview = ObjectManager::getInstance(\Weline\Theme\Service\PreviewContextService::class);
        if ($preview->isEditorThemeRequest()) {
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $themeId = (int)$request->getParam('theme_id', 0);
            if ($themeId <= 0) {
                return null;
            }
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->load($themeId);

            return $theme->getId() ? $theme : null;
        }

        return ObjectManager::getInstance(ThemeContextService::class)->resolveTheme($area);
    }
    
    /**
     * Overlay Scope-stored partials param.* values onto file defaults.
     * Enables website-scoped logoHeight etc. when getFileParams definitions are empty.
     *
     * @param array<string, mixed> $partialsMeta
     * @return array<string, mixed>
     */
    private function overlayStoredPartialsParamOverrides(
        array $partialsMeta,
        string $metaIdentify,
        string $scope,
        string $area,
    ): array {
        try {
            $theme = $this->resolvePartialsTheme($area === 'backend' ? 'backend' : 'frontend');
            $themeId = (string)($theme?->getId() ?? '');
            if ($themeId === '' || $themeId === '0') {
                return $partialsMeta;
            }

            $repo = ObjectManager::getInstance(\Weline\Meta\Api\MetaConfigRepositoryInterface::class);
            $normalizedArea = $area === 'backend' ? 'backend' : 'frontend';
            $identities = [];
            $keys = [];
            foreach (array_keys($partialsMeta) as $paramName) {
                $paramName = (string)$paramName;
                if ($paramName === '') {
                    continue;
                }
                $keys[] = $paramName;
                $identities[] = new \Weline\Meta\Api\Data\MetaConfigIdentity(
                    namespace: 'theme.' . $normalizedArea,
                    configKey: $metaIdentify . '.param.' . $paramName,
                    scope: $scope,
                    locale: null,
                    identifyId: $themeId,
                );
            }
            if ($identities === []) {
                return $partialsMeta;
            }
            $records = $repo->resolveBatch($identities);
            foreach ($keys as $i => $paramName) {
                $record = $records[$i] ?? null;
                if (!$record instanceof \Weline\Meta\Api\Data\MetaConfigRecord) {
                    continue;
                }
                $value = $record->value;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_numeric($value) && isset($partialsMeta[$paramName]) && is_numeric($partialsMeta[$paramName])) {
                    $partialsMeta[$paramName] = str_contains((string)$value, '.')
                        ? (float)$value
                        : (int)$value;
                } else {
                    $partialsMeta[$paramName] = $value;
                }
            }
        } catch (\Throwable) {
            return $partialsMeta;
        }

        return $partialsMeta;
    }

    /**
     * 从文件解析 partials 的 meta 数据
     * 
     * @param string $modulePath 模块路径（如 Weline_Theme::theme/frontend/partials/head/default.phtml）
     * @param string $area 区域
     * @param string $type partials 类型
     * @param string $option partials 选项
     * @return array 解析后的参数值数组
     */
    private function parsePartialsMetaFromFile(string $modulePath, string $area, string $type, string $option): array
    {
        // 解析模块路径为文件系统路径
        $filePath = $this->resolveModulePath($modulePath);
        if (!$filePath || !is_file($filePath)) {
            return [];
        }
        
        // 使用 ComponentMetaParser 从文件解析参数定义
        $parsedMeta = ComponentMetaParser::parse($filePath);
        if (empty($parsedMeta['params']) || !is_array($parsedMeta['params'])) {
            return [];
        }
        
        // 格式化参数定义
        $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
        
        // 提取默认值作为参数值
        $params = [];
        foreach ($formattedParams as $paramName => $paramDef) {
            $defaultValue = $paramDef['default'] ?? null;
            // 处理布尔值默认值
            if ($defaultValue === 'true' || $defaultValue === true) {
                $defaultValue = true;
            } elseif ($defaultValue === 'false' || $defaultValue === false) {
                $defaultValue = false;
            }
            // 处理空字符串默认值
            if ($defaultValue === '') {
                $defaultValue = '';
            }
            $params[$paramName] = $defaultValue;
        }
        
        return $params;
    }
    private function parseCapturedPartialMeta(array $captured): array
    {
        $snapshot = ThemeLayoutSourceSnapshot::current();
        if ($snapshot !== null) {
            return $snapshot->parseSourceMeta($captured);
        }
        if (TraceGate::isArmed()) {
            return TraceGate::measurePhase('theme.source.meta_parse', static fn(): array => ComponentMetaParser::parseContent($captured['bytes'], $captured['origin']));
        }
        return ComponentMetaParser::parseContent($captured['bytes'], $captured['origin']);
    }

    private function traceCall(string $name, callable $callback, string $category = 'theme'): mixed
    {
        if (!TraceGate::isArmed()) {
            self::cooperativeRenderYield();
            try {
                return $callback();
            } finally {
                self::cooperativeRenderYield();
            }
        }

        self::cooperativeRenderYield();
        $start = microtime(true);
        TraceGate::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            TraceGate::popCurrentParent();
            TraceGate::recordSpan($name, (microtime(true) - $start) * 1000, $category);
            self::cooperativeRenderYield();
        }
    }

    private static function cooperativeRenderYield(): void
    {
        if (!Runtime::isPersistent() || !SchedulerSystem::isSchedulerActive()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }

        $now = \microtime(true);
        self::$fiberRenderYieldAt ??= new \WeakMap();
        $lastYieldAt = (float)(self::$fiberRenderYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            self::$fiberRenderYieldAt[$fiber] = $now;
            return;
        }
        if ($lastYieldAt > 0.0 && (($now - $lastYieldAt) * 1000000) < self::WLS_RENDER_YIELD_MIN_INTERVAL_US) {
            return;
        }

        // Same contract as SlotRendererService / Template: never yield with
        // unflushed process-level chunks, or peer fibers steal/discard HTML.
        if (!\Weline\Framework\Runtime\FiberOutputBuffer::flushBeforeYield()) {
            return;
        }

        self::$fiberRenderYieldAt[$fiber] = $now;
        SchedulerSystem::yield();
    }
}
