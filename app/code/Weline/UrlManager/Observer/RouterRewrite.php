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

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Model\UrlRewrite;

class RouterRewrite implements \Weline\Framework\Event\ObserverInterface
{
    private const PROCESS_CACHE_TTL = 300;
    private const PROCESS_CACHE_MAX_ITEMS = 2048;

    private ?UrlRewrite $urlRewrite = null;
    
    private ?CachePoolInterface $cache = null;

    /** @var array<string, mixed> */
    private static array $processCache = [];

    /** @var array<string, int> */
    private static array $processCacheExpiresAt = [];

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
     * 按 website_id 和 rewrite 查找重写记录，多条匹配时取 rewrite_id 最大的（最近新增的那条）
     *
     * @param int $websiteId 网站ID
     * @param string $rewrite 重写路径
     * @return UrlRewrite
     */
    private function findRewriteByWebsiteAndRewrite(int $websiteId, string $rewrite): UrlRewrite
    {
        return $this->getUrlRewrite()
            ->reset()
            ->clearQuery()
            ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlRewrite::schema_fields_REWRITE, $rewrite)
            ->order(UrlRewrite::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
    }

    /**
     * 生成缓存键（包含 websiteId 隔离）
     */
    private function getCacheKey(string $uri, int $websiteId): string
    {
        return 'v3_website_' . $websiteId . '_' . $this->normalizeRewriteCacheUri($uri);
    }

    private function normalizeRewriteCacheUri(string $uri): string
    {
        $path = Url::parse_url($uri, 'path');
        if (is_string($path) && $path !== '') {
            return ltrim($path, '/');
        }

        return ltrim(strtok($uri, '?') ?: $uri, '/');
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
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $uri = ltrim($event->getData(), '/');
        $cache = $this->getCache();
        $websiteId = $this->getCurrentWebsiteId();
        $cacheUri = $this->normalizeRewriteCacheUri($uri);
        $cacheKey = $this->getCacheKey($cacheUri, $websiteId);
        
        // 尝试从缓存获取（缓存按 website_id 隔离）
        // 返回值说明：
        // - array: 找到缓存，包含path
        // - null: 缓存了"未找到"的结果
        // - false: 缓存未命中，需要查询数据库
        $rewriteData = $this->getProcessCache($cacheKey);
        if ($rewriteData === false && $this->isCanonicalDirectRoute($cacheUri)) {
            $this->setProcessCache($cacheKey, 'not_found');
            return;
        }
        if ($rewriteData === false) {
            $rewriteData = $cache->get($cacheKey);
            if ($rewriteData !== false) {
                $this->setProcessCache($cacheKey, $rewriteData);
            }
        }
        if (is_array($rewriteData) && isset($rewriteData['path'])) {
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
        
        // $rewriteData === false，缓存未命中，查询数据库
        $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $cacheUri);
        if (!$rewrite->getId()) {
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $cacheUri);
        }
        
        if ($rewrite->getId()) {
            $path = $rewrite->getData('path');
            $rewriteData = [
                'path' => $path,
                'url_id' => (string)($rewrite->getData(UrlRewrite::schema_fields_URL_ID) ?? ''),
                'website_id' => (int)($rewrite->getData(UrlRewrite::schema_fields_WEBSITE_ID) ?? 0),
            ];
            $this->setProcessCache($cacheKey, $rewriteData);
            $cache->set($cacheKey, $rewriteData);
            
            $this->applyRewrite(
                $event,
                $path,
                $uri,
                $rewriteData['url_id'],
                $rewriteData['website_id']
            );
        } else {
            $path = Url::parse_url($uri, 'path');
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $path);
            if (!$rewrite->getId()) {
                $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $path);
            }

            if ($rewrite->getId()) {
                $rewritePath = $rewrite->getData('path');
                $rewriteData = [
                    'path' => $rewritePath,
                    'url_id' => (string)($rewrite->getData(UrlRewrite::schema_fields_URL_ID) ?? ''),
                    'website_id' => (int)($rewrite->getData(UrlRewrite::schema_fields_WEBSITE_ID) ?? 0),
                ];
                $this->setProcessCache($cacheKey, $rewriteData);
                $cache->set($cacheKey, $rewriteData);
                
                $this->applyRewrite(
                    $event,
                    $rewritePath,
                    $uri,
                    $rewriteData['url_id'],
                    $rewriteData['website_id']
                );
            } else {
                $this->setProcessCache($cacheKey, 'not_found');
                $cache->set($cacheKey, 'not_found');
            }
        }
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

        if (\preg_match('/^[A-Z]{3}$/i', $segments[0]) === 1) {
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
            'customer/account',
            'weshop/customer/account',
        ] as $prefix) {
            if ($route === $prefix || \str_starts_with($route, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function applyRewrite(Event &$event, string $path, string $uri, ?string $urlId = null, ?int $websiteId = null): void
    {
        # 读取原地址
        $query = Url::parse_url($uri, 'query');
        $origin_path = '/' . $path;
        if ($query) {
            if (str_contains($origin_path, '?')) {
                $origin_path .= '&' . $query;
            } else {
                $origin_path .= '?' . $query;
            }
        }

        $event->setData('data', $origin_path);
        $query = Url::parse_url($origin_path, 'query');
        $decodedParams = [];
        parse_str($query, $decodedParams);
        WelineEnv::replaceGet($decodedParams);
        $this->syncDecodedWlsRequestState($origin_path, $uri, $decodedParams, $websiteId, $urlId);
    }

    /**
     * WLS 下 SEO 解码后需要同步当前请求状态，而不是依赖 302 跳转。
     * 否则同一请求后半段仍可能读取到旧 REQUEST_URI / Query / RequestContext / Request 参数包。
     */
    private function syncDecodedWlsRequestState(string $decodedUri, string $originUri, array $decodedParams, ?int $websiteId = null, ?string $urlId = null): void
    {
        $queryString = Url::parse_url($decodedUri, 'query') ?: '';
        $originRequestUri = '/' . ltrim($originUri, '/');

        WelineEnv::setServer('REQUEST_URI', $decodedUri, 'RouterRewrite decoded uri');
        WelineEnv::setServer('QUERY_STRING', $queryString, 'RouterRewrite decoded uri');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $originRequestUri, 'RouterRewrite decoded uri');

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
