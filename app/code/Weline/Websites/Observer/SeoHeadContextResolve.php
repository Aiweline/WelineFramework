<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 向 SEO Head 解析事件提供当前站点名称、根 URL 等站点级 SEO 字段。
 */
class SeoHeadContextResolve implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $website = WebsiteData::getWebsite();
        if ($website === null || $website->getWebsiteId() < 0) {
            return;
        }

        $siteName = trim($website->getName());
        $siteUrl = trim($website->getUrl());
        if ($siteName === '' && $siteUrl === '') {
            return;
        }

        $headContext = $event->getData('head_context');
        $headContext = is_array($headContext) ? $headContext : [];

        if ($siteName !== '') {
            $headContext['site_name'] = $siteName;
        }

        $organization = is_array($headContext['organization'] ?? null) ? $headContext['organization'] : [];
        if ($siteName !== '') {
            $organization['name'] = $siteName;
        }
        if ($siteUrl !== '') {
            $organization['url'] = $siteUrl;
        }
        if ($organization !== []) {
            $headContext['organization'] = $organization;
        }

        $headContext['website_id'] = $website->getWebsiteId();
        $headContext['website_code'] = $website->getCode();

        $event->setData('head_context', $headContext);
    }
}
