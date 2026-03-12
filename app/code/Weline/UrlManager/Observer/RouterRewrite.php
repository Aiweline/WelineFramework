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
            $this->applyRewrite($event, $rewriteData['path'], $uri);
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
            $rewriteData = ['path' => $path];
            $cache->set($cacheKey, $rewriteData);
            
            $this->applyRewrite($event, $path, $uri);
        } else {
            $path = Url::parse_url($uri, 'path');
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $path);
            if (!$rewrite->getId()) {
                $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $path);
            }
            if ($rewrite->getId()) {
                $rewritePath = $rewrite->getData('path');
                $rewriteData = ['path' => $rewritePath];
                $cache->set($cacheKey, $rewriteData);
                
                $this->applyRewrite($event, $rewritePath, $uri);
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
     */
    private function applyRewrite(Event &$event, string $path, string $uri): void
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
        parse_str($query, $_GET);
    }
}
