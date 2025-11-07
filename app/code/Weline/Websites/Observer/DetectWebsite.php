<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

class DetectWebsite implements ObserverInterface
{

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $get_sites = $event->getData('get_sites');
        if ($get_sites) {
            /** @var Website $website_model */
            $website_model = w_obj(Website::class);
            $sites = $website_model->select()->fetchArray();
            $event->setData('sites', $sites);
            return;
        }
        # 第一个url获取url协议和域名部分
        $url1 = $event->getData('url');
        $path = Url::parse_url($url1, 'path');
        # 网站模型
        /** @var Website $website_model */
        $website_model = w_obj(Website::class);
        # 获取$first_url加上/分割部分的第一个path
        if ($path !== '/') {
            $url2 = $url1 . explode('/', $_SERVER['REQUEST_URI'])[1] ?? '';
            /** @var Website $site */
            $site = $website_model->where('url', $url2)->find()->fetch();
            if ($site->getId()) {
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

        // 如果精确匹配失败，尝试使用域名匹配（处理 www 和非 www 的情况）
        if (!$site->getId()) {
            // 获取所有网站配置
            $allSites = $website_model->reset()->select()->fetchArray();
            
            // 遍历所有网站，使用域名匹配
            foreach ($allSites as $siteData) {
                $siteUrl = $siteData['url'] ?? '';
                if (empty($siteUrl)) {
                    continue;
                }
                
                // 使用域名匹配函数检查是否匹配
                if (Url::isHostMatch($url1, $siteUrl)) {
                    // 找到匹配的网站，创建 Website 对象
                    $site = $website_model->reset();
                    $site->setData($siteData);
                    break;
                }
            }
        }

        // 如果查不到站点，检查是否禁止未匹配的域名访问
        if (!$site->getId()) {
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
    }
}