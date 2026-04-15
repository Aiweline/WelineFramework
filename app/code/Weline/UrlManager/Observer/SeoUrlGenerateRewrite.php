<?php

namespace Weline\UrlManager\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\UrlManager\Model\UrlRewrite;

class SeoUrlGenerateRewrite implements ObserverInterface
{
    private const CACHE_TTL = 300;
    private const CACHE_PREFIX = 'seo_generate_rewrite.v1.';
    private const REQUEST_CACHE_PREFIX = 'seo_generate_rewrite.';
    private const REQUEST_CONTEXT_CACHE_PREFIX = 'seo_generate_rewrite_context.';

    private ?CachePoolInterface $cache = null;

    public function __construct(private UrlRewrite $urlRewrite)
    {
    }

    public function execute(Event &$event): void
    {
        $url = (string)$event->getData('data');
        if ($url === '') {
            return;
        }

        if (!$this->isRewriteCandidate($url)) {
            return;
        }

        // 检查当前请求是否为后台请求，如果是则跳过 SEO 重写
        // 后台 URL 不应该被 SEO 重写，因为它们有自己的路由规则
        if ($this->isBackendRequest()) {
            return;
        }

        $rewriteContext = $this->resolveRewriteContext($url);
        if ($rewriteContext === null) {
            return;
        }

        $websiteId = (int)$rewriteContext['website_id'];
        $matchUri = strtolower((string)$rewriteContext['uri']);
        $realUri = strtolower((string)$rewriteContext['real_uri']);
        if ($realUri === '') {
            return;
        }

        $cachedRewrite = $this->traceSection('seo_url_generate_rewrite::resolve_cached_rewrite', function () use ($websiteId, $matchUri, $realUri) {
            return $this->resolveCachedRewrite($websiteId, $matchUri, $realUri);
        });
        if ($cachedRewrite === 'not_found') {
            return;
        }
        if (is_array($cachedRewrite)) {
            $event->setData('data', $this->traceSection('seo_url_generate_rewrite::apply_cached_rewrite', function () use ($url, $cachedRewrite) {
                return $this->applyRewrite($url, $cachedRewrite);
            }));
            return;
        }

        $resolvedRewrite = $this->traceSection('seo_url_generate_rewrite::find_rewrite_primary', function () use ($websiteId, $matchUri) {
            return $this->findRewrite($websiteId, $matchUri);
        });
        if ($resolvedRewrite === null && $matchUri !== $realUri) {
            $resolvedRewrite = $this->traceSection('seo_url_generate_rewrite::find_rewrite_fallback', function () use ($websiteId, $realUri) {
                return $this->findRewrite($websiteId, $realUri);
            });
        }

        if ($resolvedRewrite === null) {
            $this->traceSection('seo_url_generate_rewrite::store_not_found_cache', function () use ($websiteId, $matchUri, $realUri) {
                $this->storeRewriteCache($websiteId, $matchUri, $realUri, 'not_found');
                return null;
            });
            return;
        }

        $this->traceSection('seo_url_generate_rewrite::store_hit_cache', function () use ($websiteId, $matchUri, $realUri, $resolvedRewrite) {
            $this->storeRewriteCache($websiteId, $matchUri, $realUri, $resolvedRewrite);
            return null;
        });
        $event->setData('data', $this->traceSection('seo_url_generate_rewrite::apply_resolved_rewrite', function () use ($url, $resolvedRewrite) {
            return $this->applyRewrite($url, $resolvedRewrite);
        }));
    }

    private function isBackendRequest(): bool
    {
        if (RequestContext::isBackendArea()) {
            return true;
        }

        $area = (string)RequestContext::get('env.area', '');
        return $area === RequestContext::AREA_BACKEND || $area === RequestContext::AREA_REST_BACKEND;
    }

