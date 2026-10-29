<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\Disk\ThemeDiskHeadService;
use Weline\Theme\Service\Disk\ThemeDiskKeys;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;

/**
 * Compile-time / runtime head chrome CSS packs for storefront/backend.
 *
 * Prefer PACK_STOREFRONT / PACK_BACKEND (one link each). Legacy fragment packs remain
 * for collectSources composition and merge=off fallbacks in templates.
 * Gated by resource_files/theme_css_merge (auto/on = always on). Source leaves stay editable;
 * runtime join may include theme.css bytes — never write back into source theme.css.
 * Head CSS order (Amazon-aligned three guns):
 * layout-critical → tokens (① vars/colors) → storefront base (② foundation/theme) →
 * disk → overlay (③ theme private page leaves, layout-scoped) → widget packs.
 * Overlay list stays theme-owned; flat lists remain compatible (all pages get full list).
 * Artifacts publish under the **active theme** static namespace and are keyed by
 * **application scope** (+ layout_type for overlay) so themes/scopes/layouts never cross-pollute.
 *
 * FOUC policy (progressive): progressive paint, no whole-page body hide.
 * Blocking policy: ONLY PACK_TOKENS blocks (variables must exist before first paint).
 * PACK_STOREFRONT / PACK_OVERLAY are emitted via tryPreloadLinkHtml (rel=preload as=style
 * + onload switch + noscript fallback) so LCP is not serialized behind CSS.
 * First-paint shell stability is owned by layout-critical (<= 14 KB budget), not by
 * blocking links. media=print defer remains reserved for widget late source only
 * (WidgetAssetOptimizer) — never for chrome packs.
 *
 * work_mode=theme_module_runtime
 */
final class ThemeHeadChromeCssPack
{
    /** ① Theme variables / light color tokens (blocking first link). */
    public const PACK_TOKENS = 'tokens';
    /** ② Global base elements + theme.css + dark tokens (preload+onload; not blocking). */
    public const PACK_STOREFRONT = 'storefront';
    public const PACK_BACKEND = 'backend';
    public const PACK_TOKENS_PRE = 'tokens-pre';
    public const PACK_TOKENS_POST = 'tokens-post';
    public const PACK_TOKENS_MINIMAL = 'tokens-minimal';
    public const PACK_TOKENS_BACKEND = 'tokens-backend';
    public const PACK_UI = 'ui';
    /** ③ Design theme private leaves — after theme + disk; layout-scoped when manifest has layouts{}. */
    public const PACK_OVERLAY = 'overlay';

    /** Design/theme-owned overlay list relative to theme view root. */
    public const OVERLAY_LIST_RELATIVE = 'partials/head/theme-head-css-overlay.php';

    /**
     * Request-scoped: pack keys that successfully returned a &lt;link&gt; from tryLinkHtml.
     * area\0pack → true. Used so themeOverlayAbsorbed cannot lie on manifest alone.
     *
     * @var array<string, true>
     */
    private array $emittedPacks = [];

    /** @var array<string, string> inline pack CSS keyed by raw-source sha (worker-safe) */
    private static array $inlineCssCache = [];

    public function __construct(
        private readonly ThemeResourceConfig $resourceConfig,
        private readonly ThemeContextService $themeContext,
        private readonly ThemePathResolver $pathResolver,
        private readonly ThemeResourceGateway $gateway,
        private readonly ThemeDiskHeadService $diskHead,
        private readonly ThemeDiskCompileService $diskCompile,
        private readonly WidgetAssetArtifactPublisher $publisher,
        private readonly StaticAssetMinifier $minifier,
        private readonly AtomicCompiledFilePublisher $atomicPublisher,
    ) {
    }

