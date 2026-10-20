<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/23 19:52:23
 */

namespace Weline\UrlManager\Observer;

use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\UrlManager\Model\UrlRewrite;

class RouterRewrite implements \Weline\Framework\Event\ObserverInterface
{
    private const PROCESS_CACHE_TTL = 300;
    private const PROCESS_CACHE_MAX_ITEMS = 2048;
    private const CACHE_KEY_PREFIX = 'v3_website_';

    private ?UrlRewrite $urlRewrite = null;
    
    private ?CachePoolInterface $cache = null;

    /** @var array<string, mixed> */
    private static array $processCache = [];

    /** @var array<string, int> */
    private static array $processCacheExpiresAt = [];

    private static ?bool $themeRouterPresent = null;
    private static ?bool $blogRouterPresent = null;

    public function __construct(
        ?UrlRewrite $urlRewrite = null
    )
    {
        if ($urlRewrite !== null) {
            $this->urlRewrite = $urlRewrite;
        }
    }
    
    /**
     * 获取UrlRewrite模型实例（延迟加载）
     */
    private function getUrlRewrite(): UrlRewrite
    {
        if ($this->urlRewrite === null) {
            $this->urlRewrite = \Weline\Framework\Manager\ObjectManager::getInstance(UrlRewrite::class);
        }
        return $this->urlRewrite;
    }
    