    private function isRewriteCandidate(string $url): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return false;
        }

        $firstSegment = strtolower((string)strtok(ltrim($path, '/'), '/'));
        if ($firstSegment === '') {
            return true;
        }

        $nonFrontendPrefixes = array_filter([
            strtolower(trim((string)Env::getAreaRoutePrefix('backend'), '/')),
            strtolower(trim((string)Env::getAreaRoutePrefix('rest_backend'), '/')),
            strtolower(trim((string)Env::getAreaRoutePrefix('rest_frontend'), '/')),
            'media',
            'statics',
            'pub',
        ]);

        return !in_array($firstSegment, $nonFrontendPrefixes, true);
    }

    private function stripLocaleSegments(string $uri, string $currency, string $language): string
    {
        $realUri = $uri;
        if ($currency !== '') {
            $prefix = $currency . '/';
            if (\str_starts_with($realUri, $prefix)) {
                $realUri = substr($realUri, strlen($prefix));
            }
        }
        if ($language !== '') {
            $prefix = $language . '/';
            if (\str_starts_with($realUri, $prefix)) {
                $realUri = substr($realUri, strlen($prefix));
            }
        }

        return $realUri;
    }

    /**
     * @return array{website_id:int,uri:string,real_uri:string}|null
     */
    private function resolveRewriteContext(string $url): ?array
    {
        $fastContext = $this->resolveCurrentSiteRewriteContext($url);
        if ($fastContext !== null) {
            return $fastContext;
        }

        $parse = $this->traceSection('seo_url_generate_rewrite::parser', static function () use ($url) {
            return Url::parser($url);
        });
        if (!\is_array($parse)) {
            return null;
        }

        $uri = ltrim((string)($parse['uri'] ?? ''), '/');
        if ($uri === '') {
            return null;
        }

        $realUri = $this->stripLocaleSegments($uri, (string)($parse['currency'] ?? ''), (string)($parse['language'] ?? ''));
        if ($realUri === '') {
            return null;
        }

        return [
            'website_id' => $this->resolveWebsiteId($parse),
            'uri' => $uri,
            'real_uri' => $realUri,
        ];
    }

    /**
     * Fast-path same-site frontend URLs to avoid the full Url::parser() pipeline.
     *
     * @return array{website_id:int,uri:string,real_uri:string}|null
     */
    private function resolveCurrentSiteRewriteContext(string $url): ?array
    {
        $requestCacheKey = self::REQUEST_CONTEXT_CACHE_PREFIX . md5($url);
        if (RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);
            return \is_array($cached) ? $cached : null;
        }

        $path = (string)(\parse_url($url, \PHP_URL_PATH) ?? '');
        if ($path === '') {
            return null;
        }

        $urlHost = \strtolower(\trim((string)(\parse_url($url, \PHP_URL_HOST) ?? '')));
        $currentHost = $this->resolveCurrentHost();
        if ($urlHost !== '' && $currentHost !== '' && !$this->isSameSiteHost($urlHost, $currentHost)) {
            return null;
        }

        $uri = \ltrim($path, '/');
        if ($uri === '') {
            return null;
        }

        [$currency, $language] = $this->resolveCurrentSiteLocaleSegments($uri);
        $realUri = $this->stripLocaleSegments($uri, $currency, $language);
        if ($realUri === '') {
            $realUri = $uri;
        }

        $context = [
            'website_id' => $this->resolveCurrentWebsiteId(),
            'uri' => $uri,
            'real_uri' => $realUri,
        ];
        RequestContext::set($requestCacheKey, $context);

        return $context;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveCurrentSiteLocaleSegments(string $uri): array
    {
        $segments = \explode('/', \ltrim($uri, '/'));
        $currency = '';
        $language = '';

        $currencyCandidates = [];
        foreach ([
            RequestContext::currency(),
            \w_env('user.currency', ''),
            \w_env('website.currency', ''),
            Env::get('currency', 'CNY'),
        ] as $candidate) {
            $candidate = \strtoupper(\trim((string)$candidate));
            if ($candidate !== '') {
                $currencyCandidates[$candidate] = true;
            }
        }

        if (isset($segments[0])) {
            $first = \strtoupper(\trim((string)$segments[0]));
            if ($first !== '' && isset($currencyCandidates[$first])) {
                $currency = $first;
            }
        }

        $languageCandidates = [];
        foreach ([
            RequestContext::locale(),
            \w_env('user.lang', ''),
            \w_env('website.language', ''),
            Env::get('lang', Env::get('locale', 'zh_Hans_CN')),
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                $languageCandidates[$candidate] = true;
            }
        }

        $languageIndex = $currency !== '' ? 1 : 0;
        if (isset($segments[$languageIndex])) {
            $candidate = \trim((string)$segments[$languageIndex]);
            if ($candidate !== '' && isset($languageCandidates[$candidate])) {
                $language = $candidate;
            }
        }

        return [$currency, $language];
    }

    private function resolveCurrentWebsiteId(): int
    {
        $websiteId = RequestContext::websiteId();
        if (\is_int($websiteId) && $websiteId > 0) {
            return $websiteId;
        }

        return UrlRewrite::getCurrentWebsiteId();
    }

    private function resolveCurrentHost(): string
    {
        $host = $this->normalizeHost((string)RequestContext::get('input.host', ''));
        if ($host !== '') {
            return $host;
        }

        $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        return $this->normalizeHost((string)(\parse_url($websiteUrl, \PHP_URL_HOST) ?? ''));
    }

    private function isSameSiteHost(string $left, string $right): bool
    {
        $left = $this->normalizeHost($left);
        $right = $this->normalizeHost($right);
        if ($left === $right) {
            return true;
        }

        $left = \preg_replace('/^www\./', '', $left) ?? $left;
        $right = \preg_replace('/^www\./', '', $right) ?? $right;

        return $left === $right;
    }

    private function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }

        if (\str_contains($host, '://')) {
            return \strtolower(\trim((string)(\parse_url($host, \PHP_URL_HOST) ?? '')));
        }

        if ($host[0] === '[') {
            $pos = \strpos($host, ']');
            if ($pos !== false) {
                return \substr($host, 0, $pos + 1);
            }
        }

        if (\substr_count($host, ':') === 1) {
            [$candidateHost, $candidatePort] = \explode(':', $host, 2);
            if ($candidatePort !== '' && \ctype_digit($candidatePort)) {
                return $candidateHost;
            }
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $parse
     */
    private function resolveWebsiteId(array $parse): int
    {
        if (isset($parse['website']['website_id'])) {
            return (int)$parse['website']['website_id'];
        }
        if (isset($parse['server']['WELINE_WEBSITE_ID']) && $parse['server']['WELINE_WEBSITE_ID'] !== '') {
            return (int)$parse['server']['WELINE_WEBSITE_ID'];
        }

        return UrlRewrite::getCurrentWebsiteId();
    }

    /**
     * @return array{rewrite: string, matched_uri: string}|string|false
     */
    private function resolveCachedRewrite(int $websiteId, string $matchUri, string $realUri): array|string|false
    {
        $requestCacheKey = $this->buildRequestCacheKey($websiteId, $matchUri, $realUri);
        if (RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);
            return is_array($cached) || is_string($cached) ? $cached : false;
        }

        $cacheKey = $this->buildPersistentCacheKey($websiteId, $matchUri, $realUri);
        $cached = $this->getCache()->get($cacheKey);
        if (!is_array($cached) && !is_string($cached)) {
            return false;
        }

        RequestContext::set($requestCacheKey, $cached);
        return $cached;
    }

    /**
     * @return array{rewrite: string, matched_uri: string}|null
     */
    private function findRewrite(int $websiteId, string $path): ?array
    {
        $rewrite = $this->urlRewrite->reset()
            ->clearQuery()
            ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlRewrite::schema_fields_PATH, $path)
            ->order(UrlRewrite::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if (!$rewrite->getId()) {
            return null;
        }

        return [
            'rewrite' => (string)$rewrite->getData('rewrite'),
            'matched_uri' => $path,
        ];
    }

    /**
     * @param array{rewrite: string, matched_uri: string}|string $value
     */
    private function storeRewriteCache(int $websiteId, string $matchUri, string $realUri, array|string $value): void
    {
        $requestCacheKey = $this->buildRequestCacheKey($websiteId, $matchUri, $realUri);
        RequestContext::set($requestCacheKey, $value);

        $cacheKey = $this->buildPersistentCacheKey($websiteId, $matchUri, $realUri);
        $this->getCache()->set($cacheKey, $value, self::CACHE_TTL);
    }

    /**
     * @param array{rewrite: string, matched_uri: string} $rewrite
     */
    private function applyRewrite(string $url, array $rewrite): string
    {
        return str_ireplace($rewrite['matched_uri'], $rewrite['rewrite'], $url);
    }

    private function buildRequestCacheKey(int $websiteId, string $matchUri, string $realUri): string
    {
        return self::REQUEST_CACHE_PREFIX . md5($websiteId . '|' . $matchUri . '|' . $realUri);
    }

    private function buildPersistentCacheKey(int $websiteId, string $matchUri, string $realUri): string
    {
        return self::CACHE_PREFIX . md5($websiteId . '|' . $matchUri . '|' . $realUri);
    }

    private function getCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $this->cache = w_cache('url_rewrite');
        }

        return $this->cache;
    }

    private function traceSection(string $name, callable $callback): mixed
    {
        if (!RequestLifecycleTrace::isEnabled()) {
            return $callback();
        }

        $start = \microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (\microtime(true) - $start) * 1000, 'observer');
        }
    }
}
