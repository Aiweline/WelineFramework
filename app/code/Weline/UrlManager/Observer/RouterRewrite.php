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
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Model\UrlRewrite;

class RouterRewrite implements \Weline\Framework\Event\ObserverInterface
{
    private ?UrlRewrite $urlRewrite = null;
    
    private ?CachePoolInterface $cache = null;

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
        return 'website_' . $websiteId . '_' . $uri;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $uri = ltrim($event->getData(), '/');
        $cache = $this->getCache();
        $websiteId = $this->getCurrentWebsiteId();
        $cacheKey = $this->getCacheKey($uri, $websiteId);
        
        // 尝试从缓存获取（缓存按 website_id 隔离）
        // 返回值说明：
        // - array: 找到缓存，包含path
        // - null: 缓存了"未找到"的结果
        // - false: 缓存未命中，需要查询数据库
        $rewriteData = $cache->get($cacheKey);
        
        if (is_array($rewriteData) && isset($rewriteData['path'])) {
            $this->applyRewrite(
                $event,
                $rewriteData['path'],
                $uri,
                $rewriteData['url_id'] ?? null,
                isset($rewriteData['website_id']) ? (int)$rewriteData['website_id'] : null
            );
            return;
        } elseif ($rewriteData === null) {
            return;
        }
        
        // $rewriteData === false，缓存未命中，查询数据库
        $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $uri);
        if (!$rewrite->getId()) {
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $uri);
        }
        
        if ($rewrite->getId()) {
            $path = $rewrite->getData('path');
            $rewriteData = [
                'path' => $path,
                'url_id' => (string)($rewrite->getData(UrlRewrite::schema_fields_URL_ID) ?? ''),
                'website_id' => (int)($rewrite->getData(UrlRewrite::schema_fields_WEBSITE_ID) ?? 0),
            ];
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
                $cache->set($cacheKey, $rewriteData);
                
                $this->applyRewrite(
                    $event,
                    $rewritePath,
                    $uri,
                    $rewriteData['url_id'],
                    $rewriteData['website_id']
                );
            } else {
                $cache->set($cacheKey, null);
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

        // PageBuilder 重写优先透传精确 page_id / website_id，避免仅靠 handle 命中旧页面或错误模板
        if (str_starts_with($origin_path, '/pagebuilder/frontend/page/view')) {
            $originQuery = Url::parse_url($origin_path, 'query') ?: '';
            parse_str($originQuery, $originParams);
            if (!isset($originParams['page_id']) && is_string($urlId) && preg_match('/^pagebuilder_page_(\d+)$/', $urlId, $matches)) {
                $originParams['page_id'] = (int)$matches[1];
            }
            if (!isset($originParams['website_id']) && $websiteId !== null && $websiteId > 0) {
                $originParams['website_id'] = $websiteId;
            }
            $basePath = Url::parse_url($origin_path, 'path') ?: '/pagebuilder/frontend/page/view';
            $rebuiltQuery = http_build_query($originParams);
            $origin_path = $basePath . ($rebuiltQuery !== '' ? '?' . $rebuiltQuery : '');
        }

        $event->setData('data', $origin_path);
        $query = Url::parse_url($origin_path, 'query');
        $decodedParams = [];
        parse_str($query, $decodedParams);
        $_GET = $decodedParams;
        $this->syncDecodedWlsRequestState($origin_path, $uri, $decodedParams, $websiteId);
    }

    /**
     * WLS 下 SEO 解码后需要同步当前请求状态，而不是依赖 302 跳转。
     * 否则同一请求后半段仍可能读取到旧 REQUEST_URI / Query / RequestContext / Request 参数包。
     */
    private function syncDecodedWlsRequestState(string $decodedUri, string $originUri, array $decodedParams, ?int $websiteId = null): void
    {
        $_SERVER['REQUEST_URI'] = $decodedUri;
        $_SERVER['QUERY_STRING'] = Url::parse_url($decodedUri, 'query') ?: '';
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = '/' . ltrim($originUri, '/');

        if ($websiteId !== null && $websiteId > 0) {
            $_SERVER['WELINE_WEBSITE_ID'] = (string)$websiteId;
        }

        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            \Weline\Framework\Runtime\RequestContext::syncFromServer();
            if ($websiteId !== null && $websiteId > 0) {
                \Weline\Framework\Runtime\RequestContext::websiteId($websiteId);
            }
            if (isset($decodedParams['locale']) && \is_string($decodedParams['locale']) && $decodedParams['locale'] !== '') {
                \Weline\Framework\Runtime\RequestContext::locale($decodedParams['locale']);
            }
            if (isset($decodedParams['currency']) && \is_string($decodedParams['currency']) && $decodedParams['currency'] !== '') {
                \Weline\Framework\Runtime\RequestContext::currency($decodedParams['currency']);
            }
        }

        try {
            /** @var \Weline\Framework\Http\Request $request */
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $request->resetParameterBag()
                ->invalidateUriCache()
                ->unsetData('params')
                ->setServer('REQUEST_URI', $decodedUri)
                ->setServer('QUERY_STRING', $_SERVER['QUERY_STRING'])
                ->setServer('WELINE_ORIGIN_REQUEST_URI', $_SERVER['WELINE_ORIGIN_REQUEST_URI']);

            foreach ($decodedParams as $key => $value) {
                if (\is_string($key)) {
                    $request->setGet($key, $value);
                }
            }
        } catch (\Throwable $e) {
            // 请求对象可能尚未初始化；此时保留 $_SERVER/$_GET 同步结果即可
        }
    }
}
