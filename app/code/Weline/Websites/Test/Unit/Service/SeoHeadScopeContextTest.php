<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Observer\SeoHeadContextResolve;

final class SeoHeadScopeContextTest extends TestCase
{
    public function testStoreIdentityAndUrlOverrideWebsiteWhileChannelInherits(): void
    {
        WebsiteData::setWebsite((new Website())->setWebsiteId(0)->setCode('default')->setName('Website')->setUrl('https://website.test'));
        RequestContext::setWelineStoreId(11);
        RequestContext::setWelineChannelId(21);
        $stores = $this->createMock(StoreCatalogInterface::class);
        $stores->method('byId')->with(11)->willReturn(new StoreSummary(11, 0, 'shop', 'Shop', 'normal', false, true, 'active', null, 'https://shop.test:9555/retail'));
        $channels = $this->createMock(SalesChannelCatalogInterface::class);
        $channels->method('byId')->with(21)->willReturn(new SalesChannelSummary(21, 0, 11, 'web', 'Web', false, true, 'active', true));
        $event = new Event('Weline_Seo::head_context_resolve', ['head_context' => []]);
        (new SeoHeadContextResolve($stores, $channels))->execute($event);
        $head = $event->getData('head_context');
        self::assertSame('Shop', $head['site_name']);
        self::assertSame('https://shop.test:9555/retail', $head['organization']['url']);
        self::assertSame(11, $head['store_id']);
        self::assertSame(21, $head['channel_id']);
        WebsiteData::resetRequestState();
    }
}
