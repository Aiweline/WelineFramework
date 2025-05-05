<?php

namespace Weline\Websites\Observer;

use Weline\Framework\App\Debug;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Websites\Model\Website;

class DetectWebsite implements ObserverInterface
{

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
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

        if (!$site->getId()) return;

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