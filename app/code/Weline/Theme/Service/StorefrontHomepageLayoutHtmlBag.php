<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Websites\Model\Website;

/**
 * HotCache bag for formal storefront homepage layout HTML (post-wrap).
 *
 * ControllerFetchFileAfter peeks before renderLayoutChain; MISS remembers the
 * rendered layout. Payloads are gzip-packed so Process L1 (256KiB entry cap)
 * and FileAdapter post-SSR memory headroom can accept ~0.7MB HTML.
 * Shared write is also deferred via PostResponse Scope (SWR family) when the
 * in-request write is refused under memory pressure.
 * Layer B hydrate peeks Shared → Process only (no SSR/seed).
 * Preview / editor bypass the bag.
 */
final class StorefrontHomepageLayoutHtmlBag implements ProcessSharedInterface
{
    public const RESOURCE = 'theme.storefront_homepage_layout_html';

    private const REQUEST_MEMO_PREFIX = 'theme.homepage_layout_html.req.v1.';
    /** v2: payload is post hard-cut finalize (safety_net complete). */
    /** v4: unsupported language uses the switcher dialog, not a header list. */
    /**
     * v26: 全局区片段进入店面外壳（异步占位 + 内联像素），旧 bag 必须整体失效——
     * 否则会拿到改动前写下的外壳，出现「有的请求有占位、有的没有」的抖动。
     */
    /**
     * v28: 外壳 bag 泛化到「按 page_type」且把有效查询串指纹并入 key
     * v27: 外壳 bag 从「仅首页」泛化为「按 page_type」（默认仍只开首页，
     * 由 `theme.layout_html_bag.pages` 逐站开启，例如 homepage/products/about）。
     */
    private const LOGICAL_KEY_PREFIX = 'theme.layout_html.v28.';
    /** Gzip envelope marker (ASCII — avoids null-byte FileAdapter edge cases). */
    private const PACK_PREFIX = 'HPLH1:';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::storefrontHomepageLayoutHtmlPolicy();
    }

    /**
     * Formal layouts/homepage wrap only; controller content template is irrelevant.
     * Never preview/editor.
     * Path-locale storefront prefixes (e.g. /bn_BD) are supported because
     * logicalKey() carries the active locale: each language gets its own bag.
     * If the active locale has not caught up with the visitor path yet, bypass
     * the bag so a default-language payload can never be served under a
     * path-locale URL (GSC indexes those prefixes).
     */
    public function isEligible(string $contentTemplate, string $layoutTemplate): bool
    {
        unset($contentTemplate);
        $layout = \str_replace('\\', '/', $layoutTemplate);
        $pageType = $this->pageTypeFromLayout($layout);
        if ($pageType === '' || !\in_array($pageType, $this->allowedPageTypes(), true)) {
            return false;
        }
        // 变体（分页/筛选/排序）把「有效查询串指纹」放进 key；但拿不到可信 URI 时
        // 必须保守绕过缓存：宁可少缓存一次，也不能把第 2 页混成第 1 页（实测会混）。
        if ($pageType !== 'homepage' && $this->requestUriForQueryCheck() === '') {
            return false;
        }
        if ($this->isPreviewOrEditorRequest()) {
            return false;
        }
        $pathLocale = $this->requestPathLocaleSegment();
        if ($pathLocale !== '' && $pathLocale !== $this->activeLocaleSegment()) {
            // The URI asks for a locale the request context has not adopted yet.
            // Bypass instead of risking a default-language bag HIT.
            return false;
        }

        return true;
    }


    /**
     * 允许的外壳缓存页面类型（逐站可配，默认只有首页，行为与旧版一致）。
     *
     * @return list<string>
     */
    private function allowedPageTypes(): array
    {
        $raw = [];
        try {
            $raw = (array)\Weline\Framework\App\Env::get('theme_layout_html_bag.pages', ['homepage']);
        } catch (\Throwable) {
            $raw = ['homepage'];
        }
        $types = [];
        foreach ($raw as $type) {
            $type = \strtolower(\trim((string)$type));
            if ($type !== '' && \preg_match('/^[a-z0-9_]+$/', $type) === 1) {
                $types[$type] = true;
            }
        }

        return $types === [] ? ['homepage'] : \array_keys($types);
    }

    /** 从布局模板路径推导 page_type：`/layouts/products/default.phtml` → `products`。 */
    private function pageTypeFromLayout(string $layout): string
    {
        if (\preg_match('#/layouts/([a-z0-9_]+)(?:/|\.phtml)#', $layout, $m) === 1) {
            return \strtolower((string)$m[1]);
        }
        if (\preg_match('#::theme/frontend/layouts/([a-z0-9_]+)#', $layout, $m) === 1) {
            return \strtolower((string)$m[1]);
        }

        return '';
    }

    /**
     * 非首页是否带「会改变内容」的查询串（分页/筛选/排序…）。
     * 只有无查询串或纯跟踪参数时才允许走外壳缓存。
     */
    /**
     * 有效查询串指纹（分页/筛选/排序等），剔除穿章与跟踪参数。
     * 同一路径的变体各占一个 key，不会互相顶掉。
     */
    private function queryVariantSegment(): string
    {
        $uri = $this->requestUriForQueryCheck();
        if ($uri === '') {
            return 'q?';
        }
        $query = (string)(\parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return 'q0';
        }
        \parse_str($query, $params);
        $ignored = [
            'w_fpc_pierce', '_weline_dev', 'n', '_t',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'gclid', 'fbclid',
        ];
        foreach (\array_keys($params) as $key) {
            if (\in_array((string)$key, $ignored, true)) {
                unset($params[$key]);
            }
        }
        if ($params === []) {
            return 'q0';
        }
        \ksort($params);

        return 'q' . \substr(\sha1((string)\json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);
    }

    private function requestHasSignificantQuery(): bool
    {
        try {
            $uri = $this->requestUriForQueryCheck();
            if ($uri === '') {
                // 拿不到 URI 就按「有变体」处理：宁可绕过外壳缓存，也不能把变体混成一份。
                return true;
            }
            $query = (string)(\parse_url($uri, PHP_URL_QUERY) ?? '');
            if ($query === '') {
                return false;
            }
            \parse_str($query, $params);
            $ignored = [
                'w_fpc_pierce', '_weline_dev', 'n', '_t',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'gclid', 'fbclid',
            ];
            foreach (\array_keys($params) as $key) {
                if (!\in_array((string)$key, $ignored, true)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /** 依次从几个来源取请求 URI（带 query）；都取不到返回空串。 */
    private function requestUriForQueryCheck(): string
    {
        $candidates = [];
        try {
            $candidates[] = (string)($_SERVER['REQUEST_URI'] ?? '');
            $candidates[] = (string)(\Weline\Framework\Runtime\RequestContext::get('input.server.REQUEST_URI', '') ?? '');
            $candidates[] = (string)(\Weline\Framework\Runtime\RequestContext::get('request.uri', '') ?? '');
            $candidates[] = (string)\w_env('request.uri', '');
            // WLS 下 $_SERVER/RequestContext 常常还是 '/'，真正可信的是 Context 的 input.uri。
            $context = \Weline\Framework\Runtime\Context::current();
            $candidates[] = (string)($context->get('input.server.REQUEST_URI', '') ?? '');
            $candidates[] = (string)($context->get('input.uri', '') ?? '');
        } catch (\Throwable) {
            // 继续用 $_GET 兜底
        }
        foreach ($candidates as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate === '' || $candidate === '/') {
                continue;
            }
            return $candidate;
        }
        // 没有任何可信 URI：若 $_GET 有参数，用查询串表达；否则返回空串表示「未知」。
        $get = isset($_GET) && \is_array($_GET) ? $_GET : [];

        return $get === [] ? '' : ('?' . \http_build_query($get));
    }

    public function peekHtml(string $contentTemplate, string $layoutTemplate): ?string
    {
        if (!$this->isEligible($contentTemplate, $layoutTemplate)) {
            return null;
        }
        $logicalKey = $this->logicalKey($layoutTemplate);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        try {
            if (RequestContext::isInitialized() && RequestContext::has($memoKey)) {
                $memo = RequestContext::get($memoKey);
                if (\is_string($memo) && $memo !== '') {
                    return $memo;
                }
            }
        } catch (\Throwable) {
        }

        $peeked = $this->hotCache->peekPolicy(self::cachePolicy(), $logicalKey);
        $html = $this->unpackPayload($peeked);
        if ($html === null || $html === '') {
            return null;
        }
        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set($memoKey, $html);
            }
        } catch (\Throwable) {
        }

        return $html;
    }

    /**
     * @param callable():string $builder
     */
    public function rememberHtml(string $contentTemplate, string $layoutTemplate, callable $builder): string
    {
        if (!$this->isEligible($contentTemplate, $layoutTemplate)) {
            $rendered = $builder();

            return \is_string($rendered) ? $rendered : '';
        }

        $logicalKey = $this->logicalKey($layoutTemplate);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        $policy = self::cachePolicy();

        $existing = $this->unpackPayload($this->hotCache->peekPolicy($policy, $logicalKey));
        if ($existing !== null && $existing !== '') {
            try {
                if (RequestContext::isInitialized()) {
                    RequestContext::set($memoKey, $existing);
                }
            } catch (\Throwable) {
            }

            return $existing;
        }

        $rendered = $builder();
        $html = \is_string($rendered) ? $rendered : '';
        if ($html === '') {
            return '';
        }

        $packed = $this->packPayload($html);
        // In-request remember (Process L1 when packed ≤256KiB; Shared may refuse under
        // FileAdapter headroom). Builder returns packed bytes already rendered.
        try {
            $this->hotCache->rememberPolicy(
                $policy,
                $logicalKey,
                static function () use ($packed): string {
                    return $packed;
                },
            );
        } catch (\Throwable) {
        }

        // Post-response Scope seed: durable Shared after SSR memory pressure clears.
        try {
            // Immediate post-response Scope seed (workers have ≥1GiB limit; gzip
            // packed payload ~80–150KiB). Also keep in-request remember for Process L1.
            PostResponseTaskQueue::enqueue(
                'theme.homepage_layout_html:' . \sha1($logicalKey),
                static function () use ($policy, $logicalKey, $packed): void {
                    try {
                        if (\function_exists('gc_collect_cycles')) {
                            \gc_collect_cycles();
                        }
                        /** @var StorefrontScopeHotCache $hot */
                        $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
                        $hot->rememberPolicy(
                            $policy,
                            $logicalKey,
                            static function () use ($packed): string {
                                return $packed;
                            },
                        );
                    } catch (\Throwable $e) {
                        \error_log('[HomepageLayoutHtmlBag] post_response_seed_fail:' . $e->getMessage());
                    }
                },
                0.0,
                PostResponseCaptureMode::Scope,
            );
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set($memoKey, $html);
            }
        } catch (\Throwable) {
        }

        return $html;
    }

    /**
     * Layer B: Shared → Process peek only (no builder / no seed).
     *
     * @return array{hit:bool,peeked:int,bags:list<string>,errors:list<string>}
     */
    public function hydrateProcessFromShared(string $layoutOption = 'default'): array
    {
        $out = ['hit' => false, 'peeked' => 0, 'bags' => [], 'errors' => []];
        try {
            if ($this->isPreviewOrEditorRequest()) {
                $out['errors'][] = self::RESOURCE . ':preview_bypass';

                return $out;
            }
            $layoutTemplate = 'Weline_Theme::theme/frontend/layouts/homepage/'
                . ($layoutOption !== '' ? $layoutOption : 'default')
                . '.phtml';
            $logicalKey = $this->logicalKey($layoutTemplate);
            $policy = self::cachePolicy();
            $peeked = $this->hotCache->peekPolicy($policy, $logicalKey);
            if ($this->unpackPayload($peeked) === null) {
                foreach ($this->hydrateCurrencyCandidates() as $currency) {
                    try {
                        RequestContext::setWelineUserCurrency($currency);
                        RequestContext::remove('framework.cache.storefront_key_context.v2');
                        ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class)
                            ->freezeCurrent();
                    } catch (\Throwable) {
                        continue;
                    }
                    $peeked = $this->hotCache->peekPolicy($policy, $logicalKey);
                    if ($this->unpackPayload($peeked) !== null) {
                        break;
                    }
                }
            }
            if ($this->unpackPayload($peeked) === null) {
                $out['errors'][] = self::RESOURCE . ':shared_miss';

                return $out;
            }
            $out['hit'] = true;
            $out['peeked'] = 1;
            $out['bags'][] = self::RESOURCE;

            return $out;
        } catch (\Throwable $e) {
            $out['errors'][] = self::RESOURCE . ':' . $e->getMessage();

            return $out;
        }
    }

    public function logicalKey(string $layoutTemplate): string
    {
        $layout = \str_replace('\\', '/', $layoutTemplate);
        $pageType = $this->pageTypeFromLayout($layout);
        if ($pageType === '') {
            $pageType = 'homepage';
        }
        $option = 'default';
        if (\preg_match('#/layouts/' . \preg_quote($pageType, '#') . '/([^/]+)\.phtml$#', $layout, $m) === 1) {
            $option = \strtolower(\trim((string)$m[1]));
        } elseif (\str_ends_with($layout, '/layouts/' . $pageType . '.phtml')) {
            $option = 'default';
        }
        if ($option === '') {
            $option = 'default';
        }

        $rejected = \strtolower(\trim((string)\Weline\Framework\Runtime\RequestContext::get('route.rejected_path_language', '')));
        $rejectedSeg = $rejected !== '' ? \substr(\sha1($rejected), 0, 8) : 'langok';
        $rejectedCurrency = \strtolower(\trim((string)\Weline\Framework\Runtime\RequestContext::get('route.rejected_path_currency', '')));
        $rejectedCurrencySeg = $rejectedCurrency !== '' ? \substr(\sha1($rejectedCurrency), 0, 8) : 'curok';
        $localeSeg = $this->activeLocaleSegment();

        return self::LOGICAL_KEY_PREFIX
            . $this->themeIdentitySegment()
            . '.' . $pageType . '.'
            . $option
            . '.'
            . $localeSeg
            . '.'
            . $rejectedSeg
            . '.'
            . $rejectedCurrencySeg
            . '.'
            . $this->globalRegionConfigSegment()
            . '.'
            . $this->queryVariantSegment()
            . '.'
            . StorefrontOriginSegment::get();
    }

    /**
     * 全局区开关/配置的指纹：开关一变，店面外壳 key 就变，旧 bag 自然不再命中。
     *
     * 没有这一段时，面板把某个片段关了再打开，玩家仍会拿到关闭期间写下的外壳
     * （表现为「面板关了能关、开了不回来」）。
     */
    private function globalRegionConfigSegment(): string
    {
        try {
            $config = \Weline\Framework\App\Env::getInstance()->getConfig('theme/base_contributions', []);
        } catch (\Throwable) {
            $config = [];
        }
        $encoded = \is_array($config) ? \json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        // 片段「标记版本」也算进 key：契约侧改了占位/浮层包装等标记时，
        // 开关没变也必须让旧外壳失效（否则页面还是旧结构）。
        return 'gr' . \substr(\sha1((string)$encoded), 0, 10)
            . '.' . \Weline\Theme\Region\GlobalRegionSourceRewriter::VERSION;
    }

    private function packPayload(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (\str_starts_with($html, self::PACK_PREFIX)) {
            return $html;
        }
        if (!\function_exists('gzcompress')) {
            return $html;
        }
        $gz = \gzcompress($html, 6);
        if (!\is_string($gz) || $gz === '') {
            return $html;
        }

        return self::PACK_PREFIX . $gz;
    }

    private function unpackPayload(mixed $payload): ?string
    {
        if (!\is_string($payload) || $payload === '') {
            return null;
        }
        if (\str_starts_with($payload, self::PACK_PREFIX)) {
            if (!\function_exists('gzuncompress')) {
                return null;
            }
            $raw = \substr($payload, \strlen(self::PACK_PREFIX));
            $html = \gzuncompress($raw);

            return \is_string($html) && $html !== '' ? $html : null;
        }

        // Legacy uncompressed HTML (pre-pack) still accepted.
        return \trim($payload) !== '' ? $payload : null;
    }

    private function themeIdentitySegment(): string
    {
        // Logical key stays on theme id only. Publish/version bumps ride the
        // CachePolicy `theme` dependency fingerprint + projection-pool purge —
        // embedding V/R here forked hydrate vs live keys (t3 vs t3.vN.formal).
        try {
            $snapshot = ThemeLayoutSourceSnapshot::current();
            if ($snapshot !== null && $snapshot->identity !== null) {
                $id = (int)$snapshot->identity->themeId;
                if ($id > 0) {
                    return 't' . $id;
                }
            }
        } catch (\Throwable) {
        }

        try {
            /** @var ThemeContextService $ctx */
            $ctx = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $ctx->resolveTheme('frontend', null, false);
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                $id = (int)$theme->getId();
                if ($id > 0) {
                    return 't' . $id;
                }
            }
        } catch (\Throwable) {
        }

        return 't0';
    }

    /**
     * Dialog copy is already translated into the cached HTML.
     * Rejected-path flags alone let two enabled languages share one bag.
     */
    private function activeLocaleSegment(): string
    {
        $lang = \strtolower(\str_replace('-', '_', \trim(
            \Weline\Framework\Runtime\RequestContext::getWelineUserLang()
        )));
        $lang = \preg_replace('/[^a-z0-9_]/', '', $lang) ?? '';

        return $lang !== '' ? $lang : 'nolang';
    }

    /**
     * @return list<string>
     */
    private function hydrateCurrencyCandidates(): array
    {
        $candidates = ['USD'];
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website = (clone $website)->load(Website::ID_DEFAULT, null, true);
            $default = \strtoupper(\trim((string)($website->getDefaultCurrency() ?? '')));
            if ($default !== '') {
                $candidates[] = $default;
            }
        } catch (\Throwable) {
        }
        try {
            $current = \strtoupper(\trim((string)RequestContext::getWelineUserCurrency()));
            if ($current !== '') {
                $candidates[] = $current;
            }
        } catch (\Throwable) {
        }

        return \array_values(\array_unique($candidates));
    }

    /**
     * True when the visitor URI starts with a storefront locale segment (bn_BD, ar_SA, …).
     * Prefer WELINE_ORIGIN_REQUEST_URI / WELINE_FULL_REQUEST_URI — Framework may rewrite
     * REQUEST_URI to the canonical (locale-stripped) path before layout bag peek.
     */
    private function requestHasStorefrontPathLocalePrefix(): bool
    {
        return $this->requestPathLocaleSegment() !== '';
    }

    /**
     * Normalized locale segment from the visitor URI, or '' when absent.
     */
    private function requestPathLocaleSegment(): string
    {
        $candidates = [];
        try {
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_VISITOR_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        } catch (\Throwable) {
        }
        $candidates[] = (string)\Weline\Framework\Env\WelineEnv::serverOrGlobal('WELINE_VISITOR_REQUEST_URI', '');
        $candidates[] = (string)\Weline\Framework\Env\WelineEnv::serverOrGlobal('WELINE_ORIGIN_REQUEST_URI', '');
        $candidates[] = (string)\Weline\Framework\Env\WelineEnv::serverOrGlobal('WELINE_FULL_REQUEST_URI', '');
        $candidates[] = \Weline\Framework\Http\RequestUri::current();

        foreach ($candidates as $uri) {
            $uri = \trim($uri);
            if ($uri === '') {
                continue;
            }
            $path = \parse_url($uri, \PHP_URL_PATH);
            if (!\is_string($path) || $path === '') {
                $path = $uri;
            }
            $q = \strpos($path, '?');
            if ($q !== false) {
                $path = \substr($path, 0, $q);
            }
            if (\preg_match('#^/([a-z]{2}(?:_[A-Za-z]{2,4}){1,2})(?:/|$)#', $path, $m) === 1) {
                return \strtolower(\str_replace('-', '_', (string)$m[1]));
            }
        }

        return '';
    }

    private function isPreviewOrEditorRequest(): bool
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $editorMode = \trim((string)$request->getParam('editor_mode', ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
            $preview = \trim((string)$request->getParam('preview', ''));
            if ($preview === '1' || \strtolower($preview) === 'true') {
                return true;
            }
            $status = \strtolower(\trim((string)$request->getParam('status', '')));
            if ($status === 'draft' || $status === 'preview') {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::isInitialized()) {
                $mode = \strtolower(\trim((string)RequestContext::get('theme.store_mode', '')));
                if ($mode === 'preview' || $mode === 'draft') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (State::isBackend()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
