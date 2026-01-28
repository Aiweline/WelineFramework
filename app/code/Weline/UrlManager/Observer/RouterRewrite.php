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

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Cache\UrlRewriteCache;
use Weline\UrlManager\Model\UrlRewrite;

class RouterRewrite implements \Weline\Framework\Event\ObserverInterface
{
    // 优化：延迟加载，避免实例化时立即创建数据库连接
    private ?UrlRewrite $urlRewrite = null;
    
    // URL重写缓存实例
    private ?UrlRewriteCache $cache = null;

    public function __construct(
        ?UrlRewrite $urlRewrite = null
    )
    {
        // 延迟加载，不立即赋值
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
    private function getCache(): UrlRewriteCache
    {
        if ($this->cache === null) {
            $this->cache = new UrlRewriteCache();
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
     * 按 website_id 和 rewrite 查找重写记录
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
            ->where(UrlRewrite::fields_WEBSITE_ID, $websiteId)
            ->where(UrlRewrite::fields_REWRITE, $rewrite)
            ->find()
            ->fetch();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $uri = ltrim($event->getData(), '/');
        $cache = $this->getCache();
        $websiteId = $this->getCurrentWebsiteId();
        
        // 尝试从缓存获取（缓存按 website_id 隔离）
        // 返回值说明：
        // - array: 找到缓存，包含path
        // - null: 缓存了"未找到"的结果
        // - false: 缓存未命中，需要查询数据库
        $rewriteData = $cache->get($uri, $websiteId);
        
        if (is_array($rewriteData) && isset($rewriteData['path'])) {
            // 找到缓存，应用重写
            $this->applyRewrite($event, $rewriteData['path'], $uri);
            return;
        } elseif ($rewriteData === null) {
            // 缓存了"未找到"的结果，直接返回
            return;
        }
        
        // $rewriteData === false，缓存未命中，查询数据库
        // 按 (website_id, rewrite) 查询，不回退到 website_id=0
        $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $uri);
        if (!$rewrite->getId()) {
            // 尝试带斜杠的版本
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $uri);
        }
        
        if ($rewrite->getId()) {
            // 缓存查询结果
            $path = $rewrite->getData('path');
            $rewriteData = ['path' => $path];
            $cache->set($uri, $rewriteData, $websiteId);
            
            // 应用重写
            $this->applyRewrite($event, $path, $uri);
        } else {
            # 找不到尝试使用path匹配
            $path = Url::parse_url($uri, 'path');
            $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, $path);
            if (!$rewrite->getId()) {
                $rewrite = $this->findRewriteByWebsiteAndRewrite($websiteId, '/' . $path);
            }
            if ($rewrite->getId()) {
                // 缓存查询结果
                $rewritePath = $rewrite->getData('path');
                $rewriteData = ['path' => $rewritePath];
                $cache->set($uri, $rewriteData, $websiteId);
                
                // 应用重写
                $this->applyRewrite($event, $rewritePath, $uri);
            } else {
                // 缓存未找到的结果（避免重复查询）
                $cache->set($uri, null, $websiteId);
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