    public function isEnabled(string $area = 'frontend'): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        try {
            return !empty($this->resourceConfig->resolve(null, $area)['theme_css_merge']);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Publish area packs so Formal/com warm path does not cold-miss on first request.
     */
    public function warmAreaPacks(string $area = 'frontend'): void
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return;
        }
        try {
            if ($area === 'backend') {
                $this->publishPack($area, self::PACK_BACKEND, ['include_disk' => true, 'include_theme_overlay' => false]);
            } else {
                $this->publishPack($area, self::PACK_TOKENS, ['include_disk' => false, 'include_theme_overlay' => false]);
                $this->publishPack($area, self::PACK_STOREFRONT, ['include_disk' => true, 'include_theme_overlay' => false]);
                foreach ($this->warmOverlayLayoutTypes($area) as $layoutType) {
                    $this->publishPack($area, self::PACK_OVERLAY, [
                        'include_disk' => false,
                        'include_theme_overlay' => true,
                        'layout_type' => $layoutType,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS warm failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Layout types to pre-warm for overlay packs (common-only + known storefront layouts).
     *
     * @return list<string>
     */
    private function warmOverlayLayoutTypes(string $area): array
    {
        $theme = $this->resolveTheme($area);
        $manifest = $theme instanceof WelineTheme
            ? $this->loadThemeOverlayManifest($area, $theme)
            : ['common' => [], 'layouts' => []];
        $types = array_keys($manifest['layouts']);
        if ($types === []) {
            return [''];
        }
        // Always warm empty layout (common-only) plus each declared layout key (incl. *).
        return array_values(array_unique(array_merge([''], $types)));
    }

    /**
     * Emit a packed &lt;link&gt;, or null when packing is off / publish failed (caller keeps fragments).
     */
    public function tryLinkHtml(string $area, string $pack, array $options = []): ?string
    {
        // Enforced standard (theme-spec-gates S1): only PACK_TOKENS may block.
        // Storefront / overlay are route-invariant bulk CSS — emitting them as blocking
        // links serializes LCP behind ~50 KB (measured 1,200 ms on mobile). Templates
        // (default + every custom design theme) keep calling tryLinkHtml, so the policy
        // is applied in one place instead of 20 head partials.
        if ($pack === self::PACK_STOREFRONT || $pack === self::PACK_OVERLAY) {
            return $this->tryPreloadLinkHtml($area, $pack, $options);
        }

        // PACK_TOKENS：唯一还会阻塞首屏的样式请求（≈6–10KB）。改为内联「已发布产物字节」——
        // 注意不是 tryInlineCssHtml()（那会每请求 composeSources+minify，2026-10-29 15:40 因此
        // 触发过 WLS 渲染超时 503）。这里 publishPackWithTarget 命中已有产物时只做一次文件读取，
        // 与原先外链路径同样廉价，却省掉一轮 RTT（移动 4G 实测渲染阻塞 140–450ms）。
        // 读不到产物/超预算时返回 null → 回退原阻塞外链分支，行为与旧版一致。
        if ($pack === self::PACK_TOKENS && !isset($options['force_link'])) {
            $inline = $this->tryInlinePublishedCssHtml($area, $pack, $options);
            if ($inline !== null) {
                return $inline;
            }
        }

        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return null;
        }
        try {
            $url = $this->publishPack($area, $pack, $options);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS pack failed: ' . $e->getMessage());
            }

            return null;
        }
        if ($url === '') {
            return null;
        }
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // progressive FOUC: only tokens/backend reach here and stay blocking.
        // media=print onload is reserved for widget late source (WidgetAssetOptimizer).
        $attrs = ' rel="stylesheet" type="text/css" data-weline-theme-head-pack="'
            . htmlspecialchars($pack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        // FOUC gate waits only on layout-critical (inline). Theme/disk/overlay must NOT
        // carry data-weline-layout-css (big layout pack uses data-weline-layout-pack).
        if ($pack === self::PACK_OVERLAY) {
            $attrs .= ' data-weline-theme-overlay="1"';
        }
        if ($pack === self::PACK_TOKENS) {
            $attrs .= ' data-weline-theme-tokens="1"';
        }

        $html = '<link' . $attrs . ' href="' . $safe . '"/>';
        // Fail-closed absorb: only mark after a real link string is produced.
        // Callers must echo this return value; thin design prefixes that skip
        // PACK_OVERLAY must not silence skin theme:css via manifest alone.
        $this->markPackEmitted($area, $pack);

        return $html;
    }

    /**
     * Whether the pack already absorbed the disk override (skip separate link).
     * When theme_css_merge is on, STOREFRONT/BACKEND fold meaningful disk after foundation.
     */
    public function diskIncludedInPack(string $area, string $pack): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return false;
        }
        if (!in_array($pack, [self::PACK_STOREFRONT, self::PACK_BACKEND], true)) {
            return false;
        }
        $theme = $this->resolveTheme($area);
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return false;
        }

        return $this->diskHead->overrideBundleHasMeaningfulCss($area, $theme);
    }

    /**
     * Whether PACK_OVERLAY was actually linked this request (skip skin fallback duplicates).
     *
     * Manifest presence alone is NOT enough: design thin assets-prefix that defer
     * packs to suffix but forget to emit PACK_OVERLAY must leave absorb=false so
     * skin theme:css still ships brand/responsive leaves (fail-closed).
     */
    public function themeOverlayAbsorbed(string $area, string $pack): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return false;
        }
        if ($pack !== self::PACK_STOREFRONT && $pack !== self::PACK_OVERLAY) {
            return false;
        }
        // Emit gate first — never claim absorb before tryLinkHtml returned a link.
        if (!$this->wasPackEmitted($area, self::PACK_OVERLAY)) {
            return false;
        }
        $theme = $this->resolveTheme($area);
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return false;
        }
        $manifest = $this->loadThemeOverlayManifest($area, $theme);

        return $manifest['common'] !== [] || $manifest['layouts'] !== [];
    }

    /**
     * 主题 overlay manifest 的全部叶（common + 每个 layouts{} 桶），供源码级重复检查使用。
     * 只读 manifest，不看请求内发包状态，因此不受 head chrome 缓存影响。
     *
     * @return list<string>
     */
    public function overlayManifestLeaves(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $area = $this->themeContext->normalizeArea($area);
        $resolved = $theme instanceof WelineTheme ? $theme : $this->resolveTheme($area);
        if (!$resolved instanceof WelineTheme || (int)$resolved->getId() < 1) {
            return [];
        }
        $manifest = $this->loadThemeOverlayManifest($area, $resolved);
        $leaves = $manifest['common'];
        foreach ($manifest['layouts'] as $bucket) {
            foreach ($bucket as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return \array_values(\array_unique($leaves));
    }

    /**
     * 本次请求 layout_type 下 PACK_OVERLAY 实际会带的叶。
     * page/layout 的回退守卫必须用它，而不是 {@see themeOverlayAbsorbed()}：
     * head chrome 走缓存时本请求根本没有 tryLinkHtml 调用，emittedPacks 为空。
     *
     * @return list<string>
     */
    public function overlayLeavesForCurrentRequest(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $area = $this->themeContext->normalizeArea($area);
        $resolved = $theme instanceof WelineTheme ? $theme : $this->resolveTheme($area);
        if (!$resolved instanceof WelineTheme || (int)$resolved->getId() < 1) {
            return [];
        }
        try {
            return self::selectOverlayLeaves(
                $this->loadThemeOverlayManifest($area, $resolved),
                $this->resolveLayoutType([])
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function markPackEmitted(string $area, string $pack): void
    {
        $this->emittedPacks[$area . "\0" . $pack] = true;
    }

    private function wasPackEmitted(string $area, string $pack): bool
    {
        return isset($this->emittedPacks[$area . "\0" . $pack]);
    }

    private function publishPack(string $area, string $pack, array $options): string
    {
        return (string)($this->publishPackWithTarget($area, $pack, $options)['url'] ?? '');
    }

    /**
     * 发布包并同时给出产物磁盘路径（内联复用已发布字节，避免每请求 compose+minify）。
     *
     * @return array{url:string,path:string}|array{}
     */
    private function publishPackWithTarget(string $area, string $pack, array $options): array
    {
        $theme = $this->resolveTheme($area);
        $layoutType = $pack === self::PACK_OVERLAY ? $this->resolveLayoutType($options) : '';
        $sources = $this->collectSources($area, $pack, $theme, $options + ['layout_type' => $layoutType]);
        if ($sources === []) {
            return [];
        }
        // Base Theme shell must never absorb leaves resolved from app/design/* (scope/theme pollution).
        if (in_array($pack, [self::PACK_STOREFRONT, self::PACK_TOKENS], true) && $this->isBaseThemeShell($theme)) {
            $filtered = [];
            foreach ($sources as $source) {
                $sourcePath = str_replace('\\', '/', (string)($source['path'] ?? ''));
                if ($sourcePath !== '' && str_contains($sourcePath, '/app/design/')) {
                    continue;
                }
                $filtered[] = $source;
            }
            $sources = $filtered;
            if ($sources === []) {
                return [];
            }
        }
        $minify = !empty($this->resourceConfig->resolve($theme, $area)['css_minify']);
        $themeIdentity = '';
        if ($theme instanceof WelineTheme) {
            try {
                $themeIdentity = (string)$theme->getPath();
            } catch (\Throwable) {
                $themeIdentity = '';
            }
            if ($themeIdentity === '') {
                try {
                    $themeIdentity = (string)$theme->getName();
                } catch (\Throwable) {
                    $themeIdentity = '';
                }
            }
        }
        $scopeFp = $this->resolvePackScopeFingerprint($area);
        $key = hash('sha256', json_encode([
            // v9: palette merge order = default → brand/ink → light → dark（未覆盖继承 default）
            // v10: design-theme leaf URLs must use active theme static namespace (not bare Weline/Theme).
            'theme-head-chrome-v14', // v14: PACK_TOKENS 再补 variables/_borders.css + _shadows.css（边框/圆角/阴影叶子）
            $area,
            $pack,
            $layoutType,
            $minify,
            (int)($theme?->getId() ?? 0),
            $themeIdentity,
            $scopeFp,
            array_column($sources, 'hash'),
            \Weline\Framework\Cache\StorefrontCacheKeyContext::pageGenerationValue(),
        ], JSON_THROW_ON_ERROR));
        // Force the same theme object used for sources — never re-resolve to another namespace.
        $target = $this->gateway->buildHeadChromeArtifact($key, $area, 'css', $theme);
        if ($target === null) {
            return [];
        }
        if (!$this->artifactPathBelongsToTheme($target['path'], $theme)) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS refused: artifact namespace mismatch theme='
                    . $themeIdentity . ' path=' . $target['path']);
            }

            return [];
        }
        if (!is_file($target['path'])) {
            $body = $this->composeSources($sources, $minify);
            if ($body === '') {
                return [];
            }
            $this->atomicPublisher->publish($target['path'], $body);
        }

        return ['url' => (string)$target['url'], 'path' => (string)$target['path']];
    }

    /**
     * 首屏内联：与 PACK_* 同一份 sources，内联到 head（关键 CSS，不产生阻塞请求）。
     */
    public function tryInlineCssHtml(string $area, string $pack, array $options = []): ?string
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return null;
        }
        try {
            $theme = $this->resolveTheme($area);
            $layoutType = $pack === self::PACK_OVERLAY ? $this->resolveLayoutType($options) : '';
            $sources = $this->collectSources($area, $pack, $theme, $options + ['layout_type' => $layoutType]);
            if ($sources === []) {
                return null;
            }
            $minify = !empty($this->resourceConfig->resolve($theme, $area)['css_minify']);
            $raw = $this->composeSources($sources, false);
            $rawKey = $minify ? hash('sha256', $raw) : '';
            if ($rawKey !== '' && isset(self::$inlineCssCache[$rawKey])) {
                $css = self::$inlineCssCache[$rawKey];
            } else {
                $css = $this->composeSources($sources, $minify);
                if ($rawKey !== '') {
                    self::$inlineCssCache[$rawKey] = $css;
                }
            }
            if ($css === '') {
                return null;
            }
            $this->markPackEmitted($area, $pack);
            return '<style data-weline-theme-head-pack="' . htmlspecialchars($pack, ENT_QUOTES) . '" data-weline-inline-pack="1">'
                . str_replace('</style', '<\\/style', $css) . '</style>';
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS inline failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 内联「已发布产物」字节：publishPackWithTarget 命中磁盘产物后只读一次文件，
     * 不做 composeSources/minify（那是 2026-10-29 渲染超时 503 的成因）。
     * 产物缺失/不可读/超过内联预算时返回 null，由调用方回退阻塞外链。
     */
    private function tryInlinePublishedCssHtml(string $area, string $pack, array $options = []): ?string
    {
        $normalized = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($normalized)) {
            return null;
        }
        try {
            $target = $this->publishPackWithTarget($normalized, $pack, $options);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS inline(published) failed: ' . $e->getMessage());
            }

            return null;
        }
        $path = (string)($target['path'] ?? '');
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        // 内联预算：生产实测 tokens 包 29.2KB（v13 起含 colors 叶子），dev 45KB；
        // 超过 48KB 说明包结构变了，交回外链更稳妥（避免 HTML 膨胀超过省下的一轮 RTT）。
        if ((int)@filesize($path) > 49152) {
            return null;
        }
        $css = @file_get_contents($path);
        if (!is_string($css) || $css === '') {
            return null;
        }
        $this->markPackEmitted($normalized, $pack);

        return '<style data-weline-theme-head-pack="' . htmlspecialchars($pack, ENT_QUOTES) . '" data-weline-inline-pack="1" data-weline-inline-from="published">'
            . str_replace('</style', '<\\/style', $css) . '</style>';
    }

    public function tryPreloadLinkHtml(string $area, string $pack, array $options = []): ?string
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return null;
        }
        try {
            $url = $this->publishPack($area, $pack, $options);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS pack failed: ' . $e->getMessage());
            }
            return null;
        }
        if ($url === '') {
            return null;
        }
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = ' data-weline-theme-head-pack="' . htmlspecialchars($pack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        // Same markers as the blocking variant: WidgetAssetHtmlPlacement,
        // ThemeHeadCssDuplicateInspector and StaticAssetResponseRewriter key off these.
        if ($pack === self::PACK_OVERLAY) {
            $attrs .= ' data-weline-theme-overlay="1"';
        }
        if ($pack === self::PACK_TOKENS) {
            $attrs .= ' data-weline-theme-tokens="1"';
        }
        $this->markPackEmitted($area, $pack);
        // fetchpriority=low（2026-10-29 LCP 优化）：storefront/overlay 是非阻塞装饰性主包（几十~267KB），
        // 默认 preload 优先级与首屏 LCP 图同为 High，在 4G 下会把带宽从图上抢走（实测 LCP 2.7s）。
        // 首屏骨架由 layout-critical + 内联 tokens 兜住；'low' 会把批量 CSS 推后 ~几百 ms，
        // 高负载/慢网下反而造成「先裸后变」的 CLS（实测 CLS 0.22 vs 0.02）。
        // 2026-10-29 A/B：默认回 'auto'（与 LCP 图同为 High，但 CF 已缓存这些包，RTT 很低）。
        // 可用 $options['preload_priority'] 覆盖（'low' 可显式降级）。
        $priority = (string)($options['preload_priority'] ?? 'auto');
        $priorityAttr = ($priority !== '' && $priority !== 'auto')
            ? ' fetchpriority="' . htmlspecialchars($priority, ENT_QUOTES) . '"'
            : '';
        return '<link rel="preload" as="style" href="' . $safe . '"' . $attrs . $priorityAttr
            . ' onload="this.onload=null;this.rel=\'stylesheet\'">'
            . '<noscript><link rel="stylesheet" href="' . $safe . '"' . $attrs . '></noscript>';
    }

    /**
     * @param list<array{label:string,content:string,url:string}> $sources
     */
    private function composeSources(array $sources, bool $minify): string
    {
        $chunks = [];
        foreach ($sources as $source) {
            $content = $source['content'];
            try {
                $content = $this->publisher->rewriteCssUrls($content, $source['url']);
            } catch (\Throwable) {
                // Keep original relative URLs when rewrite cannot prove safety.
            }
            if ($minify) {
                $content = $this->minifier->minifyFileContent($content, 'css');
            }
            $chunks[] = '/* ' . $source['label'] . ' */' . "\n" . $content;
        }

        return implode("\n", $chunks);
    }

    /**
     * Application scope fingerprint so packs never cross website/store/channel pollution.
     *
     * @return array<string, scalar>
     */
    private function resolvePackScopeFingerprint(string $area): array
    {
        try {
            $app = ThemeApplicationContext::current($area);
            if ($app instanceof ThemeApplicationContext) {
                return [
                    'scope_key' => $app->scopeKey,
                    'store_mode' => $app->storeMode,
                    'theme_id' => $app->themeId,
                    'provider' => $app->provider,
                    'version_owner_scope' => $app->versionOwnerScope,
                    'theme_version_id' => $app->themeVersionId,
                    'content_revision' => $app->contentRevision,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'scope_key' => 'unbound',
            'store_mode' => '',
            'theme_id' => 0,
            'provider' => '',
            'version_owner_scope' => '',
            'theme_version_id' => 0,
            'content_revision' => 0,
        ];
    }

    private function isBaseThemeShell(?WelineTheme $theme): bool
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return true;
        }
        try {
            $path = str_replace('\\', '/', (string)$theme->getPath());
        } catch (\Throwable) {
            $path = '';
        }
        if ($path === '') {
            return true;
        }
        // Design themes live under app/design/{Vendor}/{name}/ — base module shell under Theme/view/theme.
        if (str_contains($path, '/app/design/')) {
            return false;
        }

        return str_contains($path, '/Weline/Theme/view/theme')
            || str_ends_with(rtrim($path, '/'), '/Theme/view/theme');
    }

    private function artifactPathBelongsToTheme(string $absolutePath, ?WelineTheme $theme): bool
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return false;
        }
        try {
            $ns = trim(str_replace('\\', '/', (string)ObjectManager::getInstance(
                ThemeStaticNamespaceService::class
            )->resolvePublicThemePath($theme)), '/');
        } catch (\Throwable) {
            return false;
        }
        if ($ns === '') {
            return false;
        }
        $norm = str_replace('\\', '/', $absolutePath);

        return str_contains($norm, '/pub/static/' . $ns . '/');
    }

    /**
     * @return list<array{label:string,path:string,url:string,content:string,hash:string}>
     */
    private function collectSources(string $area, string $pack, ?WelineTheme $theme, array $options): array
    {
        $leaves = match ($pack) {
            // ① Variables + palette base/mode (blocking first gun). COM 合并序：default→ink→light；dark 在 STOREFRONT/POST。
            self::PACK_TOKENS => [
                // variables/_colors.css 必须在最前：它是 variables 层的颜色兜底盘，
                // 声明 --color-literal-* / --color-brand-* 等叶子。历史清单漏了它，
                // 导致店面上这些 var() 解析为空值 → 整条 shorthand（如
                // widget-content-policy-document-default.css 的 .doc-policy__hero
                // 双层 background）连兜底都失效，反色面 hero 直接空白。
                // weline-foundation 样式表 :113 的注释也写着 "overridden by theme
                // variables/_colors.css when loaded"，即框架假定它会被投递。
                'theme/' . $area . '/variables/_colors.css',
                'theme/' . $area . '/variables/_spacing.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
                // variables/_borders.css + _shadows.css 同属 variables 层的叶子盘，历史清单也漏了：
                // 店面 CSS 消费 --border-width-thin 134 处、--border-width-medium 46 处、
                // --border-radius-md/sm/full 共 51 处、--shadow-card 等，一旦这些叶子解析为空，
                // 形如 `border: var(--border-width-thin) solid <色>` 的整条声明会被浏览器整条丢弃
                // → 边框/圆角/卡片阴影直接消失（不是「样式略偏」而是「元素没有边框」）。
                // 实测：补投前 --border-width-thin / --border-radius-md / --border-input / --shadow-card 全为 EMPTY；
                // --radius-md / --shadow-sm 有值只是因为 foundation 与 colors 盘另有同名声明兜住。
                // _auth.css 不在此列：它只声明 --auth-*（登录/后台面），店面 CSS 引用数为 0。
                'theme/' . $area . '/variables/_borders.css',
                'theme/' . $area . '/variables/_shadows.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_light.css',
            ],
            // ② Global base elements + theme shell (+ dark mode overlay; not first gun).
            self::PACK_STOREFRONT => [
                'statics:ui/weline-foundation.css',
                'statics:ui/weline-frontend.css',
                'theme/' . $area . '/assets/css/theme.css',
                'theme/' . $area . '/colors/_dark.css',
                // Style leaves (e.g. *-amazon.css) stay OUT: backend style selection generates CSS;
                // theme head must never hard-@static them into layout pack.
            ],
            // Backend single pack：default → light → dark（COM 合并同序；未覆盖继承 default）
            self::PACK_BACKEND => [
                'statics:ui/weline-foundation.css',
                'statics:ui/weline-backend.css',
                'theme/' . $area . '/assets/css/theme.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_dark.css',
            ],
            self::PACK_OVERLAY => [],
            self::PACK_TOKENS_PRE => [
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_light.css',
            ],
            self::PACK_TOKENS_POST => [
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_spacing.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_MINIMAL => [
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_BACKEND => [
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_auto-literals.css',
            ],
            self::PACK_UI => $area === 'backend'
                ? ['statics:ui/weline-foundation.css', 'statics:ui/weline-backend.css']
                : ['statics:ui/weline-foundation.css', 'statics:ui/weline-frontend.css'],
            default => [],
        };
        $sources = [];
        foreach ($leaves as $leaf) {
            $resolved = str_starts_with($leaf, 'statics:')
                ? $this->resolveStaticsLeaf(substr($leaf, strlen('statics:')))
                : $this->resolveThemeLeaf($leaf, $theme);
            if ($resolved !== null) {
                $sources[] = $resolved;
            }
        }
        // Disk folds into STOREFRONT/BACKEND when merge on (include_disk); legacy token packs keep opt-in.
        $includeDisk = ($options['include_disk'] ?? false)
            && in_array($pack, [
                self::PACK_STOREFRONT,
                self::PACK_BACKEND,
                self::PACK_TOKENS_POST,
                self::PACK_TOKENS_MINIMAL,
                self::PACK_TOKENS_BACKEND,
            ], true);
        if ($includeDisk) {
            $disk = $this->resolveDiskOverrideSource($area, $theme);
            if ($disk !== null && ThemeDiskHeadService::cssContentIsMeaningful($disk['content'])) {
                $at = $this->diskInsertIndex($pack, $sources);
                array_splice($sources, $at, 0, [$disk]);
            }
        }
        // Overlay is its own pack (or explicit opt-in); never folded into STOREFRONT by default.
        $includeOverlay = $pack === self::PACK_OVERLAY
            || (($options['include_theme_overlay'] ?? false) && $pack === self::PACK_STOREFRONT);
        if ($includeOverlay) {
            foreach ($this->collectThemeOverlaySources($area, $theme, (string)($options['layout_type'] ?? '')) as $overlay) {
                $sources[] = $overlay;
            }
        }

        return $sources;
    }

    /**
     * @return list<array{label:string,path:string,url:string,content:string,hash:string}>
     */
    private function collectThemeOverlaySources(string $area, ?WelineTheme $theme, string $layoutType = ''): array
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return [];
        }
        $leaves = $this->resolveOverlayLeavesForLayout(
            $this->loadThemeOverlayManifest($area, $theme),
            $layoutType
        );
        if ($leaves === []) {
            return [];
        }
        $sources = [];
        foreach ($leaves as $leaf) {
            $resolved = $this->resolveThemeLeaf($leaf, $theme);
            if ($resolved !== null) {
                $sources[] = $resolved;
            }
        }

        return $sources;
    }

    /**
     * @return array{common:list<string>,layouts:array<string,list<string>>}
     */
    private function loadThemeOverlayManifest(string $area, WelineTheme $theme): array
    {
        $empty = ['common' => [], 'layouts' => []];
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return $empty;
        }
        $modulePath = rtrim((string)$module['base_path'], '/\\')
            . '/view/theme/' . $area . '/' . self::OVERLAY_LIST_RELATIVE;
        $absolute = $this->pathResolver->resolveThemeFile($modulePath, $theme);
        if (!is_string($absolute) || $absolute === '' || !is_file($absolute)) {
            return $empty;
        }
        // Only accept design/theme override — never the missing base stub as brand list.
        $normalizedAbs = str_replace('\\', '/', $absolute);
        $normalizedMod = str_replace('\\', '/', $modulePath);
        if ($normalizedAbs === $normalizedMod) {
            return $empty;
        }
        try {
            /** @var mixed $list */
            $list = include $absolute;
        } catch (\Throwable) {
            return $empty;
        }
        if (!is_array($list)) {
            return $empty;
        }

        return $this->normalizeOverlayManifest($list);
    }

    /**
     * Flat list ⇒ all leaves in common (legacy). Structured ⇒ common + layouts[type].
     *
     * @param array<mixed> $list
     * @return array{common:list<string>,layouts:array<string,list<string>>}
     */
    private function normalizeOverlayManifest(array $list): array
    {
        $normalizeLeaf = static function (string $item): string {
            $item = ltrim(str_replace('\\', '/', trim($item)), '/');
            if ($item === '' || str_contains($item, '..')) {
                return '';
            }
            if (!str_starts_with($item, 'theme/')) {
                $item = 'theme/' . $item;
            }

            return $item;
        };
        $hasStructure = array_key_exists('common', $list) || array_key_exists('layouts', $list);
        if (!$hasStructure) {
            $common = [];
            foreach ($list as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $leaf = $normalizeLeaf($item);
                if ($leaf !== '') {
                    $common[] = $leaf;
                }
            }

            return ['common' => array_values(array_unique($common)), 'layouts' => []];
        }
        $common = [];
        foreach ((array)($list['common'] ?? []) as $item) {
            if (!is_string($item)) {
                continue;
            }
            $leaf = $normalizeLeaf($item);
            if ($leaf !== '') {
                $common[] = $leaf;
            }
        }
        $layouts = [];
        foreach ((array)($list['layouts'] ?? []) as $type => $items) {
            if (!is_string($type) || !is_array($items)) {
                continue;
            }
            $type = trim($type);
            if ($type === '') {
                continue;
            }
            $bucket = [];
            foreach ($items as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $leaf = $normalizeLeaf($item);
                if ($leaf !== '') {
                    $bucket[] = $leaf;
                }
            }
            if ($bucket !== []) {
                $layouts[$type] = array_values(array_unique($bucket));
            }
        }

        return ['common' => array_values(array_unique($common)), 'layouts' => $layouts];
    }

    /**
     * Pick common + the most-specific layouts{} bucket for a layout type.
     * Exact key first, then dotted base, then path prefixes (account/login → account).
     *
     * @param array{common:list<string>,layouts:array<string,list<string>>} $manifest
     * @return list<string>
     */
    public static function selectOverlayLeaves(array $manifest, string $layoutType): array
    {
        $common = array_values(array_unique(array_map('strval', $manifest['common'] ?? [])));
        $layouts = is_array($manifest['layouts'] ?? null) ? $manifest['layouts'] : [];
        $out = $common;
        $layoutType = trim(str_replace('\\', '/', $layoutType));
        foreach (self::overlayLayoutLookupKeys($layoutType) as $key) {
            if ($key !== '' && isset($layouts[$key]) && is_array($layouts[$key])) {
                foreach ($layouts[$key] as $leaf) {
                    if (is_string($leaf) && $leaf !== '') {
                        $out[] = $leaf;
                    }
                }
                break;
            }
        }
        if (isset($layouts['*']) && is_array($layouts['*'])) {
            foreach ($layouts['*'] as $leaf) {
                if (is_string($leaf) && $leaf !== '') {
                    $out[] = $leaf;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Most-specific → least-specific lookup keys for overlay layouts{}.
     *
     * @return list<string>
     */
    public static function overlayLayoutLookupKeys(string $layoutType): array
    {
        $layoutType = trim(str_replace('\\', '/', $layoutType));
        if ($layoutType === '') {
            return [];
        }
        $keys = [$layoutType];
        if (str_contains($layoutType, '.')) {
            $keys[] = trim(explode('.', $layoutType, 2)[0]);
        }
        $norm = str_replace('.', '/', $layoutType);
        $parts = array_values(array_filter(explode('/', $norm), static fn(string $p): bool => $p !== ''));
        for ($i = count($parts); $i >= 1; --$i) {
            $keys[] = implode('/', array_slice($parts, 0, $i));
        }

        return array_values(array_unique(array_filter($keys, static fn(string $k): bool => $k !== '')));
    }

    /**
     * @param array{common:list<string>,layouts:array<string,list<string>>} $manifest
     * @return list<string>
     */
    private function resolveOverlayLeavesForLayout(array $manifest, string $layoutType): array
    {
        return self::selectOverlayLeaves($manifest, $layoutType);
    }

    /**
     * Current page layout_type for overlay scoping.
     * Prefer explicit option / Template bake, then ThemePageTypeResolver (request/controller/URI).
     * Empty ⇒ common-only overlay (no guessed "default" bucket).
     *
     * @param array<string,mixed> $options
     */
    private function resolveLayoutType(array $options): string
    {
        $fromOpt = trim((string)($options['layout_type'] ?? ''));
        if ($fromOpt !== '') {
            return $fromOpt;
        }
        $fromTpl = '';
        try {
            $tpl = ObjectManager::getInstance(Template::class);
            if ($tpl instanceof Template) {
                foreach (['layoutType', 'theme.current_layout_name', 'layout_type'] as $key) {
                    $value = trim((string)$tpl->getData($key));
                    if ($value !== '') {
                        $fromTpl = $value;
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }
        if ($fromTpl !== '') {
            return $fromTpl;
        }
        try {
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
            // Empty default: undetected pages stay common-only (do not force PAGE_TYPE_DEFAULT).
            // Pass current Request so URI path↔layout (article/journal/…) can scope overlay.
            $request = null;
            try {
                $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            } catch (\Throwable) {
                $request = null;
            }
            $resolved = trim($resolver->resolveLayoutType(null, null, $request, ''));
            if ($resolved !== '') {
                return $resolved;
            }
            $uri = (string)(\w_env('request.uri', '') ?? '');
            if ($uri !== '') {
                $fromUri = trim($resolver->resolveLayoutTypeFromUri($uri, ''));
                if ($fromUri !== '') {
                    return $fromUri;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param list<array{label:string,path:string,url:string,content:string,hash:string}> $sources
     */
    private function diskInsertIndex(string $pack, array $sources): int
    {
        if (!in_array($pack, [self::PACK_STOREFRONT, self::PACK_BACKEND], true)) {
            return count($sources);
        }
        foreach ($sources as $i => $source) {
            if (str_contains($source['label'], 'weline-foundation.css')) {
                return $i;
            }
        }

        return count($sources);
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveThemeLeaf(string $relativeUnderTheme, ?WelineTheme $theme): ?array
    {
        $relativeUnderTheme = ltrim(str_replace('\\', '/', $relativeUnderTheme), '/');
        if (str_starts_with($relativeUnderTheme, 'theme/')) {
            $relativeUnderTheme = substr($relativeUnderTheme, strlen('theme/'));
        }
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return null;
        }
        $modulePath = rtrim((string)$module['base_path'], '/\\') . '/view/theme/' . $relativeUnderTheme;
        $absolute = $modulePath;
        if ($theme instanceof WelineTheme && (int)$theme->getId() > 0) {
            $absolute = $this->pathResolver->resolveThemeFile($modulePath, $theme);
        }
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);
        if (!is_string($content) || $content === '') {
            return null;
        }
        // Prefer gateway + pinned theme so design themes publish under
        // /static/{Vendor}/{theme}/Weline/Theme/view/theme/... — never bare
        // /static/Weline/Theme/view/theme/... (that 404s design-only leaves).
        $url = $this->resolveThemeLeafPublicUrl($relativeUnderTheme, $theme);
        if ($url === '') {
            $url = $this->fetchThemeUrl('Weline_Theme::theme/' . $relativeUnderTheme);
        }
        if ($url === '') {
            $url = StaticBuildId::versionedPath('/static/Weline/Theme/view/theme/' . $relativeUnderTheme);
        }

        return [
            'label' => $relativeUnderTheme,
            'path' => $absolute,
            'url' => $url,
            'content' => $content,
            'hash' => hash('sha256', $absolute . '|' . filemtime($absolute) . '|' . $content),
        ];
    }

    /**
     * Build the public static URL for a theme leaf under the active theme namespace.
     */
    private function resolveThemeLeafPublicUrl(string $relativeUnderTheme, ?WelineTheme $theme): string
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return '';
        }
        $relativeUnderTheme = ltrim(str_replace('\\', '/', $relativeUnderTheme), '/');
        [$areaHint, $rest] = array_pad(explode('/', $relativeUnderTheme, 2), 2, '');
        $area = strtolower(trim((string)$areaHint)) === 'backend' ? 'backend' : 'frontend';
        $relativePath = ltrim((string)$rest, '/');
        if ($relativePath === '') {
            return '';
        }
        try {
            return trim($this->gateway->buildThemeAssetUrl(
                'Weline_Theme',
                $area,
                $relativePath,
                $theme,
                false,
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveStaticsLeaf(string $relativeUnderStatics): ?array
    {
        $relativeUnderStatics = ltrim(str_replace('\\', '/', $relativeUnderStatics), '/');
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return null;
        }
        $absolute = rtrim((string)$module['base_path'], '/\\') . '/view/statics/' . $relativeUnderStatics;
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $url = $this->fetchStaticsUrl('Weline_Theme::' . $relativeUnderStatics);
        if ($url === '') {
            $url = StaticBuildId::versionedPath('/static/Weline/Theme/' . $relativeUnderStatics);
        }

        return [
            'label' => $relativeUnderStatics,
            'path' => $absolute,
            'url' => $url,
            'content' => $content,
            'hash' => hash('sha256', $absolute . '|' . filemtime($absolute) . '|' . $content),
        ];
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveDiskOverrideSource(string $area, ?WelineTheme $theme): ?array
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return null;
        }
        $area = ThemeDiskKeys::normalizeArea($area);
        // Disk bundle is scope-bound — never hardcode default when application scope is known.
        $scope = 'default';
        try {
            $app = ThemeApplicationContext::current($area);
            if ($app instanceof ThemeApplicationContext && $app->scopeKey !== '') {
                $scope = ThemeDiskKeys::normalizeScope($app->scopeKey);
            }
        } catch (\Throwable) {
            $scope = 'default';
        }
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $bundleMap = ThemeData::getConfigList($area, 'disk_bundle', $scope);
        $hash = (string)($bundleMap[$scope] ?? $bundleMap['default'] ?? '');
        if ($hash === '') {
            return null;
        }
        $path = $this->diskCompile->resolveBundlePath((int)$theme->getId(), $area, $scope, $hash);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $href = $this->diskHead->getOverrideHref($area, $theme, $scope);
        if ($href === '') {
            $href = '/theme/' . $area . '/disk/override';
        }

        return [
            'label' => 'disk-override.' . $hash,
            'path' => $path,
            'url' => $href,
            'content' => $content,
            'hash' => hash('sha256', $path . '|' . filemtime($path) . '|' . $content),
        ];
    }

    private function fetchThemeUrl(string $moduleSource): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return (string)$template->fetchTagSource(DataInterface::dir_type_THEME, $moduleSource);
        } catch (\Throwable) {
            return '';
        }
    }

    private function fetchStaticsUrl(string $moduleSource): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $moduleSource);
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveTheme(string $area): ?WelineTheme
    {
        // Prefer ThemeApplicationContext / preview via ThemeContextService before
        // process ThemeData. ThemeData may still hold module Default while the
        // website application pins a design theme — trusting ThemeData first
        // publishes packs under /static/Weline/Theme/... and 404s design CSS.
        try {
            $resolved = $this->themeContext->resolveTheme($area);
            if ($resolved instanceof WelineTheme && (int)$resolved->getId() > 0) {
                return $resolved;
            }
        } catch (\Throwable) {
        }
        $current = ThemeData::getCurrentTheme();
        if ($current instanceof WelineTheme && (int)$current->getId() > 0) {
            return $current;
        }
        try {
            $resolved = $this->themeContext->resolveRegisteredDefaultTheme($area);

            return $resolved instanceof WelineTheme && (int)$resolved->getId() > 0 ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