    /**
     * 获取缓存实例
     */
    private function getCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $this->cache = w_cache('url_rewrite');
        }
        return $this->cache;
    }
    
    /**
     * 获取当前网站ID
     * 
     * @return int
     */
    private function getCurrentWebsiteId(): int
    {
        return UrlRewrite::getCurrentWebsiteId();
    }
    
    /**
     * 生成缓存键（包含 websiteId 隔离）
     */
    private function getCacheKey(string $uri, int $websiteId): string
    {
        return self::buildCacheKey($websiteId, $uri);
    }

    public static function buildCacheKey(int $websiteId, string $uri): string
    {
        $normalized = \ltrim(\strtok($uri, '?') ?: $uri, '/');

        return self::CACHE_KEY_PREFIX . $websiteId . '_' . $normalized;
    }

    /**
     * Pool/process keys for a rewrite slug (with and without leading slash).
     *
     * @return list<string>
     */
    public static function cacheKeysForRewrite(int $websiteId, string $rewrite): array
    {
        $keys = [];
        foreach (self::rewriteKeyVariants($rewrite) as $variant) {
            $keys[] = self::buildCacheKey($websiteId, $variant);
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private static function rewriteKeyVariants(string $rewrite): array
    {
        $rewrite = \trim($rewrite);
        if ($rewrite === '') {
            return [];
        }
        $noSlash = \ltrim($rewrite, '/');
        $variants = [$noSlash];
        if ($noSlash !== '') {
            $variants[] = '/' . $noSlash;
        }

        return $variants;
    }

    private function normalizeRewriteCacheUri(string $uri): string
    {
        $path = Url::parse_url($uri, 'path');
        if (is_string($path) && $path !== '') {
            return ltrim($path, '/');
        }

        return ltrim(strtok($uri, '?') ?: $uri, '/');
    }

    /**
     * Drop leading currency/locale segments so rewrite lookup uses the public
     * pretty path (contact), matching rows stored without localization prefixes.
     */
    private function stripLocalizationPrefix(string $uri): string
    {
        $stripped = Url::stripStorefrontLocalizationFromPath($uri);
        $language = \trim((string)($stripped['language'] ?? ''));
        if ($language !== '') {
            // Preserve the visitor-facing path locale after the pretty path is
            // stripped for rewrite lookup. PageBuilder LIVE reads RequestContext
            // when REQUEST_URI no longer contains /en_US/...
            RequestContext::locale($language);
        }

        return (string)($stripped['path'] ?? '');
    }

    private function hasProcessCache(string $cacheKey): bool
    {
        $expiresAt = self::$processCacheExpiresAt[$cacheKey] ?? 0;
        if ($expiresAt < \time()) {
            unset(self::$processCache[$cacheKey], self::$processCacheExpiresAt[$cacheKey]);
            return false;
        }

        return \array_key_exists($cacheKey, self::$processCache);
    }

    private function getProcessCache(string $cacheKey): mixed
    {
        return $this->hasProcessCache($cacheKey) ? self::$processCache[$cacheKey] : false;
    }

    private function setProcessCache(string $cacheKey, mixed $value): void
    {
        if (\count(self::$processCache) >= self::PROCESS_CACHE_MAX_ITEMS) {
            \array_shift(self::$processCache);
            \array_shift(self::$processCacheExpiresAt);
        }

        self::$processCache[$cacheKey] = $value;
        self::$processCacheExpiresAt[$cacheKey] = \time() + self::PROCESS_CACHE_TTL;
    }

    /**
     * Clear process-scoped SEO decode rewrite L1 (cross-request TTL bag).
     */
    public static function clearProcessCache(): void
    {
        self::$processCache = [];
        self::$processCacheExpiresAt = [];
    }

    /**
     * @param list<string> $keys
     */
    public static function forgetProcessCacheKeys(array $keys): void
    {
        foreach ($keys as $key) {
            unset(self::$processCache[$key], self::$processCacheExpiresAt[$key]);
        }
    }

    public static function forgetProcessCacheForWebsite(int $websiteId): void
    {
        $prefix = self::CACHE_KEY_PREFIX . $websiteId . '_';
        foreach (\array_keys(self::$processCache) as $key) {
            if (\is_string($key) && \str_starts_with($key, $prefix)) {
                unset(self::$processCache[$key], self::$processCacheExpiresAt[$key]);
            }
        }
    }

    /**
     * Build slash-normalized rewrite lookup candidates in priority order.
     *
     * @return list<string>
     */
    private function buildRewriteLookupCandidates(string $lookupUri): array
    {
        $candidates = [];
        $push = static function (string $rewrite) use (&$candidates): void {
            if ($rewrite === '') {
                return;
            }
            if (!\in_array($rewrite, $candidates, true)) {
                $candidates[] = $rewrite;
            }
        };

        $push($lookupUri);
        if ($lookupUri !== '') {
            $push('/' . \ltrim($lookupUri, '/'));
        }

        return $candidates;
    }

    /**
     * @return array{working_uri:string,lookup_uri:string,website_id:int}
     */
    private function resolveEventTargets(Event $event): array
    {
        $lookupPath = $event->getData('lookup_path');
        $workingUrl = $event->getData('url');
        $websiteFromPayload = $event->getData('website_id');

        if (\is_string($workingUrl) && $workingUrl !== '') {
            $workingUri = \ltrim($this->normalizeRewriteCacheUri($workingUrl), '/');
            $lookupUri = \is_string($lookupPath) ? \ltrim($lookupPath, '/') : '';
            if ($lookupUri === '') {
                $lookupUri = $this->stripLocalizationPrefix($workingUri);
            } elseif ($lookupPath !== '' && \is_string($lookupPath)) {
                // lookup_path already stripped by Url::decode_url; still pin
                // RequestContext locale when the working URL still carries a locale segment.
                $meta = Url::stripStorefrontLocalizationFromPath($workingUri);
                if ($meta['language'] !== '') {
                    RequestContext::locale($meta['language']);
                }
            }
            $websiteId = \is_numeric($websiteFromPayload) && (int)$websiteFromPayload > 0
                ? (int)$websiteFromPayload
                : $this->getCurrentWebsiteId();

            return [
                'working_uri' => $workingUri,
                'lookup_uri' => $lookupUri,
                'website_id' => $websiteId,
            ];
        }

        // Legacy scalar payload (data => url string).
        $raw = $event->getData('data');
        if (!\is_string($raw) || $raw === '') {
            $raw = $event->getData();
        }
        $uri = \is_string($raw) ? \ltrim($raw, '/') : '';
        $cacheUri = $this->normalizeRewriteCacheUri($uri);

        return [
            'working_uri' => $cacheUri,
            'lookup_uri' => $this->stripLocalizationPrefix($cacheUri),
            'website_id' => $this->getCurrentWebsiteId(),
        ];
    }

    private function writeDecodedEventUrl(Event &$event, string $decodedPath): void
    {
        if ($event->getData('url') !== null || $event->getData('lookup_path') !== null) {
            $event->setData('url', $decodedPath);
            return;
        }
        $event->setData('data', $decodedPath);
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $targets = $this->resolveEventTargets($event);
        $uri = $targets['working_uri'];
        $lookupUri = $targets['lookup_uri'];
        $websiteId = $targets['website_id'];

        // 非法 UTF-8 不得作为 rewrite 绑定参数进入 PostgreSQL（SQLSTATE 22021）。
        if ($lookupUri !== '' && !Url::isValidUtf8Text($lookupUri)) {
            return;
        }
        $cacheKey = $this->getCacheKey($lookupUri, $websiteId);
        $cache = null;
        $pool = function () use (&$cache): CachePoolInterface {
            if ($cache === null) {
                $cache = $this->getCache();
            }
            return $cache;
        };
        
        // 尝试从缓存获取（缓存按 website_id 隔离）
        // 返回值说明：
        // - array: 找到缓存，包含path
        // - 'not_found': 缓存了"未找到"的结果
        // - false: 缓存未命中，需要查询数据库
        $rewriteData = $this->getProcessCache($cacheKey);
        if ($rewriteData === false && $this->isCanonicalDirectRoute($lookupUri)) {
            $this->setProcessCache($cacheKey, 'not_found');
            return;
        }
        if ($rewriteData === false) {
            $rewriteData = $pool()->get($cacheKey);
            if ($rewriteData !== false) {
                $this->setProcessCache($cacheKey, $rewriteData);
            }
        }
        if (is_array($rewriteData) && isset($rewriteData['path'])) {
            if ($this->shouldSkipStalePageBuilderShellRewrite($lookupUri, (string)$rewriteData['path'])) {
                $this->setProcessCache($cacheKey, 'not_found');
                $pool()->set($cacheKey, 'not_found');
                return;
            }
            $this->applyRewrite(
                $event,
                $rewriteData['path'],
                $uri,
                $rewriteData['url_id'] ?? null,
                isset($rewriteData['website_id']) ? (int)$rewriteData['website_id'] : null
            );
            return;
        }
        if ($rewriteData === 'not_found') {
            return;
        }
        
        // $rewriteData === false，缓存未命中：slash 候选一次查询
        $row = $this->getUrlRewrite()->findLatestByWebsiteAndRewrites(
            $websiteId,
            $this->buildRewriteLookupCandidates($lookupUri)
        );

        if (\is_array($row) && isset($row['path'])) {
            $path = (string)$row['path'];
            if ($this->shouldSkipStalePageBuilderShellRewrite($lookupUri, $path)) {
                $this->setProcessCache($cacheKey, 'not_found');
                $pool()->set($cacheKey, 'not_found');
                return;
            }
            $rewriteData = [
                'path' => $path,
                'url_id' => (string)($row[UrlRewrite::schema_fields_URL_ID] ?? ''),
                'website_id' => (int)($row[UrlRewrite::schema_fields_WEBSITE_ID] ?? 0),
            ];
            $this->setProcessCache($cacheKey, $rewriteData);
            $pool()->set($cacheKey, $rewriteData);

            $this->applyRewrite(
                $event,
                $path,
                $uri,
                $rewriteData['url_id'],
                $rewriteData['website_id']
            );
            return;
        }

        $this->setProcessCache($cacheKey, 'not_found');
        $pool()->set($cacheKey, 'not_found');
    }

    /**
     * Default Theme storefronts own /privacy|/about|/terms|/contact shell pages.
     * Stale PageBuilder pretty rewrites (missing CMS pages) must not steal them.
     * PageBuilder / AI-site scopes keep their rewrites via Theme::prefersShellPublicAlias().
     */
    private function shouldSkipStalePageBuilderShellRewrite(string $prettyPath, string $targetPath): bool
    {
        $target = \strtolower(\ltrim($targetPath, '/'));
        if (!\str_starts_with($target, 'pagebuilder/frontend/page')) {
            return false;
        }

        $alias = \strtolower(\trim($prettyPath, '/'));
        if ($alias === '') {
            return false;
        }

        if (self::$themeRouterPresent === null) {
            self::$themeRouterPresent = \class_exists(\Weline\Theme\Controller\Router::class);
        }
        if (self::$themeRouterPresent
            && \Weline\Theme\Controller\Router::prefersShellPublicAlias($alias)
        ) {
            return true;
        }

        if (self::$blogRouterPresent === null) {
            self::$blogRouterPresent = \class_exists(\Weline\Blog\Controller\Router::class);
        }
        // Blog module owns /blog; do not keep dead PageBuilder seeds.
        if (self::$blogRouterPresent
            && ($alias === 'blog' || \str_starts_with($alias, 'blog/'))
        ) {
            return true;
        }

        return false;
    }
    
    /**
     * 应用URL重写
     * 
     * @param Event $event
     * @param string $path
     * @param string $uri
     * @param string|null $urlId
     * @param int|null $websiteId
     */
    private function isCanonicalDirectRoute(string $uri): bool
    {
        $path = \trim($this->normalizeRewriteCacheUri($uri), '/');
        if ($path === '') {
            return true;
        }

        $segments = \array_values(\array_filter(\explode('/', $path), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return true;
        }

        if (State::isAllowedCurrencyCode((string)$segments[0])) {
            \array_shift($segments);
        }
        if (isset($segments[0]) && (
            \preg_match('/^[a-z]{2}_[A-Z]{2}$/', $segments[0]) === 1
            || \preg_match('/^[a-z]{2}_[A-Z][a-z]+_[A-Z]{2}$/', $segments[0]) === 1
        )) {
            \array_shift($segments);
        }

        $route = \implode('/', $segments);
        if ($route === '') {
            return true;
        }

        foreach ([
            'catalog/category',
            'catalog/product',
            'customer/account',
            'weshop/customer/account',
            'checkout',
            'cart',
            'api/',
        ] as $prefix) {
            if ($prefix === 'api/') {
                if (\str_starts_with($route, 'api/') || \str_starts_with($route, 'rest/')) {
                    return true;
                }
                continue;
            }
            if ($route === $prefix || \str_starts_with($route, $prefix . '/')) {
                return true;
            }
        }

        // Multi-segment module/controller paths rarely use pretty rewrite rows.
        if (\substr_count($route, '/') >= 2
            && !\str_contains($route, '-')
            && \preg_match('#^[a-z0-9_]+(/[a-z0-9_]+){2,}#', $route) === 1
        ) {
            return true;
        }

        return false;
    }

    private function applyRewrite(Event &$event, string $path, string $uri, ?string $urlId = null, ?int $websiteId = null): void
    {
        # 读取原地址
        $query = Url::parse_url($uri, 'query');
        $origin_path = '/' . \ltrim($path, '/');
        if ($query) {
            if (str_contains($origin_path, '?')) {
                $origin_path .= '&' . $query;
            } else {
                $origin_path .= '?' . $query;
            }
        }

        $this->writeDecodedEventUrl($event, $origin_path);
        $query = Url::parse_url($origin_path, 'query');
        $decodedParams = [];
        parse_str((string)$query, $decodedParams);

        if ($this->isRequestAlreadySynced($origin_path, $websiteId, $urlId)) {
            return;
        }

        WelineEnv::replaceGet($decodedParams);
        $this->syncDecodedWlsRequestState($origin_path, $uri, $decodedParams, $websiteId, $urlId);
    }

    private function isRequestAlreadySynced(string $decodedUri, ?int $websiteId, ?string $urlId): bool
    {
        $current = (string)(
            $_SERVER['REQUEST_URI']
            ?? WelineEnv::server('REQUEST_URI', '')
            ?? ''
        );
        if ($current === '' || $current !== $decodedUri) {
            return false;
        }
        if ($websiteId !== null && $websiteId > 0) {
            $bound = (int)(
                $_SERVER['WELINE_URL_REWRITE_WEBSITE_ID']
                ?? WelineEnv::server('WELINE_URL_REWRITE_WEBSITE_ID', 0)
                ?? 0
            );
            if ($bound !== $websiteId) {
                return false;
            }
        }
        if ($urlId !== null && $urlId !== '') {
            $boundUrlId = (string)(
                $_SERVER['WELINE_URL_REWRITE_URL_ID']
                ?? WelineEnv::server('WELINE_URL_REWRITE_URL_ID', '')
                ?? ''
            );
            if ($boundUrlId !== $urlId) {
                return false;
            }
        }

        return true;
    }

    /**
     * WLS 下 SEO 解码后需要同步当前请求状态，而不是依赖 302 跳转。
     * 否则同一请求后半段仍可能读取到旧 REQUEST_URI / Query / RequestContext / Request 参数包。
     */
    private function syncDecodedWlsRequestState(string $decodedUri, string $originUri, array $decodedParams, ?int $websiteId = null, ?string $urlId = null): void
    {
        $queryString = Url::parse_url($decodedUri, 'query') ?: '';
        $originRequestUri = '/' . ltrim($originUri, '/');
        // Never promote the decoded controller path into ORIGIN when a visitor-
        // facing pretty path is already known — PageBuilder locale resolution
        // reads ORIGIN for /en_US/… segments.
        $existingOrigin = (string)(
            $_SERVER['WELINE_ORIGIN_REQUEST_URI']
            ?? WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '')
            ?? $_SERVER['ORIGIN_REQUEST_URI']
            ?? ''
        );
        $originIsInternal = \str_contains(\strtolower($originRequestUri), 'pagebuilder/frontend/page')
            || \Weline\Framework\Http\Fpc\FpcEngineIdentity::isGuideControllerUri($originRequestUri);
        $existingIsPretty = $existingOrigin !== ''
            && !\str_contains(\strtolower($existingOrigin), 'pagebuilder/frontend/page')
            && !\Weline\Framework\Http\Fpc\FpcEngineIdentity::isGuideControllerUri($existingOrigin);
        if ($originIsInternal) {
            // Prefer any non-internal origin (parser ORIGIN may still hold /en_US/about
            // or the stripped /about). Never let the controller path become ORIGIN.
            if ($existingIsPretty) {
                $originRequestUri = $existingOrigin;
            } elseif ($existingOrigin !== '' && !\str_contains(\strtolower($existingOrigin), 'pagebuilder/frontend/page')) {
                $originRequestUri = $existingOrigin;
            } else {
                // Last resort: keep whatever non-internal path we can salvage from
                // the rewrite input before it was replaced by the controller path.
                $fallback = '/' . \ltrim((string)$originUri, '/');
                if (!\str_contains(\strtolower($fallback), 'pagebuilder/frontend/page')) {
                    $originRequestUri = $fallback;
                }
            }
        } elseif ($existingIsPretty
            && \str_contains(\strtolower($existingOrigin), '/')
            && !\str_contains(\strtolower($originRequestUri), 'en_us')
            && \preg_match('#/[a-z]{2}_[A-Za-z0-9_]+/#i', $existingOrigin) === 1
            && \preg_match('#/[a-z]{2}_[A-Za-z0-9_]+/#i', $originRequestUri) !== 1
        ) {
            // Url::detectLanguage may strip /en_US before RouterRewrite runs, so
            // $originUri is already /about while existing ORIGIN still has /en_US/about.
            $originRequestUri = $existingOrigin;
        }

        WelineEnv::setServer('REQUEST_URI', $decodedUri, 'RouterRewrite decoded uri');
        WelineEnv::setServer('QUERY_STRING', $queryString, 'RouterRewrite decoded uri');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $originRequestUri, 'RouterRewrite decoded uri');
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $originRequestUri;
        $originLocalization = State::resolveLocalizationFromPathSegments(
            \array_values(\array_filter(
                \explode('/', \trim($originRequestUri, '/')),
                static fn(string $segment): bool => $segment !== ''
            ))
        );
        $originLanguage = \trim((string)($originLocalization['language'] ?? ''));
        if ($originLanguage === '') {
            try {
                $originLanguage = \trim((string)(RequestContext::locale() ?? ''));
            } catch (\Throwable) {
                $originLanguage = '';
            }
            if ($originLanguage === '') {
                try {
                    $originLanguage = \trim((string)WelineEnv::getLang());
                } catch (\Throwable) {
                    $originLanguage = '';
                }
            }
        }
        if ($originLanguage !== '') {
            RequestContext::locale($originLanguage);
        }
        if ($websiteId !== null && $websiteId > 0) {
            WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$websiteId, 'RouterRewrite decoded website');
            WelineEnv::setServer('WELINE_URL_REWRITE_WEBSITE_ID', (string)$websiteId, 'RouterRewrite decoded rewrite website');
        }
        if ($urlId !== null && $urlId !== '') {
            WelineEnv::setServer('WELINE_URL_REWRITE_URL_ID', $urlId, 'RouterRewrite decoded url id');
        }

        if (isset($decodedParams['locale']) && \is_string($decodedParams['locale']) && $decodedParams['locale'] !== '') {
            WelineEnv::set('user.lang', $decodedParams['locale'], 'RouterRewrite decoded locale');
        }
        if (isset($decodedParams['currency']) && \is_string($decodedParams['currency']) && $decodedParams['currency'] !== '') {
            WelineEnv::set('user.currency', $decodedParams['currency'], 'RouterRewrite decoded currency');
        }

        try {
            /** @var \Weline\Framework\Http\Request $request */
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $request->resetParameterBag()
                ->invalidateUriCache()
                ->unsetData('params')
                ->setServer('REQUEST_URI', $decodedUri)
                ->setServer('QUERY_STRING', $queryString)
                ->setServer('WELINE_ORIGIN_REQUEST_URI', $originRequestUri);

            if ($websiteId !== null && $websiteId > 0) {
                $request->setServer('WELINE_WEBSITE_ID', (string)$websiteId)
                    ->setServer('WELINE_URL_REWRITE_WEBSITE_ID', (string)$websiteId);
            }
            if ($urlId !== null && $urlId !== '') {
                $request->setServer('WELINE_URL_REWRITE_URL_ID', $urlId);
            }

            foreach ($decodedParams as $key => $value) {
                if (\is_string($key)) {
                    $request->setGet($key, $value);
                }
            }
        } catch (\Throwable $e) {
            // The request object may not be initialized yet; Context already contains the decoded state.
        }
    }
}
