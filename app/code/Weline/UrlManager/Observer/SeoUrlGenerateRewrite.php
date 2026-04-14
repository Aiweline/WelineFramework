<?php

namespace Weline\UrlManager\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\UrlManager\Model\UrlRewrite;

class SeoUrlGenerateRewrite implements ObserverInterface
{
    private const CACHE_TTL = 300;
    private const CACHE_PREFIX = 'seo_generate_rewrite.v1.';
    private const REQUEST_CACHE_PREFIX = 'seo_generate_rewrite.';

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

        $parse = Url::parser($url);
        if (is_string($parse)) {
            return;
        }

        $uri = (string)($parse['uri'] ?? '');
        if ($uri === '') {
            return;
        }

        $uri = ltrim($uri, '/');
        $realUri = $this->stripLocaleSegments($uri, (string)($parse['currency'] ?? ''), (string)($parse['language'] ?? ''));
        if ($realUri === '') {
            return;
        }

        $websiteId = $this->resolveWebsiteId($parse);
        $matchUri = strtolower($uri);
        $cachedRewrite = $this->resolveCachedRewrite($websiteId, $matchUri, $realUri);
        if ($cachedRewrite === 'not_found') {
            return;
        }
        if (is_array($cachedRewrite)) {
            $event->setData('data', $this->applyRewrite($url, $cachedRewrite));
            return;
        }

        $resolvedRewrite = $this->findRewrite($websiteId, $matchUri);
        if ($resolvedRewrite === null && $matchUri !== $realUri) {
            $resolvedRewrite = $this->findRewrite($websiteId, $realUri);
        }

        if ($resolvedRewrite === null) {
            $this->storeRewriteCache($websiteId, $matchUri, $realUri, 'not_found');
            return;
        }

        $this->storeRewriteCache($websiteId, $matchUri, $realUri, $resolvedRewrite);
        $event->setData('data', $this->applyRewrite($url, $resolvedRewrite));
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
            $realUri = substr($realUri, strlen($currency . '/'));
        }
        if ($language !== '') {
            $realUri = substr($realUri, strlen($language . '/'));
        }

        return $realUri;
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
}
