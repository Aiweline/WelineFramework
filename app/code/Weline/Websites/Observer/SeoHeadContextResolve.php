<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 向 SEO Head 解析事件提供当前站点名称、根 URL 等站点级 SEO 字段。
 */
class SeoHeadContextResolve implements ObserverInterface
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
    ) {
    }

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

        // site_name 权威源是 Website.name（网站基础信息 / 品牌身份），不由 Store.name 覆盖。
        $store = $this->stores->byId(RequestContext::getWelineStoreId());
        if ($store !== null && $store->websiteId === $website->getWebsiteId()) {
            $siteUrl = trim((string)$store->url) ?: $siteUrl;
            $headContext['store_id'] = $store->id;
            $headContext['store_code'] = $store->code;
            $storeName = trim($store->name);
            if ($storeName !== '') {
                $headContext['store_name'] = $storeName;
            }
            $channel = $this->channels->byId(RequestContext::getWelineChannelId());
            if ($channel !== null && $channel->websiteId === $website->getWebsiteId()
                && $channel->storeId === $store->id) {
                // Channels have no separate URL/meta fields; retain their identity
                // for SEO extensions and inherit the enclosing Store/Website values.
                $headContext['channel_id'] = $channel->id;
                $headContext['channel_code'] = $channel->code;
            }
        }

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
