<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class WebsiteDirectoryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteDirectoryInterface
    {
        return ObjectManager::getInstance(WebsiteDirectory::class);
    }
}
