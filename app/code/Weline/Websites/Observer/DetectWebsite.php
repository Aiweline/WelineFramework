<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

class DetectWebsite implements ObserverInterface
{
    private const CACHE_KEY_ALL_SITES = 'all_sites';
    private const CACHE_KEY_PREFIX_URL = 'site_url_';

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $cache = w_cache('website');
        
        $get_sites = $event->getData('get_sites');
        if ($get_sites) {
            // 优化：使用缓存，避免重复查询
            $sites = $cache->get(self::CACHE_KEY_ALL_SITES);
            
            if ($sites !== false && is_array($sites)) {
                // 使用缓存数据
                $event->setData('sites', array_values($sites));
                return;
            }
            
            // 缓存未命中，查询数据库
            /** @var Website $website_model */
            $website_model = w_obj(Website::class);
            $sites = $website_model->select()->fetchArray();
            
            // 保存到缓存
            $cache->set(self::CACHE_KEY_ALL_SITES, $sites);
            
            $event->setData('sites', $sites);
            return;
        }
        
        # 第一个url获取url协议和域名部分
        $url1 = $event->getData('url');
        $path = Url::parse_url($url1, 'path');
        
        // 优化：先尝试从缓存获取
        $urlCacheKey = self::CACHE_KEY_PREFIX_URL . md5($url1);
        $cachedSite = $cache->get($urlCacheKey);
        
        if ($cachedSite !== false && is_array($cachedSite) && !empty($cachedSite)) {
            // 找到缓存，直接使用
            /** @var Website $website_model */
            $website_model = w_obj(Website::class);
            $site = $website_model->reset();
            $site->setData($cachedSite);
            $this->processSite($event, $site);
            return;
        }
        
        # 网站模型
        /** @var Website $website_model */
        $website_model = w_obj(Website::class);
        # 获取$first_url加上/分割部分的第一个path
        if ($path !== '/') {
            $url2 = $url1 . explode('/', $_SERVER['REQUEST_URI'])[1] ?? '';
            /** @var Website $site */
            $site = $website_model->where('url', $url2)->find()->fetch();
            if ($site->getId()) {
                // 保存到缓存
                $url2CacheKey = self::CACHE_KEY_PREFIX_URL . md5($url2);
                $cache->set($url2CacheKey, $site->getData());
                $this->processSite($event, $site);
                return;
            }
        }

        # 采用最长匹配
        /** @var Website $site */
        $site = $website_model
            ->where('url', $url1)
            ->find()
            ->fetch();

        // 如果精确匹配失败，尝试使用最长匹配和域名匹配（处理 www 和非 www 的情况）
        if (!$site->getId()) {
            // 获取所有网站配置（使用缓存）
            $allSites = $cache->get(self::CACHE_KEY_ALL_SITES);
            if ($allSites === false || !is_array($allSites)) {
                $allSites = $website_model->reset()->select()->fetchArray();
                $cache->set(self::CACHE_KEY_ALL_SITES, $allSites);
            }
            
            // 首先尝试最长URL匹配
            $matchedSite = null;
            $maxLength = 0;
            foreach ($allSites as $siteData) {
                $siteUrl = $siteData['url'] ?? '';
                if (empty($siteUrl)) {
                    continue;
                }
                
                // 检查URL是否匹配（最长匹配）
                if (str_starts_with($url1, $siteUrl)) {
                    $siteUrlLength = strlen($siteUrl);
                    if ($siteUrlLength > $maxLength) {
                        $maxLength = $siteUrlLength;
                        $matchedSite = $siteData;
                    }
                }
            }
            
            // 如果最长匹配失败，尝试域名匹配
            if ($matchedSite === null) {
                // 解析当前URL的域名
                $currentHost = parse_url($url1, PHP_URL_HOST);
                
                // 遍历所有网站，使用域名匹配
                foreach ($allSites as $siteData) {
                    $siteUrl = $siteData['url'] ?? '';
                    if (empty($siteUrl)) {
                        continue;
                    }
                    
                    // 解析网站URL的域名
                    $siteHost = parse_url($siteUrl, PHP_URL_HOST);
                    
                    // 域名匹配（处理 www 和非 www 的情况）
                    if ($this->isHostMatch($currentHost, $siteHost)) {
                        $matchedSite = $siteData;
                        break;
                    }
                }
            }
            
            if ($matchedSite !== null) {
                // 找到匹配的网站，创建 Website 对象
                $site = $website_model->reset();
                $site->setData($matchedSite);
                // 保存到缓存
                $cache->set($urlCacheKey, $matchedSite);
            }
        }

        // 如果查不到站点，检查是否禁止未匹配的域名访问
        if (!$site->getId()) {
            // 缓存未找到的结果（空数组表示不存在）
            $cache->set($urlCacheKey, []);
            
            // 检查配置：是否禁止未匹配的域名访问
            $banUnmatchedDomain = Env::module_env('Weline_Websites', 'ban_unmatched_domain') ?? false;
            
            if ($banUnmatchedDomain) {
                // 如果配置了禁止未匹配的域名，返回404
                $response = ObjectManager::getInstance(\Weline\Framework\Http\Response::class);
                $response->noRouter(404, 'Website Not Found');
                return;
            }
            
            // 默认情况下，查不到站点也没关系，直接返回
            return;
        }

        // 保存到缓存
        $cache->set($urlCacheKey, $site->getData());
        
        /** @var DataObject $data */
        $this->processSite($event, $site);
    }

    /**
     * @param Event $event
     * @param Website $site
     * @return void
     */
    public function processSite(Event &$event, Website $site): void
    {
        /** @var DataObject $data */
        $data = $event->getData();
        $data->setData('website_url', $site->getUrl());
        $data->setData('website_id', $site->getWebsiteId());
        $data->setData('code', $site->getCode());
        $data->setData('default_currency', $site->getDefaultCurrency());
        $data->setData('default_language', $site->getDefaultLanguage());
        $data->setData('default_timezone', $site->getDefaultTimezone());
        
        # 设置默认时区
        date_default_timezone_set($site->getDefaultTimezone());
        
        # 设置静态网站数据类，供其他模块使用
        WebsiteData::setWebsite($site);
    }

    /**
     * 检查两个主机名是否匹配（处理 www 和非 www 的情况）
     * 
     * @param string $host1
     * @param string $host2
     * @return bool
     */
    private function isHostMatch(string $host1, string $host2): bool
    {
        if ($host1 === $host2) {
            return true;
        }
        
        // 处理 www 和非 www 的情况
        $host1WithoutWww = preg_replace('/^www\./', '', $host1);
        $host2WithoutWww = preg_replace('/^www\./', '', $host2);
        
        return $host1WithoutWww === $host2WithoutWww;
    }
}