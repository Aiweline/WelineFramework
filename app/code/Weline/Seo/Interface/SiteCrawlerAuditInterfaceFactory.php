<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

class SiteCrawlerAuditInterfaceFactory implements FactoryObjectInterface
{
    public function create(): SiteCrawlerAuditInterface
    {
        return ObjectManager::getInstance(SitemapCrawlerAuditService::class);
    }
}
